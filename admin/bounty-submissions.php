<?php
/**
 * Admin — Submission Management (feature 4). Every bounty submission
 * platform-wide, filterable by bounty/host/participant/status and
 * searchable, with each participant's wallet/bank info joined in for
 * payout verification. Also where an admin leaves host_feedback that
 * surfaces on the participant's My Submissions page.
 */
$adminTitle = 'Bounty Submissions';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/bounty_auth.php';

$db = getDB();
$errors = []; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_feedback') {
    requireCsrf();
    $subId = (int)($_POST['submission_id'] ?? 0);
    if ($subId) {
        // Admin is platform-wide, not scoped to one bounty — look up the
        // submission's own bounty_id so setBountySubmissionFeedback()'s
        // ownership check (added for the host-dashboard IDOR fix) is
        // satisfied with the submission's real bounty, preserving this
        // page's existing "admin can edit any submission" behavior.
        $sub = getBountySubmissionById($subId);
        if ($sub && setBountySubmissionFeedback($subId, (int)$sub['bounty_id'], $_POST['feedback'] ?? '')) {
            $success = 'Feedback saved.';
        } else {
            $errors[] = 'Submission not found.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    requireCsrf();
    $subId = (int)($_POST['submission_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($subId && in_array($status, ['submitted', 'under_review', 'winner', 'rejected', 'cancelled'], true)) {
        updateBountySubmissionStatus($subId, $status);
        $success = 'Status updated.';
    }
}

$filters = [
    'bounty_id' => (int)($_GET['bounty_id'] ?? 0),
    'host_id'   => (int)($_GET['host_id'] ?? 0),
    'status'    => $_GET['status'] ?? '',
    'search'    => trim($_GET['search'] ?? ''),
];
$submissions = listBountySubmissionsAdmin($filters);

$bountyOptions = $db->query("SELECT id, title FROM bounties WHERE deleted_at IS NULL ORDER BY title")->fetchAll();
$hostOptions   = $db->query("SELECT id, organization_name FROM bounty_hosts ORDER BY organization_name")->fetchAll();
$statusLabels  = ['submitted' => 'Pending Review', 'under_review' => 'Under Review', 'winner' => 'Winner', 'rejected' => 'Not Selected', 'cancelled' => 'Cancelled'];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Bounty Submissions</h1>
    <a href="<?= SITE_URL ?>/admin/bounties.php" class="btn-ghost btn-sm"><?= lucide('arrow-left', ['size' => 13]) ?> Bounties</a>
</div>

<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>

<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:end">
    <div class="form-group" style="margin:0;min-width:200px">
        <label class="form-label">Search</label>
        <input type="text" name="search" class="form-input" placeholder="User, email, bounty…" value="<?= sanitize($filters['search']) ?>">
    </div>
    <div class="form-group" style="margin:0;min-width:180px">
        <label class="form-label">Bounty</label>
        <select name="bounty_id" class="form-select">
            <option value="">All Bounties</option>
            <?php foreach ($bountyOptions as $b): ?><option value="<?= $b['id'] ?>" <?= $filters['bounty_id'] == $b['id'] ? 'selected' : '' ?>><?= sanitize($b['title']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin:0;min-width:160px">
        <label class="form-label">Host</label>
        <select name="host_id" class="form-select">
            <option value="">All Hosts</option>
            <?php foreach ($hostOptions as $h): ?><option value="<?= $h['id'] ?>" <?= $filters['host_id'] == $h['id'] ? 'selected' : '' ?>><?= sanitize($h['organization_name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin:0;min-width:150px">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="">All Statuses</option>
            <?php foreach ($statusLabels as $val => $label): ?><option value="<?= $val ?>" <?= $filters['status'] === $val ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-primary btn-sm"><?= lucide('filter', ['size' => 13]) ?> Filter</button>
    <a href="<?= SITE_URL ?>/admin/bounty-submissions.php" class="btn-ghost btn-sm">Clear</a>
</form>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>User</th><th>Country</th><th>Bounty</th><th>Submission</th><th>Status</th><th>Feedback</th><th>Date</th></tr></thead>
<tbody>
<?php foreach ($submissions as $s): ?>
<tr>
    <td>
        <div style="font-weight:600"><?= sanitize($s['display_name'] ?: $s['username']) ?></div>
        <div style="font-size:0.72rem;color:var(--text-muted)"><?= sanitize($s['username']) ?> · <?= sanitize($s['email']) ?></div>
        <?php if ($s['solana_wallet'] || $s['ethereum_wallet'] || $s['ton_wallet'] || $s['account_number']): ?>
            <div style="font-size:0.7rem;color:var(--text-muted);margin-top:2px"><?= lucide('wallet', ['size' => 10]) ?> payout info on file</div>
        <?php endif; ?>
    </td>
    <td style="font-size:0.78rem"><?= sanitize($s['country'] ?? '—') ?></td>
    <td style="font-size:0.8rem"><?= sanitize($s['bounty_title']) ?><?php if ($s['host_name']): ?><div style="font-size:0.7rem;color:var(--text-muted)"><?= sanitize($s['host_name']) ?></div><?php endif; ?></td>
    <td><a href="<?= sanitize($s['submission_url']) ?>" target="_blank" rel="noopener" class="btn-ghost btn-sm"><?= lucide('external-link', ['size' => 11]) ?> Link</a></td>
    <td>
        <form method="POST" style="display:flex;gap:4px;align-items:center">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
            <select name="status" class="form-select" style="font-size:0.72rem;padding:4px 6px" onchange="this.form.submit()">
                <?php foreach ($statusLabels as $val => $label): ?><option value="<?= $val ?>" <?= $s['status'] === $val ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
        </form>
    </td>
    <td>
        <form method="POST" style="display:flex;gap:4px;align-items:center;min-width:160px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_feedback">
            <input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
            <input type="text" name="feedback" class="form-input" style="font-size:0.72rem;padding:4px 6px" value="<?= sanitize((string)($s['host_feedback'] ?? '')) ?>" placeholder="Feedback for participant…">
            <button type="submit" class="btn-ghost btn-sm" style="padding:4px 6px"><?= lucide('check', ['size' => 12]) ?></button>
        </form>
    </td>
    <td style="font-size:0.72rem;color:var(--text-muted);white-space:nowrap"><?= date('M j, Y', strtotime($s['created_at'])) ?></td>
</tr>
<?php endforeach; ?>
<?php if (empty($submissions)): ?><tr><td colspan="7" class="admin-empty"><?= lucide('inbox', ['size' => 16]) ?> No submissions match these filters.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
