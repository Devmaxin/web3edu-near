<?php
// ============================================
// Web3edu — Configuration
// ============================================

// Buffer all output from this point on. Several admin pages include
// header.php (which prints the page <head>/<html>) before running
// action handlers (delete/toggle/redirect, etc.) that later call
// header('Location: ...'). Without buffering, that early HTML output
// causes "headers already sent" warnings when those handlers try to
// redirect.
//
// IMPORTANT: we always call ob_start() here, even if ob_get_level()
// already reports an active buffer. Many default php.ini's (XAMPP
// included) set `output_buffering = 4096`, which starts an *implicit*
// buffer that auto-flushes in ~4KB chunks — it does NOT hold output
// until the script ends, so relying on it (or skipping ob_start()
// because one is "already" running) still lets header.php's HTML leak
// out mid-page and breaks later header() calls. An explicitly-started
// ob_start() buffer, by contrast, is not chunked and stays open until
// the script ends or we flush it ourselves, so we push our own buffer
// level on top of whatever the ini setting already started.
ob_start();


// ------------------------------------------------------------------
// Environment variables loaded from .env (project root).
// The secrets/secrets.php file from the original Web3Edu application
// is intentionally NOT copied — this hackathon project uses its own
// dedicated database (web3edu_near_hackathon) with its own credentials.
// ------------------------------------------------------------------

function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, "\"'");
            if (!getenv($k)) {
                putenv("$k=$v");
            }
        }
    }
}

// Load .env from project root (one level up from includes/)
$projectRoot = dirname(__DIR__, 1);
loadEnv($projectRoot . '/.env');

define('DB_CHARSET', 'utf8mb4');

// Database credentials from environment variables
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'web3edu_near_hackathon');
define('DB_USER', getenv('DB_USER') ?: 'web3edu_near');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Paystack — public key only; PAYSTACK_SECRET_KEY is defined in secrets.php
define('PAYSTACK_PUBLIC_KEY', 'pk_live_95e4117f31607724a20e6c0c67b5d52a32c5d62e');
define('PREMIUM_PRICE', 9900);
define('PREMIUM_PRICE_DISPLAY', '₦9,900');

// Resend (email provider — see includes/email/ResendProvider.php)
// RESEND_API_KEY is defined in secrets.php
define('EMAIL_FROM_ADDRESS', 'noreply@web3education.xyz');
define('EMAIL_FROM_NAME', 'Web3Edu');

// CRON_SECRET is defined in secrets.php

// ------------------------------------------------------------------
// Smart Internal Queue Runner (InfinityFree external-cron workaround)
// ------------------------------------------------------------------
// InfinityFree blocks cron-job.org from ever actually reaching
// cron/email-cron.php (it returns InfinityFree's own JS anti-bot
// challenge instead of running our PHP), so the email queue has no
// working external trigger there. While INTERNAL_EMAIL_RUNNER_ENABLED is
// true, includes/email/EmailQueueRunner.php opportunistically drains a
// small batch of the queue from normal page loads instead — see that
// file for the full explanation.
//
// TO DISABLE (e.g. after moving to Hostinger or another host with real
// cron support): set this to false, then point a real cron job at
// cron/email-cron.php?key=CRON_SECRET as usual. Nothing else needs to
// change — deliverEmailTemplate(), email_queue, and processEmailQueue()
// stay exactly the same either way.
define('INTERNAL_EMAIL_RUNNER_ENABLED', true);
define('INTERNAL_EMAIL_RUNNER_COOLDOWN', 60);   // seconds between opportunistic runs
define('INTERNAL_EMAIL_RUNNER_BATCH_SIZE', 8);  // emails drained per opportunistic run (5-10)

// Inactivity reminder detection — same InfinityFree-cron workaround, see
// includes/notifications/InactivityRunner.php. Runs far less often than the
// email queue drain since crossing a comeback threshold is a once-a-day-at-
// most event per user, not a constantly-refilling queue.
define('INACTIVITY_RUNNER_ENABLED', true);
define('INACTIVITY_RUNNER_COOLDOWN', 900);      // seconds between opportunistic scans (15 min)
define('INACTIVITY_RUNNER_BATCH_SIZE', 15);     // candidate users processed per scan

