<?php
/**
 * Web3edu — AJAX: AI Study Card
 * POST /api/ai_study_card.php  { lesson_id: int, lesson_type: 'learn'|'guide' }
 *
 * Completely FREE feature — unlimited for every logged-in user,
 * regardless of membership_type. NOT subject to the AI Tutor rate limit.
 *
 * Returns JSON { success, bullets: string[], lesson_title }
 */

// Buffer everything from this point on so a stray PHP notice/warning never
// gets concatenated in front of the JSON body (same protection api/ai_chat.php
// already relies on). respond() below always clears this buffer first.
ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ai/AIService.php';

header('Content-Type: application/json');

/** Send a clean JSON response and stop — discards any buffered stray output first. */
function respond(array $payload, int $httpCode = 200): never
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($httpCode);
    echo json_encode($payload);
    exit;
}

try {

    if (!isLoggedIn()) {
        respond(['success' => false, 'error' => 'Please sign in to generate a study card.'], 401);
    }

    $lessonId   = intval($_POST['lesson_id'] ?? 0);
    $lessonType = in_array($_POST['lesson_type'] ?? 'learn', ['learn', 'guide'], true) ? $_POST['lesson_type'] : 'learn';

    if (!$lessonId) {
        respond(['success' => false, 'error' => 'Invalid lesson.']);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id, title, access_level, preview_content, full_content FROM lessons WHERE id = ?');
    $stmt->execute([$lessonId]);
    $lesson = $stmt->fetch();

    if (!$lesson) {
        respond(['success' => false, 'error' => 'Lesson not found.']);
    }

    // Respect the same access control as the lesson page itself.
    if ($lesson['access_level'] === 'premium' && !isPremium()) {
        respond(['success' => false, 'error' => 'Upgrade to Premium to unlock this lesson and its study card.']);
    }

    $content = trim($lesson['full_content'] ?: $lesson['preview_content'] ?: '');
    if ($content === '') {
        respond(['success' => false, 'error' => 'This lesson has no content to summarize yet.']);
    }

    $userId    = (int)$_SESSION['user_id'];
    $isPremium = isPremium();

    // Release the DB connection for the duration of the outbound AI
    // request — see closeDB()'s doc comment in includes/config.php for
    // why this matters specifically on InfinityFree. AIService reopens
    // a connection itself (via getDB()) when it logs the result a few
    // lines from now, so nothing here loses access to the DB — it's
    // just not held open, unused, while we wait on Groq.
    closeDB();

    $result = AIService::generateStudyCard($lesson['title'], $content, $userId, $isPremium ? 'premium' : 'free');

    if (!$result['success']) {
        // The real reason (bad API key, decommissioned model, a prompt edit that
        // broke the provider's JSON-mode requirement, network block, etc.) is
        // always logged server-side — never discarded. It's also always
        // logged to ai_usage_log (AIService::logUsage), visible under
        // Admin -> AI Management -> Recent Logs.
        //
        // The user-facing message stays short and friendly, but per product
        // requirement this endpoint must not just swallow the real error —
        // so a `debug` field carries the actual provider/exception message
        // alongside it. The frontend renders it as a small muted line under
        // the friendly message (never in place of it), so anyone looking at
        // the card — not just someone with error_log access — can see what
        // actually broke and report it usefully.
        error_log('[AI Study Card] Groq/provider request failed: ' . $result['error']);
        respond([
            'success' => false,
            'error'   => 'Could not generate a study card right now. Please try again.',
            'debug'   => mb_substr((string)$result['error'], 0, 300),
        ]);
    }

    Analytics::track('ai_study_card_generated', $userId, ['lesson_id' => $lessonId, 'lesson_type' => $lessonType, 'user_type' => $isPremium ? 'premium' : 'free']);

    respond([
        'success'      => true,
        'bullets'      => $result['bullets'],
        'lesson_title' => $lesson['title'],
    ]);

} catch (\Throwable $e) {
    // Last-resort safety net: any uncaught error anywhere above (a typo'd
    // class, a missing table, a PHP version mismatch) previously produced a
    // blank/non-JSON response — the frontend's fetch(...).then(r => r.json())
    // would reject and just show "Network error", with zero trace of what
    // actually broke. Now it's logged with full detail and still answered
    // with valid JSON.
    error_log('[AI Study Card] Uncaught error in api/ai_study_card.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond([
        'success' => false,
        'error'   => 'Could not generate a study card right now. Please try again.',
        'debug'   => mb_substr($e->getMessage(), 0, 300),
    ], 500);
}
