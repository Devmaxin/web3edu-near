<?php
$adminTitle = 'Bounty Payments';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/bounties.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$adminId = $_SESSION['admin_id'] ?? null;

if ($action === 'approve' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    approveBountyPayment($id, $adminId);
    header('Location: ' . SITE_URL . '/admin/bounty-payments.php?approved=1'); exit;
}
if ($action === 'reject' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    rejectBountyPayment($id, trim($_POST['reason'] ?? 'Could not verify payment'), $adminId);
    header('Location: ' . SITE_URL . '/admin/bounty-payments.php?rejected=1'); exit;
}
if ($action === 'add_note' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $db->prepare("UPDATE bounty_payments SET notes = ? WHERE id = ?")->execute([trim($_POST['note'] ?? ''), $id]);
    header('Location: ' . SITE_URL . '/admin/bounty-payments.php?noted=1'); exit;
}

$statusFilter = $_GET['status'] ?? 'under_review';
$where = $statusFilter === 'all' ? '1=1' : 'p.status = ' . $db->quote($statusFilter);

$payments = $db->query("
    SELECT p.*, b.title AS bounty_title, bh.organization_name AS host_name, u.username, u.email
    FROM bounty_payments p
    INNER JOIN bounties b ON b.id = p.bounty_id
    INNER JOIN bounty_hosts bh ON bh.id = p.host_id
    INNER JOIN users u ON u.id = bh.user_id
    WHERE $where
    ORDER BY p.created_at DESC
    LIMIT 200
")->fetchAll();
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h1 class="admin-page-title" style="margin:0">Bounty Payments</h1>
    <a href="<?= SITE_URL ?>/admin/bounties.php" class="btn-ghost btn-sm"><?= lucide('arrow-left', ['size' => 13]) ?> Bounties</a>
</div>

<?php if (isset($_GET['approved'])): ?><div class="admin-alert admin-alert-success">Payment approved — bounty published.</div><?php endif; ?>
<?php if (isset($_GET['rejected'])): ?><div class="admin-alert admin-alert-success">Payment rejected — bounty returned to draft.</div><?php endif; ?>
<?php if (isset($_GET['noted'])): ?><div class="admin-alert admin-alert-success">Note saved.</div><?php endif; ?>

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <?php foreach (['under_review'=>'Under Review','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','failed'=>'Failed','all'=>'All'] as $s=>$label): ?>
    <a href="?status=<?= $s ?>" class="btn-ghost btn-sm" style="<?= $statusFilter===$s?'background:var(--primary-bright);color:#fff':'' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Host</th><th>Organization</th><th>Bounty</th><th>Amount</th><th>Method</th><th>Network / Reference / Tx Hash</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($payments as $p):
    $badge = bountyPaymentStatusBadge($p['status']);
    $amount = $p['method'] === 'paystack'
        ? ($p['currency'] ?? 'NGN') . ' ' . number_format(($p['amount_kobo'] ?? 0) / 100, 2)
        : sanitize(($p['amount_crypto'] ?? '') . ' ' . ($p['network'] ?? ''));
?>
<tr>
    <td><?= sanitize($p['username']) ?><div style="font-size:0.72rem;color:var(--text-muted)"><?= sanitize($p['email']) ?></div></td>
    <td><?= sanitize($p['host_name']) ?></td>
    <td><?= sanitize($p['bounty_title']) ?></td>
    <td><?= $amount ?></td>
    <td><?= ucfirst($p['method']) ?></td>
    <td style="font-size:0.72rem;font-family:var(--font-mono)">
        <?php if ($p['method'] === 'crypto'): ?>
            <div style="font-family:var(--font-sans);font-weight:600;margin-bottom:2px"><?= sanitize($p['network'] ?? '') ?></div>
            <?php $suggested = bountySuggestedAmountForNetwork($p['network'] ?? ''); if ($suggested): ?>
                <div style="font-family:var(--font-sans);color:var(--text-muted)">Suggested: <?= sanitize($suggested) ?></div>
            <?php endif; ?>
            <?php if (!empty($p['wallet_used'])): ?>
                <div style="color:var(--text-muted)" title="Wallet address the host was shown at time of payment">To: <?= sanitize(substr($p['wallet_used'], 0, 16)) ?>…</div>
            <?php endif; ?>
            Tx: <?= sanitize(substr($p['tx_hash'] ?? '', 0, 22)) ?>…
        <?php else: ?><?= sanitize($p['reference'] ?? '') ?><?php endif; ?>
        <?php if ($p['screenshot_path']): ?><br><a href="<?= sanitize(imageUrl($p['screenshot_path'])) ?>" target="_blank"><?= lucide('eye', ['size' => 11]) ?> Screenshot</a><?php endif; ?>
    </td>
    <td><span class="status-chip <?= $badge['class'] ?>"><?= sanitize($badge['label']) ?></span></td>
    <td style="font-size:0.72rem;white-space:nowrap"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
    <td>
        <div style="display:flex;gap:5px;flex-wrap:wrap">
        <?php if (in_array($p['status'], ['pending','under_review'])): ?>
            <button type="button" class="btn-primary btn-sm" onclick="approvePayPrompt(<?= $p['id'] ?>)"><?= lucide('check', ['size' => 12]) ?> Approve</button>
            <button type="button" class="btn-danger btn-sm" onclick="rejectPayPrompt(<?= $p['id'] ?>)"><?= lucide('x', ['size' => 12]) ?> Reject</button>
        <?php endif; ?>
            <button type="button" class="btn-ghost btn-sm" onclick="notePayPrompt(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['notes'] ?? ''), ENT_QUOTES) ?>)"><?= lucide('sticky-note', ['size' => 12]) ?> Notes</button>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($payments)): ?><tr><td colspan="9" class="admin-empty"><?= lucide('inbox', ['size' => 16]) ?> No payments here.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<form method="POST" id="approvePayForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="approve"></form>
<form method="POST" id="rejectPayForm" style="display:none"><?= csrfField() ?><input type="text" name="reason" id="rejectPayReason"></form>
<form method="POST" id="notePayForm" style="display:none"><?= csrfField() ?><input type="text" name="note" id="notePayNote"></form>
<script>
function approvePayPrompt(id) {
    if (!confirm('Approve this payment and publish the bounty?')) return;
    const f = document.getElementById('approvePayForm');
    f.action = '?action=approve&id=' + id;
    f.submit();
}
function rejectPayPrompt(id) {
    const reason = prompt('Reason for rejecting this payment:', 'Could not verify the transaction.');
    if (reason === null) return;
    const f = document.getElementById('rejectPayForm');
    f.action = '?action=reject&id=' + id;
    document.getElementById('rejectPayReason').value = reason;
    f.submit();
}
function notePayPrompt(id, existing) {
    const note = prompt('Internal note for this payment:', existing || '');
    if (note === null) return;
    const f = document.getElementById('notePayForm');
    f.action = '?action=add_note&id=' + id;
    document.getElementById('notePayNote').value = note;
    f.submit();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
