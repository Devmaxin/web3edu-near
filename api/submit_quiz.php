<?php
/**
 * Web3edu — AJAX: Submit Quiz
 * POST /api/submit_quiz.php  { quiz_id: int, answers: {q_id: 'a'|'b'|'c'|'d', ...} }
 * Returns JSON { success, score, passed, pass_percent, new_badge }
 *
 * Access control: user must have completed all required lessons for this quiz
 * (either via guide path or quiz_required_lessons table).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/gamification.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if ($input !== null) {
    $quizId  = intval($input['quiz_id'] ?? 0);
    $answers = $input['answers'] ?? [];
} else {
    $quizId  = intval($_POST['quiz_id'] ?? 0);
    $answers = $_POST['answers'] ?? [];
}

if (!$quizId || !is_array($answers)) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// Load quiz
$quizStmt = $db->prepare("SELECT * FROM quizzes WHERE id = ? AND is_active = 1");
$quizStmt->execute([$quizId]);
$quiz = $quizStmt->fetch();
if (!$quiz) {
    echo json_encode(['success' => false, 'error' => 'Quiz not found']);
    exit;
}

Analytics::track('quiz_started', $userId, ['quiz_id' => $quizId]);

// ── Backend access control: verify lesson requirements are met ────────────────
if (!isQuizUnlocked($quizId, $userId, $db)) {
    echo json_encode([
        'success' => false,
        'locked'  => true,
        'error'   => 'Complete the required lessons to unlock this quiz.',
    ]);
    exit;
}

// Check already taken
$takenStmt = $db->prepare("SELECT id, score, passed FROM quiz_results WHERE user_id = ? AND quiz_id = ?");
$takenStmt->execute([$userId, $quizId]);
$existingResult = $takenStmt->fetch();
if ($existingResult && $existingResult['passed']) {
    // Already passed — return current result (don't allow re-submission of passed quiz)
    echo json_encode([
        'success'      => true,
        'already_done' => true,
        'score'        => $existingResult['score'],
        'passed'       => true,
        'pass_percent' => $quiz['pass_percent'],
    ]);
    exit;
}

// Load questions
$qStmt = $db->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order ASC");
$qStmt->execute([$quizId]);
$questions = $qStmt->fetchAll();

if (empty($questions)) {
    echo json_encode(['success' => false, 'error' => 'This quiz has no questions yet.']);
    exit;
}

// Score it — grading strategy depends on question_type; multiple_choice /
// true_false / image (default, pre-Phase-2 behavior) all grade the exact
// same way they always did: a straight match against `correct`.
$correct = 0;
$wrongQuestions = [];
foreach ($questions as $q) {
    $qType = $q['question_type'] ?? 'multiple_choice';
    $submittedRaw = $answers[$q['id']] ?? '';
    $isCorrect = false;

    switch ($qType) {
        case 'multiple_select':
            $submittedSet = is_array($submittedRaw)
                ? $submittedRaw
                : array_filter(array_map('trim', explode(',', (string)$submittedRaw)));
            $submittedSet = array_unique(array_map('strtolower', $submittedSet));
            sort($submittedSet);
            $acceptedSet = array_map('strtolower', json_decode($q['correct_answers'] ?? '[]', true) ?: []);
            sort($acceptedSet);
            $isCorrect = !empty($acceptedSet) && $submittedSet === $acceptedSet;
            break;

        case 'short_answer':
        case 'code':
        case 'scenario':
            $submittedText = strtolower(trim(is_array($submittedRaw) ? '' : (string)$submittedRaw));
            if ($submittedText !== '') {
                foreach ((json_decode($q['correct_answers'] ?? '[]', true) ?: []) as $accepted) {
                    if (str_contains($submittedText, strtolower(trim((string)$accepted)))) { $isCorrect = true; break; }
                }
            }
            break;

        case 'true_false':
        case 'multiple_choice':
        case 'image':
        default:
            $submitted = strtolower(trim(is_array($submittedRaw) ? '' : (string)$submittedRaw));
            $isCorrect = $submitted === $q['correct'];
            break;
    }

    if ($isCorrect) {
        $correct++;
    } else {
        $correctDisplay = in_array($qType, ['multiple_choice','true_false','image'], true)
            ? ($q['option_' . $q['correct']] ?? $q['correct'])
            : implode(' or ', json_decode($q['correct_answers'] ?? '[]', true) ?: []);
        $submittedDisplay = is_array($submittedRaw) ? implode(', ', $submittedRaw) : (string)$submittedRaw;
        $wrongQuestions[] = [
            'question' => $q['question'],
            'correct_answer' => (string)$correctDisplay,
            'submitted_answer' => $submittedDisplay !== '' ? $submittedDisplay : '(no answer)',
        ];
    }
}

$total  = count($questions);
$score  = $total > 0 ? (int)round($correct / $total * 100) : 0;
$passed = $score >= $quiz['pass_percent'];

// Save result (allow retakes until passed)
$ins = $db->prepare("
    INSERT INTO quiz_results (user_id, quiz_id, score, passed)
    VALUES (?,?,?,?)
    ON DUPLICATE KEY UPDATE score=VALUES(score), passed=VALUES(passed), taken_at=CURRENT_TIMESTAMP
");
$ins->execute([$userId, $quizId, $score, $passed ? 1 : 0]);

Analytics::track('quiz_completed', $userId, ['quiz_id' => $quizId, 'score' => $score, 'passed' => $passed]);

// Award XP for passing, plus badge (with its own XP) if attached
$xpAwarded = 0;
$newBadge = null;
if ($passed) {
    $xpAwarded = awardXp($userId, 'quiz_pass', 'quiz', $quizId);

    // Certificate automation: quiz-score rules (Admin -> Certificates -> Rules),
    // plus a re-check of the parent guide's certificate (covers guides that
    // require_final_quiz or set a min_quiz_score — the guide may have already
    // hit 100% lesson completion earlier and was just waiting on this quiz).
    evaluateCertificateRules($userId, 'quiz_score', ['quiz_id' => $quizId, 'score' => $score, 'quiz_title' => $quiz['title']]);
    if (!empty($quiz['guide_id'])) {
        maybeIssueGuideCertificate($userId, (int)$quiz['guide_id']);
    }

    if (!empty($quiz['badge_id'])) {
        $awarded = awardBadge((int)$quiz['badge_id']);
        if ($awarded) {
            $bStmt = $db->prepare("SELECT * FROM badges WHERE id = ?");
            $bStmt->execute([$quiz['badge_id']]);
            $b = $bStmt->fetch();
            if ($b) {
                awardXp($userId, 'badge_earned', 'badge', (int)$b['id'], (int)($b['xp_reward'] ?? 0) ?: null);
                notifyUser($userId, 'badge_earned', 'Badge earned: ' . $b['name'], $b['description'] ?? '', SITE_URL . '/dashboard.php');
                $newBadge = [
                    'id'   => $b['id'],
                    'name' => $b['name'],
                    'icon' => $b['icon'],
                    'desc' => $b['description'],
                ];
            }
        }
    }
}

// AI Mentor: explain mistakes on a failed attempt. Best-effort — a slow or
// failed AI call must never block the (already-saved) quiz result.
$aiExplanation = null;
if (!$passed && !empty($wrongQuestions)) {
    require_once __DIR__ . '/../includes/ai/AIService.php';
    $membershipType = function_exists('userHasActivePremium') && userHasActivePremium($userId) ? 'premium' : 'free';
    $aiResult = AIService::explainQuizMistakes($wrongQuestions, $userId, $membershipType);
    if ($aiResult['success']) $aiExplanation = $aiResult['content'];
}

// ── Milestone evaluation (Phase 3) — only a pass can move a quiz_pass/
// quiz_count/badge_earned requirement, so this only runs on $passed
// (mirrors the guard around the XP/badge/certificate block above).
$newMilestones = [];
if ($passed) {
    $newMilestones = evaluateMilestones($userId);
}

echo json_encode([
    'success'      => true,
    'score'        => $score,
    'correct'      => $correct,
    'total'        => $total,
    'passed'       => $passed,
    'pass_percent' => $quiz['pass_percent'],
    'xp_awarded'   => $xpAwarded,
    'new_badge'    => $newBadge,
    'ai_explanation' => $aiExplanation,
    'new_milestones' => array_values(array_map(fn($m) => [
        'id'          => (int)$m['id'],
        'name'        => $m['name'],
        'description' => $m['description'],
        'icon'        => $m['icon'],
        'point_reward'=> (int)$m['point_reward'],
        'badge'       => $m['badge_awarded'] ? ['name' => $m['badge_awarded']['name'], 'icon' => $m['badge_awarded']['icon']] : null,
    ], $newMilestones)),
]);

/**
 * Returns true if user has completed all lessons required to take this quiz.
 * A quiz is unlocked when:
 *   (a) It has a guide_id AND the user has completed ALL lessons in that guide, OR
 *   (b) It has entries in quiz_required_lessons AND all those lessons are done, OR
 *   (c) It has neither (standalone quiz with no requirements).
 */
