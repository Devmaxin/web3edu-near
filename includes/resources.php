<?php
/**
 * Resources — reusable library, attachable to lessons, guides, projects, and
 * career paths without duplication (resource_links is a pure join table,
 * same pattern as career_path_guides / guide_projects).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gamification.php';

function getActiveResources(): array {
    return getDB()->query("SELECT * FROM resources WHERE is_active = 1 AND deleted_at IS NULL ORDER BY title ASC")->fetchAll();
}

function getAllResourcesAdmin(): array {
    return getDB()->query("
        SELECT r.*, (SELECT COUNT(*) FROM resource_links WHERE resource_id = r.id) AS link_count
        FROM resources r WHERE r.deleted_at IS NULL ORDER BY r.created_at DESC
    ")->fetchAll();
}

function getResource(int $id): ?array {
    $stmt = getDB()->prepare("SELECT * FROM resources WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Resources attached to a specific lesson/guide/project/career path, in display order. */
function getLinkedResources(string $type, int $id): array {
    $stmt = getDB()->prepare("
        SELECT r.*, rl.display_order FROM resource_links rl
        INNER JOIN resources r ON r.id = rl.resource_id AND r.is_active = 1 AND r.deleted_at IS NULL
        WHERE rl.linkable_type = ? AND rl.linkable_id = ?
        ORDER BY rl.display_order ASC, r.title ASC
    ");
    $stmt->execute([$type, $id]);
    return $stmt->fetchAll();
}

function attachResource(int $resourceId, string $type, int $id): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT COALESCE(MAX(display_order),0) FROM resource_links WHERE linkable_type=? AND linkable_id=?");
    $stmt->execute([$type, $id]);
    $next = (int)$stmt->fetchColumn() + 1;
    return $db->prepare("INSERT IGNORE INTO resource_links (resource_id, linkable_type, linkable_id, display_order) VALUES (?,?,?,?)")
               ->execute([$resourceId, $type, $id, $next]);
}

function detachResource(int $linkId): bool {
    return getDB()->prepare("DELETE FROM resource_links WHERE id = ?")->execute([$linkId]);
}

/** Marks a resource as viewed by this user — feeds the Continue Learning resolver's 'resource' step. */
function markResourceViewed(int $userId, int $resourceId): void {
    getDB()->prepare("INSERT IGNORE INTO resource_views (user_id, resource_id) VALUES (?,?)")->execute([$userId, $resourceId]);
    awardXp($userId, 'resource_viewed', 'resource', $resourceId);
}

function hasViewedResource(int $userId, int $resourceId): bool {
    $stmt = getDB()->prepare("SELECT 1 FROM resource_views WHERE user_id=? AND resource_id=?");
    $stmt->execute([$userId, $resourceId]);
    return (bool)$stmt->fetchColumn();
}

/** The first not-yet-viewed resource attached to a guide, or null. Used by the smarter resolver. */
function getNextUnviewedGuideResource(int $userId, int $guideId): ?array {
    $stmt = getDB()->prepare("
        SELECT r.* FROM resource_links rl
        INNER JOIN resources r ON r.id = rl.resource_id AND r.is_active = 1 AND r.deleted_at IS NULL
        WHERE rl.linkable_type = 'guide_path' AND rl.linkable_id = ?
          AND r.id NOT IN (SELECT resource_id FROM resource_views WHERE user_id = ?)
        ORDER BY rl.display_order ASC
        LIMIT 1
    ");
    $stmt->execute([$guideId, $userId]);
    return $stmt->fetch() ?: null;
}
