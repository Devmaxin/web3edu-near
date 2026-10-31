<?php
// This endpoint is the "real external cron" path for draining the email
// queue. On InfinityFree, cron-job.org can never actually reach this file
// (InfinityFree's anti-bot layer intercepts it and returns a JS challenge
// instead of running this script) — that's why
// includes/email/EmailQueueRunner.php exists to drain the queue
// opportunistically from normal page loads instead. Once you move to a
// host with real cron support (Hostinger, etc.), this file starts working
// again as-is; just set INTERNAL_EMAIL_RUNNER_ENABLED to false in
// includes/config.php and point your host's cron at this URL.
//
// We still define this constant before loading config.php so that IF this
// endpoint is ever hit directly (manually, by a working host's cron, or
// during testing), it doesn't also trigger an opportunistic run in the
// same request — this script already does the full job itself.
define('DISABLE_INTERNAL_EMAIL_RUNNER', true);

require_once __DIR__ . '/../includes/config.php';

// Block unauthorized access
if (!hash_equals(CRON_SECRET, $_GET['key'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

$result = processEmailQueue(50);

echo date('Y-m-d H:i:s') .
     " — Sent: {$result['sent']}, Failed: {$result['failed']}";