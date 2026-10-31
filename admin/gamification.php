<?php
$adminTitle = 'Gamification';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/gamification.php';

$db  = getDB();
$tab = $_GET['tab'] ?? 'badges';
$msg = '';
$err = '';

// ─── LEVEL CRUD ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'levels') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_level') {
        $levelNumber = intval($_POST['level_number'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $xpRequired  = intval($_POST['xp_required'] ?? 0);
        $icon        = trim($_POST['icon'] ?? '');

        if ($levelNumber <= 0 || !$title) {
            $err = 'Level number and title are required.';
        } else {
            $dupe = $db->prepare("SELECT id FROM levels WHERE level_number=?");
            $dupe->execute([$levelNumber]);
            if ($dupe->fetch()) {
                $err = "Level {$levelNumber} already exists.";
            } else {
                $db->prepare("INSERT INTO levels (level_number,title,xp_required,icon) VALUES (?,?,?,?)")
                   ->execute([$levelNumber, $title, $xpRequired, $icon ?: null]);
                $msg = 'Level created.';
            }
        }
    }

    if ($action === 'update_level') {
        $levelId     = intval($_POST['level_id'] ?? 0);
        $levelNumber = intval($_POST['level_number'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $xpRequired  = intval($_POST['xp_required'] ?? 0);
        $icon        = trim($_POST['icon'] ?? '');

        if (!$levelId || $levelNumber <= 0 || !$title) {
            $err = 'Level number and title are required.';
        } else {
            $dupe = $db->prepare("SELECT id FROM levels WHERE level_number=? AND id!=?");
            $dupe->execute([$levelNumber, $levelId]);
            if ($dupe->fetch()) {
                $err = "Level {$levelNumber} is already used by another level.";
            } else {
                $db->prepare("UPDATE levels SET level_number=?, title=?, xp_required=?, icon=? WHERE id=?")
                   ->execute([$levelNumber, $title, $xpRequired, $icon ?: null, $levelId]);
                // Every user's stored current_level may now point at a stale
                // number/threshold — resync everyone against the edited curve.
                foreach ($db->query("SELECT id FROM users WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    recalculateLevel((int)$uid);
                }
                $msg = 'Level updated and all user levels resynced.';
            }
        }
    }

    if ($action === 'delete_level') {
        $levelId = intval($_POST['level_id'] ?? 0);
        $countLevels = (int)$db->query("SELECT COUNT(*) FROM levels")->fetchColumn();
        if ($countLevels <= 1) {
            $err = 'Cannot delete the last remaining level.';
        } else {
            $db->prepare("DELETE FROM levels WHERE id=?")->execute([$levelId]);
            foreach ($db->query("SELECT id FROM users WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                recalculateLevel((int)$uid);
            }
            $msg = 'Level deleted and all user levels resynced.';
        }
    }
}

// ─── BADGE CRUD ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'badges') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_badge') {
        $name     = trim($_POST['name'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $icon     = trim($_POST['icon'] ?? '🏆');
        $reqLess  = intval($_POST['required_lessons'] ?? 0);
        $reqPaths = intval($_POST['required_career_paths'] ?? 0);
        $reqProj  = intval($_POST['required_projects'] ?? 0);
        $reqCerts = intval($_POST['required_certificates'] ?? 0);

        if ($name) {
            $db->prepare("INSERT INTO badges (name,description,icon,required_lessons,required_career_paths,required_projects,required_certificates) VALUES (?,?,?,?,?,?,?)")
               ->execute([$name, $desc, $icon, $reqLess, $reqPaths, $reqProj, $reqCerts]);
            $msg = 'Badge created.';
        } else { $err = 'Name is required.'; }
    }

    if ($action === 'delete_badge') {
        $db->prepare("DELETE FROM badges WHERE id=?")->execute([intval($_POST['badge_id'])]);
        $msg = 'Badge deleted.';
    }

    if ($action === 'toggle_badge') {
        $db->prepare("UPDATE badges SET is_active = NOT is_active WHERE id=?")->execute([intval($_POST['badge_id'])]);
        $msg = 'Badge updated.';
    }
}

// ─── GUIDE CRUD ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'guides') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_guide') {
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        if ($title) {
            $db->prepare("INSERT INTO guide_paths (title,description) VALUES (?,?)")->execute([$title,$desc]);
            $newGuideId = $db->lastInsertId();
            // Attach selected lessons
            $lessonIds = array_map('intval', $_POST['lesson_ids'] ?? []);
            foreach ($lessonIds as $i => $lid) {
                if ($lid) {
                    $db->prepare("INSERT IGNORE INTO guide_path_lessons (guide_id,lesson_id,sort_order) VALUES (?,?,?)")
                       ->execute([$newGuideId, $lid, $i]);
                }
            }
            $msg = 'Guide path created.';
        } else { $err = 'Title required.'; }
    }

    if ($action === 'delete_guide') {
        $db->prepare("DELETE FROM guide_paths WHERE id=?")->execute([intval($_POST['guide_id'])]);
        $msg = 'Guide deleted.';
    }

    if ($action === 'update_guide_lessons') {
        $guideId   = intval($_POST['guide_id']);
        $lessonIds = array_map('intval', $_POST['lesson_ids'] ?? []);
        $db->prepare("DELETE FROM guide_path_lessons WHERE guide_id=?")->execute([$guideId]);
        foreach ($lessonIds as $i => $lid) {
            if ($lid) {
                $db->prepare("INSERT IGNORE INTO guide_path_lessons (guide_id,lesson_id,sort_order) VALUES (?,?,?)")
                   ->execute([$guideId, $lid, $i]);
            }
        }
        $msg = 'Guide lessons updated.';
    }
}

// ─── QUIZ CRUD ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'quizzes') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_quiz') {
        $title    = trim($_POST['title'] ?? '');
        $guideId  = intval($_POST['guide_id'] ?? 0) ?: null;
        $pass     = intval($_POST['pass_percent'] ?? 70);
        $badgeId  = intval($_POST['badge_id'] ?? 0) ?: null;
        $quizType = in_array($_POST['quiz_type'] ?? '', ['quiz','checkpoint','assessment'], true) ? $_POST['quiz_type'] : 'quiz';

        if ($title) {
            $db->prepare("INSERT INTO quizzes (title,guide_id,pass_percent,badge_id,quiz_type) VALUES (?,?,?,?,?)")
               ->execute([$title, $guideId, $pass, $badgeId, $quizType]);
            $newQId = (int)$db->lastInsertId();

            // Save questions
            $questions = $_POST['questions'] ?? [];
            foreach ($questions as $qi => $q) {
                $qText = trim($q['question'] ?? '');
                if (!$qText) continue;
                $qType = in_array($q['question_type'] ?? '', ['multiple_choice','true_false','multiple_select','short_answer','code','scenario','image'], true) ? $q['question_type'] : 'multiple_choice';
                $correctAnswers = null;
                if (in_array($qType, ['multiple_select','short_answer','code','scenario'], true)) {
                    $raw = array_filter(array_map('trim', explode(',', $q['correct_answers'] ?? '')));
                    $correctAnswers = json_encode(array_values($raw));
                }
                $db->prepare("INSERT INTO quiz_questions (quiz_id,question,option_a,option_b,option_c,option_d,correct,sort_order,question_type,correct_answers,code_snippet,image_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([
                       $newQId, $qText,
                       trim($q['a'] ?? '') ?: null, trim($q['b'] ?? '') ?: null,
                       trim($q['c'] ?? '') ?: null, trim($q['d'] ?? '') ?: null,
                       in_array($q['correct']??'',['a','b','c','d']) ? $q['correct'] : null,
                       $qi, $qType, $correctAnswers,
                       trim($q['code_snippet'] ?? '') ?: null, trim($q['image_url'] ?? '') ?: null,
                   ]);
            }

            // Save quiz_required_lessons (extra per-lesson requirements)
            $reqLessons = array_filter(array_map('intval', $_POST['required_lesson_ids'] ?? []));
            foreach ($reqLessons as $lid) {
                $db->prepare("INSERT IGNORE INTO quiz_required_lessons (quiz_id,lesson_id) VALUES (?,?)")
                   ->execute([$newQId, $lid]);
            }

            $msg = 'Quiz created with ' . count($questions) . ' question(s).';
        } else { $err = 'Title required.'; }
    }

    if ($action === 'update_quiz') {
        $qid     = intval($_POST['quiz_id']);
        $title   = trim($_POST['title'] ?? '');
        $guideId = intval($_POST['guide_id'] ?? 0) ?: null;
        $pass    = intval($_POST['pass_percent'] ?? 70);
        $badgeId = intval($_POST['badge_id'] ?? 0) ?: null;
        $quizType = in_array($_POST['quiz_type'] ?? '', ['quiz','checkpoint','assessment'], true) ? $_POST['quiz_type'] : 'quiz';

        if ($title && $qid) {
            $db->prepare("UPDATE quizzes SET title=?,guide_id=?,pass_percent=?,badge_id=?,quiz_type=? WHERE id=?")
               ->execute([$title, $guideId, $pass, $badgeId, $quizType, $qid]);

            // Rebuild questions: delete all, re-insert
            $db->prepare("DELETE FROM quiz_questions WHERE quiz_id=?")->execute([$qid]);
            $questions = $_POST['questions'] ?? [];
            foreach ($questions as $qi => $q) {
                $qText = trim($q['question'] ?? '');
                if (!$qText) continue;
                $qType = in_array($q['question_type'] ?? '', ['multiple_choice','true_false','multiple_select','short_answer','code','scenario','image'], true) ? $q['question_type'] : 'multiple_choice';
                $correctAnswers = null;
                if (in_array($qType, ['multiple_select','short_answer','code','scenario'], true)) {
                    $raw = array_filter(array_map('trim', explode(',', $q['correct_answers'] ?? '')));
                    $correctAnswers = json_encode(array_values($raw));
                }
                $db->prepare("INSERT INTO quiz_questions (quiz_id,question,option_a,option_b,option_c,option_d,correct,sort_order,question_type,correct_answers,code_snippet,image_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([
                       $qid, $qText,
                       trim($q['a'] ?? '') ?: null, trim($q['b'] ?? '') ?: null,
                       trim($q['c'] ?? '') ?: null, trim($q['d'] ?? '') ?: null,
                       in_array($q['correct']??'',['a','b','c','d']) ? $q['correct'] : null,
                       $qi, $qType, $correctAnswers,
                       trim($q['code_snippet'] ?? '') ?: null, trim($q['image_url'] ?? '') ?: null,
                   ]);
            }

            // Rebuild required lessons
            $db->prepare("DELETE FROM quiz_required_lessons WHERE quiz_id=?")->execute([$qid]);
            $reqLessons = array_filter(array_map('intval', $_POST['required_lesson_ids'] ?? []));
            foreach ($reqLessons as $lid) {
                $db->prepare("INSERT IGNORE INTO quiz_required_lessons (quiz_id,lesson_id) VALUES (?,?)")
                   ->execute([$qid, $lid]);
            }

            $msg = 'Quiz updated.';
        } else { $err = 'Title required.'; }
    }

    if ($action === 'toggle_quiz') {
        $db->prepare("UPDATE quizzes SET is_active = NOT is_active WHERE id=?")->execute([intval($_POST['quiz_id'])]);
        $msg = 'Quiz status toggled.';
    }

    if ($action === 'delete_quiz') {
        $db->prepare("DELETE FROM quizzes WHERE id=?")->execute([intval($_POST['quiz_id'])]);
        $msg = 'Quiz deleted.';
    }
}

