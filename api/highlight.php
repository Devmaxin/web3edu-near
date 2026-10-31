<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notes_highlights.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? 'add';

if ($action === 'add') {
    $lessonId = intval($_POST['lesson_id'] ?? 0);
    $text     = trim((string)($_POST['text'] ?? ''));
    $start    = isset($_POST['start']) ? intval($_POST['start']) : null;
    $end      = isset($_POST['end']) ? intval($_POST['end']) : null;
    $color    = in_array($_POST['color'] ?? 'yellow', ['yellow', 'green', 'blue', 'pink'], true) ? $_POST['color'] : 'yellow';

    if (!$lessonId || $text === '') {
        echo json_encode(['success' => false, 'error' => 'Nothing to highlight.']);
        exit;
    }
    $id = addLessonHighlight($userId, $lessonId, $text, $start, $end, $color);
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    $ok = deleteLessonHighlight($userId, $id);
    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
