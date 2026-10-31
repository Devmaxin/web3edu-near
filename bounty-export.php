<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounty_auth.php';
requireLogin();

$bountyId = (int)($_GET['id'] ?? 0);
$bounty = $bountyId ? getBounty($bountyId) : null;
if (!$bounty) { http_response_code(404); exit('Bounty not found.'); }

$userId = (int)$_SESSION['user_id'];

if (!canExportSubmissions($userId, $bountyId)) { http_response_code(403); exit('Not authorized.'); }

$csv = exportBountyParticipantsCsv($bountyId);
$filename = 'bounty-' . $bounty['slug'] . '-participants.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $csv;
