<?php
// Same reasoning as cron/email-cron.php: this endpoint is also unreachable
// via cron-job.org on InfinityFree. It only *queues* emails (expiry/renewal
// notices) — it doesn't send them itself — so the Smart Internal Queue
// Runner (includes/email/EmailQueueRunner.php) still drains whatever this
// script queues, opportunistically, from normal page traffic. This flag
// just prevents this request from *also* triggering an opportunistic run
// on top of its own work.
define('DISABLE_INTERNAL_EMAIL_RUNNER', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/subscriptions.php';

// Block unauthorized access
if (!hash_equals(CRON_SECRET, $_GET['key'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

$expiredCount  = checkAndExpireSubscriptions();
$reminderCount = sendExpiryReminders();

echo date('Y-m-d H:i:s') .
     " — Expired: {$expiredCount}, Reminders sent: {$reminderCount}";