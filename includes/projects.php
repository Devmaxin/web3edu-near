<?php
/**
 * Projects — Phase 2 "Learning by Doing".
 *
 * Projects are reusable content, exactly like Guides in Phase 1: one Project
 * can attach to many Guides via guide_projects, never duplicated. Portfolio
 * is NOT a stored table — it's computed live from approved
 * project_submissions, the same "never store what can be computed"
 * philosophy as the Phase 1 roadmap engine.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gamification.php';

// ============================================================================
// PROJECT CRUD
// ============================================================================

function getActiveProjects(): array {
    return getDB()->query("
        SELECT * FROM projects WHERE is_active = 1 AND deleted_at IS NULL ORDER BY title ASC
    ")->fetchAll();
}

function getAllProjectsAdmin(): array {
    return getDB()->query("
        SELECT p.*, (SELECT COUNT(*) FROM guide_projects WHERE project_id = p.id) AS guide_count,
               (SELECT COUNT(*) FROM project_submissions WHERE project_id = p.id AND status='approved') AS approved_count
        FROM projects p
        WHERE p.deleted_at IS NULL
        ORDER BY p.created_at DESC
    ")->fetchAll();
}

function getProject(int $id): ?array {
    $stmt = getDB()->prepare("SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function slugifyProjectTitle(string $title, int $excludeId = 0): string {
    $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
    if ($base === '') $base = 'project';
    $slug = $base;
    $db = getDB();
    $i = 2;
    while (true) {
        $stmt = $db->prepare("SELECT id FROM projects WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $excludeId]);
        if (!$stmt->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

/** Duplicate a project (title suffixed " (Copy)", new slug, always inactive until reviewed). */
function duplicateProject(int $projectId): ?int {
    $p = getProject($projectId);
    if (!$p) return null;
    $db = getDB();
    $newTitle = $p['title'] . ' (Copy)';
    $newSlug = slugifyProjectTitle($newTitle);
    $db->prepare("
        INSERT INTO projects (title, slug, description, project_type, estimated_duration, instructions,
            learning_objectives, requirements, deliverables, starter_files_url, reference_links, is_active)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,0)
    ")->execute([
        $newTitle, $newSlug, $p['description'], $p['project_type'], $p['estimated_duration'], $p['instructions'],
        $p['learning_objectives'], $p['requirements'], $p['deliverables'], $p['starter_files_url'], $p['reference_links'],
    ]);
    return (int)$db->lastInsertId();
}

// ============================================================================
// GUIDE <-> PROJECT ATTACHMENT (mirrors career_path_guides exactly)
// ============================================================================