// Daily/Weekly Analytics Report scheduling — same InfinityFree-cron
// workaround, see includes/analytics/AnalyticsReportRunner.php. Claimed by
// calendar day/week rather than a time cooldown (these are "once per day"
// / "once per week" events, not a continuously-refilling queue).
define('ANALYTICS_REPORT_RUNNER_ENABLED', true);

// Site
define('SITE_NAME', 'Web3edu');
define('SITE_URL', rtrim(getenv('SITE_URL') ?: 'http://localhost:8000', '/'));
define('SITE_TAGLINE', 'Learn Blockchain. Build the Future.');

// Google OAuth 2.0 Authorization Code Flow
// Get these from: console.cloud.google.com → APIs & Services → Credentials
// Redirect URI must be registered in Google Cloud Console.
define('GOOGLE_CLIENT_ID',     '197658997944-u7mrk6sqqtpcv12o9n4mnki5g4ekrian.apps.googleusercontent.com');
// GOOGLE_CLIENT_SECRET is defined in secrets.php
define('GOOGLE_REDIRECT_URI',  'http://localhost:8000/auth/google-callback.php');

// Upload settings
define('UPLOAD_PATH',    __DIR__ . '/../uploads/');
define('UPLOAD_URL',     SITE_URL . '/uploads/');
define('MAX_FILE_SIZE',  5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg','image/png','image/webp','image/gif']);

// Lesson video uploads (CKEditor "Upload Video"). Matches the existing 50MB
// audio cap for now — bump this single constant when Web3Edu moves off
// shared hosting to a VPS/cloud host with higher upload limits. Note this
// value cannot exceed whatever the server's own upload_max_filesize /
// post_max_size (php.ini or .htaccess) allow — on InfinityFree those are
// capped independently of this app-level constant.
define('MAX_VIDEO_FILE_SIZE', 50 * 1024 * 1024); // 50MB
define('ALLOWED_VIDEO_TYPES', ['video/mp4']);

// Session
if (session_status() === PHP_SESSION_NONE) {
    // Detect HTTPS dynamically — avoids dropping the cookie on InfinityFree's
    // internal HTTP proxy layer, which causes their "Cookies are not enabled" error.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ============================================
// DATABASE
// ============================================
function getDB(): PDO {
    global $__web3edu_pdo;
    if ($__web3edu_pdo === null) {
        try {
            $dsn = "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=".DB_CHARSET;
            $__web3edu_pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;color:#f87171;background:#09070f">
                 <h2>Database Error</h2><p>Check your DB credentials in includes/config.php</p>
                 </div>');
        }
    }
    return $__web3edu_pdo;
}

/**
 * Explicitly release the current request's MySQL connection.
 *
 * InfinityFree's free-tier MySQL caps the number of connections a whole
 * account can hold open AT ONCE — historically only a handful — shared
 * across every visitor hitting the site at that moment. getDB() normally
 * keeps one connection open for a request's entire lifetime (cheap and
 * harmless for a fast page load), but the AI Tutor and AI Study Card
 * endpoints each hold that connection open through a slow, sometimes
 * multi-second outbound cURL call to the AI provider (see
 * api/ai_chat.php and api/ai_study_card.php) before they touch the DB
 * again to log usage. On a normal host that's a non-issue; on
 * InfinityFree's shared, low connection cap, several of those requests
 * overlapping is enough to exhaust the account's connection quota and
 * throw the "Database Error" page for a completely unrelated visitor
 * loading any other page at that moment — which is why the error
 * appears intermittently and clears itself once the slow request(s)
 * finish and their connections close.
 *
 * Calling this right before the outbound AI request frees the slot for
 * that window; getDB() transparently reconnects (a fast, same-region
 * connection) the next time it's called, e.g. to log the result.
 */
function closeDB(): void {
    global $__web3edu_pdo;
    $__web3edu_pdo = null;
}

// ============================================
// SETTINGS HELPERS
// ============================================

/**
 * Retrieve a single setting from site_settings table.
 * Falls back to $default if the key doesn't exist yet.
 */
function getSetting(string $key, string $default = ''): string {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT setting_val FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row  = $stmt->fetch();
        $cache[$key] = $row ? (string)$row['setting_val'] : $default;
    } catch (\Throwable $e) {
        // Table may not exist yet (pre-migration) — use default silently
        $cache[$key] = $default;
    }
    return $cache[$key];
}

/**
 * Get premium price in kobo (integer) — used for Paystack.
 */
function getPremiumPriceKobo(): int {
    $val = getSetting('premium_price_kobo', (string)PREMIUM_PRICE);
    $int = (int)$val;
    return $int > 0 ? $int : (int)PREMIUM_PRICE;
}

/**
 * Get premium price as human-readable display string, e.g. ₦9,900.
 * Auto-generates from kobo if display setting is blank.
 */
function getPremiumPriceDisplay(): string {
    $display = getSetting('premium_price_display', '');
    if ($display !== '') return $display;
    // Auto-format from kobo
    $naira = getPremiumPriceKobo() / 100;
    return '₦' . number_format($naira, $naira == floor($naira) ? 0 : 2);
}

// ============================================
// AUTH HELPERS
// ============================================
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Real-time premium check. Delegates to userHasActivePremium() (in
 * includes/subscriptions.php, required at the bottom of this file) which
 * checks actual subscription status + expiry rather than trusting the
 * session's cached flag — a session can outlive a subscription's natural
 * expiry, and this must never grant access that has actually lapsed.
 */
function isPremium(): bool {
    if (!isLoggedIn()) return false;
    return userHasActivePremium((int)$_SESSION['user_id']);
}

function isProfileComplete(): bool {
    return isset($_SESSION['profile_completed']) && $_SESSION['profile_completed'] == 1;
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

function requireProfileComplete(): void {
    if (!isProfileComplete()) {
        header('Location: ' . SITE_URL . '/onboarding.php');
        exit;
    }
}

function setUserSession(array $user): void {
    $_SESSION['user_id']           = $user['id'];
    $_SESSION['email']             = $user['email'];
    $_SESSION['username']          = $user['username'];
    $_SESSION['avatar']            = $user['avatar'];
    $_SESSION['membership_type']   = $user['membership_type'];
    $_SESSION['profile_completed'] = $user['profile_completed'];
    $_SESSION['login_provider']    = $user['login_provider'] ?? 'email';

    // Distinct from users.last_activity_date (only moves on a qualifying
    // learning activity — see updateStreak() in includes/gamification.php).
    // This fires on every session-establishing call (login, register,
    // Google OAuth, onboarding completion) — all genuine "the user is here
    // right now" events — so inactivity rules that key off login vs.
    // learning activity stay meaningfully different signals.
    try {
        getDB()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
    } catch (\Throwable $e) {
        error_log('[Auth] Failed to update last_login_at for user #' . $user['id'] . ': ' . $e->getMessage());
    }
}

// ============================================
// IMAGE / UPLOAD HELPERS
// ============================================

/**
 * Handle image upload. Returns filename on success, null on failure.
 * $errors array passed by reference for error messages.
 */
function handleImageUpload(string $inputName, string $subdir, array &$errors): ?string {
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // No file — not an error, just skip
    }

    $file = $_FILES[$inputName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload error: ' . uploadErrorMessage($file['error']);
        return null;
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        $errors[] = 'Image must be under 5MB.';
        return null;
    }

    // Validate MIME type properly (don't trust $_FILES['type'])
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, ALLOWED_IMAGE_TYPES, true)) {
        $errors[] = 'Only JPG, PNG, WebP, and GIF images are allowed.';
        return null;
    }

    $ext      = match($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg',
    };
    $filename = uniqid('img_', true) . '.' . $ext;
    $dir      = UPLOAD_PATH . $subdir . '/';

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        $errors[] = 'Failed to save uploaded file.';
        return null;
    }

    return $subdir . '/' . $filename;
}

