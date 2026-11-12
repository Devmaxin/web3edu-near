<?php
/**
 * Admin — Project Management
 * Reusable projects: create/edit/delete/duplicate/activate/deactivate.
 * Attaching a project to a guide happens in guide-projects.php, linked from
 * both here and from the Guide edit screen.
 */
$adminTitle = 'Projects';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/projects.php';

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$msg    = '';
$err    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save') {
        $editId = intval($_POST['id'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        $type   = in_array($_POST['project_type'] ?? '', ['beginner','intermediate','advanced','capstone'], true) ? $_POST['project_type'] : 'beginner';

        if ($title === '') {
            $err = 'Title is required.';
            $action = 'edit';
        } else {
            $slug = $editId ? null : slugifyProjectTitle($title);
            $fields = [
                $title, trim($_POST['description'] ?? ''), $type, trim($_POST['estimated_duration'] ?? ''),
                $_POST['instructions'] ?? '', trim($_POST['learning_objectives'] ?? ''), trim($_POST['requirements'] ?? ''),
                trim($_POST['deliverables'] ?? ''), trim($_POST['starter_files_url'] ?? ''),
                isset($_POST['is_active']) ? 1 : 0,
            ];
            if ($editId) {
                $db->prepare("
                    UPDATE projects SET title=?, description=?, project_type=?, estimated_duration=?, instructions=?,
                        learning_objectives=?, requirements=?, deliverables=?, starter_files_url=?, is_active=?
                    WHERE id=?
                ")->execute([...$fields, $editId]);
                $id = $editId;
                $msg = 'Project updated.';
            } else {
                $db->prepare("
                    INSERT INTO projects (title, description, project_type, estimated_duration, instructions,
                        learning_objectives, requirements, deliverables, starter_files_url, is_active, slug)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([...$fields, $slug]);
                $id = (int)$db->lastInsertId();
                $msg = 'Project created.';
            }
            $action = 'edit';
        }
    }

    if ($postAction === 'toggle_active') {
        $db->prepare("UPDATE projects SET is_active = 1 - is_active WHERE id = ?")->execute([intval($_POST['id'] ?? 0)]);
        header('Location: ?action=list'); exit;
    }

    if ($postAction === 'duplicate') {
        $newId = duplicateProject(intval($_POST['id'] ?? 0));
        header('Location: ?action=' . ($newId ? "edit&id=$newId" : 'list')); exit;
    }

    if ($postAction === 'delete') {
        $pid = intval($_POST['id'] ?? 0);
        $inUse = $db->prepare("SELECT COUNT(*) FROM guide_projects WHERE project_id=?");
        $inUse->execute([$pid]);
        if ((int)$inUse->fetchColumn() > 0) {
            $db->prepare("UPDATE projects SET is_active = 0 WHERE id = ?")->execute([$pid]);
            header('Location: ?action=list&deactivated=1'); exit;
        }
        $db->prepare("UPDATE projects SET deleted_at = NOW() WHERE id = ?")->execute([$pid]);
        header('Location: ?action=list&deleted=1'); exit;
    }
}

if ($action === 'edit') {
    $project = $id ? getProject($id) : null;
}
if ($action === 'list') {
    $allProjects = getAllProjectsAdmin();
}
?>

<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Project deleted.</div><?php endif; ?>
<?php if (isset($_GET['deactivated'])): ?><div class="admin-alert admin-alert-success">This project is attached to a guide, so it was deactivated instead of deleted.</div><?php endif; ?>
<?php if ($msg): ?><div class="admin-alert admin-alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="admin-alert admin-alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Projects <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted)">(<?= count($allProjects) ?>)</span></h1>
    <a href="?action=edit" class="btn-primary btn-sm">+ New Project</a>
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Project</th><th>Type</th><th>Guides</th><th>Approved</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($allProjects as $p): ?>
<tr>
    <td style="font-weight:600"><?= sanitize($p['title']) ?></td>
    <td><?= ucfirst($p['project_type']) ?></td>
    <td style="text-align:center"><?= (int)$p['guide_count'] ?></td>
    <td style="text-align:center"><?= (int)$p['approved_count'] ?></td>
    <td>
        <span style="padding:3px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;<?= $p['is_active']?'background:rgba(11,121,88,0.12);color:#0B7958':'background:rgba(107,114,128,0.12);color:var(--text-muted)' ?>">
            <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
        </span>
    </td>
    <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="?action=edit&id=<?= $p['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn-ghost btn-sm">Duplicate</button>
            </form>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn-ghost btn-sm"><?= $p['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn-danger btn-sm" onclick="return confirm('Delete this project?')">Delete</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($allProjects)): ?>
<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:24px">No projects yet.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<?php elseif ($action === 'edit'): ?>

<h1 class="admin-page-title"><?= $project ? 'Edit' : 'New' ?> Project</h1>

<form method="POST" style="max-width:720px">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $project['id'] ?? '' ?>">

    <div class="form-group">
        <label class="form-label">Title *</label>
        <input type="text" name="title" class="form-input" required value="<?= sanitize($project['title'] ?? '') ?>">
    </div>

    <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-input" rows="2"><?= sanitize($project['description'] ?? '') ?></textarea>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
            <label class="form-label">Project Type</label>
            <select name="project_type" class="form-select">
                <?php foreach (['beginner'=>'Beginner Project','intermediate'=>'Intermediate Project','advanced'=>'Advanced Project','capstone'=>'Capstone Project'] as $k=>$label): ?>
                <option value="<?= $k ?>" <?= ($project['project_type'] ?? 'beginner') === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Estimated Duration <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
            <input type="text" name="estimated_duration" class="form-input" placeholder="e.g. 3-5 hours" value="<?= sanitize($project['estimated_duration'] ?? '') ?>">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Instructions</label>
        <textarea name="instructions" class="form-input" rows="6" placeholder="Step-by-step build instructions"><?= sanitize($project['instructions'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
        <label class="form-label">Learning Objectives</label>
        <textarea name="learning_objectives" class="form-input" rows="3"><?= sanitize($project['learning_objectives'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
        <label class="form-label">Requirements</label>
        <textarea name="requirements" class="form-input" rows="3"><?= sanitize($project['requirements'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
        <label class="form-label">Deliverables</label>
        <textarea name="deliverables" class="form-input" rows="3" placeholder="What the user must submit"><?= sanitize($project['deliverables'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
        <label class="form-label">Starter Files URL <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
        <input type="text" name="starter_files_url" class="form-input" value="<?= sanitize($project['starter_files_url'] ?? '') ?>">
        <div class="form-hint">Reference links / resources belong to the general Resources library — attach them from Admin → Resources.</div>
    </div>

    <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
            <input type="checkbox" name="is_active" value="1" <?= ($project['is_active'] ?? 1) ? 'checked' : '' ?>>
            <span>Active (attachable to guides)</span>
        </label>
    </div>

    <div style="display:flex;gap:10px;margin-top:12px">
        <button class="btn-primary" type="submit">Save</button>
        <a href="?action=list" class="btn-ghost">Cancel</a>
    </div>
</form>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
