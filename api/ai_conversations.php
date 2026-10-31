<?php
/**
 * Web3edu — AJAX: AI Conversation History
 * GET /api/ai_conversations.php                      -> list user's conversations (sidebar/drawer)
 * GET /api/ai_conversations.php?id=123                -> messages for one conversation
 * POST /api/ai_conversations.php { action: 'delete', conversation_id }
 *
 * Free users: history list capped (see aiListConversations()).
 * Premium users: unlimited history.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ai_helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$isPremium = isPremium();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $convId = intval($_POST['conversation_id'] ?? 0);
        $conversation = aiGetConversation($convId, $userId);
        if (!$conversation) {
            echo json_encode(['success' => false, 'error' => 'Conversation not found.']);
            exit;
        }
        $db = getDB();
        $db->prepare('DELETE FROM ai_conversations WHERE id = ? AND user_id = ?')->execute([$convId, $userId]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

$convId = intval($_GET['id'] ?? 0);

if ($convId > 0) {
    $conversation = aiGetConversation($convId, $userId);
    if (!$conversation) {
        echo json_encode(['success' => false, 'error' => 'Conversation not found.']);
        exit;
    }
    $messages = aiGetMessages($convId);
    echo json_encode([
        'success'      => true,
        'conversation' => ['id' => (int)$conversation['id'], 'title' => $conversation['title']],
        'messages'     => array_map(fn($m) => ['role' => $m['role'], 'content' => $m['content']], $messages),
    ]);
    exit;
}

$conversations = aiListConversations($userId, $isPremium);
echo json_encode([
    'success'       => true,
    'conversations' => array_map(fn($c) => [
        'id'         => (int)$c['id'],
        'title'      => $c['title'],
        'updated_at' => $c['updated_at'],
    ], $conversations),
    'unlimited_history' => $isPremium,
]);
