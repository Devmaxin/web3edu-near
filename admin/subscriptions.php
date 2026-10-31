<?php
$adminTitle = 'Subscriptions';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/subscriptions.php';

$db = getDB();

// Opportunistically catch up any lapsed-but-still-"active" rows before we
// render stats/lists, so what the admin sees here always matches reality
// even if cron hasn't run recently (see userHasActivePremium()'s docblock
// in includes/subscriptions.php for the same reasoning applied per-user).
checkAndExpireSubscriptions();

$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];

// ── ADMIN ACTIONS ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['do'] ?? '';
    $subId      = intval($_POST['subscription_id'] ?? 0);
    $days       = intval($_POST['days'] ?? 0);

    if ($subId && $postAction === 'extend' && $days > 0) {
        $success = adminExtendSubscription($subId, $days) ? "Extended by {$days} day(s)." : 'Could not extend that subscription.';
    } elseif ($subId && $postAction === 'reduce' && $days > 0) {
        $success = adminReduceSubscription($subId, $days) ? "Reduced by {$days} day(s)." : 'Could not reduce that subscription (permanent grants have no expiry to reduce).';
    } elseif ($subId && $postAction === 'cancel') {
        $success = adminCancelSubscription($subId) ? 'Subscription cancelled — access revoked immediately.' : 'Could not cancel that subscription.';
    } elseif ($subId && $postAction === 'reactivate') {
        $success = adminReactivateSubscription($subId) ? 'Subscription reactivated with a fresh billing period.' : 'Could not reactivate that subscription.';
    } else {
        $errors[] = 'Invalid action.';
    }
    $action = 'view';
    $id = $subId;
}

// ── VIEW: single subscription detail ────────────────────────
if ($action === 'view' && $id) {
    $sub = getSubscriptionById($id);
    if (!$sub) { header('Location: ' . SITE_URL . '/admin/subscriptions.php'); exit; }
    $payments = getSubscriptionPayments($id);
    $history  = getSubscriptionHistory($id);
}

