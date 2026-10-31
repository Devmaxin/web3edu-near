<?php
/**
 * Web3edu — AJAX: Complete Lesson
 * POST /api/complete_lesson.php  { lesson_id: int }
 * Returns JSON { success, already_done, new_badges: [], quiz_unlock: null|{...} }
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/gamification.php';
require_once __DIR__ . '/../includes/career_paths.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$lessonId = intval($_POST['lesson_id'] ?? 0);
// Optional: guide context — returns per-guide progress in response
$guideId  = intval($_POST['guide_id']  ?? 0);

if (!$lessonId) {
    echo json_encode(['success' => false, 'error' => 'Invalid lesson']);
    exit;
}

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// Verify lesson exists
$chk = $db->prepare("SELECT id FROM lessons WHERE id = ?");
$chk->execute([$lessonId]);
if (!$chk->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Lesson not found']);
    exit;
}

$isNew     = markLessonComplete($lessonId);
$xpAwarded = 0;
$newBadges = [];

if ($isNew) {
    Analytics::track('lesson_completed', $userId, ['lesson_id' => $lessonId, 'guide_id' => $guideId ?: null]);

    $xpOverride = getLessonXpOverride($lessonId, $guideId);
    $xpAwarded = awardXp($userId, 'lesson_complete', 'lesson', $lessonId, $xpOverride);
    updateStreak($userId);
    checkGuideCompletion($userId, $lessonId);

    $newBadges = evaluateAndAwardBadges();
    foreach ($newBadges as $badge) {
        awardXp($userId, 'badge_earned', 'badge', (int)$badge['id'], (int)($badge['xp_reward'] ?? 0) ?: null);
        notifyUser($userId, 'badge_earned', 'Badge earned: ' . $badge['name'], $badge['description'] ?? '', SITE_URL . '/dashboard.php');
    }
}

// ── Quiz unlock check (only after a NEW completion) ──────────────────────────
$quizUnlock = null;
if ($isNew) {
    $quizUnlock = checkQuizUnlock($lessonId, $userId, $db);
}

// ── Guide progress (returned when request comes from a guide lesson page) ──
$guideProgress = null;
if ($guideId > 0) {
    require_once __DIR__ . '/../includes/gamification.php';
    $guideProgress = getGuideProgress($guideId);
}

// ── Career Path completion + Foundation -> specialization hand-off (§6) ────
$specializationRecommendations = [];
$careerPathJustCompleted = false;
if ($isNew) {
    $activeCareerPath = getActiveUserCareerPath($userId);
    if ($activeCareerPath) {
        $recs = checkAndCompleteCareerPath($userId, (int)$activeCareerPath['career_path_id']);
        if (!empty($recs)) {
            $careerPathJustCompleted = true;
            $specializationRecommendations = array_map(fn($p) => [
                'id' => (int)$p['id'], 'name' => $p['name'], 'icon' => $p['icon'],
                'description' => $p['description'], 'theme_color' => $p['theme_color'],
            ], $recs);
        }
    }
}

// ── Milestone evaluation (Phase 3) — runs after lessons/badges/career-path
// above have all been updated, so any milestone requirement referencing
// them sees current data. No-op on every call except the one where a
// milestone's requirements first become fully satisfied.
$newMilestones = [];
if ($isNew) {
    $newMilestones = evaluateMilestones($userId);
}

echo json_encode([
    'success'        => true,
    'already_done'   => !$isNew,
    'xp_awarded'     => $xpAwarded,
    'new_badges'     => array_values(array_map(fn($b) => [
        'id'   => $b['id'],
        'name' => $b['name'],
        'icon' => $b['icon'],
        'desc' => $b['description'],
    ], $newBadges)),
    'quiz_unlock'                     => $quizUnlock,
    'guide_progress'                  => $guideProgress,
    'career_path_completed'          => $careerPathJustCompleted,
    'specialization_recommendations' => $specializationRecommendations,
    'new_milestones'                 => array_values(array_map(fn($m) => [
        'id'          => (int)$m['id'],
        'name'        => $m['name'],
        'description' => $m['description'],
        'icon'        => $m['icon'],
        'point_reward'=> (int)$m['point_reward'],
        'badge'       => $m['badge_awarded'] ? ['name' => $m['badge_awarded']['name'], 'icon' => $m['badge_awarded']['icon']] : null,
    ], $newMilestones)),
]);

/**
 * Check if completing $lessonId unlocks a quiz for this user.
 * Returns quiz data array or null.
 */
