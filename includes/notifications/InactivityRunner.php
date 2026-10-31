<?php
/**
 * ============================================================================
 * Smart Inactivity Reminder Runner  (same InfinityFree external-cron
 * workaround as includes/email/EmailQueueRunner.php — see that file for the
 * full explanation of why this can't just be a real crontab hitting
 * cron/email-cron.php on this host).
 * ============================================================================
 *
 * WHAT THIS DOES
 * ---------------
 * maybeRunInactivityCheckInBackground() is registered as a second shutdown
 * function from includes/config.php. On (almost) every request it does
 * nothing but one cheap lock check. Only once every
 * INACTIVITY_RUNNER_COOLDOWN seconds does it actually scan for users who
 * just crossed a comeback_rules threshold and queue their reminder email —
 * this never runs "live" in front of a real visitor's request.
 *
 * DETECTION
 * ---------
 * For each active comeback_rules row, this looks for users whose relevant
 * activity date (last_activity_date or last_login_at, per that rule's
 * `basis`) is EXACTLY `days_threshold` days ago — not "at least", so each
 * user is only ever a detection match once per inactivity episode, the
 * moment they cross that specific threshold. inactivity_reminder_log's
 * UNIQUE(user_id, rule_id, basis_date) is still checked as a hard backstop
 * against ever double-sending, but the exact-date query is what keeps
 * every opportunistic run cheap (a handful of matching rows, not a full
 * table scan of "everyone who's ever been away 14+ days").
 *
 * Respects: rule.is_active, the admin-wide event toggle (via
 * isNotificationEmailEnabled() — checked automatically by
 * deliverEmailTemplate() itself), the per-user opt-out
 * (wantsInactivityReminders()), and rule.template_key being set (some
 * rules can be reward-only with no email).
 * ============================================================================
 */

// includes/config.php requires this file globally (unlike most pages, which
// require gamification.php themselves per-page) so that
// maybeRunInactivityCheckInBackground() — a shutdown function that can fire
// on ANY request — always has wantsInactivityReminders() etc. available,
// even on pages that never otherwise touch gamification. require_once
// makes this a no-op on pages that already load it themselves.
require_once __DIR__ . '/../gamification.php';

function inactivityRunnerEnabled(): bool
{
    if (!defined('INACTIVITY_RUNNER_ENABLED') || !INACTIVITY_RUNNER_ENABLED) return false;
    if (defined('DISABLE_INTERNAL_EMAIL_RUNNER') && DISABLE_INTERNAL_EMAIL_RUNNER) return false; // same CLI/cron-endpoint guard as the email runner
    return true;
}

