<?php
/**
 * Opportunities Hub — Jobs, Grants, Hackathons, Bounties, Ambassador
 * Programs, Events, Fellowships, Internships. Relevance to a Career Path is
 * a join-table relationship (career_path_opportunities), same pattern as
 * career_path_guides / guide_projects — an opportunity is never duplicated
 * to appear under multiple paths.
 */

require_once __DIR__ . '/config.php';

const OPPORTUNITY_CATEGORIES = [
    'job'         => 'Job',
    'grant'       => 'Grant',
    'hackathon'   => 'Hackathon',
    'bounty'      => 'Bounty',
    'ambassador'  => 'Ambassador Program',
    'event'       => 'Event',
    'fellowship'  => 'Fellowship',
    'internship'  => 'Internship',
];

/**
 * Active opportunities, optionally filtered by category/work_mode/career path.
 * $filters keys: category, work_mode, career_path_id, featured_only, q (search).
 */
function getOpportunities(array $filters = []): array {
    $db = getDB();
    $where = ["o.status = 'active'", "o.deleted_at IS NULL"];
    $params = [];

    if (!empty($filters['category']) && array_key_exists($filters['category'], OPPORTUNITY_CATEGORIES)) {
        $where[] = 'o.category = ?';
        $params[] = $filters['category'];
    }
    if (!empty($filters['work_mode']) && in_array($filters['work_mode'], ['remote','hybrid','onsite'], true)) {
        $where[] = 'o.work_mode = ?';
        $params[] = $filters['work_mode'];
    }
    if (!empty($filters['featured_only'])) {
        $where[] = 'o.is_featured = 1';
    }
    if (!empty($filters['q'])) {
        $where[] = '(o.title LIKE ? OR o.organization LIKE ? OR o.tags LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        array_push($params, $like, $like, $like);
    }

    $joinCareerPath = '';
    if (!empty($filters['career_path_id'])) {
        $joinCareerPath = 'INNER JOIN career_path_opportunities cpo ON cpo.opportunity_id = o.id AND cpo.career_path_id = ?';
        array_unshift($params, (int)$filters['career_path_id']);
    }

    $sql = "
        SELECT o.* FROM opportunities o
        $joinCareerPath
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.is_featured DESC, (o.deadline IS NULL), o.deadline ASC, o.created_at DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getOpportunity(int $id): ?array {
    $stmt = getDB()->prepare("SELECT * FROM opportunities WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getAllOpportunitiesAdmin(): array {
    return getDB()->query("
        SELECT o.*, (SELECT COUNT(*) FROM career_path_opportunities WHERE opportunity_id = o.id) AS career_path_count
        FROM opportunities o WHERE o.deleted_at IS NULL
        ORDER BY o.created_at DESC
    ")->fetchAll();
}

/** Opportunities linked to a specific Career Path (for the Learn/Roadmap "relevant opportunities" display). */
function getCareerPathOpportunities(int $careerPathId, int $limit = 6): array {
    $stmt = getDB()->prepare("
        SELECT o.* FROM career_path_opportunities cpo
        INNER JOIN opportunities o ON o.id = cpo.opportunity_id AND o.status='active' AND o.deleted_at IS NULL
        WHERE cpo.career_path_id = ?
        ORDER BY cpo.display_order ASC, o.is_featured DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $careerPathId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getLinkedCareerPaths(int $opportunityId): array {
    $stmt = getDB()->prepare("
        SELECT cp.id, cp.name, cp.icon FROM career_path_opportunities cpo
        INNER JOIN career_paths cp ON cp.id = cpo.career_path_id
        WHERE cpo.opportunity_id = ? ORDER BY cpo.display_order ASC
    ");
    $stmt->execute([$opportunityId]);
    return $stmt->fetchAll();
}

function linkOpportunityToCareerPath(int $opportunityId, int $careerPathId): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT COALESCE(MAX(display_order),0) FROM career_path_opportunities WHERE career_path_id=?");
    $stmt->execute([$careerPathId]);
    $next = (int)$stmt->fetchColumn() + 1;
    return $db->prepare("INSERT IGNORE INTO career_path_opportunities (career_path_id, opportunity_id, display_order) VALUES (?,?,?)")
               ->execute([$careerPathId, $opportunityId, $next]);
}

function unlinkOpportunityFromCareerPath(int $linkId): bool {
    return getDB()->prepare("DELETE FROM career_path_opportunities WHERE id = ?")->execute([$linkId]);
}
