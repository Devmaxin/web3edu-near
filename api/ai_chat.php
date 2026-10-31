<?php
/**
 * Web3edu — AJAX: AI Tutor Chat
 * POST /api/ai_chat.php  { message: string, conversation_id?: int, source?: 'page'|'widget' }
 *
 * Used by BOTH the floating AI popup and the dedicated AI Tutor page —
 * one backend, one rate limit, one history store.
 *
 * Returns JSON:
 *   success, reply, conversation_id, remaining ('unlimited'|int), limit_reached
 */

// Buffer everything from this point on. Any stray PHP notice/warning/
// deprecation text that leaks before our JSON (very easy to trigger on
// mismatched PHP versions between dev and shared hosting) would otherwise
// get concatenated in front of the JSON body, breaking response.json() on
// the frontend and making the AI Tutor look like it's silently doing
// nothing. respond() below always clears this buffer before writing the
// real JSON, so the response body is guaranteed to be valid JSON no matter
// what else printed along the way.
ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ai_helpers.php';

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
        respond(['success' => false, 'error' => 'Please sign in to use the AI Tutor.'], 401);
    }

    $userId  = (int)$_SESSION['user_id'];
    $message = trim((string)($_POST['message'] ?? ''));
    $convId  = intval($_POST['conversation_id'] ?? 0);
    $source  = in_array($_POST['source'] ?? 'page', ['page', 'widget'], true) ? ($_POST['source'] ?? 'page') : 'page';

    // Optional "what is the user currently looking at" context — populated by
    // window.__WEB3EDU_LESSON_CONTEXT__ on lesson/guide pages. Purely additive:
    // absent on every other page, and never trusted for access control.
    $currentLessonId   = intval($_POST['lesson_id'] ?? 0);
    $currentLessonType = in_array($_POST['lesson_type'] ?? '', ['learn', 'guide'], true) ? $_POST['lesson_type'] : null;
    $currentContext    = $currentLessonId > 0 ? ['lesson_id' => $currentLessonId, 'lesson_type' => $currentLessonType] : null;

    if ($message === '') {
        respond(['success' => false, 'error' => 'Please enter a question.']);
    }
    if (mb_strlen($message) > 2000) {
        respond(['success' => false, 'error' => 'That question is too long. Please shorten it.']);
    }

    // Re-verify membership from DB (never trust session alone for gating)
    $db = getDB();
    $isPremium = userHasActivePremium($userId);

    // ── Rate limit (free vs premium) — Study Cards are NEVER affected by this ──
    $rate = aiChatRateLimitStatus($userId, $isPremium);
    if (!$rate['allowed']) {
        Analytics::track('ai_free_limit_reached', $userId, ['feature' => 'chat', 'used' => $rate['used'], 'limit' => $rate['limit']]);
        respond([
            'success'       => false,
            'limit_reached' => true,
            'used'          => $rate['used'],
            'limit'         => $rate['limit'],
            'error'         => 'Daily free question used. Upgrade to Premium for unlimited AI Tutor access.',
        ]);
    }

    // ── Conversation: continue existing or start new ───────────────────────────
    if ($convId > 0) {
        $conversation = aiGetConversation($convId, $userId);
        if (!$conversation) {
            respond(['success' => false, 'error' => 'Conversation not found.']);
        }
    } else {
        $convId = aiCreateConversation($userId, aiDeriveTitle($message), $source);
    }

    // Build short history (last 10 turns) for context
    $historyRows = aiGetMessages($convId);
    $history     = array_map(fn($r) => ['role' => $r['role'], 'content' => $r['content']], array_slice($historyRows, -10));

    aiAddMessage($convId, 'user', $message);

    // See closeDB()'s doc comment in includes/config.php — releases the
    // MySQL connection for the duration of the outbound Groq request
    // instead of holding it open and idle for however long that takes.
    closeDB();

    $result = AIService::askTutor($history, $message, $userId, $isPremium ? 'premium' : 'free', $currentContext);

    if (!$result['success']) {
        // The real reason (bad API key, decommissioned model, network block,
        // Groq-side error, etc.) is always logged server-side and saved to
        // ai_usage_log by AIService — never discarded. What we show the user
        // stays generic (provider internals shouldn't leak into the chat UI),
        // but "generic" no longer means "invisible": check PHP's error_log
        // or Admin -> AI Management -> Recent Logs for the exact message.
        error_log('[AI Tutor] Groq/provider request failed: ' . $result['error']);
        respond([
            'success'         => false,
            'conversation_id' => $convId,
            'error'           => "The AI Tutor is temporarily unavailable. Please try again shortly.",
        ]);
    }

    aiAddMessage($convId, 'assistant', $result['content']);

    Analytics::track('ai_chat_used', $userId, ['user_type' => $isPremium ? 'premium' : 'free', 'source' => $source]);

    $newRate = aiChatRateLimitStatus($userId, $isPremium);

    respond([
        'success'         => true,
        'reply'           => $result['content'],
        'conversation_id' => $convId,
        'remaining'       => $isPremium ? 'unlimited' : max(0, $newRate['limit'] - $newRate['used']),
        'limit_reached'   => !$isPremium && !$newRate['allowed'],
    ]);

} catch (\Throwable $e) {
    // Last-resort safety net: any uncaught error anywhere above (a typo'd
    // class, a missing table, a PHP version mismatch) previously produced a
    // blank 500 response with no JSON body -- the frontend's
    // fetch(...).then(r => r.json()) would reject, land in .catch(), and
    // just show "Network error", with zero trace of what actually broke.
    // Now it's logged with full detail and still answered with valid JSON.
    error_log('[AI Tutor] Uncaught error in api/ai_chat.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond([
        'success' => false,
        'error'   => 'The AI Tutor is temporarily unavailable. Please try again shortly.',
    ], 500);
}
