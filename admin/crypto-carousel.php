<?php
$adminTitle = 'Crypto Carousel';
require_once __DIR__ . '/header.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];

if ($action === 'toggle' && $id) {
    $db->prepare("UPDATE crypto_carousel_items SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/crypto-carousel.php'); exit;
}
if ($action === 'delete' && $id) {
    $stmt = $db->prepare("SELECT logo_path FROM crypto_carousel_items WHERE id=?"); $stmt->execute([$id]);
    if ($row = $stmt->fetch()) deleteUploadedFile($row['logo_path']);
    $db->prepare("DELETE FROM crypto_carousel_items WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/crypto-carousel.php?deleted=1'); exit;
}
if ($action === 'move' && $id) {
    $dir = $_GET['dir'] === 'up' ? -1 : 1;
    $stmt = $db->prepare("SELECT sort_order FROM crypto_carousel_items WHERE id=?"); $stmt->execute([$id]);
    $current = (int)$stmt->fetchColumn();
    $swapStmt = $db->prepare("SELECT id, sort_order FROM crypto_carousel_items WHERE sort_order " . ($dir < 0 ? "<" : ">") . " ? ORDER BY sort_order " . ($dir < 0 ? "DESC" : "ASC") . " LIMIT 1");
    $swapStmt->execute([$current]);
    if ($swap = $swapStmt->fetch()) {
        $db->prepare("UPDATE crypto_carousel_items SET sort_order=? WHERE id=?")->execute([$swap['sort_order'], $id]);
        $db->prepare("UPDATE crypto_carousel_items SET sort_order=? WHERE id=?")->execute([$current, $swap['id']]);
    }
    header('Location: ' . SITE_URL . '/admin/crypto-carousel.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $coinName = trim($_POST['coin_name'] ?? '');
    if (empty($coinName)) $errors[] = 'Coin name is required.';

    $logoPath = handleImageUpload('logo', 'carousel', $errors);

    if (empty($errors)) {
        if ($id) {
            if ($logoPath) {
                $old = $db->prepare("SELECT logo_path FROM crypto_carousel_items WHERE id=?"); $old->execute([$id]);
                if ($oldRow = $old->fetch()) deleteUploadedFile($oldRow['logo_path']);
                $db->prepare("UPDATE crypto_carousel_items SET coin_name=?, logo_path=? WHERE id=?")->execute([$coinName, $logoPath, $id]);
            } else {
                $db->prepare("UPDATE crypto_carousel_items SET coin_name=? WHERE id=?")->execute([$coinName, $id]);
            }
            $success = 'Coin updated.';
        } else {
            if (!$logoPath) {
                $errors[] = 'Please upload a logo image.';
            } else {
                $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM crypto_carousel_items")->fetchColumn();
                $db->prepare("INSERT INTO crypto_carousel_items (coin_name, logo_path, sort_order) VALUES (?,?,?)")->execute([$coinName, $logoPath, $maxOrder + 1]);
                $success = 'Coin added.';
            }
        }
        if (empty($errors)) { $action = 'list'; $id = 0; }
    }
}

$coins = $db->query("SELECT * FROM crypto_carousel_items ORDER BY sort_order ASC")->fetchAll();
$editing = null;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM crypto_carousel_items WHERE id=?"); $stmt->execute([$id]);
    $editing = $stmt->fetch();
}
?>

<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Coin removed.</div><?php endif; ?>
<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<h1 class="admin-page-title" style="margin-bottom:24px">Crypto Carousel</h1>

<div style="background:rgba(139,92,246,0.07);border:1px solid rgba(139,92,246,0.2);border-radius:var(--radius);padding:12px 16px;margin-bottom:22px;font-size:0.84rem;color:var(--text-muted)">
    Powers the auto-scrolling logo strip on the homepage. Hidden entirely if no coins are active.
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start">
    <div class="admin-table-wrap">
    <table class="admin-table">
    <thead><tr><th>Order</th><th>Logo</th><th>Coin</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($coins as $i => $c): ?>
    <tr>
        <td>
            <div style="display:flex;gap:4px">
                <a href="?action=move&id=<?= $c['id'] ?>&dir=up" class="btn-ghost btn-sm" style="padding:2px 8px;<?= $i === 0 ? 'opacity:0.3;pointer-events:none' : '' ?>">↑</a>
                <a href="?action=move&id=<?= $c['id'] ?>&dir=down" class="btn-ghost btn-sm" style="padding:2px 8px;<?= $i === count($coins)-1 ? 'opacity:0.3;pointer-events:none' : '' ?>">↓</a>
            </div>
        </td>
        <td><img src="<?= sanitize(imageUrl($c['logo_path'])) ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover"></td>
        <td style="font-weight:600"><?= sanitize($c['coin_name']) ?></td>
        <td>
            <a href="?action=toggle&id=<?= $c['id'] ?>" style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;text-decoration:none;<?= $c['is_active'] ? 'background:rgba(34,197,94,0.12);color:#22c55e' : 'background:rgba(148,148,163,0.12);color:var(--text-muted)' ?>">
                <?= $c['is_active'] ? 'Active' : 'Disabled' ?>
            </a>
        </td>
        <td>
            <div style="display:flex;gap:6px">
                <a href="?action=edit&id=<?= $c['id'] ?>" class="btn-ghost btn-sm">Edit</a>
                <a href="?action=delete&id=<?= $c['id'] ?>" class="btn-danger btn-sm" onclick="return confirm('Remove this coin?')">Delete</a>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($coins)): ?><tr><td colspan="5" class="admin-empty">No coins yet — add one on the right.</td></tr><?php endif; ?>
    </tbody>
    </table>
    </div>

    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
        <h2 style="font-size:1rem;margin-bottom:16px"><?= $editing ? 'Edit Coin' : 'Add Coin' ?></h2>
        <form method="POST" action="<?= $editing ? '?action=edit&id='.$editing['id'] : '' ?>" enctype="multipart/form-data">
            <div class="form-group">
                <label class="form-label">Coin Name</label>
                <input type="text" name="coin_name" class="form-input" value="<?= sanitize($editing['coin_name'] ?? '') ?>" placeholder="e.g. Bitcoin" required>
            </div>
            <div class="form-group">
                <label class="form-label">Logo <?= $editing ? '(leave blank to keep current)' : '' ?></label>
                <?php if ($editing && $editing['logo_path']): ?>
                    <img src="<?= sanitize(imageUrl($editing['logo_path'])) ?>" alt="" style="width:48px;height:48px;border-radius:50%;object-fit:cover;margin-bottom:8px;display:block">
                <?php endif; ?>
                <input type="file" name="logo" class="form-input" accept="image/*" <?= $editing ? '' : 'required' ?>>
                <div class="form-hint">Square logo recommended, transparent PNG works best.</div>
            </div>
            <button type="submit" class="btn-primary" style="width:100%"><?= $editing ? 'Save Changes' : 'Add Coin' ?></button>
            <?php if ($editing): ?><a href="<?= SITE_URL ?>/admin/crypto-carousel.php" class="btn-ghost btn-sm" style="width:100%;text-align:center;margin-top:8px;display:block">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
