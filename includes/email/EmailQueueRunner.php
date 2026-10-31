<?php
/**
 * ============================================================================
 * Smart Internal Queue Runner  (InfinityFree external-cron workaround)
 * ============================================================================
 *
 * WHY THIS EXISTS
 * ----------------
 * InfinityFree (free-tier shared hosting) sits behind an anti-bot layer that
 * intercepts requests that don't look like a real browser — including
 * cron-job.org hitting cron/email-cron.php. InfinityFree still answers with
 * HTTP 200, but the response body is InfinityFree's own JavaScript "prove
 * you're not a bot" challenge page (aes.js), not our PHP output. So the
 * external cron job "succeeds" (200 OK) while never actually reaching our
 * code — processEmailQueue() never runs, even though every other part of
 * the pipeline (deliverEmailTemplate(), the email_queue table, the Resend
 * provider, manual/cURL calls) works fine.
 *
 * There is no way to make cron-job.org's request look enough like a
 * browser to get past that challenge from the outside. So instead of
 * fighting InfinityFree's edge layer, we stop depending on an external
 * trigger entirely: real visitors loading real pages are never blocked by
 * that challenge (it only targets automated hits to cron/email-cron.php),
 * so we piggyback the queue drain onto normal page traffic.
 *
 * WHAT THIS FILE DOES
 * --------------------
 * maybeRunEmailQueueInBackground() is registered as a shutdown function
 * from includes/config.php, so it runs at the very end of every request —
 * after the page's own logic has already finished. On (almost) every
 * request it does nothing but one cheap check. Only once every
 * INTERNAL_EMAIL_RUNNER_COOLDOWN seconds does it actually process a small
 * batch of the email_queue.
 *
 * Safety rules enforced here (per the requirements this was built against):
 *   - never runs on every request        → cooldown check below
 *   - uses a lock/cooldown               → claimEmailRunnerSlot()
 *   - runs at most once every 60s        → INTERNAL_EMAIL_RUNNER_COOLDOWN
 *   - processes a small batch only       → INTERNAL_EMAIL_RUNNER_BATCH_SIZE
 *   - never noticeably slows page loads  → registered as a *shutdown*
 *     function (runs after the page has already rendered its own work),
 *     and if PHP-FPM's fastcgi_finish_request() is available we flush the
 *     response to the visitor's browser and close their connection
 *     *before* touching the queue, so the visitor never waits on it at
 *     all. InfinityFree's classic Apache/mod_php stack doesn't expose
 *     that function, so there we simply run the (tiny) batch inline —
 *     it's at most 5-10 emails, once every 60 seconds site-wide, so the
 *     added time on that one unlucky request is small and infrequent.
 *
 * HOW TO DISABLE THIS LATER (e.g. after moving to Hostinger)
 * ------------------------------------------------------------
 * Once you're on a host with real, working external cron:
 *   1. In includes/config.php, set INTERNAL_EMAIL_RUNNER_ENABLED to false.
 *   2. Point a real cron job at cron/email-cron.php?key=CRON_SECRET, same
 *      as before.
 * Nothing else changes — deliverEmailTemplate(), email_queue, and
 * processEmailQueue() are the same core system either way. You can also
 * run both at once if you want a safety net; they can't double-send (see
 * processEmailQueue()'s docblock in EmailService.php).
 * ============================================================================
 */

/** Whether the internal runner is allowed to fire at all right now. */
function emailRunnerEnabled(): bool
{
    if (!defined('INTERNAL_EMAIL_RUNNER_ENABLED') || !INTERNAL_EMAIL_RUNNER_ENABLED) {
        return false;
    }
    // cron/email-cron.php and cron/subscription-cron.php set this before
    // loading config.php, so a request to the real cron endpoint doesn't
    // also trigger an opportunistic run in the same breath.
    if (defined('DISABLE_INTERNAL_EMAIL_RUNNER') && DISABLE_INTERNAL_EMAIL_RUNNER) {
        return false;
    }
    return true;
}

/**
 * Atomically decides whether *this* request is the one that gets to run
 * the queue right now. Implemented as a single UPDATE ... WHERE against
 * the existing site_settings table (no new table needed) — MySQL runs
 * that UPDATE as one atomic operation, so if two requests hit this at
 * nearly the same instant, only one of their UPDATE statements can match
 * the WHERE clause and actually change the row; the other affects 0 rows.
 * That row-level atomicity is our lock — no file locks, no extra table.
 */
function claimEmailRunnerSlot(int $cooldownSeconds): bool
{
    try {
        $db = getDB();

        // Make sure the tracking row exists (first request ever / fresh
        // install). INSERT IGNORE is a no-op on every later request.
        $db->prepare("
            INSERT IGNORE INTO site_settings (setting_key, setting_val, label, description)
            VALUES ('email_runner_last_run', '0', 'Email runner last run', 'Internal use by the Smart Internal Queue Runner — do not edit.')
        ")->execute();

        $now = time();
        $stmt = $db->prepare("
            UPDATE site_settings
            SET setting_val = ?
            WHERE setting_key = 'email_runner_last_run'
              AND (? - CAST(setting_val AS UNSIGNED)) >= ?
        ");
        $stmt->execute([(string)$now, $now, $cooldownSeconds]);

        // rowCount() is 0 if another request already claimed this window,
        // or if the cooldown hasn't elapsed yet.
        return $stmt->rowCount() > 0;
    } catch (\Throwable $e) {
        // If site_settings is unreachable for some reason, fail *safe* by
        // not running — a missed opportunistic pass just means the next
        // page view tries again. This must never be allowed to break a
        // normal page request.
        error_log('[EmailRunner] Could not claim run slot: ' . $e->getMessage());
        return false;
    }
}

/**
 * Registered via register_shutdown_function() in includes/config.php — do
 * not call this directly from page code. Cheap no-op on almost every
 * request; only does real work once every INTERNAL_EMAIL_RUNNER_COOLDOWN
 * seconds.
 */
function maybeRunEmailQueueInBackground(): void
{
    if (!emailRunnerEnabled()) return;
    if (php_sapi_name() === 'cli') return; // e.g. one-off CLI scripts/maintenance

    $cooldown  = defined('INTERNAL_EMAIL_RUNNER_COOLDOWN') ? INTERNAL_EMAIL_RUNNER_COOLDOWN : 60;
    $batchSize = defined('INTERNAL_EMAIL_RUNNER_BATCH_SIZE') ? INTERNAL_EMAIL_RUNNER_BATCH_SIZE : 8;

    if (!claimEmailRunnerSlot($cooldown)) return;

    // If we're behind PHP-FPM, close out the HTTP response to the visitor
    // first so they see zero added delay — the queue drain then happens
    // "behind the scenes" after their page has already fully loaded.
    // InfinityFree's Apache/mod_php stack generally does NOT expose this
    // function, so on InfinityFree we fall through to running the small
    // batch inline instead.
    if (function_exists('fastcgi_finish_request')) {
        if (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
        fastcgi_finish_request();
    }

    try {
        processEmailQueue($batchSize);
    } catch (\Throwable $e) {
        error_log('[EmailRunner] Background batch failed: ' . $e->getMessage());
    }
}
