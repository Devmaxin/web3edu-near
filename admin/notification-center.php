<?php
/**
 * Admin Notification Center (Phase 2, feature 4).
 *
 * Distinct from admin/notifications.php (which sends manual notifications
 * TO users and browses the `notifications` table). This page is the reader
 * for `admin_notifications` — the table notifyAdmins()/adminNotify() in
 * includes/notifications/NotificationService.php have been writing to
 * since Phase 1 with no UI to view them until now. Same table, first UI.
 */
$adminTitle = 'Notification Center';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/lucide.php'; // lucide() is only auto-loaded via bounties.php elsewhere — this page needs it directly

$db = getDB();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    if ($postAction === 'mark_read' && !empty($_POST['id'])) {
        $db->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = ?")->execute([(int)$_POST['id']]);
    } elseif ($postAction === 'mark_all_read') {
        $db->exec("UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0");
        $success = 'All notifications marked as read.';
    } elseif ($postAction === 'send_daily_summary') {
        // Manual trigger for testing the Daily Summary Service — no cron
        // exists yet (see includes/notifications/DailySummaryService.php),
        // this button just calls the same function a future cron would.
        generateDailySummary();
        $success = 'Daily summary generated and queued.';
    } elseif ($postAction === 'send_analytics_daily') {
        require_once __DIR__ . '/../includes/analytics/AnalyticsReportService.php';
        generateDailyAnalyticsReport();
        $success = 'Daily Analytics Report generated and queued.';
    } elseif ($postAction === 'send_analytics_weekly') {
        require_once __DIR__ . '/../includes/analytics/AnalyticsReportService.php';
        generateWeeklyAnalyticsReport();
        $success = 'Weekly Analytics Report generated and queued.';
    }
    $__manualActions = ['send_daily_summary', 'mark_all_read', 'send_analytics_daily', 'send_analytics_weekly'];
    if (!in_array($postAction, $__manualActions, true)) {
        header('Location: ' . SITE_URL . '/admin/notification-center.php' . (isset($_GET['page']) ? '?page=' . (int)$_GET['page'] : ''));
        exit;
    }
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$filterType = trim($_GET['type'] ?? '');
$filterUnread = isset($_GET['unread']) && $_GET['unread'] === '1';
$search = trim($_GET['search'] ?? '');

$where = ['1=1'];
$params = [];
if ($filterType !== '') { $where[] = 'type = ?'; $params[] = $filterType; }
if ($filterUnread) { $where[] = 'is_read = 0'; }
if ($search !== '') {
    $where[] = '(title LIKE ? OR body LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
}
$whereSql = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM admin_notifications WHERE $whereSql");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$pages = max(1, (int)ceil($totalCount / $perPage));

