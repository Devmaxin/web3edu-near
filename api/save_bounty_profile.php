<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/bounties.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in first.']);
    exit;
}
requireCsrfAjax();

$userId = (int)$_SESSION['user_id'];
$ok = saveBountyProfile($userId, $_POST);

if ($ok) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Display Name, Country, and Twitter/X are required.']);
}
