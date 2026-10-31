<?php
$adminTitle = 'Dashboard';
require_once __DIR__ . '/header.php';

$db = getDB();

$stats = [
    ['label'=>'Total Users',    'value'=>$db->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn(),                         'icon'=>'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>','color'=>'var(--cyan)'],
    ['label'=>'Active Subscriptions', 'value'=>$db->query("SELECT COUNT(*) FROM user_subscriptions WHERE status='active'")->fetchColumn(), 'icon'=>'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/></svg>','color'=>'#d8b4fe'],
    ['label'=>'Guides',         'value'=>$db->query("SELECT COUNT(*) FROM guide_paths WHERE deleted_at IS NULL")->fetchColumn(),                 'icon'=>'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>','color'=>'#86efac'],
    ['label'=>'Total Lessons',  'value'=>$db->query("SELECT COUNT(*) FROM lessons WHERE deleted_at IS NULL")->fetchColumn(),                       'icon'=>'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg>','color'=>'#86efac'],
    ['label'=>'Blog Posts',     'value'=>$db->query("SELECT COUNT(*) FROM blog_posts WHERE is_published=1 AND deleted_at IS NULL")->fetchColumn(),'icon'=>'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 0-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>','color'=>'var(--warning)'],
    ['label'=>'Active Airdrops','value'=>$db->query("SELECT COUNT(*) FROM airdrops WHERE status='active' AND deleted_at IS NULL")->fetchColumn(), 'icon'=>'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M17 17 A 7 7 0 0 0 5 17"/><line x1="11" y1="17" x2="9" y2="22"/><line x1="13" y1="17" x2="15" y2="22"/><path d="M11 17V5a1 1 0 0 1 2 0v12"/></svg>','color'=>'var(--cyan)'],
    ['label'=>'Total Revenue',  'value'=>'₦'.number_format($db->query("SELECT COALESCE(SUM(amount),0) FROM purchases WHERE status='success'")->fetchColumn(), 2), 'icon'=>'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>','color'=>'#86efac'],
];

// Recent users
$recentUsers = $db->query("SELECT * FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 8")->fetchAll();

// Recent payments
$recentPayments = $db->query("SELECT p.*, u.username, u.email FROM purchases p LEFT JOIN users u ON p.user_id=u.id ORDER BY p.created_at DESC LIMIT 6")->fetchAll();
?>

<h1 class="admin-page-title">Dashboard Overview</h1>

<style>
.admin-dash-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
}
@media (max-width: 899px) {
  .admin-dash-grid { grid-template-columns: 1fr; }
}
</style>

<div class="grid-3" style="margin-bottom:32px">
    <?php foreach ($stats as $s): ?>
    <div class="stat-card">
        <div style="font-size:1.8rem;margin-bottom:8px"><?= $s['icon'] ?></div>
        <div class="stat-card-num" style="font-size:1.8rem;color:<?= $s['color'] ?>"><?= $s['value'] ?></div>
        <div class="stat-card-label"><?= $s['label'] ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="admin-dash-grid">
    <!-- Recent Users -->
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h2 style="font-size:1rem;font-weight:700">Recent Users</h2>
            <a href="<?= SITE_URL ?>/admin/users.php" class="btn-ghost" style="padding:6px 14px;font-size:0.78rem">View All</a>
        </div>
        <table class="admin-table">
            <thead><tr><th>User</th><th>Plan</th><th>Joined</th></tr></thead>
            <tbody>
            <?php foreach ($recentUsers as $u): ?>
            <tr>
                <td>
                    <div style="font-weight:600;font-size:0.875rem"><?= sanitize($u['username'] ?? '—') ?></div>
                    <div style="color:var(--text-muted);font-size:0.75rem"><?= sanitize($u['email']) ?></div>
                </td>
                <td>
                    <span style="padding:3px 8px;border-radius:20px;font-size:0.7rem;font-weight:700;
                        <?= $u['membership_type']==='premium'?'background:rgba(168,85,247,0.15);color:#d8b4fe':'background:rgba(255,255,255,0.05);color:var(--text-muted)' ?>">
                        <?= ucfirst($u['membership_type']) ?>
                    </span>
                </td>
                <td style="color:var(--text-muted);font-size:0.78rem"><?= date('M j', strtotime($u['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Recent Payments -->
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h2 style="font-size:1rem;font-weight:700">Recent Payments</h2>
            <a href="<?= SITE_URL ?>/admin/payments.php" class="btn-ghost" style="padding:6px 14px;font-size:0.78rem">View All</a>
        </div>
        <?php if (empty($recentPayments)): ?>
        <p style="color:var(--text-muted);font-size:0.875rem;text-align:center;padding:20px">No payments yet</p>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>User</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recentPayments as $p): ?>
            <tr>
                <td style="font-size:0.82rem"><?= sanitize($p['username'] ?? $p['email']) ?></td>
                <td style="color:var(--primary-bright);font-weight:600">₦<?= number_format($p['amount'], 2) ?></td>
                <td>
                    <span style="padding:3px 8px;border-radius:20px;font-size:0.7rem;font-weight:700;
                        <?= $p['status']==='success'?'background:rgba(34,197,94,0.15);color:#86efac':($p['status']==='failed'?'background:rgba(239,68,68,0.15);color:#fca5a5':'background:rgba(245,158,11,0.15);color:#fcd34d') ?>">
                        <?= ucfirst($p['status']) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