// ─── Load data for display ──────────────────────────────────────────────────────
$badges  = $db->query("SELECT * FROM badges ORDER BY required_lessons ASC, id ASC")->fetchAll();
$guides  = $db->query("SELECT * FROM guide_paths ORDER BY id ASC")->fetchAll();
$quizzes = $db->query("
    SELECT q.*, gp.title AS guide_title, b.name AS badge_name
    FROM quizzes q
    LEFT JOIN guide_paths gp ON gp.id = q.guide_id
    LEFT JOIN badges b ON b.id = q.badge_id
    ORDER BY q.id ASC
")->fetchAll();
$allLessons = $db->query("SELECT id,title,sort_order FROM lessons ORDER BY sort_order ASC")->fetchAll();
$levels = $db->query("SELECT * FROM levels ORDER BY level_number ASC")->fetchAll();

// Which level is being edited (if any)?
$editLevelId = isset($_GET['edit_level']) ? intval($_GET['edit_level']) : 0;
$editLevel = null;
if ($editLevelId) {
    foreach ($levels as $lv) { if ((int)$lv['id'] === $editLevelId) { $editLevel = $lv; break; } }
}

// Pre-load questions & required lessons keyed by quiz_id
$allQuizQuestions = [];
$allQuizReqLessons = [];
foreach ($db->query("SELECT * FROM quiz_questions ORDER BY quiz_id ASC, sort_order ASC")->fetchAll() as $qq) {
    $allQuizQuestions[$qq['quiz_id']][] = $qq;
}
foreach ($db->query("SELECT * FROM quiz_required_lessons ORDER BY quiz_id ASC")->fetchAll() as $rl) {
    $allQuizReqLessons[$rl['quiz_id']][] = $rl['lesson_id'];
}

// Which quiz is being edited (if any)?
$editQuizId = isset($_GET['edit_quiz']) ? intval($_GET['edit_quiz']) : 0;
?>

<h1 class="admin-page-title">Gamification</h1>

<?php if ($msg): ?><div class="admin-flash success" style="margin-bottom:16px;padding:12px 16px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);border-radius:var(--radius);color:#86efac"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="admin-flash error"   style="margin-bottom:16px;padding:12px 16px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius);color:#fca5a5"><?= sanitize($err) ?></div><?php endif; ?>

<!-- Tab bar -->
<div style="display:flex;gap:6px;margin-bottom:24px;border-bottom:1px solid var(--border);padding-bottom:0">
    <?php foreach (['badges'=>'🏆 Badges','guides'=>'🚀 Guides','quizzes'=>'❓ Quizzes','levels'=>'⭐ Levels'] as $t=>$label): ?>
    <a href="?tab=<?= $t ?>" style="padding:8px 16px;font-size:0.85rem;font-weight:600;border-radius:var(--radius) var(--radius) 0 0;text-decoration:none;
        <?= $tab===$t ? 'background:var(--bg-card);border:1px solid var(--border);border-bottom:1px solid var(--bg-card);color:var(--primary-bright)' : 'color:var(--text-muted)' ?>">
        <?= $label ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ═══════════════ BADGES TAB ═══════════════ -->
<?php if ($tab === 'badges'): ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">

  <!-- Create badge -->
  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px">
    <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:16px">Create Badge</h2>
    <form method="post" action="?tab=badges">
      <input type="hidden" name="action" value="create_badge">
      <div style="margin-bottom:12px">
        <label class="form-label">Badge Name *</label>
        <input type="text" name="name" class="form-input" required placeholder="e.g. First Step">
      </div>
      <div style="margin-bottom:12px">
        <label class="form-label">Description</label>
        <input type="text" name="description" class="form-input" placeholder="Short description">
      </div>
      <div style="margin-bottom:12px">
        <label class="form-label">Icon (emoji)</label>
        <input type="text" name="icon" class="form-input" value="🏆" maxlength="5">
      </div>
      <div style="margin-bottom:16px">
        <label class="form-label">Required Lessons (0 = not this trigger)</label>
        <input type="number" name="required_lessons" class="form-input" value="0" min="0">
      </div>
      <div style="margin-bottom:16px">
        <label class="form-label">Required Career Paths Completed (0 = not this trigger)</label>
        <input type="number" name="required_career_paths" class="form-input" value="0" min="0">
      </div>
      <div style="margin-bottom:16px">
        <label class="form-label">Required Projects Approved (0 = not this trigger)</label>
        <input type="number" name="required_projects" class="form-input" value="0" min="0">
      </div>
      <div style="margin-bottom:16px">
        <label class="form-label">Required Certificates Earned (0 = not this trigger)</label>
        <input type="number" name="required_certificates" class="form-input" value="0" min="0">
      </div>
      <div class="form-hint" style="margin-bottom:12px">A badge awards when ANY ONE of the thresholds above is met — set only the dimension(s) relevant to this badge, leave the rest at 0.</div>
      <button type="submit" class="btn-primary" style="width:100%">Create Badge</button>
    </form>
  </div>

  <!-- Badge list -->
  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px">
    <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:16px">All Badges (<?= count($badges) ?>)</h2>
    <?php if (empty($badges)): ?>
    <p style="color:var(--text-muted);font-size:0.875rem">No badges yet.</p>
    <?php else: ?>
    <?php foreach ($badges as $b): ?>
    <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border-subtle)">
        <span style="font-size:1.4rem;flex-shrink:0"><?= htmlspecialchars($b['icon']) ?></span>
        <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:0.875rem"><?= sanitize($b['name']) ?>
                <?php if (!$b['is_active']): ?><span style="color:var(--text-muted);font-size:0.72rem">(inactive)</span><?php endif; ?>
            </div>
            <div style="font-size:0.76rem;color:var(--text-muted)">
                <?php
                    $dims = [];
                    if ($b['required_lessons'] > 0) $dims[] = "{$b['required_lessons']} lesson" . ($b['required_lessons']!=1?'s':'');
                    if (($b['required_career_paths'] ?? 0) > 0) $dims[] = "{$b['required_career_paths']} career path" . ($b['required_career_paths']!=1?'s':'');
                    if (($b['required_projects'] ?? 0) > 0) $dims[] = "{$b['required_projects']} project" . ($b['required_projects']!=1?'s':'');
                    if (($b['required_certificates'] ?? 0) > 0) $dims[] = "{$b['required_certificates']} certificate" . ($b['required_certificates']!=1?'s':'');
                    echo $dims ? implode(' · ', $dims) . ' required' : 'Quiz-linked or manual';
                ?>
            </div>
        </div>
        <form method="post" action="?tab=badges" style="display:flex;gap:6px">
            <input type="hidden" name="badge_id" value="<?= $b['id'] ?>">
            <button name="action" value="toggle_badge" class="btn-ghost" style="padding:4px 10px;font-size:0.72rem;min-height:unset"><?= $b['is_active'] ? 'Disable' : 'Enable' ?></button>
            <button name="action" value="delete_badge" class="btn-danger" style="padding:4px 10px;font-size:0.72rem;min-height:unset" onclick="return confirm('Delete badge?')">Del</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<!-- ═══════════════ LEVELS TAB ═══════════════ -->
