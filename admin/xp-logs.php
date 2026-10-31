<?php
/**
 * Admin — XP Logs & Analytics
 * Read-only view over the existing user_activity_log (the same ledger
 * awardXp() already writes to) plus a few aggregate stats. No new tables,
 * no new XP-writing logic — this only reads what already exists.
 */
$adminTitle = 'XP Logs & Analytics';
require_once __DIR__ . '/header.php';

$db = getDB();
$tab = $_GET['tab'] ?? 'logs';

$filterUser = trim($_GET['username'] ?? '');
$filterAction = $_GET['action_key'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($filterUser !== '') {
    $where[] = 'u.username LIKE ?';
    $params[] = '%' . $filterUser . '%';
}
if ($filterAction !== '') {
    $where[] = 'l.action_key = ?';
    $params[] = $filterAction;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$actionKeys = $db->query("SELECT DISTINCT action_key FROM user_activity_log ORDER BY action_key ASC")->fetchAll(PDO::FETCH_COLUMN);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM user_activity_log l INNER JOIN users u ON u.id=l.user_id $whereSql");
$totalStmt->execute($params);
$totalRows = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($totalRows / $perPage));

$logStmt = $db->prepare("
    SELECT l.*, u.username FROM user_activity_log l
    INNER JOIN users u ON u.id = l.user_id
    $whereSql
    ORDER BY l.created_at DESC LIMIT $perPage OFFSET $offset
");
$logStmt->execute($params);
$logs = $logStmt->fetchAll();

// ---- Analytics tab: reuses existing tables, aggregate-only queries ----
if ($tab === 'analytics') {
    $totalXpAwarded = (int)$db->query("SELECT COALESCE(SUM(xp_awarded),0) FROM user_activity_log")->fetchColumn();
    $topActions = $db->query("
        SELECT action_key, COUNT(*) AS cnt, SUM(xp_awarded) AS total_xp
        FROM user_activity_log GROUP BY action_key ORDER BY cnt DESC LIMIT 10
    ")->fetchAll();
    $totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $activeCareerPathUsers = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM user_career_paths WHERE status='active'")->fetchColumn();
    $completedCareerPaths = (int)$db->query("SELECT COUNT(*) FROM user_career_paths WHERE status='completed'")->fetchColumn();
    $totalCertificates = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE revoked_at IS NULL")->fetchColumn();
    $approvedProjects = (int)$db->query("SELECT COUNT(*) FROM project_submissions WHERE status='approved'")->fetchColumn();
    $activeOpportunities = (int)$db->query("SELECT COUNT(*) FROM opportunities WHERE status='active' AND deleted_at IS NULL")->fetchColumn();
    $topCareerPaths = $db->query("
        SELECT cp.name, COUNT(*) AS cnt FROM user_career_paths ucp
        INNER JOIN career_paths cp ON cp.id = ucp.career_path_id
        GROUP BY cp.id ORDER BY cnt DESC LIMIT 5
    ")->fetchAll();
}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">XP Logs & Analytics</h1>
    <div style="display:flex;gap:8px">
        <a href="?tab=logs" class="btn-<?= $tab==='logs'?'primary':'ghost' ?> btn-sm">XP Logs</a>
        <a href="?tab=analytics" class="btn-<?= $tab==='analytics'?'primary':'ghost' ?> btn-sm">Analytics</a>
    </div>
</div>

<?php if ($tab === 'logs'): ?>

<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
    <input type="hidden" name="tab" value="logs">
    <input type="text" name="username" class="form-input" placeholder="Filter by username…" value="<?= sanitize($filterUser) ?>" style="width:220px">
    <select name="action_key" class="form-select" style="width:220px" onchange="this.form.submit()">
        <option value="">All Actions</option>
        <?php foreach ($actionKeys as $ak): ?>
        <option value="<?= sanitize($ak) ?>" <?= $filterAction===$ak?'selected':'' ?>><?= sanitize($ak) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn-primary btn-sm" type="submit">Filter</button>
</form>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>User</th><th>Action</th><th>Reference</th><th>XP</th><th>When</th></tr></thead>
<tbody>
<?php foreach ($logs as $l): ?>
<tr>
    <td style="font-weight:600"><?= sanitize($l['username']) ?></td>
    <td><?= sanitize($l['action_key']) ?></td>
    <td style="font-size:0.78rem;color:var(--text-muted)"><?= sanitize($l['reference_type'] ?: '—') ?><?= $l['reference_id'] ? ' #' . $l['reference_id'] : '' ?></td>
    <td style="font-weight:700;color:var(--primary-bright)"><?= (int)$l['xp_awarded'] >= 0 ? '+' : '' ?><?= (int)$l['xp_awarded'] ?></td>
    <td style="font-size:0.78rem;color:var(--text-muted)"><?= date('M j, Y g:ia', strtotime($l['created_at'])) ?></td>
</tr>
<?php endforeach; ?>
<?php if (empty($logs)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">No matching activity.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<div style="display:flex;gap:6px;margin-top:16px;flex-wrap:wrap">
<?php for ($i = 1; $i <= $pages; $i++): ?>
    <a href="?tab=logs&page=<?= $i ?>&username=<?= urlencode($filterUser) ?>&action_key=<?= urlencode($filterAction) ?>" class="btn-<?= $i===$page?'primary':'ghost' ?> btn-sm"><?= $i ?></a>
<?php endfor; ?>
</div>
<?php endif; ?>

<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px">
    <div class="admin-stat-card"><div class="value"><?= number_format($totalXpAwarded) ?></div><div class="label">Total XP Awarded</div></div>
    <div class="admin-stat-card"><div class="value"><?= number_format($totalUsers) ?></div><div class="label">Total Users</div></div>
    <div class="admin-stat-card"><div class="value"><?= number_format($activeCareerPathUsers) ?></div><div class="label">Active Career Paths</div></div>
    <div class="admin-stat-card"><div class="value"><?= number_format($completedCareerPaths) ?></div><div class="label">Career Paths Completed</div></div>
    <div class="admin-stat-card"><div class="value"><?= number_format($totalCertificates) ?></div><div class="label">Certificates Issued</div></div>
    <div class="admin-stat-card"><div class="value"><?= number_format($approvedProjects) ?></div><div class="label">Projects Approved</div></div>
    <div class="admin-stat-card"><div class="value"><?= number_format($activeOpportunities) ?></div><div class="label">Active Opportunities</div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px">
        <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:12px">Top XP-Earning Actions</h2>
        <?php foreach ($topActions as $a): ?>
        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:0.84rem">
            <span><?= sanitize($a['action_key']) ?></span>
            <span style="color:var(--text-muted)"><?= number_format($a['cnt']) ?>× · <?= number_format($a['total_xp']) ?> XP</span>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px">
        <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:12px">Most-Chosen Career Paths</h2>
        <?php foreach ($topCareerPaths as $cp): ?>
        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:0.84rem">
            <span><?= sanitize($cp['name']) ?></span>
            <span style="color:var(--text-muted)"><?= number_format($cp['cnt']) ?> users</span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($topCareerPaths)): ?><p style="color:var(--text-muted);font-size:0.82rem">No data yet.</p><?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