$stmt = $db->prepare("SELECT * FROM admin_notifications WHERE $whereSql ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$items = $stmt->fetchAll();

$unreadCount = (int)$db->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0")->fetchColumn();
$types = $db->query("SELECT DISTINCT type FROM admin_notifications ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);

function adminNotifTypeLabel(string $type): string
{
    return match (true) {
        $type === 'daily_summary' => 'Daily Summary',
        $type === 'analytics_daily_report' => 'Daily Analytics',
        $type === 'analytics_weekly_report' => 'Weekly Analytics',
        str_starts_with($type, 'host_profile_') => 'Host Profile',
        str_starts_with($type, 'bounty_payment_') => 'Payment',
        str_starts_with($type, 'bounty_') => 'Bounty',
        $type === 'subscription_purchased' || $type === 'subscription_renewed' || $type === 'subscription_expired' => 'Subscription',
        default => ucfirst(str_replace('_', ' ', $type)),
    };
}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Notification Center</h1>
    <div style="display:flex;gap:8px">
        <form method="POST" onsubmit="return confirm('Generate and send the daily summary now?');">
            <input type="hidden" name="action" value="send_daily_summary">
            <button type="submit" class="btn-ghost btn-sm"><?= lucide('send', ['size' => 13]) ?> Send Daily Summary Now</button>
        </form>
        <form method="POST" onsubmit="return confirm('Generate and send the Daily Analytics Report now?');">
            <input type="hidden" name="action" value="send_analytics_daily">
            <button type="submit" class="btn-ghost btn-sm"><?= lucide('bar-chart', ['size' => 13]) ?> Send Daily Analytics Now</button>
        </form>
        <form method="POST" onsubmit="return confirm('Generate and send the Weekly Analytics Report now?');">
            <input type="hidden" name="action" value="send_analytics_weekly">
            <button type="submit" class="btn-ghost btn-sm"><?= lucide('calendar', ['size' => 13]) ?> Send Weekly Analytics Now</button>
        </form>
        <form method="POST">
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn-primary btn-sm"<?= $unreadCount === 0 ? ' disabled' : '' ?>><?= lucide('check', ['size' => 13]) ?> Mark all read</button>
        </form>
    </div>
</div>

<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>

<div class="admin-stats-grid" style="margin-bottom:20px">
    <div class="admin-stat-card"><span class="value"><?= number_format($totalCount) ?></span><span class="label">Total</span></div>
    <div class="admin-stat-card"><span class="value"><?= number_format($unreadCount) ?></span><span class="label">Unread</span></div>
</div>

<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:end">
    <div class="form-group" style="margin:0;min-width:220px">
        <label class="form-label">Search</label>
        <input type="text" name="search" class="form-input" placeholder="Title or body…" value="<?= sanitize($search) ?>">
    </div>
    <div class="form-group" style="margin:0;min-width:180px">
        <label class="form-label">Type</label>
        <select name="type" class="form-select">
            <option value="">All Types</option>
            <?php foreach ($types as $t): ?><option value="<?= sanitize($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= sanitize(adminNotifTypeLabel($t)) ?></option><?php endforeach; ?>
        </select>
    </div>
    <label style="display:flex;align-items:center;gap:6px;font-size:0.82rem;padding-bottom:9px">
        <input type="checkbox" name="unread" value="1" <?= $filterUnread ? 'checked' : '' ?> onchange="this.form.submit()"> Unread only
    </label>
    <button type="submit" class="btn-primary btn-sm"><?= lucide('filter', ['size' => 13]) ?> Filter</button>
    <a href="<?= SITE_URL ?>/admin/notification-center.php" class="btn-ghost btn-sm">Clear</a>
</form>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th></th><th>Type</th><th>Title</th><th>Body</th><th>Received</th><th></th></tr></thead>
<tbody>
<?php foreach ($items as $n): ?>
<tr style="<?= $n['is_read'] ? '' : 'background:rgba(139,92,246,0.05)' ?>">
    <td style="width:10px"><?php if (!$n['is_read']): ?><span title="Unread" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--primary-bright)"></span><?php endif; ?></td>
    <td><span style="padding:2px 8px;border-radius:20px;font-size:0.68rem;background:var(--glow-soft);color:var(--primary-bright);white-space:nowrap"><?= sanitize(adminNotifTypeLabel($n['type'])) ?></span></td>
    <td style="font-size:0.82rem;font-weight:<?= $n['is_read'] ? '400' : '700' ?>">
        <?php if (!empty($n['link'])): ?><a href="<?= sanitize($n['link']) ?>" style="color:inherit"><?= sanitize($n['title']) ?></a><?php else: ?><?= sanitize($n['title']) ?><?php endif; ?>
    </td>
    <td style="font-size:0.78rem;color:var(--text-muted);max-width:360px"><?= sanitize(mb_strimwidth((string)($n['body'] ?? ''), 0, 140, '…')) ?></td>
    <td style="color:var(--text-muted);font-size:0.75rem;white-space:nowrap"><?= date('M j, g:ia', strtotime($n['created_at'])) ?> <span title="<?= sanitize($n['created_at']) ?>">· <?= sanitize(timeAgo($n['created_at'])) ?></span></td>
    <td>
        <?php if (!$n['is_read']): ?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="mark_read">
            <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
            <button type="submit" class="btn-ghost btn-sm" style="padding:4px 8px" title="Mark as read"><?= lucide('check', ['size' => 12]) ?></button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($items)): ?><tr><td colspan="6" class="admin-empty"><?= lucide('inbox', ['size' => 16]) ?> No admin notifications match these filters.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<div style="display:flex;gap:6px;justify-content:center;margin-top:16px">
    <?php
    $qs = fn($p) => http_build_query(array_filter(['page' => $p, 'type' => $filterType, 'unread' => $filterUnread ? '1' : '', 'search' => $search]));
    for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?<?= $qs($i) ?>" class="btn-<?= $i === $page ? 'primary' : 'ghost' ?> btn-sm"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
