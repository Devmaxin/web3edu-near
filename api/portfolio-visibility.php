<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/projects.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in.']);
    exit;
}

$isPublic = ($_POST['is_public'] ?? '0') === '1';
$settings = setPortfolioVisibility((int)$_SESSION['user_id'], $isPublic);

echo json_encode(['success' => true, 'is_public' => (bool)$settings['is_public'], 'slug' => $settings['slug']]);