// ── LIST: stats + filterable table ──────────────────────────
if ($action === 'list') {
    $stats = [
        'total_premium' => $db->query("SELECT COUNT(*) FROM user_subscriptions WHERE status='active' AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn(),
        'monthly_subs'  => $db->query("SELECT COUNT(*) FROM user_subscriptions us INNER JOIN premium_plans pp ON pp.id=us.plan_id WHERE us.status='active' AND (us.expires_at IS NULL OR us.expires_at > NOW()) AND pp.billing_cycle='monthly'")->fetchColumn(),
        'yearly_subs'   => $db->query("SELECT COUNT(*) FROM user_subscriptions us INNER JOIN premium_plans pp ON pp.id=us.plan_id WHERE us.status='active' AND (us.expires_at IS NULL OR us.expires_at > NOW()) AND pp.billing_cycle='yearly'")->fetchColumn(),
        'expired'       => $db->query("SELECT COUNT(*) FROM user_subscriptions WHERE status='expired'")->fetchColumn(),
        'expiring_7'    => $db->query("SELECT COUNT(*) FROM user_subscriptions WHERE status='active' AND expires_at IS NOT NULL AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
        'expiring_30'   => $db->query("SELECT COUNT(*) FROM user_subscriptions WHERE status='active' AND expires_at IS NOT NULL AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)")->fetchColumn(),
        'monthly_rev'   => $db->query("SELECT COALESCE(SUM(amount),0) FROM purchases WHERE status='success' AND billing_cycle='monthly'")->fetchColumn(),
        'yearly_rev'    => $db->query("SELECT COALESCE(SUM(amount),0) FROM purchases WHERE status='success' AND billing_cycle='yearly'")->fetchColumn(),
    ];

    $filter = $_GET['filter'] ?? 'all';
    $search = trim($_GET['search'] ?? '');

    $where  = ['1=1'];
    $params = [];

    if ($search !== '') {
        $where[] = '(u.username LIKE ? OR u.email LIKE ?)';
        $params[] = "%$search%"; $params[] = "%$search%";
    }

    switch ($filter) {
        case 'active':        $where[] = "us.status='active' AND (us.expires_at IS NULL OR us.expires_at > NOW())"; break;
        case 'expired':       $where[] = "us.status='expired'"; break;
        case 'cancelled':     $where[] = "us.status='cancelled'"; break;
        case 'pending':       $where[] = "us.status='pending'"; break;
        case 'monthly':       $where[] = "pp.billing_cycle='monthly'"; break;
        case 'yearly':        $where[] = "pp.billing_cycle='yearly'"; break;
        case 'expiring_soon': $where[] = "us.status='active' AND us.expires_at IS NOT NULL AND us.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)"; break;
    }

    $whereSql = implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT us.*, pp.name AS plan_name, pp.billing_cycle, u.username, u.email,
               (SELECT p.reference FROM purchases p WHERE p.subscription_id = us.id ORDER BY p.created_at DESC LIMIT 1) AS last_reference
        FROM user_subscriptions us
        INNER JOIN premium_plans pp ON pp.id = us.plan_id
        INNER JOIN users u ON u.id = us.user_id
        WHERE $whereSql
        ORDER BY us.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $subscriptions = $stmt->fetchAll();
}
?>

<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<?php if ($action === 'view' && isset($sub)): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Subscription #<?= (int)$sub['id'] ?></h1>
    <a href="<?= SITE_URL ?>/admin/subscriptions.php" class="btn-ghost btn-sm">← Back to all subscriptions</a>
</div>

<style>
  @media (max-width: 900px) { .sub-detail-grid { grid-template-columns: 1fr !important; } }
</style>
<div class="sub-detail-grid" style="display:grid;grid-template-columns:2fr 1fr;gap:24px;align-items:start">
  <div>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;margin-bottom:24px">
        <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px">Details</h2>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:0.875rem">
            <div><div style="color:var(--text-muted);font-size:0.72rem;text-transform:uppercase;margin-bottom:2px">User</div><div style="font-weight:600"><?= sanitize($sub['username'] ?? '—') ?></div></div>
            <div><div style="color:var(--text-muted);font-size:0.72rem;text-transform:uppercase;margin-bottom:2px">Email</div><div><?= sanitize($sub['email']) ?></div></div>
            <div><div style="color:var(--text-muted);font-size:0.72rem;text-transform:uppercase;margin-bottom:2px">Plan</div><div><?= sanitize($sub['plan_name']) ?> (<?= ucfirst($sub['billing_cycle']) ?>)</div></div>
            <div><div style="color:var(--text-muted);font-size:0.72rem;text-transform:uppercase;margin-bottom:2px">Status</div><div>
                <span style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;<?php
                    echo match($sub['status']) {
                        'active'    => 'background:rgba(34,197,94,0.15);color:#86efac',
                        'expired'   => 'background:rgba(239,68,68,0.15);color:#fca5a5',
                        'cancelled' => 'background:rgba(148,148,163,0.15);color:var(--text-muted)',
                        default     => 'background:rgba(245,158,11,0.15);color:#fcd34d',
                    };
                ?>"><?= ucfirst($sub['status']) ?></span>
            </div></div>
            <div><div style="color:var(--text-muted);font-size:0.72rem;text-transform:uppercase;margin-bottom:2px">Started</div><div><?= $sub['started_at'] ? date('M j, Y', strtotime($sub['started_at'])) : '—' ?></div></div>
            <div><div style="color:var(--text-muted);font-size:0.72rem;text-transform:uppercase;margin-bottom:2px">Expires</div><div><?= $sub['expires_at'] ? date('M j, Y · H:i', strtotime($sub['expires_at'])) : 'Never (permanent)' ?></div></div>
            <div><div style="color:var(--text-muted);font-size:0.72rem;text-transform:uppercase;margin-bottom:2px">Payment method</div><div><?= ucfirst($sub['provider']) ?></div></div>
            <div><div style="color:var(--text-muted);font-size:0.72rem;text-transform:uppercase;margin-bottom:2px">Auto-renew</div><div><?= $sub['cancel_at_period_end'] ? 'No — cancelled' : ($sub['status']==='active' ? 'Yes' : '—') ?></div></div>
        </div>
    </div>

    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;margin-bottom:24px">
        <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px">Payment history</h2>
        <?php if (empty($payments)): ?>
        <p style="color:var(--text-muted);font-size:0.875rem">No payments recorded for this subscription yet.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>Reference</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
            <tr>
                <td style="font-family:var(--font-mono);font-size:0.72rem;color:var(--text-muted)"><?= sanitize($p['reference']) ?></td>
                <td style="color:var(--primary-bright);font-weight:600">₦<?= number_format($p['amount'], 2) ?></td>
                <td><?= ucfirst($p['status']) ?></td>
                <td style="color:var(--text-muted);font-size:0.78rem"><?= date('M j, Y · H:i', strtotime($p['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
        <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px">History</h2>
        <?php if (empty($history)): ?>
        <p style="color:var(--text-muted);font-size:0.875rem">No history recorded yet.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px">
            <?php foreach ($history as $h): ?>
            <div style="display:flex;justify-content:space-between;gap:12px;padding-bottom:10px;border-bottom:1px solid var(--border);font-size:0.84rem">
                <div>
                    <span style="font-weight:600;text-transform:capitalize"><?= sanitize($h['event']) ?></span>
                    <?php if ($h['note']): ?><div style="color:var(--text-muted);font-size:0.78rem;margin-top:2px"><?= sanitize($h['note']) ?></div><?php endif; ?>
                    <?php if ($h['reference']): ?><div style="color:var(--text-muted);font-size:0.72rem;font-family:var(--font-mono)"><?= sanitize($h['reference']) ?><?= $h['amount'] ? ' · ₦'.number_format($h['amount'],2) : '' ?></div><?php endif; ?>
                </div>
                <div style="color:var(--text-muted);font-size:0.75rem;white-space:nowrap"><?= date('M j, Y · H:i', strtotime($h['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
  </div>

  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px">Actions</h2>

    <?php if ($sub['expires_at']): ?>
    <form method="POST" style="margin-bottom:16px">
        <input type="hidden" name="subscription_id" value="<?= (int)$sub['id'] ?>">
        <input type="hidden" name="do" value="extend">
        <label class="form-label">Extend by (days)</label>
        <div style="display:flex;gap:8px">
            <input type="number" name="days" class="form-input" min="1" value="30" required>
            <button type="submit" class="btn-primary btn-sm">Extend</button>
        </div>
    </form>

    <form method="POST" style="margin-bottom:16px">
        <input type="hidden" name="subscription_id" value="<?= (int)$sub['id'] ?>">
        <input type="hidden" name="do" value="reduce">
        <label class="form-label">Reduce by (days)</label>
        <div style="display:flex;gap:8px">
            <input type="number" name="days" class="form-input" min="1" value="7" required>
            <button type="submit" class="btn-secondary btn-sm" onclick="return confirm('Reduce this subscription\'s remaining time?')">Reduce</button>
        </div>
    </form>
    <?php else: ?>
    <p style="color:var(--text-muted);font-size:0.8rem;margin-bottom:16px">This is a permanent grant (no expiry) — extend/reduce don't apply. Cancel to revoke it.</p>
    <?php endif; ?>

    <?php if ($sub['status'] === 'active'): ?>
    <form method="POST" style="margin-bottom:10px" onsubmit="return confirm('Cancel this subscription immediately? The user loses premium access right away.')">
        <input type="hidden" name="subscription_id" value="<?= (int)$sub['id'] ?>">
        <input type="hidden" name="do" value="cancel">
        <button type="submit" class="btn-danger btn-sm" style="width:100%">Cancel subscription (immediate)</button>
    </form>
    <?php elseif (in_array($sub['status'], ['expired','cancelled'], true)): ?>
    <form method="POST" onsubmit="return confirm('Reactivate this subscription with a fresh billing period starting today?')">
        <input type="hidden" name="subscription_id" value="<?= (int)$sub['id'] ?>">
        <input type="hidden" name="do" value="reactivate">
        <button type="submit" class="btn-primary btn-sm" style="width:100%">Reactivate</button>
    </form>
    <?php endif; ?>

    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
        <a href="<?= SITE_URL ?>/admin/users.php?search=<?= urlencode($sub['email']) ?>" class="btn-ghost btn-sm" style="width:100%;text-align:center;display:block">View user profile</a>
    </div>
  </div>
</div>

<?php elseif ($action === 'list'): ?>

<h1 class="admin-page-title" style="margin-bottom:20px">Subscriptions</h1>

<div class="admin-stats-grid">
    <div class="admin-stat-card"><span class="value"><?= number_format((int)$stats['total_premium']) ?></span><span class="label">Total Premium Users</span></div>
    <div class="admin-stat-card"><span class="value"><?= number_format((int)$stats['monthly_subs']) ?></span><span class="label">Monthly Subscribers</span></div>
    <div class="admin-stat-card"><span class="value"><?= number_format((int)$stats['yearly_subs']) ?></span><span class="label">Yearly Subscribers</span></div>
    <div class="admin-stat-card"><span class="value"><?= number_format((int)$stats['expired']) ?></span><span class="label">Expired Subscribers</span></div>
    <div class="admin-stat-card"><span class="value"><?= number_format((int)$stats['expiring_7']) ?></span><span class="label">Expiring in 7 Days</span></div>
    <div class="admin-stat-card"><span class="value"><?= number_format((int)$stats['expiring_30']) ?></span><span class="label">Expiring in 30 Days</span></div>
    <div class="admin-stat-card"><span class="value">₦<?= number_format((float)$stats['monthly_rev'], 2) ?></span><span class="label">Monthly Revenue</span></div>
    <div class="admin-stat-card"><span class="value">₦<?= number_format((float)$stats['yearly_rev'], 2) ?></span><span class="label">Yearly Revenue</span></div>
</div>

<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap">
    <form style="flex:1;min-width:200px" method="GET">
        <div class="search-wrap">
            <span class="search-icon"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
            <input type="text" name="search" class="search-input" placeholder="Search by email or username..." value="<?= sanitize($search) ?>">
            <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= sanitize($filter) ?>"><?php endif; ?>
        </div>
    </form>
    <div class="tabs" style="flex-wrap:wrap">
        <?php foreach (['all'=>'All','active'=>'Active','expired'=>'Expired','monthly'=>'Monthly','yearly'=>'Yearly','expiring_soon'=>'Expiring Soon','cancelled'=>'Cancelled','pending'=>'Pending'] as $f => $label): ?>
        <a href="?filter=<?= $f ?><?= $search?'&search='.urlencode($search):'' ?>" class="tab-btn <?= $filter===$f?'active':'' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="admin-table-wrap">
<table class="admin-table">
    <thead><tr>
        <th>User</th><th>Plan</th><th>Started</th><th>Expires</th><th>Days Left</th><th>Status</th><th>Payment</th><th>Reference</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($subscriptions as $s):
        $daysLeft = $s['expires_at'] ? (int)ceil((strtotime($s['expires_at']) - time()) / 86400) : null;
    ?>
    <tr>
        <td>
            <div style="font-weight:600;font-size:0.875rem"><?= sanitize($s['username'] ?? '—') ?></div>
            <div style="color:var(--text-muted);font-size:0.75rem"><?= sanitize($s['email']) ?></div>
        </td>
        <td style="font-size:0.84rem"><?= sanitize($s['plan_name']) ?><div style="color:var(--text-muted);font-size:0.72rem"><?= ucfirst($s['billing_cycle']) ?></div></td>
        <td style="color:var(--text-muted);font-size:0.78rem"><?= $s['started_at'] ? date('M j, Y', strtotime($s['started_at'])) : '—' ?></td>
        <td style="color:var(--text-muted);font-size:0.78rem"><?= $s['expires_at'] ? date('M j, Y', strtotime($s['expires_at'])) : 'Never' ?></td>
        <td style="font-size:0.84rem;<?= ($daysLeft !== null && $daysLeft <= 7 && $s['status']==='active') ? 'color:#fca5a5;font-weight:700' : '' ?>"><?= $daysLeft === null ? '—' : ($daysLeft < 0 ? '0' : $daysLeft) ?></td>
        <td>
            <span style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;<?php
                echo match($s['status']) {
                    'active'    => 'background:rgba(34,197,94,0.15);color:#86efac',
                    'expired'   => 'background:rgba(239,68,68,0.15);color:#fca5a5',
                    'cancelled' => 'background:rgba(148,148,163,0.15);color:var(--text-muted)',
                    default     => 'background:rgba(245,158,11,0.15);color:#fcd34d',
                };
            ?>"><?= ucfirst($s['status']) ?></span>
        </td>
        <td style="font-size:0.8rem"><?= ucfirst($s['provider']) ?></td>
        <td style="font-family:var(--font-mono);font-size:0.7rem;color:var(--text-muted)"><?= $s['last_reference'] ? sanitize(substr($s['last_reference'],0,18)).'…' : '—' ?></td>
        <td><a href="?action=view&id=<?= (int)$s['id'] ?>" class="btn-ghost btn-sm">View</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($subscriptions)): ?>
    <tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:40px">No subscriptions match this filter</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
