<?php
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Crypto Airdrops';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];
$extraJs   = [SITE_URL . '/assets/js/bookmark.js'];
$db = getDB();

$status = $_GET['status'] ?? 'active';
if (!in_array($status, ['active', 'ended', 'upcoming'], true)) $status = 'active';

$stmt = $db->prepare("SELECT * FROM airdrops WHERE status = ? AND deleted_at IS NULL ORDER BY is_featured DESC, created_at DESC");
$stmt->execute([$status]);
$airdrops = $stmt->fetchAll();

$counts = [];
foreach (['active', 'upcoming', 'ended'] as $s) {
    $c = $db->prepare("SELECT COUNT(*) FROM airdrops WHERE status=? AND deleted_at IS NULL");
    $c->execute([$s]);
    $counts[$s] = $c->fetchColumn();
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header container" data-reveal>
  <div class="section-label">Live opportunities</div>
  <h1 class="page-header-title">Crypto <span class="gradient-text">Airdrops</span></h1>
  <p class="page-header-subtitle">Track, qualify, and earn from the latest Web3 airdrop opportunities.</p>

  <?php if (isLoggedIn() && !isPremium()): ?>
  <div class="alert alert-warning" style="display:inline-flex;margin-top:var(--space-4)">
    <span>Premium guides with step-by-step walkthroughs are locked.</span>
    <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary btn-sm" style="margin-left:var(--space-3)">Upgrade</a>
  </div>
  <?php endif; ?>
</div>

<div class="container section">

  <?php $activeBountyTab = 'airdrops'; require __DIR__ . '/includes/bounty_tabs.php'; ?>

  <!-- Status filter tabs -->
  <div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-6);flex-wrap:wrap" data-reveal>
    <?php
    $statusMeta = [
        'active'   => 'Active',
        'upcoming' => 'Upcoming',
        'ended'    => 'Ended',
    ];
    foreach ($statusMeta as $s => $label): ?>
      <a href="?status=<?= $s ?>" class="btn <?= $status === $s ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
        <span class="status-pill status-<?= $s ?>" style="background:none;padding:0"><span class="dot"></span></span>
        <?= $label ?> (<?= (int)$counts[$s] ?>)
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($airdrops)): ?>
    <div class="learn-empty">
      <h3>No <?= htmlspecialchars($status) ?> airdrops right now</h3>
      <p>Check back soon or switch to another tab.</p>
    </div>
  <?php else: ?>
    <div class="grid-2">
      <?php foreach ($airdrops as $airdrop):
        $isPremiumAirdrop = $airdrop['access_level'] === 'premium';
        $hasSlug  = !empty($airdrop['slug']);
        $guideUrl = $hasSlug ? SITE_URL . '/airdrops/' . urlencode($airdrop['slug']) : '#';
      ?>
      <div class="card airdrop-card card-interactive" data-reveal>
        <?php if ($airdrop['is_featured']): ?><span class="airdrop-featured-badge">★ Featured</span><?php endif; ?>

        <div class="airdrop-header">
          <div>
            <div class="airdrop-kicker"><?= $isPremiumAirdrop ? '◆ Premium guide' : '● Public guide' ?></div>
            <h3 class="airdrop-title"><?= sanitize($airdrop['title']) ?></h3>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:var(--space-2)">
            <button class="bookmark-btn <?= isBookmarked('airdrop', (int)$airdrop['id']) ? 'active' : '' ?>"
                    data-type="airdrop" data-id="<?= (int)$airdrop['id'] ?>"
                    aria-pressed="<?= isBookmarked('airdrop', (int)$airdrop['id']) ? 'true' : 'false' ?>"
                    title="<?= isBookmarked('airdrop', (int)$airdrop['id']) ? 'Remove bookmark' : 'Save for later' ?>">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
            </button>
            <span class="status-pill status-<?= $airdrop['status'] ?>"><span class="dot"></span> <?= ucfirst($airdrop['status']) ?></span>
          </div>
        </div>

        <p style="font-size:var(--text-sm)"><?= sanitize($airdrop['description']) ?></p>

        <div class="airdrop-meta-row">
          <div class="airdrop-meta-chip"><span class="k">Est. reward</span><span class="v"><?= sanitize($airdrop['reward']) ?></span></div>
          <div class="airdrop-meta-chip"><span class="k">Difficulty</span><span class="v diff-<?= strtolower($airdrop['difficulty']) ?>"><?= htmlspecialchars($airdrop['difficulty']) ?></span></div>
          <?php if ($airdrop['deadline']): ?>
          <div class="airdrop-meta-chip"><span class="k">Deadline</span><span class="v"><?= date('M j, Y', strtotime($airdrop['deadline'])) ?></span></div>
          <?php endif; ?>
        </div>

        <div style="display:flex;gap:var(--space-3);flex-wrap:wrap;margin-top:auto">
          <?php if ($hasSlug): ?>
            <a href="<?= $guideUrl ?>" class="btn btn-primary" style="flex:1;justify-content:center;min-width:120px">View guide</a>
          <?php endif; ?>
          <?php if (!empty($airdrop['project_url'])): ?>
            <a href="<?= htmlspecialchars($airdrop['project_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="<?= $hasSlug ? '' : 'flex:1;justify-content:center' ?>">Visit project ↗</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