<?php elseif ($tab === 'levels'): ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">

  <!-- Create / Edit level -->
  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px">
    <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:16px"><?= $editLevel ? 'Edit Level' : 'Create Level' ?></h2>
    <form method="post" action="?tab=levels<?= $editLevel ? '&edit_level='.$editLevel['id'] : '' ?>">
      <input type="hidden" name="action" value="<?= $editLevel ? 'update_level' : 'create_level' ?>">
      <?php if ($editLevel): ?><input type="hidden" name="level_id" value="<?= $editLevel['id'] ?>"><?php endif; ?>
      <div style="margin-bottom:12px">
        <label class="form-label">Level Number *</label>
        <input type="number" name="level_number" class="form-input" required min="1"
               value="<?= $editLevel ? (int)$editLevel['level_number'] : '' ?>" placeholder="e.g. 8">
      </div>
      <div style="margin-bottom:12px">
        <label class="form-label">Title *</label>
        <input type="text" name="title" class="form-input" required placeholder="e.g. Chain Legend"
               value="<?= $editLevel ? sanitize($editLevel['title']) : '' ?>">
      </div>
      <div style="margin-bottom:12px">
        <label class="form-label">XP Required</label>
        <input type="number" name="xp_required" class="form-input" min="0"
               value="<?= $editLevel ? (int)$editLevel['xp_required'] : 0 ?>">
        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px">Total lifetime XP a user needs to reach this level.</div>
      </div>
      <div style="margin-bottom:16px">
        <label class="form-label">Icon (emoji, optional)</label>
        <input type="text" name="icon" class="form-input" maxlength="20"
               value="<?= $editLevel ? sanitize($editLevel['icon'] ?? '') : '' ?>" placeholder="e.g. ⭐">
      </div>
      <button type="submit" class="btn-primary" style="width:100%"><?= $editLevel ? 'Save Changes' : 'Create Level' ?></button>
      <?php if ($editLevel): ?>
      <a href="?tab=levels" class="btn-ghost" style="width:100%;display:block;text-align:center;margin-top:8px">Cancel edit</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Level list -->
  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px">
    <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:16px">All Levels (<?= count($levels) ?>)</h2>
    <?php if (empty($levels)): ?>
    <p style="color:var(--text-muted);font-size:0.875rem">No levels yet — create Level 1 to get started.</p>
    <?php else: ?>
    <?php foreach ($levels as $lv):
        $isEditing = $editLevel && (int)$editLevel['id'] === (int)$lv['id'];
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border-subtle);<?= $isEditing ? 'background:rgba(124,58,237,0.05);border-radius:var(--radius);padding-left:10px;padding-right:10px' : '' ?>">
        <span style="font-size:1.3rem;flex-shrink:0"><?= sanitize($lv['icon'] ?: '⭐') ?></span>
        <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:0.875rem">
                Level <?= (int)$lv['level_number'] ?> — <?= sanitize($lv['title']) ?>
                <?php if ($isEditing): ?><span style="font-size:0.65rem;background:rgba(124,58,237,0.2);color:var(--primary-bright);padding:1px 6px;border-radius:99px;font-weight:700;margin-left:6px">EDITING</span><?php endif; ?>
            </div>
            <div style="font-size:0.76rem;color:var(--text-muted)"><?= number_format((int)$lv['xp_required']) ?> XP required</div>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0">
            <a href="?tab=levels&edit_level=<?= $lv['id'] ?>" class="btn-ghost" style="padding:4px 10px;font-size:0.72rem;min-height:unset;text-decoration:none">Edit</a>
            <form method="post" action="?tab=levels" style="display:inline">
                <input type="hidden" name="action" value="delete_level">
                <input type="hidden" name="level_id" value="<?= $lv['id'] ?>">
                <button class="btn-danger" style="padding:4px 10px;font-size:0.72rem;min-height:unset" onclick="return confirm('Delete this level? Users currently at this level will be recalculated to the nearest remaining level.')">Del</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<!-- ═══════════════ GUIDES TAB ═══════════════ -->
