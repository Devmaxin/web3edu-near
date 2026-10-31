<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounty_auth.php';
require_once __DIR__ . '/includes/countries.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
if (!canViewHostDashboard($userId)) {
    header('Location: ' . SITE_URL . '/bounty-host.php');
    exit;
}
$host = getBountyHost($userId);
if (!$host) {
    header('Location: ' . SITE_URL . '/bounty-host.php');
    exit;
}

$pageTitle = 'Host Dashboard';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];

$db = getDB();
$tab = $_GET['tab'] ?? 'overview';

$myBounties = $db->prepare("
    SELECT b.*, (SELECT COUNT(*) FROM bounty_submissions WHERE bounty_id=b.id) AS submission_count
    FROM bounties b WHERE b.host_id = ? AND b.deleted_at IS NULL ORDER BY b.created_at DESC
");
$myBounties->execute([$host['id']]);
$myBounties = $myBounties->fetchAll();

// Bookmark counts per bounty, in one query (bookmarks are stored generically in user_bookmarks).
$bookmarkCounts = [];
$bountyIds = array_column($myBounties, 'id');
if (!empty($bountyIds)) {
    $in = implode(',', array_fill(0, count($bountyIds), '?'));
    $bmStmt = $db->prepare("SELECT bookmarkable_id, COUNT(*) AS c FROM user_bookmarks WHERE bookmarkable_type='bounty' AND bookmarkable_id IN ($in) GROUP BY bookmarkable_id");
    $bmStmt->execute($bountyIds);
    foreach ($bmStmt->fetchAll() as $row) $bookmarkCounts[(int)$row['bookmarkable_id']] = (int)$row['c'];
}

$activeBountyId = (int)($_GET['bounty_id'] ?? ($myBounties[0]['id'] ?? 0));

// "Close Bounty" — stop accepting new submissions (published -> judging). Same
// transition the old "Start Judging" action drove; kept under both names since
// that's exactly what "closing" a bounty to new entries means in this system.
if (($_GET['action'] ?? '') === 'judging' && $activeBountyId && canManageBounty($userId, $activeBountyId)) {
    requireCsrfGet();
    $b = getBounty($activeBountyId);
    if ($b && $b['status'] === 'published') {
        $db->prepare("UPDATE bounties SET status='judging' WHERE id=?")->execute([$activeBountyId]);
        header('Location: ' . SITE_URL . '/bounty-host-dashboard.php?tab=bounties'); exit;
    }
}

// "Archive Bounty" — final, hidden-from-marketplace state. Previously admin-only;
// now available to the owning host too, via the same canManageBounty() gate.
if (($_GET['action'] ?? '') === 'archive' && $activeBountyId && canManageBounty($userId, $activeBountyId)) {
    requireCsrfGet();
    $b = getBounty($activeBountyId);
    if ($b && $b['status'] !== 'archived') {
        $db->prepare("UPDATE bounties SET status='archived' WHERE id=?")->execute([$activeBountyId]);
        header('Location: ' . SITE_URL . '/bounty-host-dashboard.php?tab=bounties'); exit;
    }
}

// Announce winners action
$errors = []; $success = '';
if (isset($_GET['saved'])) $success = 'Bounty updated.';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'announce_winners' && $activeBountyId) {
    requireCsrf();
    if (!canSelectWinners($userId, $activeBountyId)) {
        $errors[] = 'You are not authorized to select winners for this bounty.';
    } else {
        $winners = [];
        foreach (($_POST['winner_submission_id'] ?? []) as $i => $subId) {
            if (!$subId) continue;
            $winners[] = ['submission_id' => (int)$subId, 'placement' => (int)$_POST['winner_placement'][$i], 'prize' => trim($_POST['winner_prize'][$i] ?? '')];
        }
        if (!empty($winners)) {
            announceBountyWinners($activeBountyId, $winners);
            $success = 'Winners announced! Certificates and notifications are on their way.';
        } else {
            $errors[] = 'Select at least one winner.';
        }
    }
}

// Host review feedback (surfaced on the participant's My Submissions page)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_feedback' && $activeBountyId && canManageBounty($userId, $activeBountyId)) {
    requireCsrf();
    $subId = (int)($_POST['submission_id'] ?? 0);
    if ($subId) {
        if (setBountySubmissionFeedback($subId, $activeBountyId, $_POST['feedback'] ?? '')) {
            $success = 'Feedback saved.';
        } else {
            $errors[] = 'That submission could not be found for this bounty.';
        }
    }
}

