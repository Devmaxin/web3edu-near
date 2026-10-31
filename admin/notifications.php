<?php
$adminTitle = 'Notifications';
require_once __DIR__ . '/header.php';

$db = getDB();
$success = ''; $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $targetUserId = intval($_POST['user_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body'] ?? '');
    $link  = trim($_POST['link'] ?? '');

    if (!$targetUserId || empty($title)) {
        $errors[] = 'Please select a user and enter a title.';
    } else {
        $db->prepare("INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, 'admin_message', ?, ?, ?)")
           ->execute([$targetUserId, $title, $body ?: null, $link ?: null]);
        $success = 'Notification sent.';
    }
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;
$typeFilter = trim($_GET['type'] ?? '');

$where = '1=1';
$params = [];
if ($typeFilter) { $where .= ' AND n.type = ?'; $params[] = $typeFilter; }

$total = $db->prepare("SELECT COUNT(*) FROM notifications n WHERE $where");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$pages = max(1, (int)ceil($totalCount / $perPage));

$stmt = $db->prepare("
    SELECT n.*, u.username, u.email
    FROM notifications n LEFT JOIN users u ON u.id = n.user_id
    WHERE $where ORDER BY n.created_at DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$types = $db->query("SELECT DISTINCT type FROM notifications ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
$typeCounts = $db->query("SELECT type, COUNT(*) c FROM notifications GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<h1 class="admin-page-title" style="margin-bottom:24px">Notifications</h1>

<div class="admin-stats-grid">
    <div class="admin-stat-card"><span class="value"><?= number_format($totalCount) ?></span><span class="label">Total sent</span></div>
    <?php foreach ($typeCounts as $type => $count): ?>
    <div class="admin-stat-card"><span class="value"><?= number_format($count) ?></span><span class="label"><?= sanitize(str_replace('_', ' ', $type)) ?></span></div>
    <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start">
    <div>
        <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
            <a href="?" class="btn-<?= $typeFilter === '' ? 'primary' : 'ghost' ?> btn-sm">All</a>
            <?php foreach ($types as $t): ?>
                <a href="?type=<?= urlencode($t) ?>" class="btn-<?= $typeFilter === $t ? 'primary' : 'ghost' ?> btn-sm"><?= sanitize(str_replace('_', ' ', ucfirst($t))) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="admin-table-wrap">
        <table class="admin-table">
        <thead><tr><th>User</th><th>Type</th><th>Title</th><th>Sent</th><th>Read</th></tr></thead>
        <tbody>
        <?php foreach ($notifications as $n): ?>
        <tr>
            <td style="font-size:0.82rem"><?= sanitize($n['username'] ?? $n['email'] ?? 'Unknown') ?></td>
            <td><span style="padding:2px 8px;border-radius:20px;font-size:0.68rem;background:var(--glow-soft);color:var(--primary-bright)"><?= sanitize(str_replace('_', ' ', $n['type'])) ?></span></td>
            <td style="font-size:0.82rem"><?= sanitize($n['title']) ?></td>
            <td style="color:var(--text-muted);font-size:0.78rem"><?= date('M j, g:ia', strtotime($n['created_at'])) ?></td>
            <td style="text-align:center"><?= $n['is_read'] ? '✓' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($notifications)): ?><tr><td colspan="5" class="admin-empty">No notifications yet.</td></tr><?php endif; ?>
        </tbody>
        </table>
        </div>

        <?php if ($pages > 1): ?>
        <div style="display:flex;gap:6px;justify-content:center;margin-top:16px">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <a href="?page=<?= $i ?>&type=<?= urlencode($typeFilter) ?>" class="btn-<?= $i === $page ? 'primary' : 'ghost' ?> btn-sm"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
        <h2 style="font-size:1rem;margin-bottom:16px">Send Manual Notification</h2>
        <form method="POST">
            <input type="hidden" name="action" value="send">
            <div class="form-group">
                <label class="form-label">User email or username</label>
                <input type="text" id="userSearch" class="form-input" placeholder="Start typing…" autocomplete="off">
                <input type="hidden" name="user_id" id="userIdField">
                <div id="userSearchResults" style="margin-top:4px;font-size:0.8rem"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">Body (optional)</label>
                <textarea name="body" class="form-input" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Link (optional)</label>
                <input type="text" name="link" class="form-input" placeholder="<?= SITE_URL ?>/...">
            </div>
            <button type="submit" class="btn-primary" style="width:100%">Send</button>
        </form>
    </div>
</div>

<script>
// Lightweight inline user lookup — reuses the existing admin users list rather than a new API endpoint
let searchTimeout;
document.getElementById('userSearch').addEventListener('input', function () {
    clearTimeout(searchTimeout);
    const q = this.value.trim();
    const resultsBox = document.getElementById('userSearchResults');
    if (q.length < 2) { resultsBox.innerHTML = ''; return; }
    searchTimeout = setTimeout(async () => {
        const res = await fetch('<?= SITE_URL ?>/admin/users.php?ajax_search=' + encodeURIComponent(q));
        if (!res.ok) return;
        const users = await res.json().catch(() => []);
        resultsBox.innerHTML = users.slice(0, 6).map(u =>
            `<div style="padding:6px 8px;cursor:pointer;border-radius:6px" onclick="selectUser(${u.id}, '${u.username || u.email}')" onmouseover="this.style.background='var(--glow-soft)'" onmouseout="this.style.background='none'">${u.username || ''} <span style="color:var(--text-muted)">${u.email}</span></div>`
        ).join('') || '<div style="color:var(--text-muted);padding:6px 8px">No matches</div>';
    }, 300);
});
function selectUser(id, label) {
    document.getElementById('userIdField').value = id;
    document.getElementById('userSearch').value = label;
    document.getElementById('userSearchResults').innerHTML = '';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
