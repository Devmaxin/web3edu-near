<?php
/**
 * Admin — Career Path Management
 * List / Create / Edit / Activate / Deactivate / Delete career paths.
 * The Career Path Builder (attach guides, milestones, guide rules) lives in
 * career-path-builder.php, linked from the edit screen once a path exists.
 */
$adminTitle = 'Career Paths';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/career_paths.php';

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$msg    = '';
$err    = '';

// ── POST handlers ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save') {
        $editId  = intval($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $icon    = trim($_POST['icon'] ?? '');
        $color   = trim($_POST['theme_color'] ?? '#0B7958');
        $order   = intval($_POST['display_order'] ?? 0);
        $active  = isset($_POST['is_active']) ? 1 : 0;
        $isFound = isset($_POST['is_foundation']) ? 1 : 0;
        $diff    = in_array($_POST['difficulty'] ?? '', ['Beginner','Intermediate','Advanced']) ? $_POST['difficulty'] : 'Beginner';
        $beginner= isset($_POST['beginner_friendly']) ? 1 : 0;
        $recPrev = intval($_POST['recommended_previous_career_path_id'] ?? 0) ?: null;
        $badgeId = intval($_POST['completion_badge_id'] ?? 0) ?: null;
        $certId  = intval($_POST['certificate_template_id'] ?? 0) ?: null;

        if (!$name) {
            $err = 'Name is required.';
            $action = 'edit';
        } else {
            if ($isFound) {
                // Only one Foundation path at a time — unset any other.
                $db->prepare("UPDATE career_paths SET is_foundation = 0 WHERE id != ?")->execute([$editId ?: 0]);
            }
            if ($editId) {
                $db->prepare("
                    UPDATE career_paths SET name=?, description=?, icon=?, theme_color=?, display_order=?, is_active=?,
                        is_foundation=?, difficulty=?, beginner_friendly=?, recommended_previous_career_path_id=?,
                        completion_badge_id=?, certificate_template_id=?
                    WHERE id=?
                ")->execute([$name,$desc,$icon,$color,$order,$active,$isFound,$diff,$beginner,$recPrev,$badgeId,$certId,$editId]);
                $msg = 'Career Path updated.';
                $id = $editId;
            } else {
                $db->prepare("
                    INSERT INTO career_paths (name,description,icon,theme_color,display_order,is_active,
                        is_foundation,difficulty,beginner_friendly,recommended_previous_career_path_id,
                        completion_badge_id,certificate_template_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([$name,$desc,$icon,$color,$order,$active,$isFound,$diff,$beginner,$recPrev,$badgeId,$certId]);
                $id = (int)$db->lastInsertId();
                $msg = 'Career Path created.';
            }
            $action = 'edit';
        }
    }

    if ($postAction === 'toggle_active') {
        $tid = intval($_POST['id'] ?? 0);
        $db->prepare("UPDATE career_paths SET is_active = 1 - is_active WHERE id = ?")->execute([$tid]);
        header('Location: ?action=list'); exit;
    }

    if ($postAction === 'delete') {
        $tid = intval($_POST['id'] ?? 0);
        // Deactivate rather than hard-delete when users already have history,
        // per §9 rule 6 — preserves user_career_paths for anyone who started it.
        $inUse = $db->prepare("SELECT COUNT(*) FROM user_career_paths WHERE career_path_id = ?");
        $inUse->execute([$tid]);
        if ((int)$inUse->fetchColumn() > 0) {
            $db->prepare("UPDATE career_paths SET is_active = 0 WHERE id = ?")->execute([$tid]);
            header('Location: ?action=list&deactivated=1'); exit;
        }
        $db->prepare("DELETE FROM career_paths WHERE id = ?")->execute([$tid]);
        header('Location: ?action=list&deleted=1'); exit;
    }
}

if ($action === 'edit') {
    $path = $id ? getCareerPath($id) : null;
    $otherPaths = $db->query("SELECT id, name FROM career_paths" . ($id ? " WHERE id != $id" : "") . " ORDER BY name ASC")->fetchAll();
    $badges = $db->query("SELECT id, name FROM badges WHERE is_active=1 AND deleted_at IS NULL ORDER BY name ASC")->fetchAll();
    $templates = $db->query("SELECT id, name FROM certificate_templates WHERE deleted_at IS NULL AND is_active=1 ORDER BY name ASC")->fetchAll();
}

if ($action === 'list') {
    $allPaths = getAllCareerPathsAdmin();
    foreach ($allPaths as &$p) {
        $cnt = $db->prepare("SELECT COUNT(*) FROM career_path_guides WHERE career_path_id = ?");
        $cnt->execute([$p['id']]);
        $p['guide_count'] = (int)$cnt->fetchColumn();
    }
    unset($p);
}
?>

<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Career Path deleted.</div><?php endif; ?>
<?php if (isset($_GET['deactivated'])): ?><div class="admin-alert admin-alert-success">Users have history on this path, so it was deactivated instead of deleted.</div><?php endif; ?>
<?php if ($msg): ?><div class="admin-alert admin-alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="admin-alert admin-alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Career Paths <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted)">(<?= count($allPaths) ?>)</span></h1>
    <a href="?action=edit" class="btn-primary btn-sm">+ New Career Path</a>
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr>
    <th>Path</th><th>Difficulty</th><th>Guides</th><th>Foundation</th><th>Recommends After</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($allPaths as $p): ?>
<tr>
    <td style="font-weight:600"><?= sanitize($p['icon'] ?: '⭐') ?> <?= sanitize($p['name']) ?></td>
    <td><?= sanitize($p['difficulty']) ?></td>
    <td style="text-align:center">
        <span style="padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:700;background:rgba(139,92,246,0.12);color:var(--primary-bright)"><?= $p['guide_count'] ?></span>
    </td>
    <td><?= $p['is_foundation'] ? '★' : '—' ?></td>
    <td style="font-size:0.8rem;color:var(--text-muted)"><?= sanitize($p['recommended_previous_name'] ?? '—') ?></td>
    <td>
        <span style="padding:3px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;<?= $p['is_active']?'background:rgba(11,121,88,0.12);color:#0B7958':'background:rgba(107,114,128,0.12);color:var(--text-muted)' ?>">
            <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
        </span>
    </td>
    <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="?action=edit&id=<?= $p['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <a href="career-path-builder.php?id=<?= $p['id'] ?>" class="btn-ghost btn-sm" style="color:var(--cyan)">Builder</a>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn-ghost btn-sm"><?= $p['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn-danger btn-sm" onclick="return confirm('Delete this Career Path? If users have already started it, it will be deactivated instead.')">Delete</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php elseif ($action === 'edit'): ?>

<h1 class="admin-page-title"><?= $path ? 'Edit' : 'New' ?> Career Path</h1>

<form method="POST" style="max-width:640px">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $path['id'] ?? '' ?>">

    <div class="form-group">
        <label class="form-label">Name <span style="color:var(--danger)">*</span></label>
        <input type="text" name="name" class="form-input" required value="<?= sanitize($path['name'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-input" rows="3"><?= sanitize($path['description'] ?? '') ?></textarea>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
        <div class="form-group">
            <label class="form-label">Icon (emoji)</label>
            <input type="text" name="icon" class="form-input" maxlength="8" value="<?= sanitize($path['icon'] ?? '⭐') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Theme Color</label>
            <input type="color" name="theme_color" class="form-input" value="<?= sanitize($path['theme_color'] ?? '#0B7958') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Display Order</label>
            <input type="number" name="display_order" class="form-input" value="<?= (int)($path['display_order'] ?? 0) ?>">
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
            <label class="form-label">Difficulty</label>
            <select name="difficulty" class="form-select">
                <?php foreach (['Beginner','Intermediate','Advanced'] as $d): ?>
                <option value="<?= $d ?>" <?= ($path['difficulty'] ?? 'Beginner') === $d ? 'selected' : '' ?>><?= $d ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Recommended Previous Path</label>
            <select name="recommended_previous_career_path_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($otherPaths as $op): ?>
                <option value="<?= $op['id'] ?>" <?= (int)($path['recommended_previous_career_path_id'] ?? 0) === (int)$op['id'] ? 'selected' : '' ?>><?= sanitize($op['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
            <label class="form-label">Completion Badge</label>
            <select name="completion_badge_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($badges as $b): ?>
                <option value="<?= $b['id'] ?>" <?= (int)($path['completion_badge_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>><?= sanitize($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Certificate Template</label>
            <select name="certificate_template_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($templates as $t): ?>
                <option value="<?= $t['id'] ?>" <?= (int)($path['certificate_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
            <input type="checkbox" name="beginner_friendly" value="1" <?= !empty($path['beginner_friendly']) ? 'checked' : '' ?>>
            <span>Beginner Friendly</span>
        </label>
    </div>
    <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
            <input type="checkbox" name="is_foundation" value="1" <?= !empty($path['is_foundation']) ? 'checked' : '' ?>>
            <span>This is the Foundation Path ("I'm New to Web3")</span>
        </label>
    </div>
    <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
            <input type="checkbox" name="is_active" value="1" <?= ($path['is_active'] ?? 1) ? 'checked' : '' ?>>
            <span>Active (visible in Welcome Modal / Learn page)</span>
        </label>
    </div>

    <div style="display:flex;gap:10px;margin-top:12px;align-items:center">
        <button class="btn-primary" type="submit">Save</button>
        <a href="?action=list" class="btn-ghost">Cancel</a>
        <?php if ($path): ?>
            <a href="career-path-builder.php?id=<?= $path['id'] ?>" class="btn-ghost" style="color:var(--cyan);margin-left:auto">Open Career Path Builder →</a>
        <?php endif; ?>
    </div>
</form>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
