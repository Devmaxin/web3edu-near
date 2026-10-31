<?php
// Same reasoning as cron/email-cron.php and cron/subscription-cron.php: this
// endpoint is also unreachable via cron-job.org on InfinityFree. It only
// *queues* the reminder email — the Smart Internal Queue Runner still drains
// it opportunistically from normal page traffic. This flag just prevents
// this request from *also* triggering an opportunistic run on top of its
// own work.
define('DISABLE_INTERNAL_EMAIL_RUNNER', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/bounties.php';

// Block unauthorized access
if (!hash_equals(CRON_SECRET, $_GET['key'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

$db = getDB();

// Bounties closing within 24 hours that haven't had a reminder sent yet.
$stmt = $db->query("
    SELECT b.*, bh.user_id AS host_user_id
    FROM bounties b
    LEFT JOIN bounty_hosts bh ON bh.id = b.host_id
    WHERE b.status = 'published'
      AND b.deleted_at IS NULL
      AND b.expiry_reminder_sent = 0
      AND b.deadline IS NOT NULL
      AND b.deadline <= DATE_ADD(NOW(), INTERVAL 1 DAY)
      AND b.deadline > NOW()
");
$reminders = 0;
foreach ($stmt->fetchAll() as $b) {
    $ownerId = $b['host_user_id'] ?: $b['submitted_by'];
    if ($ownerId) {
        emailUser((int)$ownerId, 'bounty_expiring_host', ['title' => $b['title']]);
        notifyUser((int)$ownerId, 'bounty_expiring', 'Bounty closes soon', "\"{$b['title']}\" closes within 24 hours.", SITE_URL . '/bounty-host-dashboard.php');
        $reminders++;
    }
    $db->prepare("UPDATE bounties SET expiry_reminder_sent = 1 WHERE id = ?")->execute([$b['id']]);
}

// Auto-archive published bounties well past their deadline (7+ days) that
// never had winners announced, so they stop cluttering the live listing.
$archived = $db->exec("
    UPDATE bounties SET status = 'archived'
    WHERE status = 'published' AND deadline IS NOT NULL
      AND deadline < DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL
");

echo date('Y-m-d H:i:s') .
     " — Expiry reminders sent: {$reminders}, Auto-archived: {$archived}";
