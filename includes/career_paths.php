<?php
/**
 * Career Paths — Phase 1 Personalized Learning Architecture.
 *
 * This file is a pure read/compute layer on top of the EXISTING guide/lesson
 * system (guide_paths, guide_path_lessons, user_lesson_progress). It never
 * writes to those tables and never duplicates a lesson or guide row.
 *
 * See /phase1-learning-architecture.md for the full spec this implements.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gamification.php';
require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/resources.php';

// ============================================================================
// BASIC LOOKUPS
// ============================================================================

/** All career paths an end user may choose from (Welcome Modal, Change Path). */
function getActiveCareerPaths(): array {
    return getDB()->query("
        SELECT * FROM career_paths WHERE is_active = 1 ORDER BY display_order ASC, id ASC
    ")->fetchAll();
}

/** All career paths, including inactive — for the admin list. */
function getAllCareerPathsAdmin(): array {
    return getDB()->query("
        SELECT cp.*, prev.name AS recommended_previous_name
        FROM career_paths cp
        LEFT JOIN career_paths prev ON prev.id = cp.recommended_previous_career_path_id
        ORDER BY cp.display_order ASC, cp.id ASC
    ")->fetchAll();
}

function getCareerPath(int $id): ?array {
    $stmt = getDB()->prepare("SELECT * FROM career_paths WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getFoundationCareerPath(): ?array {
    $stmt = getDB()->prepare("SELECT * FROM career_paths WHERE is_foundation = 1 AND is_active = 1 LIMIT 1");
    $stmt->execute();
    return $stmt->fetch() ?: null;
}

// ============================================================================
// USER <-> CAREER PATH STATE (§5)
// ============================================================================

/**
 * The user's current path in focus, joined with its definition. This is the
 * most recent row that hasn't been superseded by a switch — status 'active'
 * OR 'completed'. A completed path stays the user's current focus (still
 * revisitable, still shown on Learn/Roadmap) until they explicitly switch to
 * something else, per §5: "a completed path can still be revisited... without
 * changing its status back to active." Only switchActiveCareerPath() ever
 * demotes a row to 'paused'. Null if the user has never chosen a path.
 */
function getActiveUserCareerPath(int $userId): ?array {
    $stmt = getDB()->prepare("
        SELECT ucp.*, cp.name, cp.description, cp.icon, cp.theme_color, cp.is_foundation,
               cp.difficulty, cp.completion_badge_id, cp.certificate_template_id
        FROM user_career_paths ucp
        INNER JOIN career_paths cp ON cp.id = ucp.career_path_id
        WHERE ucp.user_id = ? AND ucp.status IN ('active','completed')
        ORDER BY ucp.last_activity_at DESC, ucp.id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/** Full history — every path the user has ever started, most recent activity first. */
function getUserCareerPathHistory(int $userId): array {
    $stmt = getDB()->prepare("
        SELECT ucp.*, cp.name, cp.icon, cp.theme_color, cp.difficulty
        FROM user_career_paths ucp
        INNER JOIN career_paths cp ON cp.id = ucp.career_path_id
        WHERE ucp.user_id = ?
        ORDER BY ucp.last_activity_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Switch (or start) a user's active career path. Pure metadata operation —
 * never touches career_path_guides, guide_path_lessons, or
 * user_lesson_progress, so no progress is ever lost (§5).
 */
function switchActiveCareerPath(int $userId, int $careerPathId): bool {
    $db = getDB();
    $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE user_career_paths SET status = 'paused', last_activity_at = NOW(6)
            WHERE user_id = ? AND status = 'active'
        ")->execute([$userId]);

        $existing = $db->prepare("SELECT id, status FROM user_career_paths WHERE user_id = ? AND career_path_id = ?");
        $existing->execute([$userId, $careerPathId]);
        $row = $existing->fetch();

        if ($row) {
            $db->prepare("
                UPDATE user_career_paths SET status = 'active', last_activity_at = NOW(6) WHERE id = ?
            ")->execute([$row['id']]);
        } else {
            $db->prepare("
                INSERT INTO user_career_paths (user_id, career_path_id, status, started_at, last_activity_at)
                VALUES (?, ?, 'active', NOW(), NOW(6))
            ")->execute([$userId, $careerPathId]);
        }

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('switchActiveCareerPath failed: ' . $e->getMessage());
        return false;
    }
}

// ============================================================================
// ROADMAP / PROGRESS CALCULATION (§4) — never stored, always computed
// ============================================================================

/**
 * Full roadmap computation for (user, career_path): guides (grouped by
 * milestone if any are defined), per-guide/per-lesson completion, overall
 * required-guide percentage, current stage, and unlock state.
 */
function computeCareerPathRoadmap(int $userId, int $careerPathId): array {
    $db = getDB();

    $milestones = $db->prepare("
        SELECT * FROM career_path_milestones WHERE career_path_id = ? ORDER BY display_order ASC, id ASC
    ");
    $milestones->execute([$careerPathId]);
    $milestones = $milestones->fetchAll();

    $guideRows = $db->prepare("
        SELECT cpg.*, gp.title, gp.description AS guide_description, gp.slug, gp.difficulty
        FROM career_path_guides cpg
        INNER JOIN guide_paths gp ON gp.id = cpg.guide_id AND gp.deleted_at IS NULL
        WHERE cpg.career_path_id = ? AND cpg.is_visible = 1
        ORDER BY cpg.display_order ASC, cpg.id ASC
    ");
    $guideRows->execute([$careerPathId]);
    $guideRows = $guideRows->fetchAll();

    $requiredTotal = 0;
    $requiredDone  = 0;
    $priorRequiredComplete = true; // rolling: are all required guides *before* this one complete?
    $currentStageGuideId = null;
    $guides = [];

    foreach ($guideRows as $g) {
        $lessons = $db->prepare("
            SELECT gpl.lesson_id, l.title, l.slug, gpl.sort_order,
                   (ulp.id IS NOT NULL) AS is_complete
            FROM guide_path_lessons gpl
            INNER JOIN lessons l ON l.id = gpl.lesson_id AND l.deleted_at IS NULL
            LEFT JOIN user_lesson_progress ulp ON ulp.lesson_id = gpl.lesson_id AND ulp.user_id = ?
            WHERE gpl.guide_id = ?
            ORDER BY gpl.sort_order ASC
        ");
        $lessons->execute([$userId, $g['guide_id']]);
        $lessons = $lessons->fetchAll();

        $total = count($lessons);
        $done  = 0;
        foreach ($lessons as $l) { if ($l['is_complete']) $done++; }
        $lessonsComplete = $total > 0 && $done === $total;

        // Phase 2: "done" now means the guide's full pipeline is satisfied
        // (lessons + quiz + project + assessment, whichever the guide is
        // configured to require) — not lessons alone. Guides that don't
        // opt into any Phase 2 requirement behave exactly as in Phase 1.
        $guideComplete = $lessonsComplete && isGuidePipelineComplete($userId, (int)$g['guide_id']);

        if ($g['requirement'] === 'required') {
            $requiredTotal += $total;
            $requiredDone  += $done;
        }

        $unlocked = $priorRequiredComplete;

        if ($currentStageGuideId === null && $g['requirement'] === 'required' && !$guideComplete) {
            $currentStageGuideId = $g['guide_id'];
        }

        $guides[] = [
            'guide_id'      => (int)$g['guide_id'],
            'milestone_id'  => $g['milestone_id'] !== null ? (int)$g['milestone_id'] : null,
            'title'         => $g['title'],
            'description'   => $g['guide_description'],
            'slug'          => $g['slug'],
            'difficulty'    => $g['difficulty'],
            'requirement'   => $g['requirement'],
            'display_order' => (int)$g['display_order'],
            'lessons'       => $lessons,
            'total_lessons' => $total,
            'done_lessons'  => $done,
            'pct'           => $total > 0 ? (int)round($done / $total * 100) : 0,
            'lessons_complete' => $lessonsComplete,
            'is_complete'   => $guideComplete,
            'status'        => $guideComplete ? 'completed' : ($unlocked ? 'unlocked' : 'locked'),
        ];

        // Only required guides gate the chain (§4 rule 6).
        if ($g['requirement'] === 'required' && !$guideComplete) {
            $priorRequiredComplete = false;
        }
    }

    // If every required guide is done, fall back to first incomplete optional guide.
    if ($currentStageGuideId === null) {
        foreach ($guides as $g) {
            if ($g['requirement'] === 'optional' && !$g['is_complete']) {
                $currentStageGuideId = $g['guide_id'];
                break;
            }
        }
    }
    foreach ($guides as &$g) {
        $g['is_current'] = ($g['guide_id'] === $currentStageGuideId);
    }
    unset($g);

    $pct = $requiredTotal > 0 ? (int)round($requiredDone / $requiredTotal * 100) : 0;
    $pathComplete = $requiredTotal > 0 && $requiredDone === $requiredTotal;

    // Group guides under their milestone (or "ungrouped") for display only.
    $grouped = [];
    if (!empty($milestones)) {
        foreach ($milestones as $m) {
            $grouped[] = [
                'milestone' => $m,
                'guides'    => array_values(array_filter($guides, fn($g) => $g['milestone_id'] === (int)$m['id'])),
            ];
        }
        $ungrouped = array_values(array_filter($guides, fn($g) => $g['milestone_id'] === null));
        if (!empty($ungrouped)) {
            $grouped[] = ['milestone' => null, 'guides' => $ungrouped];
        }
    } else {
        // No milestones defined for this path — flat list (§8).
        $grouped[] = ['milestone' => null, 'guides' => $guides];
    }

    return [
        'guides'                => $guides,
        'grouped'               => $grouped,
        'has_milestones'        => !empty($milestones),
        'progress'              => ['completed' => $requiredDone, 'total' => $requiredTotal, 'pct' => $pct],
        'current_stage_guide_id'=> $currentStageGuideId,
        'is_complete'           => $pathComplete,
    ];
}

// ============================================================================
// CONTINUE LEARNING — typed resolver (§7)
// ============================================================================

/**
 * resolveNextLearningItem(user, career_path) -> ['type'=>, 'id'=>, 'guide_id'=>, ...] | null
 *
 * Phase 1 only ever returns type 'lesson'. Future phases add quiz/project/
 * assessment checks as extra priority steps inside this same function —
 * callers never need to change, they just switch on `type`.
 */
/**
 * resolveNextLearningItem(user, career_path) -> ['type'=>, 'id'=>, 'guide_id'=>, ...] | null
 *
 * Phase 1 only returned type 'lesson'. Phase 2 fills in the priority chain
 * exactly where the Phase 1 comments reserved it — every existing caller
 * (Learn page card, Roadmap page, dashboard widget) already switches on
 * `type`, so none of them need to change for these new types to appear.
 */
function resolveNextLearningItem(int $userId, int $careerPathId): ?array {
    $roadmap = computeCareerPathRoadmap($userId, $careerPathId);

    // Pass 1: real pending work, across the whole path in guide order. An
    // already-finished guide's unviewed Resource must never block a later
    // guide's actual next lesson — so resources are handled in pass 2, only
    // once nothing here fires for any guide.
    foreach ($roadmap['guides'] as $guide) {
        if ($guide['status'] === 'locked') continue; // don't resolve into a locked guide
        $guideId = $guide['guide_id'];

        // 1. Next lesson with no progress row.
        foreach ($guide['lessons'] as $lesson) {
            if (!$lesson['is_complete']) {
                return [
                    'type'        => 'lesson',
                    'id'          => (int)$lesson['lesson_id'],
                    'guide_id'    => $guideId,
                    'lesson_title'=> $lesson['title'],
                    'guide_title' => $guide['title'],
                ];
            }
        }
        // Lessons for this guide are done from here on.

        // 2. Guide's Quiz (quiz_type='quiz'), if it has one and hasn't passed it.
        $quiz = getDB()->prepare("SELECT id, title FROM quizzes WHERE guide_id=? AND quiz_type='quiz' AND is_active=1 LIMIT 1");
        $quiz->execute([$guideId]);
        $quiz = $quiz->fetch();
        if ($quiz) {
            $passed = getDB()->prepare("SELECT passed FROM quiz_results WHERE user_id=? AND quiz_id=?");
            $passed->execute([$userId, $quiz['id']]);
            if (!(int)$passed->fetchColumn()) {
                return ['type' => 'quiz', 'id' => (int)$quiz['id'], 'guide_id' => $guideId, 'quiz_title' => $quiz['title'], 'guide_title' => $guide['title']];
            }
        }

        // 3. Required Projects attached to this guide, in display order.
        foreach (getGuideProjects($guideId) as $proj) {
            if ($proj['requirement'] !== 'required') continue;
            $status = getUserSubmissionStatus($userId, (int)$proj['project_id'], $guideId);
            if ($status !== 'approved') {
                return ['type' => 'project', 'id' => (int)$proj['project_id'], 'guide_id' => $guideId, 'project_title' => $proj['title'], 'guide_title' => $guide['title'], 'submission_status' => $status];
            }
        }

        // 4. Guide's Final Assessment (quiz_type='assessment').
        $assessment = getDB()->prepare("SELECT id, title FROM quizzes WHERE guide_id=? AND quiz_type='assessment' AND is_active=1 LIMIT 1");
        $assessment->execute([$guideId]);
        $assessment = $assessment->fetch();
        if ($assessment) {
            $passed = getDB()->prepare("SELECT passed FROM quiz_results WHERE user_id=? AND quiz_id=?");
            $passed->execute([$userId, $assessment['id']]);
            if (!(int)$passed->fetchColumn()) {
                return ['type' => 'assessment', 'id' => (int)$assessment['id'], 'guide_id' => $guideId, 'assessment_title' => $assessment['title'], 'guide_title' => $guide['title']];
            }
        }

        // Everything gating for this guide is done — move to the next guide.
    }

    // Pass 2: nothing gating is pending anywhere in the path. Only now offer
    // a supplementary, not-yet-viewed Resource as a soft "before you go" nudge.
    foreach ($roadmap['guides'] as $guide) {
        if ($guide['status'] === 'locked') continue;
        $resource = getNextUnviewedGuideResource($userId, $guide['guide_id']);
        if ($resource) {
            return ['type' => 'resource', 'id' => (int)$resource['id'], 'guide_id' => $guide['guide_id'], 'resource_title' => $resource['title'], 'guide_title' => $guide['title']];
        }
    }

    return null; // path complete for every item type Phase 2 knows about
}

// ============================================================================
// FOUNDATION -> SPECIALIZATION HAND-OFF (§6)
// ============================================================================

/** Career paths that recommend themselves after $completedPathId finishes. */
function getSpecializationRecommendations(int $completedPathId): array {
    $stmt = getDB()->prepare("
        SELECT * FROM career_paths
        WHERE recommended_previous_career_path_id = ? AND is_active = 1
        ORDER BY display_order ASC
    ");
    $stmt->execute([$completedPathId]);
    return $stmt->fetchAll();
}

/**
 * Call after any lesson completion that could finish a required guide.
 * Marks the user's active path 'completed' if the roadmap just hit 100%,
 * and returns hand-off recommendations if there are any (empty array
 * otherwise — no special-casing needed for non-Foundation paths, §6).
 */
function checkAndCompleteCareerPath(int $userId, int $careerPathId): array {
    $roadmap = computeCareerPathRoadmap($userId, $careerPathId);
    if (!$roadmap['is_complete']) return [];

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, status FROM user_career_paths WHERE user_id = ? AND career_path_id = ?
    ");
    $stmt->execute([$userId, $careerPathId]);
    $row = $stmt->fetch();
    if (!$row) return [];

    // Only surface hand-off recommendations on the actual completion
    // transition — if this path was already marked 'completed' on a prior
    // call (e.g. the user is now finishing optional guides), don't re-fire
    // the "choose your specialization" prompt on every later lesson.
    if ($row['status'] === 'completed') return [];

    $db->prepare("
        UPDATE user_career_paths SET status = 'completed', completed_at = NOW(), last_activity_at = NOW(6)
        WHERE id = ?
    ")->execute([$row['id']]);

    // Phase 2: issue the Career Path Completion certificate (reuses the
    // existing certificate engine from includes/certificates.php — the same
    // one Guide Completion certificates already use) and award the path's
    // completion badge, if the admin configured either on this path.
    $careerPath = getCareerPath($careerPathId);
    if ($careerPath) {
        awardXp($userId, 'career_path_complete', 'career_path', $careerPathId);

        require_once __DIR__ . '/certificates.php';
        $typeRow = getCertificateTypeByKey('career_path_completion');
        if ($typeRow) {
            issueCertificateV2(
                $userId,
                'career_path',
                $careerPathId,
                (int)$typeRow['id'],
                $careerPath['certificate_template_id'] ? (int)$careerPath['certificate_template_id'] : null,
                null,
                ['certificate_title' => $careerPath['name'], 'career_path_name' => $careerPath['name']]
            );
        }
        if (!empty($careerPath['completion_badge_id'])) {
            $db->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?,?)")
               ->execute([$userId, (int)$careerPath['completion_badge_id']]);
        }
    }

    return getSpecializationRecommendations($careerPathId);
}

// ============================================================================
// ONBOARDING STATE (Welcome Modal — first Learn visit only)
// ============================================================================

function hasSeenLearnOnboarding(int $userId): bool {
    $stmt = getDB()->prepare("SELECT has_seen_learn_onboarding FROM user_onboarding_state WHERE user_id = ?");
    $stmt->execute([$userId]);
    $val = $stmt->fetchColumn();
    return $val !== false && (int)$val === 1;
}

function markLearnOnboardingSeen(int $userId, string $reason = 'completed'): void {
    $db = getDB();
    $completedAt = $reason === 'completed' ? 'NOW()' : 'NULL';
    $skippedAt   = $reason === 'skipped' ? 'NOW()' : 'NULL';
    $db->prepare("
        INSERT INTO user_onboarding_state (user_id, has_seen_learn_onboarding, skipped_at, completed_at)
        VALUES (?, 1, " . ($reason === 'skipped' ? 'NOW()' : 'NULL') . ", " . ($reason === 'completed' ? 'NOW()' : 'NULL') . ")
        ON DUPLICATE KEY UPDATE has_seen_learn_onboarding = 1,
            skipped_at = IF(VALUES(skipped_at) IS NOT NULL, VALUES(skipped_at), skipped_at),
            completed_at = IF(VALUES(completed_at) IS NOT NULL, VALUES(completed_at), completed_at)
    ")->execute([$userId]);
}

// ============================================================================
// CAREER PATH BUILDER helpers (admin) — attach/remove/reorder/rules
// ============================================================================

/** Guides available to attach: everything not already in this career path. */
function getAttachableGuides(int $careerPathId): array {
    $stmt = getDB()->prepare("
        SELECT gp.id, gp.title, gp.difficulty,
               (SELECT COUNT(*) FROM guide_path_lessons WHERE guide_id = gp.id) AS lesson_count
        FROM guide_paths gp
        WHERE gp.deleted_at IS NULL
          AND gp.id NOT IN (SELECT guide_id FROM career_path_guides WHERE career_path_id = ?)
        ORDER BY gp.title ASC
    ");
    $stmt->execute([$careerPathId]);
    return $stmt->fetchAll();
}

function getCareerPathGuides(int $careerPathId): array {
    $stmt = getDB()->prepare("
        SELECT cpg.*, gp.title, gp.difficulty,
               (SELECT COUNT(*) FROM guide_path_lessons WHERE guide_id = gp.id) AS lesson_count
        FROM career_path_guides cpg
        INNER JOIN guide_paths gp ON gp.id = cpg.guide_id
        WHERE cpg.career_path_id = ?
        ORDER BY cpg.display_order ASC, cpg.id ASC
    ");
    $stmt->execute([$careerPathId]);
    return $stmt->fetchAll();
}

function getCareerPathMilestones(int $careerPathId): array {
    $stmt = getDB()->prepare("
        SELECT * FROM career_path_milestones WHERE career_path_id = ? ORDER BY display_order ASC, id ASC
    ");
    $stmt->execute([$careerPathId]);
    return $stmt->fetchAll();
}
