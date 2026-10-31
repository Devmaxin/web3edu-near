<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/gamification.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in to bookmark content.']);
    exit;
}

$type = $_POST['type'] ?? '';
$id   = intval($_POST['id'] ?? 0);
$validTypes = ['lesson', 'guide_path', 'airdrop', 'blog_post', 'bounty', 'project', 'resource', 'opportunity'];

if (!in_array($type, $validTypes, true) || !$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid bookmark target.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$db = getDB();

$stmt = $db->prepare("SELECT id FROM user_bookmarks WHERE user_id=? AND bookmarkable_type=? AND bookmarkable_id=?");
$stmt->execute([$userId, $type, $id]);
$existing = $stmt->fetch();

if ($existing) {
    $db->prepare("DELETE FROM user_bookmarks WHERE id=?")->execute([$existing['id']]);
    echo json_encode(['success' => true, 'bookmarked' => false]);
} else {
    $db->prepare("INSERT INTO user_bookmarks (user_id, bookmarkable_type, bookmarkable_id) VALUES (?,?,?)")->execute([$userId, $type, $id]);
    // reference_type is namespaced per bookmark type so the same numeric id
    // across different content types (e.g. lesson #5 and project #5) can't
    // collide in awardXp()'s per-(action,type,id) dedupe check.
    awardXp($userId, 'bookmark_created', 'bookmark_' . $type, $id);
    Analytics::track('content_bookmarked', $userId, ['content_type' => $type, 'content_id' => $id]);
    echo json_encode(['success' => true, 'bookmarked' => true]);
}
