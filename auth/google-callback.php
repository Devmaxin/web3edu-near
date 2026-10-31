<?php
/**
 * Web3edu — Google OAuth 2.0 Callback Handler
 *
 * Google redirects the user here after they select an account.
 * This handler:
 *   1. Validates the CSRF state token
 *   2. Handles OAuth errors (user denied, etc.)
 *   3. Exchanges the authorization code for tokens
 *   4. Fetches the verified user profile from Google
 *   5. Creates or updates the local user account
 *   6. Sets the session and redirects to dashboard / onboarding
 *
 * URL:  /auth/google-callback.php
 * Method: GET (redirect from Google — NOT a POST like the old GIS flow)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/GoogleOAuth.php';
require_once __DIR__ . '/../includes/waitlist_rewards.php';

// ------------------------------------------------------------------
// Guard: Only GET requests are valid (Google redirects via GET)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    setFlash('error', 'Invalid request method.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// ------------------------------------------------------------------
// Guard: Already logged in
// ------------------------------------------------------------------
if (isLoggedIn()) {
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

// ------------------------------------------------------------------
// STEP 1 — Handle OAuth errors returned by Google
// e.g. user clicks "Cancel" on the consent screen
// ------------------------------------------------------------------
if (!empty($_GET['error'])) {
    $oauthError = htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8');

    // Map known error codes to friendly messages
    $friendlyMessages = [
        'access_denied'              => 'You cancelled the Google sign-in. Please try again.',
        'interaction_required'       => 'Additional interaction is required. Please sign in again.',
        'login_required'             => 'Google requires you to sign in first.',
        'account_selection_required' => 'Please select a Google account to continue.',
    ];

    $msg = $friendlyMessages[$oauthError] ?? 'Google sign-in was unsuccessful. Please try again.';
    setFlash('error', $msg);
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// ------------------------------------------------------------------
// STEP 2 — Validate the authorization code is present
// ------------------------------------------------------------------
$code  = trim($_GET['code']  ?? '');
$state = trim($_GET['state'] ?? '');

if (empty($code)) {
    setFlash('error', 'No authorization code received from Google. Please try again.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// ------------------------------------------------------------------
// STEP 3 — Validate CSRF state token
// ------------------------------------------------------------------
$oauth = new GoogleOAuth(GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI);

if (!$oauth->validateState($state)) {
    // State mismatch — potential CSRF attack or expired session
    setFlash('error', 'Security validation failed. Please try signing in again.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// ------------------------------------------------------------------
// STEP 4 — Exchange authorization code for access + ID tokens
// ------------------------------------------------------------------
$tokens = $oauth->exchangeCodeForTokens($code);

if (!$tokens || empty($tokens['access_token'])) {
    setFlash('error', 'Could not complete Google sign-in. Please try again.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// ------------------------------------------------------------------
// STEP 5 — Validate ID token claims (issuer, audience, expiry)
// ------------------------------------------------------------------
$idTokenClaims = null;
if (!empty($tokens['id_token'])) {
    $idTokenClaims = $oauth->decodeIdToken($tokens['id_token']);
    if (!$idTokenClaims) {
        setFlash('error', 'Google sign-in verification failed. Please try again.');
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

// ------------------------------------------------------------------
// STEP 6 — Fetch authoritative user profile via UserInfo endpoint
// This is a second server-to-server call that confirms identity
// independently of the ID token.
// ------------------------------------------------------------------
$googleUser = $oauth->getUserInfo($tokens['access_token']);

if (!$googleUser || empty($googleUser['sub']) || empty($googleUser['email'])) {
    setFlash('error', 'Could not retrieve your Google account details. Please try again.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// ------------------------------------------------------------------
// STEP 7 — Require verified email
// ------------------------------------------------------------------
if (($googleUser['email_verified'] ?? false) !== true) {
    setFlash('error', 'Your Google account email is not verified. Please verify it and try again.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// ------------------------------------------------------------------
// STEP 8 — Sanitize user data from Google
// ------------------------------------------------------------------
$googleId   = (string) $googleUser['sub'];
$email      = strtolower(trim((string) $googleUser['email']));
$name       = trim((string) ($googleUser['name'] ?? ''));
$givenName  = trim((string) ($googleUser['given_name'] ?? ''));
$picture    = (string) ($googleUser['picture'] ?? '');

// Final sanity check on email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    setFlash('error', 'Invalid email address returned by Google.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// ------------------------------------------------------------------
// STEP 9 — Find or create user in our database
// Delegates to the existing findOrCreateGoogleUser() in config.php,
// which handles: existing Google user, email merge, new registration.
// ------------------------------------------------------------------
$googleData = [
    'sub'            => $googleId,
    'email'          => $email,
    'name'           => $name,
    'given_name'     => $givenName,
    'picture'        => $picture,
    'email_verified' => 'true', // Already validated above
];

$result = findOrCreateGoogleUser($googleData);
$user   = $result['user'];
$isNew  = $result['is_new'];

if (!$user) {
    setFlash('error', 'Failed to create or retrieve your account. Please try again.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// ------------------------------------------------------------------
// STEP 10 — Set session
// ------------------------------------------------------------------
setUserSession($user);

Analytics::track($isNew ? 'signup' : 'login', (int)$user['id'], ['method' => 'google']);

// Brand-new Google account: run the same shared post-registration step as password sign-up (welcome bonus via the
// existing XP rules, then any admin-approved waitlist reward for this email, exactly once). Google's email_verified
// was enforced in STEP 7. Existing accounts signing in (including a first-time Google link to an email/password
// account) are NOT a registration and are never credited here. Never blocks sign-in.
$rewardNote = '';
if ($isNew) {
    $rewards = wlrOnRegistration((int)$user['id'], 'google');
    if ($rewards['waitlist_xp'] > 0) {
        $rewardNote = ' Your early-access rewards were added: ' . (int)$rewards['waitlist_xp'] . ' XP.';
    }
}

// Regenerate session ID after privilege change (security best practice)
session_regenerate_id(true);

// ------------------------------------------------------------------
// STEP 10b — Queue welcome email for brand-new Google users
// ------------------------------------------------------------------
if ($isNew) {
    deliverEmailTemplate(
        'welcome',
        $email,
        [
            'username' => $email
        ],
        immediate: true
    );
}

// ------------------------------------------------------------------
// STEP 11 — Redirect based on profile completion
// ------------------------------------------------------------------
if (!$user['profile_completed']) {
    // New user (or incomplete profile) → guided onboarding
    setFlash('info', 'Welcome' . ($givenName ? ', ' . htmlspecialchars($givenName, ENT_QUOTES, 'UTF-8') : '') . '! Set up your Web3edu profile to get started.' . $rewardNote);
    header('Location: ' . SITE_URL . '/onboarding.php');
} else {
    // Existing user with complete profile
    $redirect = $_SESSION['redirect_after_login'] ?? SITE_URL . '/dashboard.php';
    unset($_SESSION['redirect_after_login']);

    // Whitelist the redirect (must start with our site URL)
    if (!str_starts_with($redirect, SITE_URL)) {
        $redirect = SITE_URL . '/dashboard.php';
    }

    $msg = $isNew
        ? 'Account created via Google! Welcome to Web3edu.' . $rewardNote
        : 'Welcome back, ' . htmlspecialchars($user['username'] ?? 'Explorer', ENT_QUOTES, 'UTF-8') . '!';

    setFlash('success', $msg);
    header('Location: ' . $redirect);
}
exit;
