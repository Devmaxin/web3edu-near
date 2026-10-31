<?php
/**
 * Admin — Milestone Management (Phase 3)
 * Manages milestones + milestone_requirements. Rewards/completion logic
 * itself lives in includes/gamification.php (evaluateMilestones() /
 * getMilestoneProgress()) — this page only edits the config those read.
 * user_milestones (completion records) are never edited here — only
 * viewed, and only ever written by evaluateMilestones() itself.
 */
$adminTitle = 'Milestones';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/gamification.php';

$db = getDB();
$editId = intval($_GET['id'] ?? 0);
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save_milestone') {
        $id          = intval($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $slug        = trim($_POST['slug'] ?? '') ?: strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug        = trim($slug, '-');
        $description = trim($_POST['description'] ?? '');
        $icon        = trim($_POST['icon'] ?? '') ?: '🏆';
        $pointReward = max(0, intval($_POST['point_reward'] ?? 0));
        $badgeId     = intval($_POST['badge_id'] ?? 0) ?: null;
        $sortOrder   = intval($_POST['sort_order'] ?? 0);
        $active      = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $slug === '') {
            $err = 'Name is required.';
        } else {
            try {
                if ($id) {
                    $db->prepare("UPDATE milestones SET name=?, slug=?, description=?, icon=?, point_reward=?, badge_id=?, sort_order=?, is_active=? WHERE id=?")
                       ->execute([$name, $slug, $description, $icon, $pointReward, $badgeId, $sortOrder, $active, $id]);
                    $editId = $id;
                } else {
                    $db->prepare("INSERT INTO milestones (name, slug, description, icon, point_reward, badge_id, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$name, $slug, $description, $icon, $pointReward, $badgeId, $sortOrder, $active]);
                    $editId = (int)$db->lastInsertId();
                }
                $msg = 'Milestone saved.';
            } catch (\PDOException $e) {
                $err = str_contains($e->getMessage(), 'uniq_slug') ? 'That slug is already in use.' : 'Save failed.';
            }
        }
    }

    if ($postAction === 'toggle_milestone') {
        $db->prepare("UPDATE milestones SET is_active = NOT is_active WHERE id=?")->execute([intval($_POST['id'] ?? 0)]);
        header('Location: ?'); exit;
    }

    if ($postAction === 'delete_milestone') {
        $id = intval($_POST['id'] ?? 0);
        $hasCompletions = $db->prepare("SELECT COUNT(*) FROM user_milestones WHERE milestone_id=?");
        $hasCompletions->execute([$id]);
        if ((int)$hasCompletions->fetchColumn() > 0) {
            // Never hard-delete a milestone real users have already completed —
            // deactivate instead so their completion history stays intact and
            // meaningful.
            $db->prepare("UPDATE milestones SET is_active = 0 WHERE id=?")->execute([$id]);
            $msg = 'This milestone has completions on record, so it was deactivated instead of deleted.';
        } else {
            $db->prepare("UPDATE milestones SET deleted_at = NOW(), is_active = 0 WHERE id=?")->execute([$id]);
            $msg = 'Milestone deleted.';
        }
        header('Location: ?'); exit;
    }

    if ($postAction === 'save_requirement') {
        $milestoneId = intval($_POST['milestone_id'] ?? 0);
        $type        = trim($_POST['requirement_type'] ?? '');
        $targetId    = $_POST['target_id'] !== '' ? intval($_POST['target_id']) : null;
        $targetCount = $_POST['target_count'] !== '' ? intval($_POST['target_count']) : null;
        $label       = trim($_POST['label'] ?? '') ?: null;
        $sortOrder   = intval($_POST['sort_order'] ?? 0);

        $types = milestoneRequirementTypes();
        if ($milestoneId && isset($types[$type])) {
            $db->prepare("INSERT INTO milestone_requirements (milestone_id, requirement_type, target_id, target_count, label, sort_order) VALUES (?,?,?,?,?,?)")
               ->execute([$milestoneId, $type, $targetId, $targetCount, $label, $sortOrder]);
            $msg = 'Requirement added.';
        } else {
            $err = 'Invalid requirement type.';
        }
        $editId = $milestoneId;
    }

    if ($postAction === 'delete_requirement') {
        $reqId = intval($_POST['req_id'] ?? 0);
        $milestoneId = intval($_POST['milestone_id'] ?? 0);
        $db->prepare("DELETE FROM milestone_requirements WHERE id=?")->execute([$reqId]);
        header('Location: ?id=' . $milestoneId); exit;
    }
}

