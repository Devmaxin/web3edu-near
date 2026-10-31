<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/gamification.php';
require_once __DIR__ . '/includes/video_helpers.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/resources.php';

if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = SITE_URL . '/guide.php';
    setFlash('info', 'Sign in to access the Guide.');
    header('Location: ' . SITE_URL . '/login.php'); exit;
}
requireProfileComplete();

$extraJs = [SITE_URL . '/assets/js/bookmark.js'];

$db      = getDB();
$userId  = (int)$_SESSION['user_id'];

// Load active guide paths
$guides = $db->query("SELECT * FROM guide_paths WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Default to first guide
$activeGuideId = intval($_GET['guide'] ?? ($guides[0]['id'] ?? 0));

$activeGuide   = null;
$guideLessons  = [];
$guideProgress = ['completed'=>0,'total'=>0,'pct'=>0];
$guideQuiz     = null;
$guideAssessment = null;
$guideProjectsList = [];
$guideResources = [];
$userBadges    = getUserBadges();
$newBadgeJson  = 'null';

if ($activeGuideId) {
    $gStmt = $db->prepare("SELECT * FROM guide_paths WHERE id = ? AND is_active = 1");
    $gStmt->execute([$activeGuideId]);
    $activeGuide = $gStmt->fetch();

    if ($activeGuide) {
        // Ordered lessons in this path
        $lStmt = $db->prepare("
            SELECT l.*, gpl.sort_order,
                   (SELECT COUNT(*) FROM user_lesson_progress WHERE user_id=? AND lesson_id=l.id) AS is_done
            FROM guide_path_lessons gpl
            INNER JOIN lessons l ON l.id = gpl.lesson_id
            WHERE gpl.guide_id = ?
            ORDER BY gpl.sort_order ASC
        ");
        $lStmt->execute([$userId, $activeGuideId]);
        $guideLessons = $lStmt->fetchAll();

        $guideProgress = getGuideProgress($activeGuideId);

        // Quiz attached to this guide
        $qStmt = $db->prepare("
            SELECT q.*, b.name AS badge_name, b.icon AS badge_icon
            FROM quizzes q
            LEFT JOIN badges b ON b.id = q.badge_id
            WHERE q.guide_id = ? AND q.quiz_type = 'quiz' AND q.is_active = 1
            LIMIT 1
        ");
        $qStmt->execute([$activeGuideId]);
        $guideQuiz = $qStmt->fetch() ?: null;

        // Check if quiz already passed
        if ($guideQuiz) {
            $qrStmt = $db->prepare("SELECT passed FROM quiz_results WHERE user_id=? AND quiz_id=?");
            $qrStmt->execute([$userId, $guideQuiz['id']]);
            $qResult = $qrStmt->fetch();
            $guideQuiz['already_passed'] = $qResult && $qResult['passed'];

            // Load questions if not already passed
            if (!$guideQuiz['already_passed']) {
                $qqStmt = $db->prepare("SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order ASC");
                $qqStmt->execute([$guideQuiz['id']]);
                $guideQuiz['questions'] = $qqStmt->fetchAll();
            }
        }

        // Phase 2: this guide's Final Assessment, attached Projects (with
        // this user's submission status), and attached Resources.
        $aStmt = $db->prepare("SELECT * FROM quizzes WHERE guide_id=? AND quiz_type='assessment' AND is_active=1 LIMIT 1");
        $aStmt->execute([$activeGuideId]);
        $guideAssessment = $aStmt->fetch() ?: null;
        if ($guideAssessment) {
            $arStmt = $db->prepare("SELECT passed FROM quiz_results WHERE user_id=? AND quiz_id=?");
            $arStmt->execute([$userId, $guideAssessment['id']]);
            $ar = $arStmt->fetch();
            $guideAssessment['already_passed'] = $ar && $ar['passed'];
        }

        $guideProjectsList = getGuideProjects($activeGuideId);
        foreach ($guideProjectsList as &$gpRow) {
            $gpRow['submission_status'] = getUserSubmissionStatus($userId, (int)$gpRow['project_id'], $activeGuideId);
        }
        unset($gpRow);

        $guideResources = getLinkedResources('guide_path', $activeGuideId);
    }
}

// Detect if a new badge was just earned (from session flash set by lesson page)
if (!empty($_SESSION['new_badge'])) {
    $newBadgeJson = json_encode($_SESSION['new_badge']);
    unset($_SESSION['new_badge']);
}

$pageTitle = 'Guide — ' . ($activeGuide['title'] ?? 'Learning Paths');
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Guide page scoped styles — no global overrides */
.guide-wrap { padding-top: 100px; }
.guide-layout { display: grid; grid-template-columns: 260px 1fr; gap: 28px; }
.guide-sidebar { position: sticky; top: 100px; height: fit-content; }

/* Progress bar */
.gp-bar-wrap {
    background: var(--border);
    border-radius: 99px;
    height: 8px;
    overflow: hidden;
    margin: 8px 0 4px;
}
.gp-bar-fill {
    height: 100%;
    border-radius: 99px;
    background: linear-gradient(90deg, var(--primary), var(--cyan));
    transition: width 0.5s ease;
}

/* Lesson step row */
.guide-step {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 14px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    background: var(--bg-card);
    transition: border-color 0.2s;
    position: relative;
}
.guide-step.done  { border-color: rgba(34,197,94,0.35); }
.guide-step.locked-step { opacity: 0.55; }
.guide-step:not(.locked-step):hover { border-color: var(--border-hov); }
.step-num {
    width: 28px; height: 28px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.72rem; font-weight: 700;
    flex-shrink: 0;
}
.step-num.done-num { background: rgba(34,197,94,0.15); color: #86efac; }
.step-num.todo-num { background: var(--bg-card2); color: var(--text-muted); border: 1px solid var(--border); }

/* Badge strip */
.badge-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}
.badge-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 600;
    background: rgba(168,85,247,0.12);
    border: 1px solid rgba(168,85,247,0.3);
    color: #d8b4fe;
}

/* Quiz card */
.quiz-card {
    background: var(--bg-card);
    border: 1.5px solid rgba(139,92,246,0.3);
    border-radius: var(--radius-lg);
    padding: 24px;
    margin-top: 24px;
}
.quiz-question { margin-bottom: 20px; }
.quiz-opts label {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    cursor: pointer;
    margin-bottom: 8px;
    font-size: 0.875rem;
    transition: border-color 0.15s, background 0.15s;
}
.quiz-opts label:hover { border-color: var(--primary); background: var(--glow-soft); }
.quiz-opts input[type=radio] { accent-color: var(--primary); flex-shrink: 0; }

/* Badge modal */
.badge-modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.75);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.badge-modal-overlay.show { display: flex; }
.badge-modal-box {
    background: var(--bg-card);
    border: 1.5px solid rgba(168,85,247,0.4);
    border-radius: var(--radius-lg);
    padding: 40px 32px;
    text-align: center;
    max-width: 340px;
    width: 90%;
    box-shadow: 0 0 60px rgba(139,92,246,0.25);
    animation: badgePop 0.4s cubic-bezier(0.34,1.56,0.64,1);
}
@keyframes badgePop { from { transform: scale(0.6); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.badge-icon-big { font-size: 3.5rem; display: block; margin-bottom: 14px; animation: spin 0.6s ease; }
@keyframes spin { from { transform: rotate(-180deg) scale(0.5); } to { transform: rotate(0deg) scale(1); } }

/* Confetti */
.confetti-piece {
    position: fixed;
    width: 8px; height: 8px;
    border-radius: 2px;
    pointer-events: none;
    z-index: 10000;
    animation: confettiFall linear forwards;
}
@keyframes confettiFall {
    0%   { transform: translateY(-10px) rotate(0deg); opacity: 1; }
    100% { transform: translateY(110vh) rotate(720deg); opacity: 0; }
}

@media (max-width: 800px) {
    .guide-layout { grid-template-columns: 1fr; }
    .guide-sidebar { position: static; }
    .guide-wrap { padding-top: 80px; }
    /* Prevent step rows from overflowing on narrow screens */
    .guide-step { flex-wrap: wrap; gap: 8px; }
    .guide-step > div[style*="flex:1"] { min-width: 0; width: 100%; }
    .guide-step > div[style*="flex-shrink:0"] { margin-left: auto; }
    /* Badge chips wrap */
    .badge-strip { gap: 6px; }
    .badge-chip  { font-size: 0.72rem; padding: 4px 10px; }
    /* Quiz card */
    .quiz-card { padding: 16px; }
    /* Guide tab pills wrap */
    .guide-tab-pills { flex-wrap: wrap !important; }
}

@media (max-width: 480px) {
    .guide-wrap { padding-top: 74px; }
    /* Step action buttons full width */
    .guide-step-action { width: 100% !important; }
    .guide-step-action a { width: 100%; justify-content: center; }
    /* Quiz options text wrap */
    .quiz-opts label { align-items: flex-start; }
}

/* ══════════════════════════════════════════════════════════════
   STEP ROW: button drops below text at ≤ 470px
   ══════════════════════════════════════════════════════════════ */
@media (max-width: 470px) {

    /* Stack the step row so button sits under the text block */
    .guide-step {
        flex-wrap: wrap;
        align-items: flex-start;
    }

    /* Text block takes full row width */
    .guide-step > div[style*="flex:1"] {
        flex: 1 1 0;
        min-width: 0;
    }

    /* Button wrapper drops to its own row, left-aligned */
    .guide-step > div[style*="flex-shrink:0"] {
        width: 100%;
        margin-left: 40px; /* align under the text, past the step-num circle */
        flex-shrink: unset;
    }

    .guide-step > div[style*="flex-shrink:0"] > a {
        display: inline-flex;
    }
}
</style>

<!-- Badge Modal -->
<div class="badge-modal-overlay" id="badgeModal" role="dialog" aria-modal="true">
    <div class="badge-modal-box">
        <span class="badge-icon-big" id="badgeModalIcon">🏆</span>
        <p style="color:var(--primary-bright);font-size:0.78rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:6px">You Earned a Badge!</p>
        <h2 id="badgeModalName" style="font-size:1.4rem;font-weight:800;margin-bottom:8px"></h2>
        <p id="badgeModalDesc" style="color:var(--text-muted);font-size:0.875rem;margin-bottom:24px"></p>
        <button onclick="closeBadgeModal()" class="btn-primary" style="width:100%">Awesome! 🎉</button>
    </div>
</div>

<div class="guide-wrap">
  <div class="container">

    <?php if (empty($guides)): ?>
    <div style="text-align:center;padding:80px 20px">
        <p style="color:var(--text-muted);font-size:1rem">No guide paths are available yet. Check back soon!</p>
    </div>
    <?php else: ?>

    <div style="margin-bottom:28px">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
          <div style="flex:1;min-width:240px">
            <h1 class="gradient-text" style="font-size:clamp(1.4rem,4vw,2rem);font-weight:800;margin-bottom:6px">
                <?= sanitize($activeGuide['title'] ?? 'Guide') ?>
            </h1>
            <?php if (!empty($activeGuide['description'])): ?>
            <p style="color:var(--text-muted);font-size:0.9rem"><?= sanitize($activeGuide['description']) ?></p>
            <?php endif; ?>
          </div>
          <?php if ($activeGuide): ?>
          <button class="bookmark-btn <?= isBookmarked('guide_path', (int)$activeGuideId) ? 'active' : '' ?>"
                  data-type="guide_path" data-id="<?= (int)$activeGuideId ?>"
                  aria-pressed="<?= isBookmarked('guide_path', (int)$activeGuideId) ? 'true' : 'false' ?>"
                  title="<?= isBookmarked('guide_path', (int)$activeGuideId) ? 'Remove bookmark' : 'Save for later' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          </button>
          <?php endif; ?>
        </div>

        <?php if (count($guides) > 1): ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px">
            <?php foreach ($guides as $g): ?>
            <a href="<?= SITE_URL ?>/guide.php?guide=<?= $g['id'] ?>"
               style="padding:5px 14px;border-radius:20px;font-size:0.8rem;font-weight:600;border:1px solid var(--border);
                      <?= $g['id'] == $activeGuideId ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : 'color:var(--text-muted)' ?>">
                <?= sanitize($g['title']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="guide-layout">

      <!-- SIDEBAR -->
      <aside class="guide-sidebar">
        <!-- Progress -->
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:20px">
            <div style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:10px">Your Progress</div>
            <div class="gp-bar-wrap">
                <div class="gp-bar-fill" style="width:<?= $guideProgress['pct'] ?>%"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:0.8rem;color:var(--text-muted)">
                <span><?= $guideProgress['completed'] ?> / <?= $guideProgress['total'] ?> lessons</span>
                <span style="font-weight:700;color:var(--primary-bright)"><?= $guideProgress['pct'] ?>%</span>
            </div>
        </div>

        <!-- Badges earned -->
        <?php if (!empty($userBadges)): ?>
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px">
            <div style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:10px">Your Badges</div>
            <div class="badge-strip">
                <?php foreach ($userBadges as $b): ?>
                <span class="badge-chip" title="<?= sanitize($b['description']) ?>">
                    <?= htmlspecialchars($b['icon']) ?> <?= sanitize($b['name']) ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
      </aside>

      <!-- MAIN -->
      <main>
        <!-- Lesson steps -->
        <?php if (empty($guideLessons)): ?>
        <p style="color:var(--text-muted)">No lessons assigned to this guide path yet.</p>
        <?php else: ?>

        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:32px">
        <?php
        $prevDone = true; // first lesson is always unlocked
        foreach ($guideLessons as $idx => $lesson):
            $done     = (bool)$lesson['is_done'];
            $isLocked = $lesson['access_level'] === 'premium' && !isPremium();
            $stepNum  = $idx + 1;
            // Pretty URL — handled by guides/.htaccess rewrite
            $lessonUrl       = guideLessonUrl($activeGuideId, $lesson['id']);
            // Direct fallback — works even if mod_rewrite is disabled on the host
            $lessonDirectUrl = guideLessonDirectUrl($activeGuideId, $lesson['id']);
        ?>
        <div class="guide-step <?= $done ? 'done' : '' ?>">
            <span class="step-num <?= $done ? 'done-num' : 'todo-num' ?>">
                <?php if ($done): ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                <?= $stepNum ?>
                <?php endif; ?>
            </span>

            <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:0.875rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <?= sanitize($lesson['title']) ?>
                </div>
                <div style="font-size:0.76rem;color:var(--text-muted)">
                    <?= sanitize($lesson['category']) ?> · <?= sanitize($lesson['duration']) ?>
                    <?= $done ? ' <span style="color:#86efac;font-weight:700">✓ Done</span>' : '' ?>
                </div>
            </div>

            <div style="flex-shrink:0">
                <?php if ($isLocked): ?>
                <a href="<?= SITE_URL ?>/upgrade.php" class="btn-upgrade" style="padding:6px 12px;font-size:0.76rem;min-height:unset">
                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Unlock
                </a>
                <?php elseif ($done): ?>
                <a href="<?= $lessonUrl ?>"
                   data-fallback="<?= $lessonDirectUrl ?>"
                   class="btn-ghost guide-lesson-link"
                   style="padding:6px 12px;font-size:0.76rem;min-height:unset">Review</a>
                <?php else: ?>
                <a href="<?= $lessonUrl ?>"
                   data-fallback="<?= $lessonDirectUrl ?>"
                   class="btn-primary guide-lesson-link"
                   style="padding:6px 14px;font-size:0.76rem;min-height:unset">Start →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <?php endif; // lessons ?>

        <!-- Quiz -->
        <?php if ($guideQuiz): ?>
        <?php if ($guideQuiz['already_passed']): ?>
        <!-- Passed state -->
        <div class="quiz-card" style="border-color:rgba(34,197,94,0.3)">
            <div style="display:flex;align-items:center;gap:12px">
                <div style="width:40px;height:40px;border-radius:50%;background:rgba(34,197,94,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#86efac" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div>
                    <div style="font-weight:700;font-size:0.95rem;color:#86efac">Quiz Passed ✓</div>
                    <div style="font-size:0.8rem;color:var(--text-muted)"><?= sanitize($guideQuiz['title']) ?><?= $guideQuiz['badge_name'] ? ' · ' . htmlspecialchars($guideQuiz['badge_icon']) . ' ' . sanitize($guideQuiz['badge_name']) : '' ?></div>
                </div>
            </div>
        </div>

        <?php elseif ($guideProgress['pct'] < 100): ?>
        <!-- Locked — lessons not done -->
        <div class="quiz-card" style="border-style:dashed;border-color:rgba(107,114,128,0.3)">
            <div style="display:flex;align-items:center;gap:12px;opacity:0.7">
                <div style="width:40px;height:40px;border-radius:50%;background:var(--bg-card2);display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid var(--border)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <div>
                    <div style="font-weight:700;font-size:0.9rem">🎯 <?= sanitize($guideQuiz['title']) ?></div>
                    <div style="font-size:0.78rem;color:var(--text-muted)">Complete all <?= $guideProgress['total'] ?> lessons to unlock this quiz<?= $guideQuiz['badge_name'] ? ' and earn the ' . sanitize($guideQuiz['badge_name']) . ' badge' : '' ?>.</div>
                </div>
            </div>
            <div style="margin-top:12px">
                <div style="background:var(--border);border-radius:99px;height:4px;overflow:hidden">
                    <div style="width:<?= $guideProgress['pct'] ?>%;height:100%;border-radius:99px;background:var(--primary);transition:width 0.5s"></div>
                </div>
                <div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px"><?= $guideProgress['completed'] ?>/<?= $guideProgress['total'] ?> lessons done</div>
            </div>
        </div>

        <?php else: ?>
        <!-- UNLOCKED — all lessons done -->
        <div class="quiz-card" style="border-color:rgba(168,85,247,0.5);background:linear-gradient(135deg,rgba(139,92,246,0.06),rgba(168,85,247,0.03))">
            <!-- Unlocked header -->
            <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:20px">
                <div style="width:44px;height:44px;border-radius:50%;background:rgba(168,85,247,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid rgba(168,85,247,0.3);font-size:1.2rem">🎯</div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--primary-bright);margin-bottom:4px">Quiz Unlocked</div>
                    <h3 style="font-size:1rem;font-weight:800;margin-bottom:4px;line-height:1.3"><?= sanitize($guideQuiz['title']) ?></h3>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin:0;line-height:1.4">
                        Pass mark: <strong style="color:var(--primary-bright)"><?= $guideQuiz['pass_percent'] ?>%</strong>
                        <?php if (!empty($guideQuiz['badge_name'])): ?>
                        · Earn: <strong style="color:#d8b4fe"><?= htmlspecialchars($guideQuiz['badge_icon']) ?> <?= sanitize($guideQuiz['badge_name']) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Quiz questions -->
            <div id="quizQuestionsWrap">
            <?php foreach (($guideQuiz['questions'] ?? []) as $qi => $q): ?>
            <?php $qType = $q['question_type'] ?? 'multiple_choice'; ?>
            <div class="quiz-question" data-qid="<?= $q['id'] ?>" data-qtype="<?= $qType ?>">
                <p style="font-weight:700;font-size:0.875rem;margin-bottom:10px;line-height:1.4"><?= ($qi+1) ?>. <?= sanitize($q['question']) ?></p>

                <?php if ($qType === 'image' && !empty($q['image_url'])): ?>
                <img src="<?= sanitize($q['image_url']) ?>" alt="" style="max-width:100%;border-radius:var(--radius);margin-bottom:10px">
                <?php endif; ?>
                <?php if ($qType === 'code' && !empty($q['code_snippet'])): ?>
                <pre style="background:var(--bg-card2);border:1px solid var(--border);border-radius:var(--radius);padding:12px;font-size:0.8rem;overflow-x:auto;margin-bottom:10px;font-family:var(--font-mono)"><?= sanitize($q['code_snippet']) ?></pre>
                <?php endif; ?>

                <?php if (in_array($qType, ['multiple_choice', 'true_false', 'image'], true)): ?>
                <div class="quiz-opts">
                    <?php foreach (['a','b','c','d'] as $opt): ?>
                    <?php if (empty($q['option_' . $opt])) continue; ?>
                    <label>
                        <input type="radio" name="q_<?= $q['id'] ?>" value="<?= $opt ?>">
                        <span><?= strtoupper($opt) ?>. <?= sanitize($q['option_' . $opt]) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <?php elseif ($qType === 'multiple_select'): ?>
                <div class="quiz-opts">
                    <?php foreach (['a','b','c','d'] as $opt): ?>
                    <?php if (empty($q['option_' . $opt])) continue; ?>
                    <label>
                        <input type="checkbox" name="q_<?= $q['id'] ?>_multi" value="<?= $opt ?>">
                        <span><?= strtoupper($opt) ?>. <?= sanitize($q['option_' . $opt]) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p style="font-size:0.72rem;color:var(--text-muted);margin-top:6px">Select all that apply.</p>

                <?php else: // short_answer, code, scenario ?>
                <textarea name="q_<?= $q['id'] ?>_text" class="form-input" rows="<?= $qType === 'code' ? 4 : 2 ?>"
                          placeholder="Type your answer…" style="<?= $qType === 'code' ? 'font-family:var(--font-mono);' : '' ?>"></textarea>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>

            <div id="quizResult" style="display:none;margin-bottom:14px;padding:13px 14px;border-radius:var(--radius);font-size:0.875rem;font-weight:600;line-height:1.4"></div>

            <div id="quizBtnRow" style="display:flex;gap:10px;flex-wrap:wrap">
                <button onclick="submitQuiz(<?= $guideQuiz['id'] ?>)" class="btn-primary" id="quizSubmitBtn" style="flex:1;min-width:140px;justify-content:center">
                    Submit Quiz
                </button>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Phase 2: Projects attached to this guide -->
        <?php foreach ($guideProjectsList as $proj): ?>
        <?php
            $pStatus = $proj['submission_status'];
            $pApproved = $pStatus === 'approved';
            $pBorder = $pApproved ? 'rgba(34,197,94,0.3)' : 'rgba(107,114,128,0.3)';
        ?>
        <div class="quiz-card" style="border-color:<?= $pBorder ?>;<?= $pApproved ? '' : 'border-style:dashed;' ?>margin-top:14px">
            <div style="display:flex;align-items:center;gap:12px">
                <div style="width:40px;height:40px;border-radius:50%;background:<?= $pApproved ? 'rgba(34,197,94,0.15)' : 'var(--bg-card2)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem"><?= $pApproved ? '✓' : '🛠️' ?></div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:0.9rem;<?= $pApproved ? 'color:#86efac' : '' ?>"><?= sanitize($proj['title']) ?><?= $proj['requirement']==='optional' ? ' <span style="font-weight:400;color:var(--text-muted);font-size:0.75rem">(optional)</span>' : '' ?></div>
                    <div style="font-size:0.78rem;color:var(--text-muted)">
                        <?php if ($pStatus === null): ?>Not started
                        <?php elseif ($pStatus === 'draft'): ?>Draft saved
                        <?php elseif ($pStatus === 'submitted'): ?>Awaiting review
                        <?php elseif ($pStatus === 'needs_revision'): ?>Needs revision — see feedback
                        <?php elseif ($pStatus === 'approved'): ?>Approved ✓
                        <?php elseif ($pStatus === 'rejected'): ?>Rejected — see feedback
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="bookmark-btn <?= isBookmarked('project', (int)$proj['project_id']) ? 'active' : '' ?>"
                        data-type="project" data-id="<?= (int)$proj['project_id'] ?>"
                        aria-pressed="<?= isBookmarked('project', (int)$proj['project_id']) ? 'true' : 'false' ?>"
                        title="Bookmark this project" style="flex-shrink:0"></button>
            </div>
            <?php if (!$pApproved): ?>
            <div style="margin-top:12px">
                <a href="<?= SITE_URL ?>/projects/submit.php?project_id=<?= (int)$proj['project_id'] ?>&guide_id=<?= (int)$activeGuideId ?>" class="btn-primary btn-sm" style="width:100%;justify-content:center">
                    <?= $pStatus ? 'Continue Project' : 'Start Project' ?>
                </a>
            </div>
            <?php else: ?>
            <div style="margin-top:10px"><a href="<?= SITE_URL ?>/portfolio.php" style="font-size:0.78rem;color:var(--primary-bright)">View in Portfolio →</a></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Phase 2: Final Assessment -->
        <?php if ($guideAssessment): ?>
        <div class="quiz-card" style="margin-top:14px;<?= $guideAssessment['already_passed'] ? 'border-color:rgba(34,197,94,0.3)' : '' ?>">
            <div style="display:flex;align-items:center;gap:12px">
                <div style="width:40px;height:40px;border-radius:50%;background:<?= $guideAssessment['already_passed'] ? 'rgba(34,197,94,0.15)' : 'var(--bg-card2)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem"><?= $guideAssessment['already_passed'] ? '✓' : '📝' ?></div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:0.9rem;<?= $guideAssessment['already_passed'] ? 'color:#86efac' : '' ?>">Final Assessment: <?= sanitize($guideAssessment['title']) ?></div>
                    <div style="font-size:0.78rem;color:var(--text-muted)"><?= $guideAssessment['already_passed'] ? 'Passed ✓' : 'Pass mark: ' . (int)$guideAssessment['pass_percent'] . '%' ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Phase 2: Resources -->
        <?php if (!empty($guideResources)): ?>
        <div class="quiz-card" style="margin-top:14px;border-style:solid">
            <div style="font-weight:700;font-size:0.85rem;margin-bottom:10px">📚 Resources</div>
            <div style="display:flex;flex-direction:column;gap:8px">
            <?php foreach ($guideResources as $res): ?>
                <div style="display:flex;align-items:center;gap:6px">
                <a href="<?= sanitize($res['url']) ?>" target="_blank" rel="noopener"
                   onclick="fetch(window.SITE_URL+'/api/resource-view.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'resource_id=<?= (int)$res['id'] ?>'})"
                   style="font-size:0.82rem;color:var(--text-muted);display:flex;align-items:center;gap:8px;flex:1">
                    <span><?= ['documentation'=>'📄','github'=>'💻','article'=>'📰','youtube'=>'▶️','download'=>'⬇️','tool'=>'🔧','template'=>'📋'][$res['resource_type']] ?? '🔗' ?></span>
                    <span><?= sanitize($res['title']) ?></span>
                </a>
                <button type="button" class="bookmark-btn <?= isBookmarked('resource', (int)$res['id']) ? 'active' : '' ?>"
                        data-type="resource" data-id="<?= (int)$res['id'] ?>"
                        aria-pressed="<?= isBookmarked('resource', (int)$res['id']) ? 'true' : 'false' ?>"
                        title="Bookmark this resource" style="flex-shrink:0;transform:scale(0.75)"></button>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

      </main>
    </div><!-- /guide-layout -->

    <?php endif; // guides exist ?>
  </div>
</div>

<script>
// ── Badge modal ────────────────────────────────────────────────────────────────
function showBadgeModal(icon, name, desc) {
    document.getElementById('badgeModalIcon').textContent = icon || '🏆';
    document.getElementById('badgeModalName').textContent = name;
    document.getElementById('badgeModalDesc').textContent = desc;
    document.getElementById('badgeModal').classList.add('show');
    launchConfetti();
}
function closeBadgeModal() {
    document.getElementById('badgeModal').classList.remove('show');
}

// ── Confetti ──────────────────────────────────────────────────────────────────
function launchConfetti() {
    const colors = ['#a855f7','#22d3ee','#86efac','#fbbf24','#f87171'];
    for (let i = 0; i < 50; i++) {
        setTimeout(() => {
            const el = document.createElement('div');
            el.className = 'confetti-piece';
            el.style.cssText = `
                left:${Math.random()*100}vw;
                top:-10px;
                background:${colors[Math.floor(Math.random()*colors.length)]};
                animation-duration:${1.5+Math.random()*2}s;
                animation-delay:${Math.random()*0.5}s;
                width:${6+Math.random()*6}px;
                height:${6+Math.random()*6}px;
                border-radius:${Math.random()>0.5?'50%':'2px'};
            `;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 4000);
        }, i * 30);
    }
}

// ── Auto-show badge if earned via session ─────────────────────────────────────
const _newBadge = <?= $newBadgeJson ?>;
if (_newBadge) {
    window.addEventListener('DOMContentLoaded', () =>
        setTimeout(() => showBadgeModal(_newBadge.icon, _newBadge.name, _newBadge.desc), 600)
    );
}

// ── Quiz submission ────────────────────────────────────────────────────────────
function submitQuiz(quizId) {
    const btn = document.getElementById('quizSubmitBtn');
    const resultEl = document.getElementById('quizResult');
    const btnRow   = document.getElementById('quizBtnRow');

    // Collect answers — format depends on each question's type.
    const answers = {};
    const questionBlocks = document.querySelectorAll('#quizQuestionsWrap .quiz-question');
    let unanswered = 0;

    questionBlocks.forEach(block => {
        const qid = block.dataset.qid;
        const qtype = block.dataset.qtype;

        if (qtype === 'multiple_select') {
            const checked = Array.from(block.querySelectorAll('input[type=checkbox]:checked')).map(i => i.value);
            if (checked.length === 0) { unanswered++; return; }
            answers[qid] = checked;
        } else if (qtype === 'short_answer' || qtype === 'code' || qtype === 'scenario') {
            const val = block.querySelector('textarea')?.value.trim() || '';
            if (val === '') { unanswered++; return; }
            answers[qid] = val;
        } else {
            const checked = block.querySelector('input[type=radio]:checked');
            if (!checked) { unanswered++; return; }
            answers[qid] = checked.value;
        }
    });

    if (unanswered > 0) {
        resultEl.style.cssText = 'display:block;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#fca5a5;margin-bottom:14px;padding:13px 14px;border-radius:var(--radius);font-size:0.875rem;font-weight:600';
        resultEl.textContent = 'Please answer all questions before submitting.';
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Submitting…';

    const form = new FormData();
    form.append('quiz_id', quizId);
    Object.entries(answers).forEach(([k, v]) => {
        if (Array.isArray(v)) v.forEach(val => form.append('answers[' + k + '][]', val));
        else form.append('answers[' + k + ']', v);
    });

    fetch('<?= SITE_URL ?>/api/submit_quiz.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { btn.disabled = false; btn.textContent = 'Submit Quiz'; return; }

            resultEl.style.display = 'block';

            if (d.passed) {
                resultEl.style.cssText = 'display:block;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#86efac;margin-bottom:14px;padding:13px 14px;border-radius:var(--radius);font-size:0.875rem;font-weight:700';
                resultEl.textContent = '🎉 You passed! Score: ' + d.score + '% (' + d.correct + '/' + d.total + ' correct)';
                btnRow.style.display = 'none';
                // Hide questions
                document.getElementById('quizQuestionsWrap').style.display = 'none';
                if (d.new_badge) {
                    setTimeout(() => showBadgeModal(d.new_badge.icon, d.new_badge.name, d.new_badge.desc), 800);
                }
            } else {
                resultEl.style.cssText = 'display:block;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#fca5a5;margin-bottom:14px;padding:13px 14px;border-radius:var(--radius);font-size:0.875rem;font-weight:700;line-height:1.5';
                resultEl.innerHTML = '<strong>Score: ' + d.score + '%</strong> — you need ' + d.pass_percent + '% to pass. Keep learning and try again!';
                if (d.ai_explanation) {
                    resultEl.innerHTML += '<div style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(252,165,165,0.25);font-weight:400;font-size:0.85rem;white-space:pre-line">'
                        + '<strong style="display:block;margin-bottom:6px;font-weight:700">🧠 AI Mentor</strong>' + d.ai_explanation.replace(/</g,'&lt;') + '</div>';
                }
                // Show retry + continue buttons
                btnRow.innerHTML =
                    '<button onclick="retryQuiz('+quizId+')" class="btn-primary" style="flex:1;min-width:120px;justify-content:center">Retry Quiz</button>' +
                    '<a href="<?= SITE_URL ?>/guide.php" class="btn-ghost" style="flex:1;min-width:120px;justify-content:center">Continue Learning</a>';
            }
        })
        .catch(() => { btn.disabled = false; btn.textContent = 'Submit Quiz'; });
}

function retryQuiz(quizId) {
    // Reset UI for retry
    document.getElementById('quizResult').style.display = 'none';
    document.getElementById('quizQuestionsWrap').style.display = 'block';
    // Uncheck all radio buttons
    document.querySelectorAll('#quizQuestionsWrap input[type=radio]').forEach(r => r.checked = false);
    // Restore submit button
    document.getElementById('quizBtnRow').innerHTML =
        '<button onclick="submitQuiz('+quizId+')" class="btn-primary" id="quizSubmitBtn" style="flex:1;min-width:140px;justify-content:center">Submit Quiz</button>';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script>
/**
 * Guide lesson link fallback.
 * InfinityFree shared hosting sometimes strips or ignores mod_rewrite
 * for subdirectory routes. This intercepts clicks on .guide-lesson-link,
 * does a HEAD fetch of the pretty URL — if it resolves, proceeds normally.
 * If it 404s, silently redirects to the direct query-string URL instead.
 */
(function () {
    document.querySelectorAll('a.guide-lesson-link').forEach(function (link) {
        link.addEventListener('click', function (e) {
            var pretty   = link.getAttribute('href');
            var fallback = link.getAttribute('data-fallback');
            if (!fallback) return; // no fallback defined — proceed normally

            e.preventDefault();

            fetch(pretty, { method: 'HEAD', redirect: 'follow' })
                .then(function (r) {
                    // 200 or any non-404 → pretty URL works
                    if (r.ok || r.status !== 404) {
                        window.location.href = pretty;
                    } else {
                        window.location.href = fallback;
                    }
                })
                .catch(function () {
                    // Network error / CORS block → just try pretty URL anyway
                    window.location.href = pretty;
                });
        });
    });
})();
</script>
