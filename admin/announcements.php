<?php
$adminTitle = 'Announcements';
require_once __DIR__ . '/header.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];

/**
 * Fan out an announcement to every active user via the existing notifications
 * table (reused as-is — same table/columns as badges, level ups, etc).
 * The link encodes the announcement id so republishing/editing never
 * re-notifies a user who already received this specific announcement.
 */
function fanOutAnnouncementNotifications(PDO $db, int $announcementId, string $title, string $body): void {
    $link = SITE_URL . '/notifications.php?ann=' . $announcementId;
    $stmt = $db->prepare("
        INSERT INTO notifications (user_id, type, title, body, link)
        SELECT u.id, 'announcement', ?, ?, ?
        FROM users u
        WHERE u.deleted_at IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM notifications n
              WHERE n.user_id = u.id AND n.type = 'announcement' AND n.link = ?
          )
    ");
    $stmt->execute([$title, $body, $link, $link]);
}

if ($action === 'toggle' && $id) {
    $db->prepare("UPDATE announcements SET is_active = NOT is_active WHERE id = ?")->execute([$id]);

    // If this flip just turned a never-published announcement on, treat it as publishing.
    $row = $db->prepare("SELECT * FROM announcements WHERE id=?"); $row->execute([$id]);
    $ann = $row->fetch();
    if ($ann && $ann['is_active'] && !$ann['published_at']) {
        $db->prepare("UPDATE announcements SET published_at=NOW() WHERE id=?")->execute([$id]);
        fanOutAnnouncementNotifications($db, $id, $ann['title'], $ann['body']);
    }

    header('Location: ' . SITE_URL . '/admin/announcements.php'); exit;
}
if ($action === 'delete' && $id) {
    $db->prepare("UPDATE announcements SET deleted_at=NOW(), deleted_by=? WHERE id=?")->execute([$_SESSION['admin_id'] ?? null, $id]);
    header('Location: ' . SITE_URL . '/admin/announcements.php?deleted=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $publishNow = isset($_POST['publish_now']);

    if (empty($title) || empty($body)) {
        $errors[] = 'Title and body are required.';
    } else {
        if ($id) {
            $existingStmt = $db->prepare("SELECT * FROM announcements WHERE id=?"); $existingStmt->execute([$id]);
            $existing = $existingStmt->fetch();

            $db->prepare("UPDATE announcements SET title=?, body=?, is_active=? WHERE id=?")->execute([$title, $body, $isActive, $id]);

            // If this announcement was never published before and is now being
            // saved as active, treat this save as the publish moment. Already
            // published announcements are never re-published from here, so
            // editing content never generates duplicate notifications.
            if ($existing && !$existing['published_at'] && $isActive) {
                $db->prepare("UPDATE announcements SET published_at=NOW() WHERE id=?")->execute([$id]);
                fanOutAnnouncementNotifications($db, $id, $title, $body);
            }

            $success = 'Announcement updated.';
        } else {
            $db->prepare("INSERT INTO announcements (title, body, is_active, published_at) VALUES (?,?,?,?)")
               ->execute([$title, $body, $isActive, $publishNow ? date('Y-m-d H:i:s') : null]);
            $newId = (int)$db->lastInsertId();

            // Broadcast: fan out a notification to every user via the existing
            // notifications table (read-state itself lives in
            // user_announcement_reads once a user opens the notification).
            if ($publishNow && $isActive) {
                fanOutAnnouncementNotifications($db, $newId, $title, $body);
                $success = 'Announcement published and is now live site-wide.';
            } elseif ($publishNow) {
                $success = 'Announcement saved, but is disabled so no notifications were sent.';
            } else {
                $success = 'Announcement saved as draft.';
            }
        }
        $action = 'list'; $id = 0;
    }
}

$announcements = $db->query("
    SELECT a.*, (SELECT COUNT(*) FROM user_announcement_reads WHERE announcement_id=a.id) AS read_count
    FROM announcements a WHERE a.deleted_at IS NULL ORDER BY a.created_at DESC
")->fetchAll();
$editing = null;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM announcements WHERE id=?"); $stmt->execute([$id]);
    $editing = $stmt->fetch();
}
?>

<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Announcement deleted.</div><?php endif; ?>
<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Announcements</h1>
    <a href="?action=edit" class="btn-primary btn-sm">+ New Announcement</a>
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Title</th><th>Status</th><th>Published</th><th>Read by</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($announcements as $a): ?>
<tr>
    <td style="font-weight:600"><?= sanitize($a['title']) ?></td>
    <td>
        <a href="?action=toggle&id=<?= $a['id'] ?>" style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;text-decoration:none;<?= $a['is_active'] ? 'background:rgba(11,121,88,0.12);color:#0B7958' : 'background:rgba(148,148,163,0.12);color:var(--text-muted)' ?>">
            <?= $a['is_active'] ? 'Active' : 'Disabled' ?>
        </a>
    </td>
    <td style="color:var(--text-muted);font-size:0.82rem"><?= $a['published_at'] ? date('M j, Y', strtotime($a['published_at'])) : 'Draft' ?></td>
    <td style="text-align:center;font-family:var(--font-mono)"><?= (int)$a['read_count'] ?></td>
    <td>
        <div style="display:flex;gap:6px">
            <a href="?action=edit&id=<?= $a['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <a href="?action=delete&id=<?= $a['id'] ?>" class="btn-danger btn-sm" onclick="return confirm('Delete this announcement?')">Delete</a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($announcements)): ?><tr><td colspan="5" class="admin-empty">No announcements yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php else: ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
    <h1 class="admin-page-title" style="margin:0"><?= $id ? 'Edit Announcement' : 'New Announcement' ?></h1>
    <a href="<?= SITE_URL ?>/admin/announcements.php" class="btn-ghost btn-sm">← Back</a>
</div>

<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:28px;max-width:640px">
<form method="POST">
    <div class="form-group">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-input" value="<?= sanitize($editing['title'] ?? '') ?>" required>
    </div>
    <div class="form-group">
        <label class="form-label">Body</label>
        <textarea name="body" class="form-input" rows="6" required><?= sanitize($editing['body'] ?? '') ?></textarea>
    </div>
    <div class="form-group" style="display:flex;gap:20px">
        <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem">
            <input type="checkbox" name="is_active" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>> Active
        </label>
        <?php if (!$id): ?>
        <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem">
            <input type="checkbox" name="publish_now" checked> Publish immediately
        </label>
        <?php endif; ?>
    </div>
    <button type="submit" class="btn-primary"><?= $id ? 'Save Changes' : 'Create Announcement' ?></button>
</form>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