function uploadErrorMessage(int $code): string {
    return match($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large.',
        UPLOAD_ERR_PARTIAL   => 'File only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR=> 'No temp directory.',
        UPLOAD_ERR_CANT_WRITE=> 'Cannot write to disk.',
        default              => 'Unknown upload error.',
    };
}

function imageUrl(?string $path, string $placeholder = 'lesson'): string {
    if (empty($path)) {
        return SITE_URL . '/assets/images/placeholder-' . $placeholder . '.svg';
    }
    // Could be a relative path from uploads/ or a legacy filename
    if (str_starts_with($path, 'http')) return $path;
    if (str_contains($path, '/')) return UPLOAD_URL . $path; // subdir/filename
    return UPLOAD_URL . $path;
}

function deleteUploadedFile(?string $path): void {
    if (empty($path) || str_starts_with($path, 'http')) return;
    $fullPath = UPLOAD_PATH . $path;
    if (file_exists($fullPath)) unlink($fullPath);
}

/**
 * Handle MP3 audio upload.
 * Returns relative path (audio/filename.mp3) on success, null if no file, halts on error.
 */
function handleAudioUpload(string $inputName, array &$errors): ?string {
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$inputName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Audio upload error: ' . uploadErrorMessage($file['error']);
        return null;
    }

    // Max 50MB for audio
    if ($file['size'] > 50 * 1024 * 1024) {
        $errors[] = 'Audio file must be under 50MB.';
        return null;
    }

    // Validate MIME — accept audio/mpeg (MP3) only
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if ($mimeType !== 'audio/mpeg') {
        $errors[] = 'Only MP3 audio files are allowed.';
        return null;
    }

    $dir = UPLOAD_PATH . 'audio/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Sanitize original filename, add unique prefix to avoid collisions
    $originalName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $originalName = substr($originalName, 0, 60); // cap length
    $filename     = uniqid('aud_', true) . '_' . $originalName . '.mp3';

    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        $errors[] = 'Failed to save audio file.';
        return null;
    }

    return 'audio/' . $filename;
}

