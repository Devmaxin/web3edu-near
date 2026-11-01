<?php
/**
 * Web3Edu NEAR — Generate Authentication Challenge
 * POST /auth/near-challenge.php
 * Body: {"action": "login"|"register"|"link", "user_id": int (optional, for link)}
 * Returns: {"challenge_id": "...", "nonce": "...", "recipient": "...", "expires_at": "..."}
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/near-auth.php';
require_once __DIR__ . '/../includes/csrf.php';

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

$action = $input['action'] ?? '';
$userId = $input['user_id'] ?? null;

// Rate limiting check (active enforcement)
if (Web3Edu\Near\Auth\NearAuth::isRateLimited($action)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts. Please wait and try again.']);
    exit;
}

// For 'link' action, user must be logged in
if ($action === 'link') {
    if (!isLoggedIn()) {
        Web3Edu\Near\Auth\NearAuth::recordFailedAttempt($action);
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    $userId = (int)$_SESSION['user_id'];
}

try {
    $challenge = Web3Edu\Near\Auth\NearAuth::createChallenge($action, $userId ?: null);

    echo json_encode([
        'success'    => true,
        'challenge_id' => $challenge['challenge_id'],
        'nonce'      => $challenge['nonce'],
        'recipient'  => $challenge['recipient'],
        'expires_at' => $challenge['expires_at'],
    ]);
} catch (Exception $e) {
    Web3Edu\Near\Auth\NearAuth::recordFailedAttempt($action);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to create challenge']);
    exit;
}
