<?php
/**
 * Real-cron endpoint for the Daily/Weekly Analytics Reports.
 *
 * Like every other file in cron/, this is currently UNREACHABLE by an
 * external scheduler (cron-job.org) on InfinityFree — see the docblock at
 * the top of includes/email/EmailQueueRunner.php for why. Until this
 * project moves to a host with real cron support, the reports are instead
 * generated opportunistically by includes/analytics/AnalyticsReportRunner.php,
 * piggybacking on normal site traffic.
 *
 * Once you ARE on a host with real cron (e.g. Hostinger):
 *   1. Set ANALYTICS_REPORT_RUNNER_ENABLED to false in includes/config.php
 *      (avoids double-sending — this script becomes the only trigger).
 *   2. Point a real crontab (or your host's cron UI) at:
 *        php /path/to/cron/process_analytics_reports.php
 *      recommended: once daily, a few minutes after midnight Africa/Lagos.
 *      This one run handles BOTH the daily report (every day) and the
 *      weekly report (only actually sends on Mondays) — no separate weekly
 *      cron entry needed.
 *   OR, if your host only offers URL-based cron (cron-job.org etc.), hit:
 *        https://yoursite.com/cron/process_analytics_reports.php?key=CRON_SECRET
 *      on the same schedule — this file supports both invocation styles.
 *
 * Nothing else changes — generateDailyAnalyticsReport()/
 * generateWeeklyAnalyticsReport() and the email_queue pipeline underneath
 * them are identical either way.
 */
define('DISABLE_INTERNAL_EMAIL_RUNNER', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/analytics/AnalyticsReportService.php';

$isCli = (php_sapi_name() === 'cli');

// Block unauthorized access — CLI invocations (a real crontab running this
// file directly) are inherently already server-side/trusted, so only the
// URL path needs the shared-secret check, exactly like cron/email-cron.php.
if (!$isCli) {
    if (!hash_equals(CRON_SECRET, $_GET['key'] ?? '')) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$lagosNow = new DateTime('now', new DateTimeZone('Africa/Lagos'));
$lines = [];

$daily = generateDailyAnalyticsReport();
$lines[] = date('Y-m-d H:i:s') . " — Daily Analytics Report sent: {$daily['new_users']} new users, {$daily['revenue']} revenue.";

// Weekly report only actually sends on Mondays — see the docblock above.
// force=1 in the query string (or a second CLI arg) bypasses the day
// check, for manually re-sending a missed week.
$forceWeekly = ($_GET['force_weekly'] ?? '') === '1' || in_array('--force-weekly', $argv ?? []);
if ($lagosNow->format('N') == 1 || $forceWeekly) {
    $weekly = generateWeeklyAnalyticsReport();
    $lines[] = date('Y-m-d H:i:s') . " — Weekly Analytics Report sent: {$weekly['new_users']} new users, {$weekly['revenue']} revenue, {$weekly['mrr']} MRR.";
} else {
    $lines[] = date('Y-m-d H:i:s') . ' — Weekly Analytics Report skipped (not Monday in Africa/Lagos). Pass ?force_weekly=1 to send anyway.';
}

echo implode("\n", $lines) . ($isCli ? "\n" : '');
