<?php
$adminTitle = 'Payment History';
require_once __DIR__ . '/header.php';

$db = getDB();

$total    = $db->query("SELECT COALESCE(SUM(amount),0) FROM purchases WHERE status='success'")->fetchColumn();
$count    = $db->query("SELECT COUNT(*) FROM purchases WHERE status='success'")->fetchColumn();

$payments = $db->query("SELECT p.*, u.username, u.email FROM purchases p LEFT JOIN users u ON p.user_id=u.id ORDER BY p.created_at DESC")->fetchAll();
?>

<h1 class="admin-page-title">Payments</h1>

<div class="grid-3" style="margin-bottom:28px">
    <div class="stat-card">
        <div style="font-size:1.6rem;margin-bottom:6px"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <div class="stat-card-num" style="font-size:1.8rem;color:var(--success)">₦<?= number_format($total, 2) ?></div>
        <div class="stat-card-label">Total Revenue</div>
    </div>
    <div class="stat-card">
        <div style="font-size:1.6rem;margin-bottom:6px"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
        <div class="stat-card-num" style="font-size:1.8rem"><?= $count ?></div>
        <div class="stat-card-label">Successful Payments</div>
    </div>
    <div class="stat-card">
        <div style="font-size:1.6rem;margin-bottom:6px"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
        <div class="stat-card-num" style="font-size:1.8rem;color:var(--purple)"><?= $db->query("SELECT COUNT(*) FROM users WHERE membership_type='premium'")->fetchColumn() ?></div>
        <div class="stat-card-label">Premium Members</div>
    </div>
</div>

<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
    <table class="admin-table">
        <thead><tr>
            <th>User</th><th>Reference</th><th>Amount</th><th>Status</th><th>Date</th>
        </tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
        <tr>
            <td>
                <div style="font-weight:600;font-size:0.875rem"><?= sanitize($p['username'] ?? '—') ?></div>
                <div style="color:var(--text-muted);font-size:0.75rem"><?= sanitize($p['email']) ?></div>
            </td>
            <td style="font-family:var(--font-mono);font-size:0.72rem;color:var(--text-muted)"><?= sanitize(substr($p['reference'],0,28)) ?>...</td>
            <td style="color:var(--primary-bright);font-weight:600">₦<?= number_format($p['amount'],2) ?></td>
            <td><span style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;
                <?= $p['status']==='success'?'background:rgba(34,197,94,0.15);color:#86efac':($p['status']==='failed'?'background:rgba(239,68,68,0.15);color:#fca5a5':'background:rgba(245,158,11,0.15);color:#fcd34d') ?>"><?= ucfirst($p['status']) ?></span></td>
            <td style="color:var(--text-muted);font-size:0.78rem"><?= date('M j, Y · H:i', strtotime($p['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($payments)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:40px">No payments yet</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
