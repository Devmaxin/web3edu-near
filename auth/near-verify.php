<?php
/**
 * Web3Edu NEAR — Verify NEP-413 Signature & Establish Session
 * POST /auth/near-verify.php
 * Body: {
 *   "challenge_id": "...",
 *   "account_id": "...",
 *   "public_key": "...",
 *   "signature": "...",
 *   "signed_payload": {"message": "...", "nonce": "...", "recipient": "...", "callbackUrl?": "..."}
 * }
 * Returns: {"success": true, "user_id": int, "action": "login"|"register"|"link", "is_new": bool}
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/near-auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/gamification.php';
require_once __DIR__ . '/../includes/waitlist_rewards.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF protection
requireCsrfAjax();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$challengeId = $input['challenge_id'] ?? '';
$accountId   = $input['account_id'] ?? '';
$publicKey   = $input['public_key'] ?? '';
$signature   = $input['signature'] ?? '';
$signedPayload = $input['signed_payload'] ?? [];

if (!$challengeId || !$accountId || !$publicKey || !$signature || !$signedPayload) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Consume the challenge (exactly once)
$challenge = Web3Edu\Near\Auth\NearAuth::consumeChallenge($challengeId);
if (!$challenge) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid, expired, or already-used challenge']);
    exit;
}

// Verify NEP-413 signature
try {
    $valid = Web3Edu\Near\Auth\NearAuth::verifyNep413Signature(
        $challenge,
        $accountId,
        $publicKey,
        $signature,
        $signedPayload
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Signature verification error']);
    exit;
}

if (!$valid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid signature']);
    exit;
}

// Signature verified. Now handle based on action.
$action = $challenge['action'];
$db = getDB();
$userId = null;
$isNew = false;

// ------------------------------------------------------------------
// Find or create user based on action
// ------------------------------------------------------------------

// Check if wallet is already linked
$stmt = $db->prepare("SELECT user_id FROM user_wallets WHERE near_account_id = ?");
$stmt->execute([$accountId]);
$existingWallet = $stmt->fetch();

if ($existingWallet) {
    // Wallet already linked to a user
    $userId = (int)$existingWallet['user_id'];

    if ($action === 'register') {
        // Wallet already exists — user should log in instead
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error'   => 'Wallet already registered. Please log in instead.',
            'action'  => 'login',
        ]);
        exit;
    }

    // login or link — authenticate existing user
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Linked user not found']);
        exit;
    }

    $isNew = false;

} else {
    // Wallet not yet linked
    if ($action === 'login') {
        // Unknown wallet cannot log in
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'Wallet not registered. Please register first.',
            'action'  => 'register',
        ]);
        exit;
    }

    if ($action === 'link') {
        // Linking requires current user to be logged in
        if (!isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            exit;
        }
        $userId = (int)$_SESSION['user_id'];
        $isNew = false;
    } elseif ($action === 'register') {
        // Create new wallet-only user
        $avatar = getRandomAvatar();
        $stmt = $db->prepare("
            INSERT INTO users (email, password, avatar, membership_type, profile_completed, login_provider)
            VALUES (NULL, '', ?, 'free', 0, 'near')
        ");
        $stmt->execute([$avatar]);
        $userId = (int)$db->lastInsertId();
        $isNew = true;

        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }
}

// ------------------------------------------------------------------
// Create wallet link
// ------------------------------------------------------------------
$isPrimary = false;
$stmt = $db->prepare("SELECT COUNT(*) FROM user_wallets WHERE user_id = ?");
$stmt->execute([$userId]);
$existingCount = (int)$stmt->fetchColumn();
if ($existingCount === 0) {
    $isPrimary = true; // First wallet becomes primary
}

$stmt = $db->prepare("
    INSERT INTO user_wallets (user_id, near_account_id, public_key, is_primary)
    VALUES (?, ?, ?, ?)
");
$stmt->execute([$userId, $accountId, $publicKey, $isPrimary ? 1 : 0]);

// ------------------------------------------------------------------
// Establish PHP session
// ------------------------------------------------------------------
setUserSession([
    'id'             => $userId,
    'email'          => $user['email'] ?? null,
    'username'       => $user['username'],
    'avatar'         => $user['avatar'],
    'membership_type'=> $user['membership_type'],
    'profile_completed'=> $user['profile_completed'],
    'login_provider' => 'near',
]);

// Track analytics
Analytics::track($isNew ? 'signup' : 'login', $userId, ['method' => 'near', 'action' => $action]);

// Welcome bonus for new registrations
if ($isNew && $action === 'register') {
    $rewards = wlrOnRegistration($userId, 'near');
}

// Regenerate session ID after privilege change
session_regenerate_id(true);

echo json_encode([
    'success' => true,
    'user_id' => $userId,
    'action'  => $action,
    'is_new'  => $isNew,
    'is_primary_wallet' => $isPrimary,
]);