<?php elseif ($tab === 'guides'): ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">

  <!-- Create guide -->
  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px">
    <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:16px">Create Guide Path</h2>
    <form method="post" action="?tab=guides">
      <input type="hidden" name="action" value="create_guide">
      <div style="margin-bottom:12px">
        <label class="form-label">Title *</label>
        <input type="text" name="title" class="form-input" required placeholder="e.g. Web3 Foundations">
      </div>
      <div style="margin-bottom:14px">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-input" rows="2" placeholder="Short description"></textarea>
      </div>
      <div style="margin-bottom:16px">
        <label class="form-label">Lessons (select & order — Ctrl/Cmd+click for multi)</label>
        <select name="lesson_ids[]" multiple class="form-input" style="height:140px">
            <?php foreach ($allLessons as $l): ?>
            <option value="<?= $l['id'] ?>">[#<?= $l['sort_order'] ?>] <?= sanitize($l['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px">Order in guide = order selected here</div>
      </div>
      <button type="submit" class="btn-primary" style="width:100%">Create Guide Path</button>
    </form>
  </div>

  <!-- Guide list -->
  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px">
    <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:16px">Guide Paths (<?= count($guides) ?>)</h2>
    <?php foreach ($guides as $g):
        $gLessons = $db->prepare("SELECT l.id,l.title,l.sort_order FROM guide_path_lessons gpl INNER JOIN lessons l ON l.id=gpl.lesson_id WHERE gpl.guide_id=? ORDER BY gpl.sort_order ASC");
        $gLessons->execute([$g['id']]);
        $gLessons = $gLessons->fetchAll();
    ?>
    <div style="padding:12px 0;border-bottom:1px solid var(--border-subtle)">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px">
            <div>
                <div style="font-weight:600;font-size:0.875rem"><?= sanitize($g['title']) ?></div>
                <div style="font-size:0.75rem;color:var(--text-muted)"><?= count($gLessons) ?> lessons</div>
            </div>
            <div style="display:flex;gap:6px">
                <a href="<?= SITE_URL ?>/guide.php?guide=<?= $g['id'] ?>" target="_blank" class="btn-ghost" style="padding:4px 10px;font-size:0.72rem;min-height:unset">View</a>
                <form method="post" action="?tab=guides" style="display:inline">
                    <input type="hidden" name="action" value="delete_guide">
                    <input type="hidden" name="guide_id" value="<?= $g['id'] ?>">
                    <button class="btn-danger" style="padding:4px 10px;font-size:0.72rem;min-height:unset" onclick="return confirm('Delete guide?')">Del</button>
                </form>
            </div>
        </div>
        <!-- Update lessons form -->
        <details style="margin-top:4px">
            <summary style="font-size:0.76rem;color:var(--text-muted);cursor:pointer">Edit lessons</summary>
            <form method="post" action="?tab=guides" style="margin-top:8px">
                <input type="hidden" name="action" value="update_guide_lessons">
                <input type="hidden" name="guide_id" value="<?= $g['id'] ?>">
                <select name="lesson_ids[]" multiple class="form-input" style="height:120px;font-size:0.8rem">
                    <?php
                    $currentIds = array_column($gLessons, 'id');
                    foreach ($allLessons as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= in_array($l['id'],$currentIds)?'selected':'' ?>>[#<?= $l['sort_order'] ?>] <?= sanitize($l['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-ghost" style="width:100%;margin-top:8px;font-size:0.78rem;min-height:unset">Save Lessons</button>
            </form>
        </details>
    </div>
    <?php endforeach; ?>
  </div>

</div>

<!-- ═══════════════ QUIZZES TAB ═══════════════ -->
<?php elseif ($tab === 'quizzes'): ?>

<?php
// If editing a specific quiz, load it fully
$editQuiz = null;
if ($editQuizId) {
    foreach ($quizzes as $q) {
        if ((int)$q['id'] === $editQuizId) { $editQuiz = $q; break; }
    }
}
$formAction = $editQuiz ? 'update_quiz' : 'create_quiz';
$formTitle  = $editQuiz ? 'Edit Quiz: ' . htmlspecialchars($editQuiz['title']) : 'Create Quiz';
$editQQs    = $editQuiz ? ($allQuizQuestions[$editQuiz['id']] ?? []) : [];
$editReqLs  = $editQuiz ? ($allQuizReqLessons[$editQuiz['id']] ?? []) : [];
?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">

  <!-- Create / Edit Quiz Panel -->
  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <h2 style="font-size:0.95rem;font-weight:700;margin:0"><?= $formTitle ?></h2>
        <?php if ($editQuiz): ?>
        <a href="?tab=quizzes" style="font-size:0.75rem;color:var(--text-muted);text-decoration:none">✕ Cancel Edit</a>
        <?php endif; ?>
    </div>
    <form method="post" action="?tab=quizzes" id="quizForm">
      <input type="hidden" name="action"  value="<?= $formAction ?>">
      <?php if ($editQuiz): ?>
      <input type="hidden" name="quiz_id" value="<?= $editQuiz['id'] ?>">
      <?php endif; ?>

      <div style="margin-bottom:12px">
        <label class="form-label">Quiz Title *</label>
        <input type="text" name="title" class="form-input" required
               placeholder="e.g. Web3 Foundations Quiz"
               value="<?= $editQuiz ? htmlspecialchars($editQuiz['title']) : '' ?>">
      </div>

      <div style="margin-bottom:12px">
        <label class="form-label">Attach to Guide Path</label>
        <select name="guide_id" class="form-input">
            <option value="">— Standalone —</option>
            <?php foreach ($guides as $g): ?>
            <option value="<?= $g['id'] ?>" <?= ($editQuiz && (int)$editQuiz['guide_id'] === (int)$g['id']) ? 'selected' : '' ?>>
                <?= sanitize($g['title']) ?>
            </option>
            <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-bottom:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div>
            <label class="form-label">Pass Mark (%)</label>
            <input type="number" name="pass_percent" class="form-input" min="1" max="100"
                   value="<?= $editQuiz ? (int)$editQuiz['pass_percent'] : 70 ?>">
        </div>
        <div>
            <label class="form-label">Award Badge (on pass)</label>
            <select name="badge_id" class="form-input">
                <option value="">— None —</option>
                <?php foreach ($badges as $b): ?>
                <option value="<?= $b['id'] ?>" <?= ($editQuiz && (int)$editQuiz['badge_id'] === (int)$b['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($b['icon']) ?> <?= sanitize($b['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
      </div>

      <!-- Required lessons multi-select -->
      <div style="margin-bottom:16px">
        <label class="form-label" style="margin-bottom:6px">
            Required Lessons to Unlock <span style="color:var(--text-muted);font-weight:400">(optional — leave blank if guide path is enough)</span>
        </label>
        <div style="max-height:160px;overflow-y:auto;background:var(--bg-card2);border:1px solid var(--border);border-radius:var(--radius);padding:10px;display:flex;flex-direction:column;gap:6px">
            <?php foreach ($allLessons as $l): ?>
            <label style="display:flex;align-items:center;gap:8px;font-size:0.8rem;cursor:pointer">
                <input type="checkbox" name="required_lesson_ids[]" value="<?= $l['id'] ?>"
                       <?= in_array((int)$l['id'], array_map('intval', $editReqLs)) ? 'checked' : '' ?>>
                <span><?= sanitize($l['title']) ?></span>
            </label>
            <?php endforeach; ?>
            <?php if (empty($allLessons)): ?>
            <span style="font-size:0.78rem;color:var(--text-muted)">No lessons yet.</span>
            <?php endif; ?>
        </div>
      </div>

      <div style="margin-top:16px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between">
          <span style="font-size:0.85rem;font-weight:700">Questions</span>
          <span style="font-size:0.72rem;color:var(--text-muted)">All options required for each question</span>
      </div>
      <div id="questionsContainer">
        <?php if (!empty($editQQs)): ?>
        <?php foreach ($editQQs as $qi => $qq): ?>
        <div class="qblock" style="background:var(--bg-card2);border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-bottom:12px;position:relative">
            <input type="text" name="questions[<?= $qi ?>][question]" class="form-input"
                   placeholder="Question <?= $qi+1 ?>" style="margin-bottom:8px"
                   value="<?= htmlspecialchars($qq['question']) ?>">

            <div class="form-group" style="margin-bottom:8px">
                <label class="form-label" style="font-size:0.76rem">Question Type</label>
                <select name="questions[<?= $qi ?>][question_type]" class="form-input qtype-select" onchange="toggleQuestionFields(this)">
                    <?php foreach (['multiple_choice'=>'Multiple Choice','true_false'=>'True / False','multiple_select'=>'Multiple Select','short_answer'=>'Short Answer','code'=>'Code Question','scenario'=>'Scenario Question','image'=>'Image Question'] as $qtKey=>$qtLabel): ?>
                    <option value="<?= $qtKey ?>" <?= ($qq['question_type'] ?? 'multiple_choice') === $qtKey ? 'selected' : '' ?>><?= $qtLabel ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="qc-mc" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <?php foreach (['a','b','c','d'] as $opt): ?>
                <input type="text" name="questions[<?= $qi ?>][<?= $opt ?>]" class="form-input"
                       placeholder="Option <?= strtoupper($opt) ?>"
                       value="<?= htmlspecialchars($qq['option_'.$opt] ?? '') ?>">
                <?php endforeach; ?>
            </div>
            <div class="qc-mc" style="margin-top:8px">
                <label class="form-label" style="font-size:0.76rem">Correct Answer</label>
                <select name="questions[<?= $qi ?>][correct]" class="form-input">
                    <?php foreach (['a','b','c','d'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= ($qq['correct'] ?? '') === $opt ? 'selected' : '' ?>>
                        <?= strtoupper($opt) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="qc-answers" style="margin-top:8px;display:none">
                <label class="form-label" style="font-size:0.76rem">Accepted Answers (comma-separated)</label>
                <input type="text" name="questions[<?= $qi ?>][correct_answers]" class="form-input"
                       placeholder="e.g. public, external"
                       value="<?= htmlspecialchars(implode(', ', json_decode($qq['correct_answers'] ?? '[]', true) ?: [])) ?>">
                <div class="form-hint">For Multiple Select: the exact option letters (a, c). For Short Answer/Code/Scenario: any keyword/phrase that should count as correct.</div>
            </div>

            <div class="qc-code" style="margin-top:8px;display:none">
                <label class="form-label" style="font-size:0.76rem">Code Snippet (shown to the user)</label>
                <textarea name="questions[<?= $qi ?>][code_snippet]" class="form-input" rows="3" style="font-family:var(--font-mono)"><?= htmlspecialchars($qq['code_snippet'] ?? '') ?></textarea>
            </div>

            <div class="qc-image" style="margin-top:8px;display:none">
                <label class="form-label" style="font-size:0.76rem">Image URL</label>
                <input type="text" name="questions[<?= $qi ?>][image_url]" class="form-input" value="<?= htmlspecialchars($qq['image_url'] ?? '') ?>">
            </div>

            <div style="margin-top:8px;text-align:right">
                <button type="button" onclick="this.closest('.qblock').remove()"
                    style="background:none;border:1px solid rgba(239,68,68,0.3);color:#fca5a5;cursor:pointer;font-size:0.72rem;border-radius:var(--radius);padding:6px 10px;white-space:nowrap">
                    Remove
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <!-- Default first question block for new quiz -->
        <div class="qblock" style="background:var(--bg-card2);border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-bottom:12px">
            <input type="text" name="questions[0][question]" class="form-input" placeholder="Question 1" style="margin-bottom:8px">
            <div class="form-group" style="margin-bottom:8px">
                <label class="form-label" style="font-size:0.76rem">Question Type</label>
                <select name="questions[0][question_type]" class="form-input qtype-select" onchange="toggleQuestionFields(this)">
                    <option value="multiple_choice">Multiple Choice</option>
                    <option value="true_false">True / False</option>
                    <option value="multiple_select">Multiple Select</option>
                    <option value="short_answer">Short Answer</option>
                    <option value="code">Code Question</option>
                    <option value="scenario">Scenario Question</option>
                    <option value="image">Image Question</option>
                </select>
            </div>
            <div class="qc-mc" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <input type="text" name="questions[0][a]" class="form-input" placeholder="Option A">
                <input type="text" name="questions[0][b]" class="form-input" placeholder="Option B">
                <input type="text" name="questions[0][c]" class="form-input" placeholder="Option C">
                <input type="text" name="questions[0][d]" class="form-input" placeholder="Option D">
            </div>
            <div class="qc-mc" style="margin-top:8px">
                <label class="form-label" style="font-size:0.76rem">Correct Answer</label>
                <select name="questions[0][correct]" class="form-input">
                    <option value="a">A</option><option value="b">B</option>
                    <option value="c">C</option><option value="d">D</option>
                </select>
            </div>
            <div class="qc-answers" style="margin-top:8px;display:none">
                <label class="form-label" style="font-size:0.76rem">Accepted Answers (comma-separated)</label>
                <input type="text" name="questions[0][correct_answers]" class="form-input" placeholder="e.g. public, external">
            </div>
            <div class="qc-code" style="margin-top:8px;display:none">
                <label class="form-label" style="font-size:0.76rem">Code Snippet</label>
                <textarea name="questions[0][code_snippet]" class="form-input" rows="3" style="font-family:var(--font-mono)"></textarea>
            </div>
            <div class="qc-image" style="margin-top:8px;display:none">
                <label class="form-label" style="font-size:0.76rem">Image URL</label>
                <input type="text" name="questions[0][image_url]" class="form-input">
            </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="form-group" style="max-width:420px;margin-bottom:12px">
          <label class="form-label" style="font-size:0.76rem">Quiz Type</label>
          <select name="quiz_type" class="form-input" id="quizTypeSelect" onchange="updateQuizTypeHint()">
              <option value="quiz" <?= ($editQuiz['quiz_type'] ?? 'quiz') === 'quiz' ? 'selected' : '' ?>>Normal Quiz</option>
              <option value="checkpoint" <?= ($editQuiz['quiz_type'] ?? '') === 'checkpoint' ? 'selected' : '' ?>>Checkpoint Quiz</option>
              <option value="assessment" <?= ($editQuiz['quiz_type'] ?? '') === 'assessment' ? 'selected' : '' ?>>Final Assessment</option>
          </select>
          <div class="form-hint" id="quizTypeHint"></div>
      </div>
      <script>
        function updateQuizTypeHint() {
            const hints = {
                quiz: 'The guide\'s single gating quiz — enable "Require passing the final quiz" under Guides → Edit to make it mandatory before Guide Complete.',
                checkpoint: 'A supplementary quiz unlocked after specific lessons (configure below under "Required Lessons"). Never blocks Guide Complete — purely a knowledge check along the way.',
                assessment: 'The guide\'s Final Assessment — enable "Require passing the Final Assessment" under Guides → Edit to make it mandatory before Guide Complete.',
            };
            document.getElementById('quizTypeHint').textContent = hints[document.getElementById('quizTypeSelect').value] || '';
        }
        updateQuizTypeHint();
      </script>

      <button type="button" onclick="addQuestion()" class="btn-ghost"
              style="width:100%;margin-bottom:12px;font-size:0.8rem;min-height:unset">
          + Add Question
      </button>
      <button type="submit" class="btn-primary" style="width:100%">
          <?= $editQuiz ? 'Save Changes' : 'Create Quiz' ?>
      </button>
    </form>
  </div>

  <!-- Quiz list -->
  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px;overflow-y:auto;max-height:820px">
    <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:16px">All Quizzes (<?= count($quizzes) ?>)</h2>
    <?php foreach ($quizzes as $q):
        $qqCount  = count($allQuizQuestions[$q['id']] ?? []);
        $reqCount = count($allQuizReqLessons[$q['id']] ?? []);
        $isEditing = ($editQuizId === (int)$q['id']);
    ?>
    <div style="padding:14px 0;border-bottom:1px solid var(--border-subtle);<?= $isEditing ? 'background:rgba(124,58,237,0.05);border-radius:var(--radius);padding:10px;margin-bottom:4px' : '' ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
            <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:0.875rem;display:flex;align-items:center;gap:6px">
                    <?= sanitize($q['title']) ?>
                    <?php
                        $qtBadge = ['quiz' => ['Normal', 'rgba(59,130,246,0.15)', '#93c5fd'], 'checkpoint' => ['Checkpoint', 'rgba(245,158,11,0.15)', '#fbbf24'], 'assessment' => ['Final Assessment', 'rgba(168,85,247,0.15)', '#d8b4fe']][$q['quiz_type'] ?? 'quiz'];
                    ?>
                    <span style="font-size:0.65rem;background:<?= $qtBadge[1] ?>;color:<?= $qtBadge[2] ?>;padding:1px 6px;border-radius:99px;font-weight:700"><?= $qtBadge[0] ?></span>
                    <?php if (!$q['is_active']): ?>
                    <span style="font-size:0.65rem;background:rgba(239,68,68,0.15);color:#fca5a5;padding:1px 6px;border-radius:99px;font-weight:700">INACTIVE</span>
                    <?php endif; ?>
                    <?php if ($isEditing): ?>
                    <span style="font-size:0.65rem;background:rgba(124,58,237,0.2);color:var(--primary-bright);padding:1px 6px;border-radius:99px;font-weight:700">EDITING</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.72rem;color:var(--text-muted);margin-top:3px;line-height:1.5">
                    <?= $qqCount ?> question<?= $qqCount !== 1 ? 's' : '' ?>
                    · Pass: <?= $q['pass_percent'] ?>%
                    <?= $q['guide_title'] ? ' · 📍 ' . sanitize($q['guide_title']) : '' ?>
                    <?= $q['badge_name']  ? ' · 🏆 ' . sanitize($q['badge_name'])  : '' ?>
                    <?php if ($reqCount): ?>
                    · <span style="color:var(--primary-bright)">🔒 <?= $reqCount ?> required lesson<?= $reqCount !== 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:5px;flex-shrink:0">
                <a href="?tab=quizzes&edit_quiz=<?= $q['id'] ?>" class="btn-ghost"
                   style="padding:4px 10px;font-size:0.72rem;min-height:unset;text-decoration:none">
                    Edit
                </a>
                <form method="post" action="?tab=quizzes" style="display:inline">
                    <input type="hidden" name="action"  value="toggle_quiz">
                    <input type="hidden" name="quiz_id" value="<?= $q['id'] ?>">
                    <button class="btn-ghost" style="padding:4px 10px;font-size:0.72rem;min-height:unset;color:var(--text-muted)" title="Toggle active/inactive">
                        <?= $q['is_active'] ? 'Disable' : 'Enable' ?>
                    </button>
                </form>
                <form method="post" action="?tab=quizzes" style="display:inline">
                    <input type="hidden" name="action"  value="delete_quiz">
                    <input type="hidden" name="quiz_id" value="<?= $q['id'] ?>">
                    <button class="btn-danger" style="padding:4px 10px;font-size:0.72rem;min-height:unset"
                            onclick="return confirm('Delete quiz and all its questions/results?')">Del</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($quizzes)): ?><p style="color:var(--text-muted);font-size:0.875rem">No quizzes yet.</p><?php endif; ?>
  </div>

</div><!-- /grid -->

<script>
let qIdx = <?= max(count($editQQs), 1) ?>;
function addQuestion() {
    const container = document.getElementById('questionsContainer');
    const i = qIdx++;
    const n = container.querySelectorAll('.qblock').length + 1;
    const div = document.createElement('div');
    div.className = 'qblock';
    div.style = 'background:var(--bg-card2);border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-bottom:12px';
    div.innerHTML = `
        <input type="text" name="questions[${i}][question]" class="form-input"
               placeholder="Question ${n}" style="margin-bottom:8px">
        <div class="form-group" style="margin-bottom:8px">
            <label class="form-label" style="font-size:0.76rem">Question Type</label>
            <select name="questions[${i}][question_type]" class="form-input qtype-select" onchange="toggleQuestionFields(this)">
                <option value="multiple_choice">Multiple Choice</option>
                <option value="true_false">True / False</option>
                <option value="multiple_select">Multiple Select</option>
                <option value="short_answer">Short Answer</option>
                <option value="code">Code Question</option>
                <option value="scenario">Scenario Question</option>
                <option value="image">Image Question</option>
            </select>
        </div>
        <div class="qc-mc" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <input type="text" name="questions[${i}][a]" class="form-input" placeholder="Option A">
            <input type="text" name="questions[${i}][b]" class="form-input" placeholder="Option B">
            <input type="text" name="questions[${i}][c]" class="form-input" placeholder="Option C">
            <input type="text" name="questions[${i}][d]" class="form-input" placeholder="Option D">
        </div>
        <div class="qc-mc" style="margin-top:8px">
            <label style="font-size:0.76rem;font-weight:700;display:block;margin-bottom:4px">Correct Answer</label>
            <select name="questions[${i}][correct]" class="form-input">
                <option value="a">A</option><option value="b">B</option>
                <option value="c">C</option><option value="d">D</option>
            </select>
        </div>
        <div class="qc-answers" style="margin-top:8px;display:none">
            <label style="font-size:0.76rem;font-weight:700;display:block;margin-bottom:4px">Accepted Answers (comma-separated)</label>
            <input type="text" name="questions[${i}][correct_answers]" class="form-input" placeholder="e.g. public, external">
        </div>
        <div class="qc-code" style="margin-top:8px;display:none">
            <label style="font-size:0.76rem;font-weight:700;display:block;margin-bottom:4px">Code Snippet</label>
            <textarea name="questions[${i}][code_snippet]" class="form-input" rows="3" style="font-family:var(--font-mono)"></textarea>
        </div>
        <div class="qc-image" style="margin-top:8px;display:none">
            <label style="font-size:0.76rem;font-weight:700;display:block;margin-bottom:4px">Image URL</label>
            <input type="text" name="questions[${i}][image_url]" class="form-input">
        </div>
        <div style="margin-top:8px;text-align:right">
            <button type="button" onclick="this.closest('.qblock').remove()"
                style="background:none;border:1px solid rgba(239,68,68,0.3);color:#fca5a5;cursor:pointer;font-size:0.72rem;border-radius:var(--radius);padding:6px 10px;white-space:nowrap">
                Remove
            </button>
        </div>
    `;
    container.appendChild(div);
    div.querySelector('input[type=text]').focus();
}

// Shows only the fields relevant to the selected question type.
// multiple_choice/true_false/image keep using option_a-d + correct (byte-identical
// to pre-Phase-2 behavior); multiple_select/short_answer/code/scenario use
// correct_answers instead, and code/image add one extra field each.
function toggleQuestionFields(select) {
    const block = select.closest('.qblock');
    const type = select.value;
    const showMc = ['multiple_choice', 'true_false', 'image'].includes(type);
    const showAnswers = ['multiple_select', 'short_answer', 'code', 'scenario'].includes(type);
    block.querySelectorAll('.qc-mc').forEach(el => el.style.display = showMc ? '' : 'none');
    block.querySelectorAll('.qc-answers').forEach(el => el.style.display = showAnswers ? '' : 'none');
    block.querySelectorAll('.qc-code').forEach(el => el.style.display = type === 'code' ? '' : 'none');
    block.querySelectorAll('.qc-image').forEach(el => el.style.display = type === 'image' ? '' : 'none');
}
document.querySelectorAll('.qtype-select').forEach(toggleQuestionFields);
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>