/**
 * Handle MP4 video upload (used by the CKEditor "Upload Video" feature).
 * Returns relative path (videos/filename.mp4) on success, null on failure.
 * $errors array passed by reference for error messages.
 */
function handleVideoUpload(string $inputName, array &$errors): ?string {
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$inputName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Video upload error: ' . uploadErrorMessage($file['error']);
        return null;
    }

    if ($file['size'] > MAX_VIDEO_FILE_SIZE) {
        $errors[] = 'Video must be under ' . round(MAX_VIDEO_FILE_SIZE / (1024 * 1024)) . 'MB.';
        return null;
    }

    // Validate MIME type properly (don't trust $_FILES['type'])
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, ALLOWED_VIDEO_TYPES, true)) {
        $errors[] = 'Only MP4 video files are allowed.';
        return null;
    }

    // Extra guard beyond MIME sniffing: reject if the extension is wildly
    // different from what was declared (defense in depth, not the only check).
    $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($origExt !== '' && !in_array($origExt, ['mp4', 'm4v'], true)) {
        $errors[] = 'Only .mp4 video files are allowed.';
        return null;
    }

    $dir = UPLOAD_PATH . 'videos/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = uniqid('vid_', true) . '.mp4';

    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        $errors[] = 'Failed to save video file.';
        return null;
    }

    return 'videos/' . $filename;
}

// ============================================
// GOOGLE OAUTH 2.0 HELPERS
// ============================================

/**
 * Verify a Google ID token server-side via Google's tokeninfo endpoint.
 * NOTE: In the Authorization Code Flow, token verification is handled by
 * GoogleOAuth::decodeIdToken() + GoogleOAuth::getUserInfo().
 * This function is retained for backward-compatibility only.
 * Returns payload array on success, null on failure.
 */
