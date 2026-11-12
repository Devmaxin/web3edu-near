<?php
/**
 * Lesson Notes, Highlights, and Related Content.
 * Notes and highlights are strictly private — never joined into any
 * public-facing query, never shown to any other user or admin.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gamification.php';

// ============================================================================
// LESSON NOTES — one private note per (user, lesson), auto-saved in place
// ============================================================================

function getLessonNote(int $userId, int $lessonId): ?array {
    try {
        $stmt = getDB()->prepare("SELECT * FROM lesson_notes WHERE user_id=? AND lesson_id=?");
        $stmt->execute([$userId, $lessonId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return null;
        }
        throw $e;
    }
}

function saveLessonNote(int $userId, int $lessonId, string $content): void {
    $db = getDB();
    if (trim($content) === '') {
        $db->prepare("DELETE FROM lesson_notes WHERE user_id=? AND lesson_id=?")->execute([$userId, $lessonId]);
        return;
    }
    $db->prepare("
        INSERT INTO lesson_notes (user_id, lesson_id, content) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE content = VALUES(content)
    ")->execute([$userId, $lessonId, $content]);
    // Awarded once per lesson — awardXp's own (action,type,id) dedupe makes
    // every subsequent autosave on the same lesson a harmless no-op.
    awardXp($userId, 'note_created', 'lesson_note', $lessonId);
}

// ============================================================================
// HIGHLIGHTS — many private highlights per (user, lesson)
// ============================================================================

function getLessonHighlights(int $userId, int $lessonId): array {
    try {
        $stmt = getDB()->prepare("
            SELECT * FROM lesson_highlights WHERE user_id=? AND lesson_id=? ORDER BY start_offset ASC, id ASC
        ");
        $stmt->execute([$userId, $lessonId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return [];
        }
        throw $e;
    }
}

function addLessonHighlight(int $userId, int $lessonId, string $text, ?int $start, ?int $end, string $color = 'yellow'): int {
    $db = getDB();
    $db->prepare("
        INSERT INTO lesson_highlights (user_id, lesson_id, highlighted_text, start_offset, end_offset, color)
        VALUES (?,?,?,?,?,?)
    ")->execute([$userId, $lessonId, $text, $start, $end, $color]);
    $newId = (int)$db->lastInsertId();
    awardXp($userId, 'highlight_created', 'lesson_highlight', $newId);
    return $newId;
}

function deleteLessonHighlight(int $userId, int $highlightId): bool {
    return getDB()->prepare("DELETE FROM lesson_highlights WHERE id=? AND user_id=?")->execute([$highlightId, $userId]);
}

// ============================================================================
// BOOKMARKS — reuses the existing (Phase 1-era) user_bookmarks table, now
// widened to also accept 'project' and 'resource' as bookmarkable_type.
// ============================================================================

function getUserBookmarksByType(int $userId, string $type): array {
    $stmt = getDB()->prepare("SELECT bookmarkable_id, created_at FROM user_bookmarks WHERE user_id=? AND bookmarkable_type=? ORDER BY created_at DESC");
    $stmt->execute([$userId, $type]);
    return $stmt->fetchAll();
}

// isBookmarked(string $type, int $id): bool already exists in includes/config.php
// (session-bound) — reused as-is rather than duplicated here.

// ============================================================================
// RELATED CONTENT — prioritizes current Career Path, current Guide, tags,
// then difficulty, falling back gracefully at each tier.
// ============================================================================

/** Related lessons: same guide first, then same category, excluding the current lesson. */
function getRelatedLessons(int $lessonId, ?int $currentGuideId = null, int $limit = 4): array {
    $db = getDB();
    $lessonStmt = $db->prepare("SELECT category FROM lessons WHERE id = ?");
    $lessonStmt->execute([$lessonId]);
    $category = $lessonStmt->fetchColumn();

    $results = [];
    if ($currentGuideId) {
        $stmt = $db->prepare("
            SELECT l.* FROM guide_path_lessons gpl
            INNER JOIN lessons l ON l.id = gpl.lesson_id AND l.deleted_at IS NULL
            WHERE gpl.guide_id = ? AND l.id != ?
            ORDER BY gpl.sort_order ASC LIMIT ?
        ");
        $stmt->bindValue(1, $currentGuideId, PDO::PARAM_INT);
        $stmt->bindValue(2, $lessonId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll();
    }

    if (count($results) < $limit && $category) {
        $need = $limit - count($results);
        $excludeIds = array_merge([$lessonId], array_column($results, 'id'));
        $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
        $stmt = $db->prepare("
            SELECT * FROM lessons WHERE category = ? AND deleted_at IS NULL AND id NOT IN ($placeholders)
            ORDER BY sort_order ASC LIMIT $need
        ");
        $stmt->execute(array_merge([$category], $excludeIds));
        $results = array_merge($results, $stmt->fetchAll());
    }

    return array_slice($results, 0, $limit);
}

/** Related guides: same difficulty first, excluding the current guide. */
function getRelatedGuides(int $guideId, int $limit = 3): array {
    $db = getDB();
    $guideStmt = $db->prepare("SELECT difficulty FROM guide_paths WHERE id = ?");
    $guideStmt->execute([$guideId]);
    $difficulty = $guideStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT * FROM guide_paths
        WHERE id != ? AND deleted_at IS NULL AND (difficulty = ? OR ? IS NULL)
        ORDER BY (difficulty = ?) DESC, id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $guideId, PDO::PARAM_INT);
    $stmt->bindValue(2, $difficulty);
    $stmt->bindValue(3, $difficulty);
    $stmt->bindValue(4, $difficulty);
    $stmt->bindValue(5, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Related projects: attached to the current guide, falling back to same project_type. */
function getRelatedProjects(?int $guideId, ?string $projectType = null, int $limit = 3): array {
    try {
        $db = getDB();
        $results = [];
        if ($guideId) {
            $stmt = $db->prepare("
                SELECT p.* FROM guide_projects gp
                INNER JOIN projects p ON p.id = gp.project_id AND p.deleted_at IS NULL AND p.is_active = 1
                WHERE gp.guide_id = ? ORDER BY gp.display_order ASC LIMIT ?
            ");
            $stmt->bindValue(1, $guideId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();
        }
        if (count($results) < $limit && $projectType) {
            $need = $limit - count($results);
            $excludeIds = array_merge([0], array_column($results, 'id'));
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $stmt = $db->prepare("
                SELECT * FROM projects WHERE project_type = ? AND is_active = 1 AND deleted_at IS NULL AND id NOT IN ($placeholders)
                LIMIT $need
            ");
            $stmt->execute(array_merge([$projectType], $excludeIds));
            $results = array_merge($results, $stmt->fetchAll());
        }
        return array_slice($results, 0, $limit);
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return [];
        }
        throw $e;
    }
}
