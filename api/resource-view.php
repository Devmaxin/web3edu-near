<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/resources.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in.']);
    exit;
}

$resourceId = intval($_POST['resource_id'] ?? 0);
if (!$resourceId) {
    echo json_encode(['success' => false, 'error' => 'Invalid resource.']);
    exit;
}

markResourceViewed((int)$_SESSION['user_id'], $resourceId);
echo json_encode(['success' => true]);
