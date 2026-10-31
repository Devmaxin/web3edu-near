<?php
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$db = getDB();

$stmt = $db->prepare("SELECT id, type, title, body, link, is_read, created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$unreadStmt->execute([$userId]);

echo json_encode([
    'success' => true,
    'unread_count' => (int)$unreadStmt->fetchColumn(),
    'notifications' => array_map(fn($n) => [
        'id' => (int)$n['id'],
        'title' => $n['title'],
        'body' => $n['body'],
        'link' => $n['link'] ?: (SITE_URL . '/notifications.php'),
        'is_read' => (bool)$n['is_read'],
        'time_ago' => timeAgo($n['created_at']),
    ], $notifications),
]);