function checkQuizUnlock(int $lessonId, int $userId, PDO $db): ?array {
    // Find all guide paths containing this lesson
    $guideCheck = $db->prepare("SELECT guide_id FROM guide_path_lessons WHERE lesson_id = ?");
    $guideCheck->execute([$lessonId]);
    $lessonGuideIds = array_column($guideCheck->fetchAll(), 'guide_id');

    foreach ($lessonGuideIds as $gid) {
        $gTotal = $db->prepare("SELECT COUNT(*) FROM guide_path_lessons WHERE guide_id=?");
        $gTotal->execute([$gid]);
        $gTotal = (int)$gTotal->fetchColumn();

        $gDone = $db->prepare("
            SELECT COUNT(*) FROM guide_path_lessons gpl
            INNER JOIN user_lesson_progress ulp ON ulp.lesson_id=gpl.lesson_id AND ulp.user_id=?
            WHERE gpl.guide_id=?
        ");
        $gDone->execute([$userId, $gid]);
        $gDone = (int)$gDone->fetchColumn();

        // gDone already includes the lesson just marked (markLessonComplete ran first)
        if ($gDone >= $gTotal) {
            // All guide lessons done — find unshown, active, unpassed quiz
            $qCheck = $db->prepare("
                SELECT q.*, b.name AS badge_name, b.icon AS badge_icon
                FROM quizzes q
                LEFT JOIN badges b ON b.id = q.badge_id
                WHERE q.guide_id=? AND q.quiz_type='quiz' AND q.is_active=1
                  AND q.id NOT IN (SELECT quiz_id FROM quiz_results WHERE user_id=? AND passed=1)
                  AND q.id NOT IN (SELECT quiz_id FROM quiz_unlock_log WHERE user_id=?)
                LIMIT 1
            ");
            $qCheck->execute([$gid, $userId, $userId]);
            $unlockQuiz = $qCheck->fetch();

            if ($unlockQuiz) {
                // Log it — so popup won't show again
                $logIns = $db->prepare("INSERT IGNORE INTO quiz_unlock_log (user_id, quiz_id) VALUES (?,?)");
                $logIns->execute([$userId, $unlockQuiz['id']]);

                // Load questions
                $qqStmt = $db->prepare("SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order ASC");
                $qqStmt->execute([$unlockQuiz['id']]);
                $questions = $qqStmt->fetchAll();

                if (empty($questions)) return null; // Quiz exists but no questions — skip

                return [
                    'id'          => (int)$unlockQuiz['id'],
                    'title'       => $unlockQuiz['title'],
                    'pass_percent'=> (int)$unlockQuiz['pass_percent'],
                    'badge_name'  => $unlockQuiz['badge_name'] ?? '',
                    'badge_icon'  => $unlockQuiz['badge_icon'] ?? '',
                    'guide_id'    => (int)$gid,
                    'questions'   => array_map(fn($q) => [
                        'id'       => (int)$q['id'],
                        'question' => $q['question'],
                        'a'        => $q['option_a'],
                        'b'        => $q['option_b'],
                        'c'        => $q['option_c'],
                        'd'        => $q['option_d'],
                    ], $questions),
                ];
            }
        }
    }

    // Also check quiz_required_lessons (standalone quiz requirements)
    $reqCheck = $db->prepare("
        SELECT q.*, b.name AS badge_name, b.icon AS badge_icon
        FROM quizzes q
        LEFT JOIN badges b ON b.id = q.badge_id
        WHERE q.is_active = 1 AND q.quiz_type IN ('quiz','checkpoint')
          AND q.id NOT IN (SELECT quiz_id FROM quiz_results WHERE user_id=? AND passed=1)
          AND q.id NOT IN (SELECT quiz_id FROM quiz_unlock_log WHERE user_id=?)
          AND q.id IN (
              SELECT DISTINCT qrl.quiz_id FROM quiz_required_lessons qrl
              WHERE qrl.quiz_id NOT IN (
                  -- quizzes where at least one required lesson is NOT done
                  SELECT qrl2.quiz_id FROM quiz_required_lessons qrl2
                  WHERE qrl2.lesson_id NOT IN (
                      SELECT lesson_id FROM user_lesson_progress WHERE user_id=?
                  )
              )
          )
        LIMIT 1
    ");
    $reqCheck->execute([$userId, $userId, $userId]);
    $reqQuiz = $reqCheck->fetch();

    if ($reqQuiz) {
        $logIns = $db->prepare("INSERT IGNORE INTO quiz_unlock_log (user_id, quiz_id) VALUES (?,?)");
        $logIns->execute([$userId, $reqQuiz['id']]);

        $qqStmt = $db->prepare("SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order ASC");
        $qqStmt->execute([$reqQuiz['id']]);
        $questions = $qqStmt->fetchAll();

        if (empty($questions)) return null;

        return [
            'id'          => (int)$reqQuiz['id'],
            'title'       => $reqQuiz['title'],
            'pass_percent'=> (int)$reqQuiz['pass_percent'],
            'badge_name'  => $reqQuiz['badge_name'] ?? '',
            'badge_icon'  => $reqQuiz['badge_icon'] ?? '',
            'guide_id'    => (int)($reqQuiz['guide_id'] ?? 0),
            'questions'   => array_map(fn($q) => [
                'id'       => (int)$q['id'],
                'question' => $q['question'],
                'a'        => $q['option_a'],
                'b'        => $q['option_b'],
                'c'        => $q['option_c'],
                'd'        => $q['option_d'],
            ], $questions),
        ];
    }

    return null;
}
