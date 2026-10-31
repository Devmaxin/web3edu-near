<?php
/**
 * Web3edu — Google OAuth 2.0 Login Initiator
 *
 * This file is the entry point for Google OAuth sign-in.
 * It generates a CSRF state token and redirects the user to
 * Google's account selection / consent screen.
 *
 * URL:  /auth/google-login.php
 * Flow: User clicks "Continue with Google" → this file →
 *       Google (account picker) → /auth/google-callback.php
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/GoogleOAuth.php';

// Already logged in — nothing to do
if (isLoggedIn()) {
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

// Capture optional return URL (e.g. from requireLogin() redirect)
// Only accept relative URLs to prevent open-redirect attacks
$returnTo = null;
if (!empty($_GET['return'])) {
    $candidate = trim($_GET['return']);
    // Allow only relative paths starting with /
    if (preg_match('#^/[^/\\\\]#', $candidate)) {
        $returnTo = SITE_URL . $candidate;
    }
} elseif (!empty($_SESSION['redirect_after_login'])) {
    $returnTo = $_SESSION['redirect_after_login'];
}

// Build the OAuth client and generate the redirect URL
$oauth    = new GoogleOAuth(GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI);
$authUrl  = $oauth->getAuthUrl($returnTo);

// Redirect user to Google
header('Location: ' . $authUrl);
exit;