/** Same atomic site_settings row-claim technique as claimEmailRunnerSlot(), under its own key. */
function claimInactivityRunnerSlot(int $cooldownSeconds): bool
{
    try {
        $db = getDB();
        $db->prepare("
            INSERT IGNORE INTO site_settings (setting_key, setting_val, label, description)
            VALUES ('inactivity_runner_last_run', '0', 'Inactivity runner last run', 'Internal use by the Smart Inactivity Reminder Runner — do not edit.')
        ")->execute();

        $now = time();
        $stmt = $db->prepare("
            UPDATE site_settings
            SET setting_val = ?
            WHERE setting_key = 'inactivity_runner_last_run'
              AND (? - CAST(setting_val AS UNSIGNED)) >= ?
        ");
        $stmt->execute([(string)$now, $now, $cooldownSeconds]);
        return $stmt->rowCount() > 0;
    } catch (\Throwable $e) {
        error_log('[InactivityRunner] Could not claim run slot: ' . $e->getMessage());
        return false;
    }
}

/**
 * Processes up to $batchSize candidate users total across all active
 * comeback_rules. Returns a small summary array for logging/testing.
 */
function runInactivityCheck(int $batchSize = 15): array
{
    $db = getDB();
    $sent = 0;
    $skipped = 0;

    $rules = $db->query("SELECT * FROM comeback_rules WHERE is_active = 1 AND template_key IS NOT NULL ORDER BY days_threshold ASC")->fetchAll();

    foreach ($rules as $rule) {
        if ($sent + $skipped >= $batchSize) break;

        $dateColumn = $rule['basis'] === 'login' ? 'last_login_at' : 'last_activity_date';
        // last_login_at is a TIMESTAMP, last_activity_date is a DATE — normalize both to a DATE for the threshold match.
        $cutoff = date('Y-m-d', strtotime('-' . (int)$rule['days_threshold'] . ' days'));

        $candidates = $db->prepare("
            SELECT u.id, u.username, u.email, u.total_xp, DATE(u.$dateColumn) AS basis_date
            FROM users u
            WHERE u.deleted_at IS NULL
              AND u.$dateColumn IS NOT NULL
              AND DATE(u.$dateColumn) = ?
              AND NOT EXISTS (
                  SELECT 1 FROM inactivity_reminder_log l
                  WHERE l.user_id = u.id AND l.rule_id = ? AND l.basis_date = DATE(u.$dateColumn)
              )
            LIMIT ?
        ");
        $remaining = max(0, $batchSize - $sent - $skipped);
        if ($remaining === 0) break;
        $candidates->bindValue(1, $cutoff);
        $candidates->bindValue(2, (int)$rule['id'], PDO::PARAM_INT);
        $candidates->bindValue(3, $remaining, PDO::PARAM_INT);
        $candidates->execute();

        foreach ($candidates->fetchAll() as $u) {
            if (empty($u['email']) || !wantsInactivityReminders((int)$u['id'])) {
                $skipped++;
                continue;
            }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM user_lesson_progress WHERE user_id=?");
            $countStmt->execute([$u['id']]);
            $completedLessons = (int)$countStmt->fetchColumn();

            $freezeLine = ((int)$rule['xp_reward'] > 0 && (int)$rule['freeze_reward'] > 0)
                ? " and +{$rule['freeze_reward']} streak freeze" . ((int)$rule['freeze_reward'] !== 1 ? 's' : '')
                : '';

            $vars = [
                'username'          => $u['username'] ?: $u['email'],
                'total_xp'          => (int)$u['total_xp'],
                'completed_lessons' => $completedLessons,
                'xp_reward'         => (int)$rule['xp_reward'],
                'freeze_line'       => $freezeLine,
                'site_url'          => SITE_URL,
            ];

            // Log first (INSERT IGNORE — the unique key is our real dedupe guard),
            // then only send if this run actually won the race for this row.
            $logIns = $db->prepare("INSERT IGNORE INTO inactivity_reminder_log (user_id, rule_id, basis_date) VALUES (?,?,?)");
            $logIns->execute([$u['id'], $rule['id'], $u['basis_date']]);
            if ($logIns->rowCount() === 0) { $skipped++; continue; } // another run already claimed this one

            deliverEmailTemplate($rule['template_key'], $u['email'], $vars);
            $sent++;
        }
    }

    return ['sent' => $sent, 'skipped' => $skipped];
}

/** Registered via register_shutdown_function() — never call directly from page code. */
function maybeRunInactivityCheckInBackground(): void
{
    if (!inactivityRunnerEnabled()) return;
    if (php_sapi_name() === 'cli') return;

    $cooldown  = defined('INACTIVITY_RUNNER_COOLDOWN') ? INACTIVITY_RUNNER_COOLDOWN : 900;
    $batchSize = defined('INACTIVITY_RUNNER_BATCH_SIZE') ? INACTIVITY_RUNNER_BATCH_SIZE : 15;

    if (!claimInactivityRunnerSlot($cooldown)) return;

    if (function_exists('fastcgi_finish_request')) {
        if (ob_get_level() > 0) { @ob_end_flush(); }
        flush();
        fastcgi_finish_request();
    }

    try {
        runInactivityCheck($batchSize);
    } catch (\Throwable $e) {
        error_log('[InactivityRunner] Background batch failed: ' . $e->getMessage());
    }
}