function verifyGoogleToken(string $idToken): ?array {
    $url      = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $ctx      = stream_context_create(['http' => [
        'timeout'        => 10,
        'ignore_errors'  => true,
    ]]);
    $response = @file_get_contents($url, false, $ctx);
    if (!$response) return null;

    $data = json_decode($response, true);
    if (!$data || isset($data['error'])) return null;

    // Validate audience matches our client ID
    if (($data['aud'] ?? '') !== GOOGLE_CLIENT_ID) return null;

    // Check token expiry
    if (($data['exp'] ?? 0) < time()) return null;

    return $data;
}

/**
 * Find or create a user from Google OAuth data.
 * Returns ['user' => array, 'is_new' => bool]
 */
function findOrCreateGoogleUser(array $googleData): array {
    $db        = getDB();
    $googleId  = $googleData['sub'];
    $email     = $googleData['email'];
    $name      = $googleData['name'] ?? '';
    $picture   = $googleData['picture'] ?? null;

    // 1. Check by google_id
    $stmt = $db->prepare("SELECT * FROM users WHERE google_id = ?");
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();
    if ($user) {
        return ['user' => $user, 'is_new' => false];
    }

    // 2. Check by email (existing email/password user)
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user) {
        // Link Google ID to existing account
        $db->prepare("UPDATE users SET google_id = ?, login_provider = 'google' WHERE id = ?")
           ->execute([$googleId, $user['id']]);
        $user['google_id']      = $googleId;
        $user['login_provider'] = 'google';
        return ['user' => $user, 'is_new' => false];
    }

    // 3. Create new user from Google
    $avatar = getRandomAvatar();
    $stmt   = $db->prepare("
        INSERT INTO users (email, password, google_id, avatar, membership_type, profile_completed, login_provider)
        VALUES (?, '', ?, ?, 'free', 0, 'google')
    ");
    $stmt->execute([$email, $googleId, $avatar]);
    $userId = (int)$db->lastInsertId();

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    return ['user' => $user, 'is_new' => true];
}

// ============================================
// UTILITY HELPERS
// ============================================
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Build the canonical URL for a lesson opened through a guide.
 * Uses pretty URL (/guides/{guideId}/lesson/{lessonId}) which .htaccess
 * rewrites to guides/lesson.php?guide_id=X&id=Y.
 * Falls back gracefully: if mod_rewrite is unavailable the direct query-string
 * URL (/guides/lesson.php?guide_id=X&id=Y) also works without any rewrite.
 */
function guideLessonUrl(int $guideId, int $lessonId): string {
    return SITE_URL . '/guides/' . $guideId . '/lesson/' . $lessonId;
}

/**
 * Direct (non-rewrite) fallback URL for guide lessons.
 * Use this as an href alongside the pretty URL via JS redirect if needed.
 */
function guideLessonDirectUrl(int $guideId, int $lessonId): string {
    return SITE_URL . '/guides/lesson.php?guide_id=' . $guideId . '&id=' . $lessonId;
}

function getAvatarList(): array {
    return [
        'cyber_01.svg' => 'Cyber Punk',
        'cyber_02.svg' => 'Cyber Ghost',
        'neon_01.svg'  => 'Neon Rider',
        'neon_02.svg'  => 'Neon Soul',
        'robot_01.svg' => 'Bot Prime',
        'robot_02.svg' => 'Data Bot',
        'alien_01.svg' => 'Crypto Alien',
        'pixel_01.svg' => 'Pixel OG',
    ];
}

function getRandomAvatar(): string {
    $avatars = array_keys(getAvatarList());
    return $avatars[array_rand($avatars)];
}