function getGuideProjects(int $guideId): array {
    try {
        $stmt = getDB()->prepare("
            SELECT gp.*, p.title, p.project_type, p.estimated_duration
            FROM guide_projects gp
            INNER JOIN projects p ON p.id = gp.project_id AND p.deleted_at IS NULL
            WHERE gp.guide_id = ? AND gp.is_visible = 1
            ORDER BY gp.display_order ASC, gp.id ASC
        ");
        $stmt->execute([$guideId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return [];
        }
        throw $e;
    }
}

function getGuideProjectsAdmin(int $guideId): array {
    $stmt = getDB()->prepare("
        SELECT gp.*, p.title, p.project_type
        FROM guide_projects gp
        INNER JOIN projects p ON p.id = gp.project_id
        WHERE gp.guide_id = ?
        ORDER BY gp.display_order ASC, gp.id ASC
    ");
    $stmt->execute([$guideId]);
    return $stmt->fetchAll();
}

function getAttachableProjects(int $guideId): array {
    $stmt = getDB()->prepare("
        SELECT id, title, project_type FROM projects
        WHERE deleted_at IS NULL AND id NOT IN (SELECT project_id FROM guide_projects WHERE guide_id = ?)
        ORDER BY title ASC
    ");
    $stmt->execute([$guideId]);
    return $stmt->fetchAll();
}

// ============================================================================
// SUBMISSIONS
// ============================================================================

/** The user's submission for (project, guide) context, or null if never started. */
function getUserSubmission(int $userId, int $projectId, ?int $guideId): ?array {
    $db = getDB();
    if ($guideId === null) {
        $stmt = $db->prepare("SELECT * FROM project_submissions WHERE user_id=? AND project_id=? AND guide_id IS NULL");
        $stmt->execute([$userId, $projectId]);
    } else {
        $stmt = $db->prepare("SELECT * FROM project_submissions WHERE user_id=? AND project_id=? AND guide_id=?");
        $stmt->execute([$userId, $projectId, $guideId]);
    }
    return $stmt->fetch() ?: null;
}

function getUserSubmissionStatus(int $userId, int $projectId, ?int $guideId): ?string {
    $sub = getUserSubmission($userId, $projectId, $guideId);
    return $sub['status'] ?? null;
}

/**
 * Create or update a submission. A resubmission after 'needs_revision' or
 * 'rejected' resets status back to 'submitted' so it re-enters the review
 * queue; an 'approved' submission is locked (callers should check status
 * before calling this to avoid clobbering an approved review).
 */
function saveProjectSubmission(int $userId, int $projectId, ?int $guideId, array $fields, bool $submit): ?int {
    $db = getDB();
    $existing = getUserSubmission($userId, $projectId, $guideId);

    $github = trim($fields['github_url'] ?? '');
    $live   = trim($fields['live_demo_url'] ?? '');
    $video  = trim($fields['video_demo_url'] ?? '');
    $notes  = trim($fields['notes'] ?? '');
    $tech   = trim($fields['technologies'] ?? '');
    $filePath = $fields['file_path'] ?? null;

    $status = $submit ? 'submitted' : 'draft';
    $submittedAt = $submit ? date('Y-m-d H:i:s') : null;

    if ($existing) {
        if ($existing['status'] === 'approved') return (int)$existing['id']; // locked, don't clobber
        $db->prepare("
            UPDATE project_submissions SET github_url=?, live_demo_url=?, video_demo_url=?, notes=?, technologies=?,
                file_path=COALESCE(?, file_path), status=?, submitted_at=COALESCE(?, submitted_at)
            WHERE id=?
        ")->execute([$github, $live, $video, $notes, $tech, $filePath, $status, $submittedAt, $existing['id']]);
        if ($submit) awardXp($userId, 'project_submitted', 'project_submission', (int)$existing['id']);
        return (int)$existing['id'];
    }

    $db->prepare("
        INSERT INTO project_submissions (user_id, project_id, guide_id, github_url, live_demo_url, video_demo_url, notes, technologies, file_path, status, submitted_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([$userId, $projectId, $guideId, $github, $live, $video, $notes, $tech, $filePath, $status, $submittedAt]);
    $newId = (int)$db->lastInsertId();
    if ($submit) awardXp($userId, 'project_submitted', 'project_submission', $newId);
    return $newId;
}

/** Admin review action: approve / reject / needs_revision, with visible feedback. */
function reviewProjectSubmission(int $submissionId, string $decision, ?int $adminId, string $feedback = ''): bool {
    if (!in_array($decision, ['approved', 'rejected', 'needs_revision'], true)) return false;
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM project_submissions WHERE id = ?");
    $stmt->execute([$submissionId]);
    $sub = $stmt->fetch();
    if (!$sub) return false;

    $db->prepare("
        UPDATE project_submissions SET status=?, reviewed_at=NOW(), reviewed_by=? WHERE id=?
    ")->execute([$decision, $adminId, $submissionId]);

    $db->prepare("
        INSERT INTO project_feedback (submission_id, admin_id, decision, feedback) VALUES (?,?,?,?)
    ")->execute([$submissionId, $adminId, $decision, $feedback]);

    notifyUser(
        (int)$sub['user_id'], 'project_reviewed',
        $decision === 'approved' ? '🎉 Project Approved' : ($decision === 'rejected' ? 'Project Rejected' : 'Project Needs Revision'),
        $feedback !== '' ? $feedback : 'Your project submission has been reviewed.',
        SITE_URL . '/portfolio.php'
    );

    // Approval may complete the guide's pipeline (require_project) — re-check
    // certificate eligibility exactly like a quiz pass does.
    if ($decision === 'approved') {
        awardXp((int)$sub['user_id'], 'project_approved', 'project_submission', $submissionId);
        evaluateBadgeService((int)$sub['user_id']);
        if (!empty($sub['guide_id'])) {
            maybeIssueGuideCertificate((int)$sub['user_id'], (int)$sub['guide_id']);
        }
    }

    return true;
}

function getSubmissionFeedback(int $submissionId): array {
    $stmt = getDB()->prepare("
        SELECT pf.*, a.name AS admin_name FROM project_feedback pf
        LEFT JOIN admins a ON a.id = pf.admin_id
        WHERE pf.submission_id = ? ORDER BY pf.created_at DESC
    ");
    $stmt->execute([$submissionId]);
    return $stmt->fetchAll();
}

/** Admin review queue: submissions awaiting a decision, oldest first. */
function getPendingSubmissions(): array {
    return getDB()->query("
        SELECT ps.*, p.title AS project_title, u.username, g.title AS guide_title
        FROM project_submissions ps
        INNER JOIN projects p ON p.id = ps.project_id
        INNER JOIN users u ON u.id = ps.user_id
        LEFT JOIN guide_paths g ON g.id = ps.guide_id
        WHERE ps.status = 'submitted'
        ORDER BY ps.submitted_at ASC
    ")->fetchAll();
}

function getAllSubmissionsAdmin(): array {
    return getDB()->query("
        SELECT ps.*, p.title AS project_title, u.username, g.title AS guide_title
        FROM project_submissions ps
        INNER JOIN projects p ON p.id = ps.project_id
        INNER JOIN users u ON u.id = ps.user_id
        LEFT JOIN guide_paths g ON g.id = ps.guide_id
        WHERE ps.status != 'draft'
        ORDER BY ps.updated_at DESC
    ")->fetchAll();
}

// ============================================================================
// PORTFOLIO — computed, never stored (same philosophy as Roadmap progress)
// ============================================================================

// ============================================================================
// PORTFOLIO PRIVACY — public/private toggle + shareable slug
// ============================================================================

function getPortfolioSettings(int $userId): array {
    $stmt = getDB()->prepare("SELECT * FROM user_portfolio_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: ['user_id' => $userId, 'is_public' => 0, 'slug' => null];
}

function generatePortfolioSlug(int $userId): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $base = strtolower(preg_replace('/[^a-z0-9]+/', '-', $stmt->fetchColumn() ?: 'user'));
    $base = trim($base, '-') ?: 'user';
    $slug = $base;
    $i = 2;
    while (true) {
        $check = $db->prepare("SELECT user_id FROM user_portfolio_settings WHERE slug = ? AND user_id != ?");
        $check->execute([$slug, $userId]);
        if (!$check->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

/** Turns the portfolio public/private. Generates a slug the first time it goes public if none exists yet. */
function setPortfolioVisibility(int $userId, bool $isPublic): array {
    $db = getDB();
    $settings = getPortfolioSettings($userId);
    $slug = $settings['slug'];
    if ($isPublic && !$slug) {
        $slug = generatePortfolioSlug($userId);
    }
    $db->prepare("
        INSERT INTO user_portfolio_settings (user_id, is_public, slug) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE is_public = VALUES(is_public), slug = COALESCE(user_portfolio_settings.slug, VALUES(slug))
    ")->execute([$userId, $isPublic ? 1 : 0, $slug]);
    return getPortfolioSettings($userId);
}

/** Resolves a public portfolio by its shareable slug. Null if not found or not public. */
function getPublicPortfolioBySlug(string $slug): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT ups.user_id, u.username, u.avatar
        FROM user_portfolio_settings ups
        INNER JOIN users u ON u.id = ups.user_id
        WHERE ups.slug = ? AND ups.is_public = 1
    ");
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

/** Completed (required-guide-100%) career paths for the public portfolio view. */
function getUserCompletedCareerPaths(int $userId): array {
    $stmt = getDB()->prepare("
        SELECT cp.id, cp.name, cp.icon, cp.theme_color, ucp.completed_at
        FROM user_career_paths ucp
        INNER JOIN career_paths cp ON cp.id = ucp.career_path_id
        WHERE ucp.user_id = ? AND ucp.status = 'completed'
        ORDER BY ucp.completed_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Earned certificates for the public portfolio view (reuses the existing certificate engine's table). */
function getUserCertificatesForPortfolio(int $userId): array {
    $stmt = getDB()->prepare("
        SELECT c.*, ct.label AS type_label, ct.icon AS type_icon
        FROM certificates c
        LEFT JOIN certificate_types ct ON ct.id = c.certificate_type_id
        WHERE c.user_id = ? AND c.revoked_at IS NULL
        ORDER BY c.issued_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
function getUserPortfolio(int $userId): array {
    $stmt = getDB()->prepare("
        SELECT ps.*, p.title AS project_title, p.project_type, p.description AS project_description,
               g.title AS guide_title, g.id AS guide_id_resolved,
               (SELECT cp.id FROM career_path_guides cpg INNER JOIN career_paths cp ON cp.id = cpg.career_path_id
                WHERE cpg.guide_id = g.id ORDER BY cpg.career_path_id ASC LIMIT 1) AS career_path_id,
               (SELECT cp.name FROM career_path_guides cpg INNER JOIN career_paths cp ON cp.id = cpg.career_path_id
                WHERE cpg.guide_id = g.id ORDER BY cpg.career_path_id ASC LIMIT 1) AS career_path_name,
               (SELECT cp.icon FROM career_path_guides cpg INNER JOIN career_paths cp ON cp.id = cpg.career_path_id
                WHERE cpg.guide_id = g.id ORDER BY cpg.career_path_id ASC LIMIT 1) AS career_path_icon
        FROM project_submissions ps
        INNER JOIN projects p ON p.id = ps.project_id
        LEFT JOIN guide_paths g ON g.id = ps.guide_id
        WHERE ps.user_id = ? AND ps.status = 'approved'
        ORDER BY ps.reviewed_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
