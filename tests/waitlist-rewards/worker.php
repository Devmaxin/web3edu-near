<?php
// Concurrency worker. Spawned by run.php; never run by hand.
//   php worker.php <mode> <arg1> <arg2> <startFile> [<arg3> <arg4>]
// Waits for <startFile> to exist (so every worker fires at the same instant), performs ONE action, prints JSON.
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/waitlist_rewards.php';
if (stripos(DB_NAME, 'test') === false) { echo '{"status":"refused"}'; exit(1); }
[$mode, $a1, $a2, $start] = [$argv[1], $argv[2], $argv[3], $argv[4]];
$deadline = microtime(true) + 20;
while (!file_exists($start) && microtime(true) < $deadline) usleep(500);
switch ($mode) {
    case 'credit':     $r = wlrCreditForUser((int)$a1, $a2); break;                          // userId, via
    case 'register':   $r = wlrOnRegistration((int)$a1, $a2); break;                         // userId, via
    case 'credit_now': $r = wlrAdminCreditNow((int)$a1, (int)$a2); break;                    // rewardId, adminId
    case 'correct':    $r = wlrApplyCorrection((int)$a1, (int)$a2, (int)$argv[5], 'concurrent test', (int)$argv[6]); break; // rewardId,newCount,expected,adminId
    default: $r = ['status' => 'bad_mode'];
}
echo json_encode($r);
