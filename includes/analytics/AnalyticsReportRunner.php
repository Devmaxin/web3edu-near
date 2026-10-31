<?php
/**
 * ============================================================================
 * Smart Analytics Report Runner (same InfinityFree external-cron workaround
 * as includes/email/EmailQueueRunner.php and
 * includes/notifications/InactivityRunner.php — see EmailQueueRunner.php
 * for the full explanation of why a real crontab can't reach cron/ on this
 * host).
 * ============================================================================
 *
 * WHAT THIS DOES
 * ---------------
 * maybeRunAnalyticsReportsInBackground() is registered as a third shutdown
 * function from includes/config.php. Unlike the email queue drain (which
 * should run frequently) or the inactivity scan (every 15 min), these
 * reports are calendar events — "once per Lagos day" and "once per ISO
 * week" — so instead of a time-based cooldown this claims a slot keyed to
 * *which* day/week it is: the claim only succeeds the first time a request
 * happens to land after that day/week has started, and every request
 * after that for the same day/week finds the slot already claimed and
 * does nothing. Cheap on every request either way (one indexed UPDATE).
 *
 * If the site gets zero traffic on a given day, that day's report simply
 * doesn't fire — same honest limitation as every other opportunistic
 * runner here. See docs/ANALYTICS.md "Cron / Scheduling" for the real-cron
 * alternative once this project is off InfinityFree.
 * ============================================================================
 */
require_once __DIR__ . '/AnalyticsReportService.php';

function analyticsReportRunnerEnabled(): bool
{
    if (!defined('ANALYTICS_REPORT_RUNNER_ENABLED') || !ANALYTICS_REPORT_RUNNER_ENABLED) return false;
    if (defined('DISABLE_INTERNAL_EMAIL_RUNNER') && DISABLE_INTERNAL_EMAIL_RUNNER) return false; // same CLI/cron-endpoint guard as the other runners
    return true;
}

/** Same atomic site_settings row-claim technique as claimEmailRunnerSlot()/claimInactivityRunnerSlot(), but keyed by a calendar value instead of a timestamp. */
function claimAnalyticsReportSlot(string $settingKey, string $currentPeriodValue): bool
{
    try {
        $db = getDB();
        $db->prepare("
            INSERT IGNORE INTO site_settings (setting_key, setting_val, label, description)
            VALUES (?, '', ?, 'Internal use by the Smart Analytics Report Runner — do not edit.')
        ")->execute([$settingKey, $settingKey]);

        $stmt = $db->prepare("
            UPDATE site_settings SET setting_val = ?
            WHERE setting_key = ? AND setting_val != ?
        ");
        $stmt->execute([$currentPeriodValue, $settingKey, $currentPeriodValue]);
        return $stmt->rowCount() > 0;
    } catch (\Throwable $e) {
        error_log('[AnalyticsReportRunner] Could not claim run slot: ' . $e->getMessage());
        return false;
    }
}

/** Registered via register_shutdown_function() — never call directly from page code. */
function maybeRunAnalyticsReportsInBackground(): void
{
    if (!analyticsReportRunnerEnabled()) return;
    if (php_sapi_name() === 'cli') return;

    try {
        $lagosNow = new DateTime('now', new DateTimeZone('Africa/Lagos'));
        $today = $lagosNow->format('Y-m-d');
        $isoWeek = $lagosNow->format('o-\WW'); // e.g. 2026-W35 — ISO year+week, stable across the week

        if (claimAnalyticsReportSlot('analytics_daily_report_last_date', $today)) {
            if (function_exists('fastcgi_finish_request')) {
                if (ob_get_level() > 0) { @ob_end_flush(); }
                flush();
                fastcgi_finish_request();
            }
            generateDailyAnalyticsReport();
        }

        // Weekly report: only ever fires on a Monday (start of the ISO
        // week) even though the slot is checked on every request, so a
        // Wednesday deploy doesn't retroactively trigger "this week"'s
        // report a few days late.
        if ($lagosNow->format('N') == 1 && claimAnalyticsReportSlot('analytics_weekly_report_last_week', $isoWeek)) {
            generateWeeklyAnalyticsReport();
        }
    } catch (\Throwable $e) {
        error_log('[AnalyticsReportRunner] Background run failed: ' . $e->getMessage());
    }
}
