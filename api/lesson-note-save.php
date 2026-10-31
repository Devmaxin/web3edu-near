<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notes_highlights.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in.']);
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$lessonId = intval($_POST['lesson_id'] ?? 0);
$content  = (string)($_POST['content'] ?? '');

if (!$lessonId) {
    echo json_encode(['success' => false, 'error' => 'Invalid lesson.']);
    exit;
}

saveLessonNote($userId, $lessonId, $content);
echo json_encode(['success' => true, 'saved_at' => date('c')]);