$stats = [
    'draft'      => count(array_filter($myBounties, fn($b) => $b['status'] === 'draft')),
    'active'     => count(array_filter($myBounties, fn($b) => in_array($b['status'], ['published', 'judging'], true))),
    'closed'     => count(array_filter($myBounties, fn($b) => in_array($b['status'], ['archived', 'winners_announced'], true))),
    'total_views'     => array_sum(array_column($myBounties, 'view_count')),
    'total_bookmarks' => array_sum($bookmarkCounts),
    'submissions'     => array_sum(array_column($myBounties, 'submission_count')),
];
$winnersStmt = $db->prepare("SELECT COUNT(*) FROM bounty_submissions s INNER JOIN bounties b ON b.id=s.bounty_id WHERE b.host_id=? AND s.status='winner'");
$winnersStmt->execute([$host['id']]);
$stats['winners_selected'] = (int)$winnersStmt->fetchColumn();
$stats['awaiting_payment'] = count(array_filter($myBounties, fn($b) => $b['status'] === 'awaiting_payment'));

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/bounty_profile_modal.php';
?>

<div class="container section">
  <div class="page-header" style="padding:0 0 var(--space-5)">
    <div class="section-label">Host Dashboard</div>
    <h1 class="page-header-title"><?= htmlspecialchars($host['organization_name']) ?></h1>
    <?php if (isset($_GET['created'])): ?><div class="alert alert-success" style="margin-top:var(--space-3)">Bounty submitted for review!</div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success" style="margin-top:var(--space-3)"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($errors)): ?><div class="alert alert-danger" style="margin-top:var(--space-3)"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>
  </div>

  <div class="host-tabs">
    <?php foreach (['overview'=>'Overview','bounties'=>'My Bounties','submissions'=>'Submissions','winners'=>'Winners','payments'=>'Payments','analytics'=>'Analytics'] as $t => $label): ?>
      <a href="?tab=<?= $t ?>&bounty_id=<?= $activeBountyId ?>" class="bounty-filter-chip <?= $tab === $t ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <a href="<?= SITE_URL ?>/bounty-host.php" class="btn btn-primary btn-sm" style="margin-left:auto;display:inline-flex;align-items:center;gap:5px"><?= lucide('plus', ['size' => 13]) ?> New Bounty</a>
  </div>

  <?php if ($tab === 'overview'): ?>
    <div class="host-stat-grid">
      <div class="card"><span class="value" style="font-size:var(--text-2xl);font-weight:700;display:block"><?= $stats['active'] ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Active Bounties</span></div>
      <div class="card"><span class="value" style="font-size:var(--text-2xl);font-weight:700;display:block"><?= $stats['draft'] ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Draft Bounties</span></div>
      <div class="card"><span class="value" style="font-size:var(--text-2xl);font-weight:700;display:block"><?= $stats['closed'] ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Closed Bounties</span></div>
      <div class="card"><span class="value" style="font-size:var(--text-2xl);font-weight:700;display:block"><?= number_format($stats['total_views']) ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Total Views</span></div>
      <div class="card"><span class="value" style="font-size:var(--text-2xl);font-weight:700;display:block"><?= number_format($stats['total_bookmarks']) ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Total Bookmarks</span></div>
      <div class="card"><span class="value" style="font-size:var(--text-2xl);font-weight:700;display:block"><?= number_format($stats['submissions']) ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Total Submissions</span></div>
      <div class="card"><span class="value" style="font-size:var(--text-2xl);font-weight:700;display:block"><?= $stats['winners_selected'] ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Winners Selected</span></div>
      <div class="card"><span class="value status-chip <?= $host['fee_policy'] === 'exempt' ? 'status-approved' : 'status-pending' ?>" style="font-size:var(--text-sm)"><?= $host['fee_policy'] === 'exempt' ? 'Fee Exempt' : 'Standard' ?></span><span style="font-size:var(--text-xs);color:var(--text-muted);display:block;margin-top:4px">Current Fee Policy</span></div>
      <div class="card"><span class="value" style="font-size:var(--text-2xl);font-weight:700;display:block"><?= $host['fee_policy'] === 'exempt' || !bountyListingFeeEnabled() ? '—' : bountyListingFeeCurrency() . ' ' . number_format((int)getSetting('bounty_listing_fee_kobo', '1000000') / 100, 0) ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Listing Fee Amount</span></div>
      <div class="card"><span class="value" style="font-size:var(--text-2xl);font-weight:700;display:block;<?= $stats['awaiting_payment'] > 0 ? 'color:#F43F5E' : '' ?>"><?= $stats['awaiting_payment'] ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Outstanding Payments</span></div>
    </div>
    <p style="color:var(--text-secondary);font-size:var(--text-sm)">Use the tabs above to manage bounties, review submissions, announce winners, and track payments.</p>

  <?php elseif ($tab === 'bounties'): ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Title</th><th>Status</th><th>Reward</th><th>Days Left</th><th>Views</th><th>Bookmarks</th><th>Submissions</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($myBounties as $b):
          $daysLeft = $b['deadline'] ? (int)ceil((strtotime($b['deadline']) - time()) / 86400) : null;
        ?>
          <tr>
            <td style="font-weight:600"><?= htmlspecialchars($b['title']) ?></td>
            <td><span class="status-chip status-<?= $b['status'] ?>"><?= ucwords(str_replace('_',' ',$b['status'])) ?></span></td>
            <td><?= htmlspecialchars($b['reward_display'] ?: '—') ?></td>
            <td><?= $daysLeft === null ? '—' : ($daysLeft > 0 ? $daysLeft . 'd' : 'Ended') ?></td>
            <td><?= number_format((int)$b['view_count']) ?></td>
            <td><?= number_format($bookmarkCounts[(int)$b['id']] ?? 0) ?></td>
            <td><?= (int)$b['submission_count'] ?></td>
            <td style="display:flex;gap:6px;flex-wrap:wrap">
              <a href="<?= SITE_URL ?>/bounty-detail.php?slug=<?= urlencode($b['slug']) ?>" class="btn-ghost btn-sm"><?= lucide('eye', ['size' => 12]) ?> View</a>
              <?php if ($b['status'] === 'awaiting_payment'): ?><a href="<?= SITE_URL ?>/bounty-payment.php?id=<?= $b['id'] ?>" class="btn-primary btn-sm"><?= lucide('credit-card', ['size' => 12]) ?> Pay Now</a><?php endif; ?>
              <?php if (canEditBounty($userId, (int)$b['id'])): ?><a href="<?= SITE_URL ?>/bounty-host-edit.php?id=<?= $b['id'] ?>" class="btn-ghost btn-sm"><?= lucide('edit', ['size' => 12]) ?> Edit</a><?php endif; ?>
              <?php if ($b['status'] === 'published'): ?><a href="?tab=bounties&action=judging&bounty_id=<?= $b['id'] ?>&<?= csrfLink() ?>" class="btn-ghost btn-sm"><?= lucide('gavel', ['size' => 12]) ?> Close Bounty</a><?php endif; ?>
              <?php if (!in_array($b['status'], ['archived'], true) && canManageBounty($userId, (int)$b['id'])): ?><a href="?tab=bounties&action=archive&bounty_id=<?= $b['id'] ?>&<?= csrfLink() ?>" class="btn-ghost btn-sm" onclick="return confirm('Archive this bounty? It will be removed from the marketplace.')"><?= lucide('package', ['size' => 12]) ?> Archive</a><?php endif; ?>
              <a href="<?= SITE_URL ?>/bounty-export.php?id=<?= $b['id'] ?>" class="btn-ghost btn-sm"><?= lucide('download', ['size' => 12]) ?> Export</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($myBounties)): ?><tr><td colspan="8" class="admin-empty">No bounties yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($tab === 'submissions' || $tab === 'winners'): ?>
    <div class="form-group" style="max-width:320px;margin-bottom:var(--space-5)">
      <label class="form-label">Bounty</label>
      <select class="form-select" onchange="location.href='?tab=<?= $tab ?>&bounty_id='+this.value">
        <?php foreach ($myBounties as $b): ?><option value="<?= $b['id'] ?>" <?= $activeBountyId === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['title']) ?></option><?php endforeach; ?>
      </select>
    </div>

    <?php if ($activeBountyId && canManageBounty($userId, $activeBountyId)):
      $subs = getBountySubmissions($activeBountyId);
      $activeBounty = getBounty($activeBountyId);
    ?>
      <div style="margin-bottom:var(--space-4)">
        <a href="<?= SITE_URL ?>/bounty-export.php?id=<?= $activeBountyId ?>" class="btn-ghost btn-sm"><?= lucide('download', ['size' => 12]) ?> Export Participants CSV</a>
      </div>

      <?php if ($tab === 'submissions'): ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead><tr><th>User</th><th>Link</th><th>Notes</th><th>Status</th><th>Feedback</th><th>Submitted</th></tr></thead>
            <tbody>
            <?php foreach ($subs as $s): ?>
              <tr>
                <td><?= htmlspecialchars($s['username']) ?></td>
                <td style="max-width:200px;word-break:break-all"><a href="<?= htmlspecialchars($s['submission_url']) ?>" target="_blank"><?= htmlspecialchars($s['submission_url']) ?></a></td>
                <td style="max-width:220px"><?= htmlspecialchars($s['description']) ?></td>
                <td><?= htmlspecialchars(bountySubmissionStatusLabel($s)) ?></td>
                <td>
                  <form method="POST" style="display:flex;gap:4px;align-items:center;min-width:160px">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_feedback">
                    <input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
                    <input type="text" name="feedback" class="form-input" style="font-size:0.72rem;padding:4px 6px" value="<?= htmlspecialchars((string)($s['host_feedback'] ?? '')) ?>" placeholder="Feedback…">
                    <button type="submit" class="btn-ghost btn-sm" style="padding:4px 6px"><?= lucide('check', ['size' => 12]) ?></button>
                  </form>
                </td>
                <td><?= date('M j', strtotime($s['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($subs)): ?><tr><td colspan="6" class="admin-empty">No submissions yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

      <?php else: /* winners tab */ ?>
        <?php if (!canSelectWinners($userId, $activeBountyId)): ?>
          <p class="admin-empty">Winner selection isn't available for this bounty.</p>
        <?php elseif ($activeBounty && $activeBounty['status'] === 'winners_announced'): ?>
          <div class="alert alert-success">Winners have been announced for this bounty.</div>
        <?php elseif (empty($subs)): ?>
          <p class="admin-empty">No submissions to pick winners from yet.</p>
        <?php else: ?>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="announce_winners">
            <div class="admin-table-wrap">
              <table class="admin-table">
                <thead><tr><th>Winner?</th><th>User</th><th>Placement</th><th>Prize</th></tr></thead>
                <tbody>
                <?php foreach ($subs as $i => $s): ?>
                  <tr>
                    <td><input type="checkbox" onchange="document.getElementById('sid<?= $i ?>').value = this.checked ? '<?= $s['id'] ?>' : ''"><input type="hidden" name="winner_submission_id[]" id="sid<?= $i ?>" value=""></td>
                    <td><?= htmlspecialchars($s['username']) ?></td>
                    <td><input type="number" name="winner_placement[]" class="form-input" min="1" value="<?= $i + 1 ?>" style="width:80px"></td>
                    <td><input type="text" name="winner_prize[]" class="form-input" placeholder="e.g. 1,800 USDC"></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:var(--space-4);display:inline-flex;align-items:center;gap:6px" onclick="return confirm('Announce winners? This issues certificates and notifies everyone — it cannot be undone.')"><?= lucide('trophy', ['size' => 15]) ?> Announce Winners</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>

  <?php elseif ($tab === 'payments'): ?>
    <?php
    $payStmt = $db->prepare("SELECT p.*, b.title, b.slug FROM bounty_payments p INNER JOIN bounties b ON b.id = p.bounty_id WHERE p.host_id = ? ORDER BY p.created_at DESC");
    $payStmt->execute([$host['id']]);
    $payments = $payStmt->fetchAll();
    ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Bounty</th><th>Amount</th><th>Method</th><th>Status</th><th>Reference / Tx Hash</th><th>Notes</th><th>Date</th><th>Receipt</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): $badge = bountyPaymentStatusBadge($p['status']); ?>
          <tr>
            <td><?= htmlspecialchars($p['title']) ?></td>
            <td><?= $p['method'] === 'paystack' ? ($p['currency'] ?? 'NGN') . ' ' . number_format($p['amount_kobo'] / 100, 2) : htmlspecialchars(($p['amount_crypto'] ?? '') . ' ' . ($p['network'] ?? '')) ?></td>
            <td><?= ucfirst($p['method']) ?></td>
            <td><span class="status-chip <?= $badge['class'] ?>"><?= htmlspecialchars($badge['label']) ?></span></td>
            <td style="font-size:0.72rem;font-family:var(--font-mono)"><?= htmlspecialchars($p['method'] === 'crypto' ? substr($p['tx_hash'] ?? '', 0, 18) . '…' : ($p['reference'] ?? '')) ?></td>
            <td style="font-size:0.78rem;color:var(--text-muted)"><?= htmlspecialchars($p['notes'] ?? '—') ?></td>
            <td><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
            <td>
              <?php if ($p['status'] === 'approved'): ?>
                <a href="<?= SITE_URL ?>/bounty-detail.php?slug=<?= urlencode($p['slug']) ?>" class="btn-ghost btn-sm">View Bounty</a>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($payments)): ?><tr><td colspan="8" class="admin-empty">No payments yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($tab === 'analytics'): ?>
    <div class="host-stat-grid">
      <div class="card"><span style="font-size:var(--text-2xl);font-weight:700;display:block"><?= number_format($stats['total_views']) ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Total Views</span></div>
      <div class="card"><span style="font-size:var(--text-2xl);font-weight:700;display:block"><?= $stats['submissions'] ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Total Submissions</span></div>
      <div class="card"><span style="font-size:var(--text-2xl);font-weight:700;display:block"><?= $stats['submissions'] > 0 ? round($stats['submissions'] / max(1,count($myBounties)), 1) : 0 ?></span><span style="font-size:var(--text-xs);color:var(--text-muted)">Avg. Submissions / Bounty</span></div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
