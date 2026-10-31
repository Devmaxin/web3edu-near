<?php
/**
 * Web3edu — CKEditor Upload Endpoint
 * ============================================
 * Handles inline image and video uploads triggered from the CKEditor 5
 * toolbar in admin/lessons.php (used for both Lessons and Guide Lessons,
 * which share the same lesson content editor and the same `lessons` table).
 *
 * Two request shapes are supported:
 *
 *   1. CKEditor's built-in image upload adapter (SimpleUploadAdapter),
 *      which POSTs the file under the field name "upload" and expects the
 *      CKEditor-specific JSON response shape: {"url": "..."} on success,
 *      or {"error": {"message": "..."}} on failure.
 *
 *   2. Our custom "Upload Video" toolbar button, which POSTs the file
 *      under the field name "video" (?type=video) and expects a simpler
 *      {"success": true, "url": "..."} shape that assets/js/admin-lesson-
 *      editor.js reads directly.
 *
 * Reuses the exact same validation helpers (handleImageUpload,
 * handleVideoUpload) as every other upload path in the app — MIME
 * sniffing via finfo, size caps, unique filenames, organized subdirs.
 * Only authenticated admins may reach this endpoint.
 *
 * OUTPUT SAFETY — why this file is wrapped the way it is:
 *   CKEditor's SimpleUploadAdapter sets `xhr.responseType = 'json'`, so the
 *   browser parses the response body itself. If that body isn't *valid*
 *   JSON — for any reason, even a single stray PHP notice/warning printed
 *   before our intended output, or accidental whitespace before "<?php" in
 *   any included file — `xhr.response` silently becomes `null` and the
 *   adapter shows a generic "Couldn't upload file: X" with zero detail
 *   about the real cause, regardless of HTTP status code. This is exactly
 *   the symptom that motivated the hardening below: an output buffer to
 *   swallow any accidental stray output, plus a global error/exception/
 *   fatal-error trap so this endpoint can *never* emit anything except
 *   clean, valid JSON — no matter what happens inside PHP, on this or any
 *   shared-hosting environment with unpredictable error-display settings.
 */

// Never let PHP print human-readable warnings/notices into the response
// body — capture them for our own error message instead. Errors are still
// logged server-side via the default error_log, just not echoed here.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Swallow ANY stray output (notices, warnings, whitespace, BOM from an
// included file, etc.) that happens before we're ready to send our own
// clean JSON body. Everything captured here is discarded, never sent.
ob_start();

header('Content-Type: application/json');

/** Send a JSON error response and stop, discarding any buffered output. */
function ckeditor_upload_fail(int $status, string $message, bool $simpleUploadShape = true): void {
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    if ($simpleUploadShape) {
        echo json_encode(['error' => ['message' => $message]]);
    } else {
        echo json_encode(['success' => false, 'error' => $message]);
    }
    exit;
}

// Catch PHP fatal errors (e.g. an uncaught TypeError deep in an included
// file) that would otherwise print an HTML error page instead of JSON.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode(['error' => ['message' => 'Server error during upload.']]);
    }
});

try {
    require_once __DIR__ . '/auth.php';
} catch (\Throwable $e) {
    ckeditor_upload_fail(500, 'Server error loading upload handler.');
}

// This is an AJAX endpoint — never redirect on auth failure, return JSON.
if (!isAdmin()) {
    ckeditor_upload_fail(401, 'Not authorized.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ckeditor_upload_fail(405, 'Method not allowed.');
}

$type   = $_GET['type'] ?? 'image';
$errors = [];

try {
    if ($type === 'video') {
        $path = handleVideoUpload('video', $errors);
        if ($path === null || !empty($errors)) {
            ckeditor_upload_fail(400, $errors[0] ?? 'Video upload failed.', false);
        }
        $url = imageUrl($path, 'lesson');
        if (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => true, 'url' => $url]);
        exit;
    }

    // Default: image upload (CKEditor's native SimpleUploadAdapter contract)
    $path = handleImageUpload('upload', 'lessons', $errors);
    if ($path === null || !empty($errors)) {
        ckeditor_upload_fail(400, $errors[0] ?? 'Image upload failed.');
    }
    $url = imageUrl($path, 'lesson');
    if (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['url' => $url]);
    exit;
} catch (\Throwable $e) {
    ckeditor_upload_fail(500, 'Server error during upload.', $type !== 'video');
}
