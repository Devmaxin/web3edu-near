<?php
$adminTitle = 'Bounty Categories';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/bounties.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];

if ($action === 'toggle' && $id) {
    requireCsrfGet();
    $db->prepare("UPDATE bounty_categories SET is_active = NOT is_active WHERE id=?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/bounty-categories.php'); exit;
}
if ($action === 'delete' && $id) {
    requireCsrfGet();
    $inUse = $db->prepare("SELECT COUNT(*) FROM bounties WHERE category_id=? AND deleted_at IS NULL"); $inUse->execute([$id]);
    if ((int)$inUse->fetchColumn() > 0) { header('Location: ' . SITE_URL . '/admin/bounty-categories.php?error=in_use'); exit; }
    $db->prepare("DELETE FROM bounty_categories WHERE id=?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/bounty-categories.php?deleted=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $name = trim($_POST['name'] ?? '');
    $icon = trim($_POST['icon'] ?? '💼') ?: '💼';
    if (!$name) $errors[] = 'Name is required.';
    if (empty($errors)) {
        $slug = generateSlug($name);
        $db->prepare("INSERT INTO bounty_categories (name, slug, icon, sort_order) VALUES (?,?,?,?)")
           ->execute([$name, $slug, $icon, (int)($_POST['sort_order'] ?? 0)]);
        $success = 'Category added.';
    }
}

$categories = getBountyCategories(false);
?>
<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Category deleted.</div><?php endif; ?>
<?php if (isset($_GET['error'])): ?><div class="admin-alert admin-alert-error">Can't delete — bounties still use this category.</div><?php endif; ?>
<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h1 class="admin-page-title" style="margin:0">Bounty Categories</h1>
    <a href="<?= SITE_URL ?>/admin/bounties.php" class="btn-ghost btn-sm"><?= lucide('arrow-left', ['size' => 13]) ?> Bounties</a>
</div>

<div style="display:grid;grid-template-columns:1.3fr 1fr;gap:24px">
<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Category</th><th>Slug</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($categories as $c): ?>
<tr>
    <td style="font-weight:600"><?= $c['icon'] ?> <?= sanitize($c['name']) ?></td>
    <td style="font-size:0.76rem;color:var(--text-muted)"><?= sanitize($c['slug']) ?></td>
    <td><a href="?action=toggle&id=<?= $c['id'] ?>&<?= csrfLink() ?>" style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;text-decoration:none;<?= $c['is_active'] ? 'background:rgba(34,197,94,0.12);color:#22c55e' : 'background:rgba(148,148,163,0.12);color:var(--text-muted)' ?>"><?= $c['is_active'] ? 'Active' : 'Disabled' ?></a></td>
    <td><a href="?action=delete&id=<?= $c['id'] ?>&<?= csrfLink() ?>" class="btn-danger btn-sm" onclick="return confirm('Delete?')"><?= lucide('trash', ['size' => 12]) ?> Delete</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;align-self:start">
    <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:6px"><?= lucide('plus', ['size' => 15]) ?> New Category</h2>
    <form method="POST">
        <?= csrfField() ?>
        <div class="form-group"><label class="form-label">Name</label><input type="text" name="name" class="form-input" required></div>
        <div class="form-group"><label class="form-label">Icon (emoji)</label><input type="text" name="icon" class="form-input" maxlength="4" value="💼"></div>
        <div class="form-group"><label class="form-label">Sort Order</label><input type="number" name="sort_order" class="form-input" value="0"></div>
        <button type="submit" class="btn-primary" style="width:100%"><?= lucide('check', ['size' => 13]) ?> Add Category</button>
    </form>
</div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