$requirementTypes = milestoneRequirementTypes();
$badges = $db->query("SELECT id, name FROM badges WHERE is_active=1 ORDER BY name ASC")->fetchAll();

if ($editId) {
    // ── Requirement builder for one milestone ──────────────────────────────
    $stmt = $db->prepare("SELECT * FROM milestones WHERE id=? AND deleted_at IS NULL");
    $stmt->execute([$editId]);
    $milestone = $stmt->fetch();
    if (!$milestone) { echo '<p style="padding:24px">Milestone not found.</p>'; require_once __DIR__.'/footer.php'; exit; }

    $reqStmt = $db->prepare("SELECT * FROM milestone_requirements WHERE milestone_id=? ORDER BY sort_order ASC, id ASC");
    $reqStmt->execute([$editId]);
    $requirements = $reqStmt->fetchAll();

    // Target option lists for the type-aware dropdowns
    $lessons     = $db->query("SELECT id, title FROM lessons WHERE is_active=1 ORDER BY title ASC")->fetchAll();
    $guides      = $db->query("SELECT id, title FROM guide_paths WHERE is_active=1 AND deleted_at IS NULL ORDER BY title ASC")->fetchAll();
    $quizzes     = $db->query("SELECT id, title FROM quizzes WHERE is_active=1 ORDER BY title ASC")->fetchAll();
    $careerPaths = $db->query("SELECT id, name FROM career_paths WHERE is_active=1 ORDER BY name ASC")->fetchAll();

    $completionCount = $db->prepare("SELECT COUNT(*) FROM user_milestones WHERE milestone_id=?");
    $completionCount->execute([$editId]);
    $completionCount = (int)$completionCount->fetchColumn();
    ?>

    <?php if (!empty($msg)): ?><div class="admin-alert admin-alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
    <?php if (!empty($err)): ?><div class="admin-alert admin-alert-error"><?= sanitize($err) ?></div><?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <a href="?" style="font-size:0.78rem;color:var(--text-muted)">&larr; All Milestones</a>
            <h1 class="admin-page-title" style="margin:4px 0 0"><?= sanitize($milestone['icon']) ?> <?= sanitize($milestone['name']) ?></h1>
        </div>
        <div style="font-size:0.8rem;color:var(--text-muted)"><?= $completionCount ?> user<?= $completionCount !== 1 ? 's' : '' ?> completed</div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:20px">
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;align-self:start">
            <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:14px">Add Requirement</h2>
            <form method="POST" id="reqForm">
                <input type="hidden" name="action" value="save_requirement">
                <input type="hidden" name="milestone_id" value="<?= $editId ?>">
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select name="requirement_type" id="reqType" class="form-select" onchange="onReqTypeChange()">
                        <?php foreach ($requirementTypes as $key => $meta): ?>
                            <option value="<?= $key ?>"><?= sanitize($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="targetLessonWrap" style="display:none">
                    <label class="form-label">Lesson</label>
                    <select name="target_id_lesson" class="form-select target-id-field">
                        <?php foreach ($lessons as $l): ?><option value="<?= $l['id'] ?>"><?= sanitize($l['title']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="targetGuideWrap" style="display:none">
                    <label class="form-label">Guide</label>
                    <select name="target_id_guide" class="form-select target-id-field">
                        <?php foreach ($guides as $g): ?><option value="<?= $g['id'] ?>"><?= sanitize($g['title']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="targetQuizWrap" style="display:none">
                    <label class="form-label">Quiz</label>
                    <select name="target_id_quiz" class="form-select target-id-field">
                        <?php foreach ($quizzes as $q): ?><option value="<?= $q['id'] ?>"><?= sanitize($q['title']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="targetCareerWrap" style="display:none">
                    <label class="form-label">Career Path</label>
                    <select name="target_id_career" class="form-select target-id-field">
                        <?php foreach ($careerPaths as $c): ?><option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="targetBadgeWrap" style="display:none">
                    <label class="form-label">Badge</label>
                    <select name="target_id_badge" class="form-select target-id-field">
                        <?php foreach ($badges as $b): ?><option value="<?= $b['id'] ?>"><?= sanitize($b['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="target_id" id="targetIdHidden">
                <div class="form-group" id="countWrap" style="display:none">
                    <label class="form-label" id="countLabel">Count</label>
                    <input type="number" name="target_count" id="targetCount" class="form-input" min="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Label override (optional)</label>
                    <input type="text" name="label" class="form-input" placeholder="e.g. Complete Module 1">
                </div>
                <div class="form-group"><label class="form-label">Sort Order</label><input type="number" name="sort_order" class="form-input" value="0"></div>
                <button class="btn-primary btn-sm" type="submit" style="width:100%" onclick="return prepTargetId()">Add Requirement</button>
            </form>
        </div>

        <div class="admin-table-wrap">
            <table class="admin-table">
            <thead><tr><th>#</th><th>Type</th><th>Target</th><th>Count</th><th>Label</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($requirements as $i => $r):
                $targetName = '—';
                if ($r['target_id']) {
                    $lookup = ['lesson_complete'=>$lessons,'guide_complete'=>$guides,'quiz_pass'=>$quizzes,'career_path_complete'=>$careerPaths,'badge_earned'=>$badges][$r['requirement_type']] ?? [];
                    foreach ($lookup as $opt) { if ((int)$opt['id'] === (int)$r['target_id']) { $targetName = $opt['title'] ?? $opt['name']; break; } }
                }
            ?>
            <tr>
                <td style="color:var(--text-muted)"><?= $i + 1 ?></td>
                <td style="font-size:0.78rem"><?= sanitize($requirementTypes[$r['requirement_type']]['label'] ?? $r['requirement_type']) ?></td>
                <td><?= sanitize($targetName) ?></td>
                <td><?= $r['target_count'] !== null ? (int)$r['target_count'] : '—' ?></td>
                <td style="font-size:0.78rem;color:var(--text-muted)"><?= sanitize($r['label'] ?? '') ?></td>
                <td>
                    <form method="POST" onsubmit="return confirm('Remove this requirement?')">
                        <input type="hidden" name="action" value="delete_requirement">
                        <input type="hidden" name="req_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="milestone_id" value="<?= $editId ?>">
                        <button class="btn-ghost btn-sm">Remove</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($requirements)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:24px">No requirements yet — this milestone can never complete until at least one is added.</td></tr><?php endif; ?>
            </tbody>
            </table>
        </div>
    </div>

    <script>
    const REQ_META = <?= json_encode($requirementTypes) ?>;
    function onReqTypeChange() {
        const type = document.getElementById('reqType').value;
        const meta = REQ_META[type];
        document.querySelectorAll('[id^="target"][id$="Wrap"]').forEach(el => el.style.display = 'none');
        document.getElementById('countWrap').style.display = meta.needs_count ? '' : 'none';
        if (meta.needs_target) {
            const map = {lessons:'targetLessonWrap', guide_paths:'targetGuideWrap', quizzes:'targetQuizWrap', career_paths:'targetCareerWrap', badges:'targetBadgeWrap'};
            const wrapId = map[meta.target_source];
            if (wrapId) document.getElementById(wrapId).style.display = '';
        }
        document.getElementById('countLabel').textContent = type === 'points_earned' ? 'XP required' : (type === 'streak_achieved' ? 'Days required' : 'Count required');
    }
    function prepTargetId() {
        let chosen = '';
        document.querySelectorAll('.target-id-field').forEach(sel => {
            if (sel.closest('.form-group').style.display !== 'none') chosen = sel.value;
        });
        document.getElementById('targetIdHidden').value = chosen;
        return true;
    }
    onReqTypeChange();
    </script>

<?php } else {
    // ── Milestone list ──────────────────────────────────────────────────────
    $milestones = $db->query("
        SELECT m.*, b.name AS badge_name,
            (SELECT COUNT(*) FROM milestone_requirements WHERE milestone_id = m.id) AS req_count,
            (SELECT COUNT(*) FROM user_milestones WHERE milestone_id = m.id) AS completion_count
        FROM milestones m
        LEFT JOIN badges b ON b.id = m.badge_id
        WHERE m.deleted_at IS NULL
        ORDER BY m.sort_order ASC, m.id ASC
    ")->fetchAll();
    ?>
    <?php if (!empty($msg)): ?><div class="admin-alert admin-alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
    <?php if (!empty($err)): ?><div class="admin-alert admin-alert-error"><?= sanitize($err) ?></div><?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <h1 class="admin-page-title" style="margin:0">Milestones</h1>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1.6fr;gap:20px">
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;align-self:start">
            <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:14px">New Milestone</h2>
            <form method="POST">
                <input type="hidden" name="action" value="save_milestone">
                <div class="form-group"><label class="form-label">Name</label><input type="text" name="name" class="form-input" required placeholder="e.g. Crypto Foundations"></div>
                <div class="form-group"><label class="form-label">Slug (optional — auto from name)</label><input type="text" name="slug" class="form-input" placeholder="crypto-foundations"></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-input" rows="2"></textarea></div>
                <div class="form-group"><label class="form-label">Icon (emoji)</label><input type="text" name="icon" class="form-input" value="🏆" maxlength="10"></div>
                <div class="form-group"><label class="form-label">XP Reward</label><input type="number" name="point_reward" class="form-input" min="0" value="100"></div>
                <div class="form-group">
                    <label class="form-label">Badge Reward (optional)</label>
                    <select name="badge_id" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($badges as $b): ?><option value="<?= $b['id'] ?>"><?= sanitize($b['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Sort Order</label><input type="number" name="sort_order" class="form-input" value="0"></div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
                        <input type="checkbox" name="is_active" value="1" checked> <span>Active</span>
                    </label>
                </div>
                <button class="btn-primary btn-sm" type="submit" style="width:100%">Create Milestone</button>
            </form>
        </div>

        <div class="admin-table-wrap">
        <table class="admin-table">
        <thead><tr><th>Milestone</th><th>Requirements</th><th>XP</th><th>Badge</th><th>Completed</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($milestones as $m): ?>
        <tr>
            <td><a href="?id=<?= $m['id'] ?>" style="font-weight:700;color:var(--text-primary)"><?= sanitize($m['icon']) ?> <?= sanitize($m['name']) ?></a></td>
            <td><?= (int)$m['req_count'] ?></td>
            <td><?= (int)$m['point_reward'] ?></td>
            <td style="font-size:0.78rem;color:var(--text-muted)"><?= sanitize($m['badge_name'] ?? '—') ?></td>
            <td><?= (int)$m['completion_count'] ?></td>
            <td><span style="padding:3px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;<?= $m['is_active']?'background:rgba(34,197,94,0.12);color:#86efac':'background:rgba(107,114,128,0.12);color:var(--text-muted)' ?>"><?= $m['is_active']?'Active':'Inactive' ?></span></td>
            <td style="display:flex;gap:6px">
                <a href="?id=<?= $m['id'] ?>" class="btn-ghost btn-sm">Edit</a>
                <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_milestone"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn-ghost btn-sm"><?= $m['is_active']?'Deactivate':'Activate' ?></button></form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this milestone? If any user has already completed it, it will be deactivated instead to preserve their record.')">
                    <input type="hidden" name="action" value="delete_milestone"><input type="hidden" name="id" value="<?= $m['id'] ?>">
                    <button class="btn-ghost btn-sm" style="color:var(--danger)">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($milestones)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">No milestones yet.</td></tr><?php endif; ?>
        </tbody>
        </table>
        </div>
    </div>
<?php } ?>

<?php require_once __DIR__ . '/footer.php'; ?>
