<?php
/**
 * AI helper functions shared by api/ai_chat.php, api/ai_conversations.php,
 * and the AI Tutor / floating widget pages.
 *
 * Keeps rate-limit + conversation-history logic in one tested place
 * instead of duplicated across endpoints.
 */
require_once __DIR__ . '/ai/AIService.php';

/**
 * How many successful chat replies this user has received today.
 * Free-tier limit is enforced against this count.
 */
function aiChatsUsedToday(int $userId): int
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM ai_usage_log
        WHERE user_id = ? AND type = 'chat' AND success = 1 AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function aiFreeDailyChatLimit(): int
{
    $limit = (int)getSetting('ai_free_daily_chat_limit', '1');
    return $limit > 0 ? $limit : 1;
}

/**
 * Returns ['allowed'=>bool, 'used'=>int, 'limit'=>int|null] for the AI Tutor chat feature.
 * Premium users are always allowed (limit = null = unlimited).
 */
function aiChatRateLimitStatus(int $userId, bool $isPremiumUser): array
{
    if ($isPremiumUser) {
        return ['allowed' => true, 'used' => 0, 'limit' => null];
    }
    $limit = aiFreeDailyChatLimit();
    $used  = aiChatsUsedToday($userId);
    return ['allowed' => $used < $limit, 'used' => $used, 'limit' => $limit];
}

/** Create a new conversation row, return its id. */
function aiCreateConversation(int $userId, string $title, string $source = 'page'): int
{
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO ai_conversations (user_id, title, source) VALUES (?,?,?)');
    $stmt->execute([$userId, mb_substr($title, 0, 250), $source]);
    return (int)$db->lastInsertId();
}

/** Append a message to a conversation and bump its updated_at. */
function aiAddMessage(int $conversationId, string $role, string $content): void
{
    $db = getDB();
    $db->prepare('INSERT INTO ai_messages (conversation_id, role, content) VALUES (?,?,?)')
       ->execute([$conversationId, $role, $content]);
    $db->prepare('UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?')
       ->execute([$conversationId]);
}

/** Fetch a conversation, verifying ownership. */
function aiGetConversation(int $conversationId, int $userId): ?array
{
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM ai_conversations WHERE id = ? AND user_id = ?');
    $stmt->execute([$conversationId, $userId]);
    return $stmt->fetch() ?: null;
}

/** Messages for a conversation, oldest first. */
function aiGetMessages(int $conversationId): array
{
    $db = getDB();
    $stmt = $db->prepare('SELECT role, content, created_at FROM ai_messages WHERE conversation_id = ? ORDER BY id ASC');
    $stmt->execute([$conversationId]);
    return $stmt->fetchAll();
}

/**
 * List a user's conversations for the history sidebar/drawer.
 * Free users see only their most recent conversations; Premium = unlimited history.
 */
function aiListConversations(int $userId, bool $isPremiumUser): array
{
    $db = getDB();
    if ($isPremiumUser) {
        $stmt = $db->prepare('SELECT id, title, updated_at FROM ai_conversations WHERE user_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$userId]);
    } else {
        $stmt = $db->prepare('SELECT id, title, updated_at FROM ai_conversations WHERE user_id = ? ORDER BY updated_at DESC LIMIT 5');
        $stmt->execute([$userId]);
    }
    return $stmt->fetchAll();
}

/** Derive a short conversation title from the first user message. */
function aiDeriveTitle(string $firstMessage): string
{
    $title = trim(preg_replace('/\s+/', ' ', $firstMessage));
    return mb_substr($title, 0, 60) . (mb_strlen($title) > 60 ? '…' : '');
}
