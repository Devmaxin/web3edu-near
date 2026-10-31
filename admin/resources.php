<?php
/**
 * Admin — Resources Library
 * Create/edit/delete resources, and attach them to lessons, guides,
 * projects, or career paths via resource_links (never duplicated).
 */
$adminTitle = 'Resources';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/resources.php';

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$msg    = '';
$err    = '';

$typeOptions = ['documentation'=>'Official Documentation','github'=>'GitHub','article'=>'Article','youtube'=>'YouTube Video','download'=>'Download','tool'=>'Tool','template'=>'Template'];
$linkableOptions = ['lesson'=>'lessons','guide_path'=>'guide_paths','project'=>'projects','career_path'=>'career_paths'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save') {
        $editId = intval($_POST['id'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        $type   = array_key_exists($_POST['resource_type'] ?? '', $typeOptions) ? $_POST['resource_type'] : 'article';
        $url    = trim($_POST['url'] ?? '');

        if ($title === '' || $url === '') {
            $err = 'Title and URL are required.';
            $action = 'edit';
        } else {
            if ($editId) {
                $db->prepare("UPDATE resources SET title=?, description=?, resource_type=?, url=?, is_active=? WHERE id=?")
                   ->execute([$title, trim($_POST['description'] ?? ''), $type, $url, isset($_POST['is_active']) ? 1 : 0, $editId]);
                $id = $editId;
                $msg = 'Resource updated.';
            } else {
                $db->prepare("INSERT INTO resources (title, description, resource_type, url, is_active) VALUES (?,?,?,?,?)")
                   ->execute([$title, trim($_POST['description'] ?? ''), $type, $url, isset($_POST['is_active']) ? 1 : 0]);
                $id = (int)$db->lastInsertId();
                $msg = 'Resource created.';
            }
            $action = 'edit';
        }
    }

    if ($postAction === 'delete') {
        $rid = intval($_POST['id'] ?? 0);
        $inUse = $db->prepare("SELECT COUNT(*) FROM resource_links WHERE resource_id=?");
        $inUse->execute([$rid]);
        if ((int)$inUse->fetchColumn() > 0) {
            $db->prepare("UPDATE resources SET is_active = 0 WHERE id = ?")->execute([$rid]);
            header('Location: ?action=list&deactivated=1'); exit;
        }
        $db->prepare("UPDATE resources SET deleted_at = NOW() WHERE id = ?")->execute([$rid]);
        header('Location: ?action=list&deleted=1'); exit;
    }

    if ($postAction === 'toggle_active') {
        $db->prepare("UPDATE resources SET is_active = 1 - is_active WHERE id = ?")->execute([intval($_POST['id'] ?? 0)]);
        header('Location: ?action=list'); exit;
    }

    if ($postAction === 'attach') {
        $rid = intval($_POST['resource_id'] ?? 0);
        $linkType = array_key_exists($_POST['linkable_type'] ?? '', $linkableOptions) ? $_POST['linkable_type'] : null;
        $linkId = intval($_POST['linkable_id'] ?? 0);
        if ($rid && $linkType && $linkId) {
            attachResource($rid, $linkType, $linkId);
            $msg = 'Attached.';
        }
        $action = 'edit'; $id = $rid;
    }

    if ($postAction === 'detach') {
        detachResource(intval($_POST['link_id'] ?? 0));
        $action = 'edit'; $id = intval($_POST['id'] ?? 0);
        $msg = 'Detached.';
    }
}

if ($action === 'edit') {
    $resource = $id ? getResource($id) : null;
    if ($id) {
        $links = $db->prepare("
            SELECT rl.*,
                CASE rl.linkable_type
                    WHEN 'lesson' THEN (SELECT title FROM lessons WHERE id=rl.linkable_id)
                    WHEN 'guide_path' THEN (SELECT title FROM guide_paths WHERE id=rl.linkable_id)
                    WHEN 'project' THEN (SELECT title FROM projects WHERE id=rl.linkable_id)
                    WHEN 'career_path' THEN (SELECT name FROM career_paths WHERE id=rl.linkable_id)
                END AS target_name
            FROM resource_links rl WHERE rl.resource_id = ? ORDER BY rl.display_order ASC
        ");
        $links->execute([$id]);
        $links = $links->fetchAll();
    }
}
if ($action === 'list') {
    $allResources = getAllResourcesAdmin();
}
?>

<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Resource deleted.</div><?php endif; ?>
<?php if (isset($_GET['deactivated'])): ?><div class="admin-alert admin-alert-success">This resource is attached somewhere, so it was deactivated instead of deleted.</div><?php endif; ?>
<?php if ($msg): ?><div class="admin-alert admin-alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="admin-alert admin-alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Resources <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted)">(<?= count($allResources) ?>)</span></h1>
    <a href="?action=edit" class="btn-primary btn-sm">+ New Resource</a>
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Resource</th><th>Type</th><th>Attached To</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($allResources as $r): ?>
<tr>
    <td style="font-weight:600"><?= sanitize($r['title']) ?></td>
    <td><?= sanitize($typeOptions[$r['resource_type']] ?? $r['resource_type']) ?></td>
    <td style="text-align:center"><?= (int)$r['link_count'] ?></td>
    <td><span style="padding:3px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;<?= $r['is_active']?'background:rgba(34,197,94,0.12);color:#86efac':'background:rgba(107,114,128,0.12);color:var(--text-muted)' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
    <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="?action=edit&id=<?= $r['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn-ghost btn-sm"><?= $r['is_active']?'Deactivate':'Activate' ?></button></form>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn-danger btn-sm" onclick="return confirm('Delete this resource?')">Delete</button></form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($allResources)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">No resources yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php elseif ($action === 'edit'): ?>

<h1 class="admin-page-title"><?= $resource ? 'Edit' : 'New' ?> Resource</h1>

<form method="POST" style="max-width:640px;margin-bottom:28px">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $resource['id'] ?? '' ?>">
    <div class="form-group"><label class="form-label">Title *</label><input type="text" name="title" class="form-input" required value="<?= sanitize($resource['title'] ?? '') ?>"></div>
    <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-input" rows="2"><?= sanitize($resource['description'] ?? '') ?></textarea></div>
    <div style="display:grid;grid-template-columns:1fr 2fr;gap:16px">
        <div class="form-group">
            <label class="form-label">Type</label>
            <select name="resource_type" class="form-select">
                <?php foreach ($typeOptions as $k=>$label): ?><option value="<?= $k ?>" <?= ($resource['resource_type'] ?? 'article')===$k?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">URL *</label><input type="text" name="url" class="form-input" required value="<?= sanitize($resource['url'] ?? '') ?>"></div>
    </div>
    <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
            <input type="checkbox" name="is_active" value="1" <?= ($resource['is_active'] ?? 1)?'checked':'' ?>> <span>Active</span>
        </label>
    </div>
    <div style="display:flex;gap:10px"><button class="btn-primary" type="submit">Save</button><a href="?action=list" class="btn-ghost">Cancel</a></div>
</form>

<?php if ($resource): ?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;max-width:640px">
    <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:12px">Attached To</h2>
    <?php if (empty($links)): ?>
        <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:14px">Not attached anywhere yet.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px">
        <?php foreach ($links as $l): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:var(--bg-card2);border-radius:var(--radius);font-size:0.82rem">
                <span><?= sanitize($linkableOptions[$l['linkable_type']] ?? $l['linkable_type']) ?>: <strong><?= sanitize($l['target_name'] ?? ('#'.$l['linkable_id'])) ?></strong></span>
                <form method="POST"><input type="hidden" name="action" value="detach"><input type="hidden" name="id" value="<?= $resource['id'] ?>"><input type="hidden" name="link_id" value="<?= $l['id'] ?>">
                    <button class="btn-ghost btn-sm" style="color:#f87171">Detach</button>
                </form>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:14px">
        <input type="hidden" name="action" value="attach">
        <input type="hidden" name="resource_id" value="<?= $resource['id'] ?>">
        <select name="linkable_type" class="form-select" style="flex:1;min-width:140px">
            <?php foreach ($linkableOptions as $k=>$label): ?><option value="<?= $k ?>"><?= ucfirst(str_replace('_',' ',$k)) ?></option><?php endforeach; ?>
        </select>
        <input type="number" name="linkable_id" class="form-input" placeholder="ID (e.g. guide id)" style="width:160px" required>
        <button class="btn-primary btn-sm" type="submit">Attach</button>
    </form>
    <p style="font-size:0.72rem;color:var(--text-muted);margin-top:8px">Find the target's ID from its own admin edit page URL (e.g. guides.php?action=edit&id=<strong>7</strong>).</p>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
