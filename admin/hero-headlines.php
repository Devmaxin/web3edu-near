<?php
$adminTitle = 'Hero Headlines';
require_once __DIR__ . '/header.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];

if ($action === 'toggle' && $id) {
    $db->prepare("UPDATE hero_headlines SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/hero-headlines.php'); exit;
}
if ($action === 'delete' && $id) {
    $db->prepare("DELETE FROM hero_headlines WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/hero-headlines.php?deleted=1'); exit;
}
if ($action === 'move' && $id) {
    $dir = $_GET['dir'] === 'up' ? -1 : 1;
    $stmt = $db->prepare("SELECT sort_order FROM hero_headlines WHERE id=?"); $stmt->execute([$id]);
    $current = (int)$stmt->fetchColumn();
    $swapStmt = $db->prepare("SELECT id, sort_order FROM hero_headlines WHERE sort_order " . ($dir < 0 ? "<" : ">") . " ? ORDER BY sort_order " . ($dir < 0 ? "DESC" : "ASC") . " LIMIT 1");
    $swapStmt->execute([$current]);
    if ($swap = $swapStmt->fetch()) {
        $db->prepare("UPDATE hero_headlines SET sort_order=? WHERE id=?")->execute([$swap['sort_order'], $id]);
        $db->prepare("UPDATE hero_headlines SET sort_order=? WHERE id=?")->execute([$current, $swap['id']]);
    }
    header('Location: ' . SITE_URL . '/admin/hero-headlines.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = trim($_POST['headline_text'] ?? '');
    if (empty($text)) {
        $errors[] = 'Headline text is required.';
    } else {
        if ($id) {
            $db->prepare("UPDATE hero_headlines SET headline_text=? WHERE id=?")->execute([$text, $id]);
            $success = 'Headline updated.';
        } else {
            $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM hero_headlines")->fetchColumn();
            $db->prepare("INSERT INTO hero_headlines (headline_text, sort_order) VALUES (?, ?)")->execute([$text, $maxOrder + 1]);
            $success = 'Headline added.';
        }
        $action = 'list'; $id = 0;
    }
}

$headlines = $db->query("SELECT * FROM hero_headlines ORDER BY sort_order ASC")->fetchAll();
$editing = null;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM hero_headlines WHERE id=?"); $stmt->execute([$id]);
    $editing = $stmt->fetch();
}
?>

<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Headline deleted.</div><?php endif; ?>
<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Hero Headlines</h1>
</div>

<div style="background:rgba(139,92,246,0.07);border:1px solid rgba(139,92,246,0.2);border-radius:var(--radius);padding:12px 16px;margin-bottom:22px;font-size:0.84rem;color:var(--text-muted)">
    These rotate on the homepage hero. Reorder with the arrows — the homepage always reflects this order live.
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start">
    <div class="admin-table-wrap">
    <table class="admin-table">
    <thead><tr><th>Order</th><th>Headline</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($headlines as $i => $h): ?>
    <tr>
        <td>
            <div style="display:flex;gap:4px">
                <a href="?action=move&id=<?= $h['id'] ?>&dir=up" class="btn-ghost btn-sm" style="padding:2px 8px;<?= $i === 0 ? 'opacity:0.3;pointer-events:none' : '' ?>">↑</a>
                <a href="?action=move&id=<?= $h['id'] ?>&dir=down" class="btn-ghost btn-sm" style="padding:2px 8px;<?= $i === count($headlines)-1 ? 'opacity:0.3;pointer-events:none' : '' ?>">↓</a>
            </div>
        </td>
        <td style="font-weight:600"><?= sanitize($h['headline_text']) ?></td>
        <td>
            <a href="?action=toggle&id=<?= $h['id'] ?>" style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;text-decoration:none;<?= $h['is_active'] ? 'background:rgba(11,121,88,0.12);color:#0B7958' : 'background:rgba(148,148,163,0.12);color:var(--text-muted)' ?>">
                <?= $h['is_active'] ? 'Active' : 'Disabled' ?>
            </a>
        </td>
        <td>
            <div style="display:flex;gap:6px">
                <a href="?action=edit&id=<?= $h['id'] ?>" class="btn-ghost btn-sm">Edit</a>
                <a href="?action=delete&id=<?= $h['id'] ?>" class="btn-danger btn-sm" onclick="return confirm('Delete this headline?')">Delete</a>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($headlines)): ?><tr><td colspan="4" class="admin-empty">No headlines yet — add one on the right.</td></tr><?php endif; ?>
    </tbody>
    </table>
    </div>

    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
        <h2 style="font-size:1rem;margin-bottom:16px"><?= $editing ? 'Edit Headline' : 'Add Headline' ?></h2>
        <form method="POST" action="<?= $editing ? '?action=edit&id='.$editing['id'] : '' ?>">
            <div class="form-group">
                <label class="form-label">Headline Text</label>
                <input type="text" name="headline_text" class="form-input" value="<?= sanitize($editing['headline_text'] ?? '') ?>" placeholder="e.g. Master DeFi" required maxlength="255">
            </div>
            <button type="submit" class="btn-primary" style="width:100%"><?= $editing ? 'Save Changes' : 'Add Headline' ?></button>
            <?php if ($editing): ?><a href="<?= SITE_URL ?>/admin/hero-headlines.php" class="btn-ghost btn-sm" style="width:100%;text-align:center;margin-top:8px;display:block">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
