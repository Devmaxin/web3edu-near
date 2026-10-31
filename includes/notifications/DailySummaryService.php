<?php
/**
 * Daily Summary Service — generates the "Today's Activity" digest for
 * admins and queues it through the existing email pipeline.
 *
 * This file only *generates and queues* the summary. It does not run on a
 * schedule — no cron file was added here, per the Phase 2 brief ("Cron
 * execution can come later"). Two ways to trigger it today:
 *   1. Manually, via the "Send Daily Summary Now" button on
 *      admin/notification-center.php (useful for testing the content/email
 *      before any cron exists).
 *   2. Later, a one-line call to generateDailySummary() from a new
 *      cron/daily-summary-cron.php, following the exact pattern already
 *      used by cron/bounty-cron.php and cron/subscription-cron.php.
 *
 * Reuses: getAdminNotificationRecipients() (NotificationService.php),
 * deliverEmailTemplate() + email_queue (EmailService.php), and the
 * 'daily_summary' template added in database/phase2-notification-center-migration.sql.
 * No new table, no new queue, no duplicate delivery mechanism.
 */

/**
 * Counts the last 24 hours of platform activity relevant to admins.
 * Kept as its own function (separate from the queuing step) so the exact
 * same numbers can be reused for an admin-facing preview/dashboard later
 * without re-running the query twice.
 */
function getDailySummaryStats(): array
{
    $db = getDB();

    $count = function (string $sql) use ($db): int {
        try {
            return (int)$db->query($sql)->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[DailySummary] Query failed: ' . $e->getMessage());
            return 0;
        }
    };

    return [
        'host_applications'          => $count("SELECT COUNT(*) FROM bounty_hosts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"),
        'new_submissions'            => $count("SELECT COUNT(*) FROM bounty_submissions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"),
        'payments_awaiting_review'   => $count("SELECT COUNT(*) FROM bounty_payments WHERE status = 'under_review' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"),
        'new_premium_users'         => $count("SELECT COUNT(*) FROM user_subscriptions WHERE status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"),
        'new_registrations'          => $count("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND deleted_at IS NULL"),
    ];
}

/**
 * Builds the summary and queues one email per admin recipient via the
 * existing email_queue/deliverEmailTemplate() pipeline — exactly like every
 * other admin-facing email, no separate send path. Also drops one
 * admin_notifications row so the digest shows up in the Admin Notification
 * Center itself, not just inboxes.
 *
 * Returns the stats used, so a caller (e.g. the manual "Send Now" button)
 * can show what was just sent without a second query.
 */
function generateDailySummary(): array
{
    $stats = getDailySummaryStats();
    $vars = array_merge($stats, [
        'summary_date' => date('F j, Y'),
        'site_url'     => SITE_URL,
    ]);

    foreach (getAdminNotificationRecipients() as $email) {
        deliverEmailTemplate('daily_summary', $email, $vars);
    }

    adminNotify(
        'daily_summary',
        'Daily Summary — ' . $vars['summary_date'],
        "{$stats['host_applications']} Host Applications · {$stats['new_submissions']} New Submissions · {$stats['payments_awaiting_review']} Payments Awaiting Review · {$stats['new_premium_users']} New Premium Users · {$stats['new_registrations']} New Registrations",
        SITE_URL . '/admin/notification-center.php'
    );

    return $stats;
}