function isQuizUnlocked(int $quizId, int $userId, PDO $db): bool {
    // Load quiz
    $q = $db->prepare("SELECT guide_id FROM quizzes WHERE id = ?");
    $q->execute([$quizId]);
    $quiz = $q->fetch();
    if (!$quiz) return false;

    // Check guide-level requirements
    if (!empty($quiz['guide_id'])) {
        $gid = (int)$quiz['guide_id'];

        $totalStmt = $db->prepare("SELECT COUNT(*) FROM guide_path_lessons WHERE guide_id=?");
        $totalStmt->execute([$gid]);
        $total = (int)$totalStmt->fetchColumn();

        if ($total > 0) {
            $doneStmt = $db->prepare("
                SELECT COUNT(*) FROM guide_path_lessons gpl
                INNER JOIN user_lesson_progress ulp ON ulp.lesson_id=gpl.lesson_id AND ulp.user_id=?
                WHERE gpl.guide_id=?
            ");
            $doneStmt->execute([$userId, $gid]);
            $done = (int)$doneStmt->fetchColumn();

            if ($done < $total) return false; // Guide not fully done
        }
    }

    // Check quiz_required_lessons (additional/standalone requirements)
    $reqCountStmt = $db->prepare("SELECT COUNT(*) FROM quiz_required_lessons WHERE quiz_id=?");
    $reqCountStmt->execute([$quizId]);
    $reqCount = (int)$reqCountStmt->fetchColumn();

    if ($reqCount > 0) {
        $reqDoneStmt = $db->prepare("
            SELECT COUNT(*) FROM quiz_required_lessons qrl
            INNER JOIN user_lesson_progress ulp ON ulp.lesson_id=qrl.lesson_id AND ulp.user_id=?
            WHERE qrl.quiz_id=?
        ");
        $reqDoneStmt->execute([$userId, $quizId]);
        $reqDone = (int)$reqDoneStmt->fetchColumn();

        if ($reqDone < $reqCount) return false;
    }

    return true;
}
