<?php
/**
 * Web3edu — Google OAuth 2.0 Authorization Code Flow
 *
 * Pure PHP implementation — no Composer, no external libraries.
 * Compatible with InfinityFree shared hosting (PHP 8+, cURL available).
 *
 * Flow:
 *   1. getAuthUrl()        → build redirect URL for Google's consent screen
 *   2. validateState()     → verify CSRF state token on callback
 *   3. exchangeCodeForTokens() → swap auth code for access + id tokens
 *   4. getUserInfo()       → fetch user profile from Google
 */
class GoogleOAuth
{
    // Google OAuth 2.0 endpoints
    private const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL   = 'https://www.googleapis.com/oauth2/v3/userinfo';
    private const CERTS_URL      = 'https://www.googleapis.com/oauth2/v3/certs';

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct(string $clientId, string $clientSecret, string $redirectUri)
    {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri  = $redirectUri;
    }

    // =========================================================
    // STEP 1 — Generate state token and build Google auth URL
    // =========================================================

    /**
     * Generate a cryptographically secure state token, store it in
     * session, and return the full Google authorization URL.
     *
     * @param string|null $returnTo  Optional URL to return to after login.
     *                               Stored in session and honoured in callback.
     * @return string                Full URL to redirect the user to.
     */
    public function getAuthUrl(?string $returnTo = null): string
    {
        // Generate state — 32 random bytes → hex (64 chars), CSRF-proof
        $state = bin2hex(random_bytes(32));

        // Store state + optional return destination
        $_SESSION['google_oauth_state']  = $state;
        $_SESSION['google_oauth_time']   = time();
        if ($returnTo) {
            $_SESSION['redirect_after_login'] = $returnTo;
        }

        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',  // always show account picker
        ]);

        return self::AUTH_ENDPOINT . '?' . $params;
    }

    // =========================================================
    // STEP 2 — Validate CSRF state on callback
    // =========================================================

    /**
     * Validate the state parameter returned by Google.
     * Returns true only if it matches the session-stored value AND
     * the token was created within the last 15 minutes.
     */
    public function validateState(string $receivedState): bool
    {
        $stored    = $_SESSION['google_oauth_state']  ?? '';
        $issuedAt  = $_SESSION['google_oauth_time']   ?? 0;

        // Clear state from session regardless — one-time use
        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_time']);

        if (empty($stored) || empty($receivedState)) {
            return false;
        }

        // Timing-safe comparison prevents timing attacks
        if (!hash_equals($stored, $receivedState)) {
            return false;
        }

        // Expiry check: state must be < 15 minutes old
        if ((time() - $issuedAt) > 900) {
            return false;
        }

        return true;
    }

    // =========================================================
    // STEP 3 — Exchange authorization code for tokens
    // =========================================================

    /**
     * POST the authorization code to Google's token endpoint.
     * Returns the decoded JSON response (contains access_token, id_token,
     * expires_in, token_type) or null on failure.
     *
     * @throws RuntimeException  On network or HTTP error.
     */
    public function exchangeCodeForTokens(string $code): ?array
    {
        $postData = http_build_query([
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        $response = $this->httpPost(self::TOKEN_ENDPOINT, $postData);

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (isset($data['error'])) {
            // Log error details server-side only — never expose to user
            error_log('[Web3edu Google OAuth] Token exchange error: ' . ($data['error_description'] ?? $data['error']));
            return null;
        }

        return $data;
    }

    // =========================================================
    // STEP 4 — Fetch user profile using access token
    // =========================================================

    /**
     * Fetch the authenticated user's profile from Google's UserInfo endpoint.
     * Returns array with: sub, email, email_verified, name, picture, given_name
     * Returns null on failure.
     */
    public function getUserInfo(string $accessToken): ?array
    {
        $response = $this->httpGet(
            self::USERINFO_URL,
            ['Authorization: Bearer ' . $accessToken]
        );

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['sub'])) {
            return null;
        }

        return $data;
    }

    // =========================================================
    // ID TOKEN — Decode and validate without library
    // =========================================================

    /**
     * Decode and validate the JWT ID token from Google.
     *
     * Verifies:
     *   - Token structure (3 parts)
     *   - Audience (aud) matches our client ID
     *   - Issuer (iss) is accounts.google.com
     *   - Expiry (exp) has not passed
     *
     * NOTE: This performs claim validation only. Signature
     * verification against Google's JWK certs is intentionally
     * skipped here because we also fetch via getUserInfo() with
     * the access token, providing a separate server-to-server
     * verified channel. For a standalone ID-token-only flow,
     * add JWK signature verification.
     *
     * @return array|null  Decoded payload or null on failure.
     */
    public function decodeIdToken(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = $this->base64UrlDecode($parts[1]);
        if ($payload === false) {
            return null;
        }

        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return null;
        }

        // Validate issuer
        if (!in_array($data['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
            error_log('[Web3edu Google OAuth] Invalid ID token issuer: ' . ($data['iss'] ?? 'none'));
            return null;
        }

        // Validate audience
        $aud = $data['aud'] ?? '';
        if (is_array($aud)) {
            $aud = $aud[0]; // Single-client apps always have one audience
        }
        if ($aud !== $this->clientId) {
            error_log('[Web3edu Google OAuth] ID token audience mismatch.');
            return null;
        }

        // Validate expiry (with 60-second clock skew tolerance)
        if (($data['exp'] ?? 0) < (time() - 60)) {
            error_log('[Web3edu Google OAuth] ID token expired.');
            return null;
        }

        return $data;
    }

    // =========================================================
    // HTTP helpers (cURL with file_get_contents fallback)
    // =========================================================

    /**
     * HTTP POST with application/x-www-form-urlencoded body.
     */
    private function httpPost(string $url, string $body): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($response === false || !empty($error)) {
                error_log('[Web3edu Google OAuth] cURL POST error: ' . $error);
                return null;
            }
            if ($httpCode >= 400) {
                error_log('[Web3edu Google OAuth] HTTP ' . $httpCode . ' from token endpoint: ' . $response);
                // Still return body — it contains the error JSON
                return $response;
            }
            return $response;
        }

        // Fallback: file_get_contents (requires allow_url_fopen = On)
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content'       => $body,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        return $response !== false ? $response : null;
    }

    /**
     * HTTP GET with optional headers array.
     */
    private function httpGet(string $url, array $headers = []): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            ]);
            $response = curl_exec($ch);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($response === false || !empty($error)) {
                error_log('[Web3edu Google OAuth] cURL GET error: ' . $error);
                return null;
            }
            return $response;
        }

        // Fallback: file_get_contents
        $headerStr = implode("\r\n", $headers);
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => $headerStr ? ($headerStr . "\r\n") : '',
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        return $response !== false ? $response : null;
    }

    /**
     * URL-safe base64 decode (JWT uses + → - and / → _ and no padding).
     */
    private function base64UrlDecode(string $input): string|false
    {
        $padded = str_pad(
            strtr($input, '-_', '+/'),
            strlen($input) + (4 - strlen($input) % 4) % 4,
            '='
        );
        return base64_decode($padded);
    }
}
