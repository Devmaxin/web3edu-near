<?php
/**
 * My Submissions (feature 3) — every bounty a user has submitted to, with
 * status, a link back to the submission/bounty, and any host/admin
 * feedback left on it.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounty_auth.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$submissions = getUserBountySubmissions($userId);

$pageTitle = 'My Submissions';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];

$statusChipClass = [
    'submitted'    => 'status-pending_review',
    'under_review' => 'status-judging',
    'winner'       => 'status-winners_announced',
    'rejected'     => 'status-archived',
    'cancelled'    => 'status-archived',
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section">
  <?php $activeBountyTab = 'bounties'; require __DIR__ . '/includes/bounty_tabs.php'; ?>

  <div class="page-header" style="padding:0 0 var(--space-6)">
    <div class="section-label">Bounties</div>
    <h1 class="page-header-title">My Submissions</h1>
  </div>

  <?php if (empty($submissions)): ?>
    <div class="learn-empty">
      <?= lucide('inbox', ['size' => 32]) ?>
      <h3>No submissions yet</h3>
      <p>Once you submit an entry to a bounty, it'll show up here.</p>
      <a href="<?= SITE_URL ?>/bounty.php" class="btn btn-primary" style="margin-top:var(--space-3);display:inline-flex;align-items:center;gap:6px">Browse Bounties <?= lucide('arrow-right', ['size' => 14]) ?></a>
    </div>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:var(--space-4)">
      <?php foreach ($submissions as $s): ?>
        <div class="card" style="display:flex;gap:var(--space-4);align-items:flex-start;flex-wrap:wrap">
          <img src="<?= htmlspecialchars(imageUrl($s['project_logo'] ?: $s['host_logo'], 'lesson')) ?>" alt=""
               style="width:52px;height:52px;border-radius:var(--radius-sm);object-fit:cover;border:1px solid var(--border);flex-shrink:0">

          <div style="flex:1;min-width:200px">
            <div style="display:flex;align-items:center;gap:var(--space-2);flex-wrap:wrap;margin-bottom:4px">
              <h3 style="font-size:var(--text-base);margin:0"><?= htmlspecialchars($s['bounty_title']) ?></h3>
              <span class="status-chip <?= $statusChipClass[$s['status']] ?? 'status-pending_review' ?>"><?= htmlspecialchars(bountySubmissionStatusLabel($s)) ?></span>
            </div>
            <div style="font-size:var(--text-xs);color:var(--text-muted);margin-bottom:var(--space-2)">
              Submitted <?= date('M j, Y', strtotime($s['created_at'])) ?>
              <?php if ($s['status'] === 'winner' && !empty($s['prize_awarded'])): ?> · <?= lucide('trophy', ['size' => 11]) ?> <?= htmlspecialchars($s['prize_awarded']) ?><?php endif; ?>
            </div>

            <?php if (!empty($s['host_feedback'])): ?>
              <div style="background:var(--surface-raised);border-radius:var(--radius-sm);padding:var(--space-3);margin-bottom:var(--space-3);font-size:var(--text-sm);color:var(--text-secondary)">
                <strong style="color:var(--text-primary)">Feedback:</strong> <?= nl2br(htmlspecialchars($s['host_feedback'])) ?>
              </div>
            <?php endif; ?>

            <div style="display:flex;gap:var(--space-3);flex-wrap:wrap;font-size:var(--text-sm)">
              <?php if (!empty($s['submission_url'])): ?>
                <a href="<?= htmlspecialchars($s['submission_url']) ?>" target="_blank" rel="noopener" style="color:var(--primary-bright);display:inline-flex;align-items:center;gap:4px"><?= lucide('external-link', ['size' => 12]) ?> Submission Link</a>
              <?php endif; ?>
              <a href="<?= SITE_URL ?>/bounty-detail.php?slug=<?= urlencode($s['bounty_slug']) ?>" style="color:var(--primary-bright);display:inline-flex;align-items:center;gap:4px"><?= lucide('eye', ['size' => 12]) ?> View Bounty</a>
              <?php if ($s['status'] === 'winner' && !empty($s['certificate_code'])): ?>
                <a href="<?= SITE_URL ?>/certificate-view.php?code=<?= urlencode($s['certificate_code']) ?>" style="color:var(--primary-bright);display:inline-flex;align-items:center;gap:4px"><?= lucide('award', ['size' => 12]) ?> View Certificate</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
