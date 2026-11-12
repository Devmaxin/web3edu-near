<?php
$adminTitle = 'Certificate Types';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/certificates.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];

if ($action === 'toggle' && $id) {
    $db->prepare("UPDATE certificate_types SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/certificate-types.php'); exit;
}
if ($action === 'delete' && $id) {
    $inUse = $db->prepare("SELECT COUNT(*) FROM certificate_templates WHERE certificate_type_id = ? AND deleted_at IS NULL");
    $inUse->execute([$id]);
    if ((int)$inUse->fetchColumn() > 0) {
        header('Location: ' . SITE_URL . '/admin/certificate-types.php?error=in_use'); exit;
    }
    $db->prepare("UPDATE certificate_types SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/certificate-types.php?deleted=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $label   = trim($_POST['label'] ?? '');
    $typeKey = trim($_POST['type_key'] ?? '');
    $typeKey = $typeKey !== '' ? preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(['-',' '], '_', $typeKey))) : preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(['-',' '], '_', $label)));
    $desc    = trim($_POST['description'] ?? '');
    $icon    = trim($_POST['icon'] ?? '🎓') ?: '🎓';
    $prefix  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['id_prefix'] ?? 'WEB3')) ?: 'WEB3';
    $active  = isset($_POST['is_active']) ? 1 : 0;

    if (empty($label)) $errors[] = 'Label is required.';
    if (empty($typeKey)) $errors[] = 'Type key could not be generated — please provide a label with letters.';

    if (empty($errors)) {
        if ($id) {
            $db->prepare("UPDATE certificate_types SET label=?, type_key=?, description=?, icon=?, id_prefix=?, is_active=? WHERE id=?")
               ->execute([$label, $typeKey, $desc, $icon, $prefix, $active, $id]);
            $success = 'Type updated.';
        } else {
            $dupe = $db->prepare("SELECT id FROM certificate_types WHERE type_key=? AND deleted_at IS NULL"); $dupe->execute([$typeKey]);
            if ($dupe->fetch()) {
                $errors[] = 'A type with that key already exists.';
            } else {
                $db->prepare("INSERT INTO certificate_types (type_key, label, description, icon, id_prefix, is_active) VALUES (?,?,?,?,?,?)")
                   ->execute([$typeKey, $label, $desc, $icon, $prefix, $active]);
                $success = 'Type created.';
            }
        }
        if (empty($errors)) { $action = 'list'; $id = 0; }
    }
}

$types = getCertificateTypes(false);
$editing = null;
if ($action === 'edit' && $id) $editing = getCertificateType($id);
?>

<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Type deleted.</div><?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] === 'in_use'): ?><div class="admin-alert admin-alert-error">Can't delete — templates still use this type. Reassign or delete them first.</div><?php endif; ?>
<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Certificate Types</h1>
    <a href="<?= SITE_URL ?>/admin/certificate-templates.php" class="btn-ghost btn-sm">← Templates</a>
</div>
<p style="font-size:0.84rem;color:var(--text-muted);margin-bottom:22px;max-width:640px">
    A type is a category of certificate (Course Completion, Event, Special Achievement, ...). Each type has its own
    certificate ID prefix — e.g. <code>WEB3-2026-000012</code> — and its own default template.
</p>

<div style="display:grid;grid-template-columns:1.3fr 1fr;gap:24px" class="type-edit-grid">

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Type</th><th>Key</th><th>Prefix</th><th>Templates</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($types as $t):
    $tplCount = (int)$db->query("SELECT COUNT(*) FROM certificate_templates WHERE certificate_type_id=" . (int)$t['id'] . " AND deleted_at IS NULL")->fetchColumn();
?>
<tr>
    <td style="font-weight:600"><?= $t['icon'] ?> <?= sanitize($t['label']) ?></td>
    <td style="font-family:var(--font-mono);font-size:0.76rem;color:var(--text-muted)"><?= sanitize($t['type_key']) ?></td>
    <td style="font-family:var(--font-mono);font-size:0.76rem"><?= sanitize($t['id_prefix']) ?></td>
    <td style="text-align:center"><?= $tplCount ?></td>
    <td style="text-align:center">
        <a href="?action=toggle&id=<?= $t['id'] ?>" style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;text-decoration:none;<?= $t['is_active'] ? 'background:rgba(11,121,88,0.12);color:#0B7958' : 'background:rgba(148,148,163,0.12);color:var(--text-muted)' ?>">
            <?= $t['is_active'] ? 'Active' : 'Disabled' ?>
        </a>
    </td>
    <td>
        <div style="display:flex;gap:6px">
            <a href="?action=edit&id=<?= $t['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <a href="?action=delete&id=<?= $t['id'] ?>" class="btn-danger btn-sm" onclick="return confirm('Delete this type?')">Delete</a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($types)): ?><tr><td colspan="6" class="admin-empty">No types yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;align-self:start">
    <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:16px"><?= $id ? 'Edit Type' : 'New Type' ?></h2>
    <form method="POST" action="<?= $id ? '?action=edit&id='.$id : '?action=edit' ?>">
        <div class="form-group">
            <label class="form-label">Label</label>
            <input type="text" name="label" class="form-input" value="<?= sanitize($editing['label'] ?? '') ?>" placeholder="e.g. Hackathon Winner" required>
        </div>
        <div class="form-group">
            <label class="form-label">Type Key <span style="font-weight:400;color:var(--text-muted)">(optional — auto from label)</span></label>
            <input type="text" name="type_key" class="form-input" value="<?= sanitize($editing['type_key'] ?? '') ?>" placeholder="e.g. hackathon_winner">
        </div>
        <div class="form-group">
            <label class="form-label">Description</label>
            <input type="text" name="description" class="form-input" value="<?= sanitize($editing['description'] ?? '') ?>">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group">
                <label class="form-label">Icon (emoji)</label>
                <input type="text" name="icon" class="form-input" value="<?= sanitize($editing['icon'] ?? '🎓') ?>" maxlength="4">
            </div>
            <div class="form-group">
                <label class="form-label">ID Prefix</label>
                <input type="text" name="id_prefix" class="form-input" value="<?= sanitize($editing['id_prefix'] ?? 'WEB3') ?>" maxlength="12">
                <div class="form-hint">Used in generated IDs: <?= sanitize($editing['id_prefix'] ?? 'WEB3') ?>-<?= date('Y') ?>-000001</div>
            </div>
        </div>
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem">
                <input type="checkbox" name="is_active" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>> Active
            </label>
        </div>
        <button type="submit" class="btn-primary" style="width:100%"><?= $id ? 'Save Changes' : 'Create Type' ?></button>
        <?php if ($id): ?><a href="<?= SITE_URL ?>/admin/certificate-types.php" class="btn-ghost btn-sm" style="display:block;text-align:center;margin-top:8px">Cancel</a><?php endif; ?>
    </form>
</div>

</div>
<style>@media (max-width: 800px) { .type-edit-grid { grid-template-columns: 1fr !important; } }</style>

<?php require_once __DIR__ . '/footer.php'; ?>
