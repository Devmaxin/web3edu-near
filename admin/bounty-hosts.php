<?php
$adminTitle = 'Host Applications';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/bounties.php';

$db = getDB();
$statusFilter = $_GET['status'] ?? 'pending_approval';
$where = $statusFilter === 'all' ? '1=1' : 'bh.status = ' . $db->quote($statusFilter);

$hosts = $db->query("
    SELECT bh.*, u.username, u.email AS registration_email,
           (SELECT COUNT(*) FROM bounties WHERE host_id=bh.id AND deleted_at IS NULL) AS bounty_count
    FROM bounty_hosts bh INNER JOIN users u ON u.id = bh.user_id
    WHERE $where
    ORDER BY bh.created_at DESC
")->fetchAll();

$counts = $db->query("SELECT status, COUNT(*) c FROM bounty_hosts GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h1 class="admin-page-title" style="margin:0">Host Applications</h1>
    <a href="<?= SITE_URL ?>/admin/bounties.php" class="btn-ghost btn-sm"><?= lucide('arrow-left', ['size' => 13]) ?> Bounties</a>
</div>

<?php if (isset($_GET['approved'])): ?><div class="admin-alert admin-alert-success">Host approved.</div><?php endif; ?>
<?php if (isset($_GET['changes_requested'])): ?><div class="admin-alert admin-alert-success">Changes requested — the host has been notified.</div><?php endif; ?>
<?php if (isset($_GET['rejected'])): ?><div class="admin-alert admin-alert-success">Application rejected.</div><?php endif; ?>
<?php if (isset($_GET['suspended'])): ?><div class="admin-alert admin-alert-success">Host suspended.</div><?php endif; ?>

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <?php foreach ([
        'pending_approval' => 'Pending Approval', 'request_changes' => 'Request Changes',
        'approved' => 'Approved', 'rejected' => 'Rejected', 'suspended' => 'Suspended', 'all' => 'All',
    ] as $s => $label): ?>
    <a href="?status=<?= $s ?>" class="btn-ghost btn-sm" style="<?= $statusFilter===$s?'background:var(--primary-bright);color:#fff':'' ?>">
        <?= $label ?><?php if ($s !== 'all' && !empty($counts[$s])): ?> (<?= (int)$counts[$s] ?>)<?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Organization</th><th>Applicant</th><th>Registration Email</th><th>Public Contact Email</th><th>Website</th><th>Country</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($hosts as $h): ?>
<tr>
    <td style="font-weight:600">
        <?= sanitize($h['organization_name']) ?>
        <?php if ($h['is_verified']): ?><span title="Verified"><?= lucide('shield-check', ['size' => 12]) ?></span><?php endif; ?>
        <?php if ($h['fee_policy'] === 'exempt'): ?><div style="font-size:0.68rem;color:var(--text-muted)">Fee Exempt</div><?php endif; ?>
    </td>
    <td style="font-size:0.78rem"><?= sanitize($h['username']) ?></td>
    <td style="font-size:0.78rem"><?= sanitize($h['registration_email']) ?></td>
    <td style="font-size:0.78rem"><?= sanitize($h['contact_email']) ?></td>
    <td style="font-size:0.78rem"><?php if ($h['website']): ?><a href="<?= sanitize($h['website']) ?>" target="_blank" style="color:var(--primary-bright)">↗</a><?php else: ?>—<?php endif; ?></td>
    <td style="font-size:0.78rem"><?= sanitize($h['country'] ?? '—') ?></td>
    <td style="font-size:0.78rem"><?= date('M j, Y', strtotime($h['created_at'])) ?></td>
    <td><span class="status-chip status-<?= $h['status'] ?>"><?= sanitize(hostStatusLabel($h['status'])) ?></span></td>
    <td><a href="<?= SITE_URL ?>/admin/bounty-host-review.php?id=<?= $h['id'] ?>" class="btn-primary btn-sm"><?= lucide('eye', ['size' => 12]) ?> Review</a></td>
</tr>
<?php endforeach; ?>
<?php if (empty($hosts)): ?><tr><td colspan="9" class="admin-empty"><?= lucide('inbox', ['size' => 16]) ?> No applications here.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