function avatarUrl(?string $filename): string {
    $svgName = $filename ? preg_replace('/\.(png|jpg|jpeg|gif)$/i', '.svg', $filename) : '';
    $svgName = basename($svgName); // strip any path component — defense against traversal
    $diskPath = __DIR__ . '/../assets/avatars/' . $svgName;

    if ($svgName === '' || !is_file($diskPath)) {
        $svgName = 'placeholder.svg'; // file missing or none set — never link a broken image
    }
    return SITE_URL . '/assets/avatars/' . $svgName;
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function generateSlug(string $text): string {
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

function uniqueSlug(string $table, string $base): string {
    $db   = getDB();
    $slug = $base;
    $i    = 1;
    while (true) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE slug = ?");
        $stmt->execute([$slug]);
        if ((int)$stmt->fetchColumn() === 0) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60)   . 'm ago';
    if ($diff < 86400)  return floor($diff/3600)  . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}

function formatBytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes/1024, 1) . ' KB';
    return round($bytes/1048576, 1) . ' MB';
}

// CSRF protection helpers (csrfToken/csrfField/requireCsrf/requireCsrfAjax) —
// loaded globally, right after the session is started above, so every page
// can protect its POST handlers without an extra require. See
// includes/csrf.php for usage.
require_once __DIR__ . '/csrf.php';

// NotificationService — the single entry point for in-app + email +
// admin notifications (notifyUser/emailUser/notifyAdmins, formerly
// defined only in includes/bounties.php; also owns the Notification
// Preferences event catalog used by deliverEmailTemplate() below and by
// admin/settings.php). Loaded before EmailService.php since
// deliverEmailTemplate() calls isNotificationEmailEnabled() from here.
require_once __DIR__ . '/notifications/NotificationService.php';

// Daily Summary Service — generates + queues the admin "Today's Activity"
// digest (includes/notifications/DailySummaryService.php). Loaded after
// NotificationService.php (needs getAdminNotificationRecipients()/adminNotify())
// and before EmailService.php isn't required since it only calls
// deliverEmailTemplate(), which is safe to reference before its own file
// loads because PHP resolves function calls at runtime, not include order —
// still loaded next for readability.
require_once __DIR__ . '/notifications/DailySummaryService.php';

// Email system — deliverEmailTemplate() is used throughout the app
// (subscriptions, auth flows, guide/certificate events), so it's loaded
// here globally rather than requiring every call site to remember to.
require_once __DIR__ . '/email/EmailService.php';

// Smart Internal Queue Runner — see the constants above and
// includes/email/EmailQueueRunner.php for the full explanation. Registered
// as a *shutdown* function so it fires after the page has already done its
// own work (and, when possible, after the response has already been sent
// to the visitor), rather than adding a check in the middle of every
// request's normal flow.
require_once __DIR__ . '/email/EmailQueueRunner.php';
register_shutdown_function('maybeRunEmailQueueInBackground');

// Smart Inactivity Reminder Runner — see the constants above and
// includes/notifications/InactivityRunner.php (which pulls in
// gamification.php itself, so its shutdown function has
// wantsInactivityReminders() etc. available on every request, not just
// pages that happen to require gamification.php themselves).
require_once __DIR__ . '/notifications/InactivityRunner.php';
register_shutdown_function('maybeRunInactivityCheckInBackground');

require_once __DIR__ . '/analytics/AnalyticsReportRunner.php';
register_shutdown_function('maybeRunAnalyticsReportsInBackground');

// Subscriptions — isPremium() above delegates to userHasActivePremium(),
// defined here, so this needs to be loaded globally rather than requiring
// every page that gates premium content to remember an extra require.
require_once __DIR__ . '/subscriptions.php';

// Product Analytics — Analytics::track()/trackAnalyticsEvent() write to the
// existing `analytics_events` table (see includes/analytics.php docblock).
// Loaded globally so every page/API endpoint can fire an event without an
// extra require, the same way emailUser()/notifyUser() are always available.
require_once __DIR__ . '/analytics.php';

/** Whether the current logged-in user has bookmarked a given piece of content. */
function isBookmarked(string $type, int $id): bool
{
    if (!isLoggedIn()) return false;
    $stmt = getDB()->prepare("SELECT id FROM user_bookmarks WHERE user_id=? AND bookmarkable_type=? AND bookmarkable_id=?");
    $stmt->execute([$_SESSION['user_id'], $type, $id]);
    return (bool)$stmt->fetch();
}
