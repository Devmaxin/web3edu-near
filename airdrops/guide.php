<?php
require_once __DIR__ . '/../includes/config.php';

// Accept /airdrops/SLUG via .htaccess rewrite → guide.php?slug=SLUG
$slug = trim($_GET['slug'] ?? '');
if (empty($slug)) { header('Location: ' . SITE_URL . '/airdrops.php'); exit; }

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM airdrops WHERE slug = ? AND deleted_at IS NULL");
$stmt->execute([$slug]);
$airdrop = $stmt->fetch();
if (!$airdrop) { header('Location: ' . SITE_URL . '/airdrops.php'); exit; }

$isPremiumGuide = $airdrop['access_level'] === 'premium';

// ── Backend access control ──────────────────────────────────────────────────
// Re-verify premium status fresh from the DB — never trust session alone.
$isLocked = false;
if ($isPremiumGuide) {
    if (!isLoggedIn()) {
        $isLocked = true;
    } else {
        $isLocked = !userHasActivePremium((int)$_SESSION['user_id']);
    }
}

// ── Prev / Next navigation ──────────────────────────────────────────────────
$all = $db->query("SELECT id, slug, title, access_level FROM airdrops WHERE deleted_at IS NULL ORDER BY is_featured DESC, created_at ASC")->fetchAll();
$currentIndex = null;
foreach ($all as $i => $a) { if ($a['slug'] === $slug) { $currentIndex = $i; break; } }
$prevGuide = ($currentIndex !== null && $currentIndex > 0) ? $all[$currentIndex - 1] : null;
$nextGuide = ($currentIndex !== null && $currentIndex < count($all) - 1) ? $all[$currentIndex + 1] : null;

$pageTitle = $airdrop['title'] . ' — Airdrop Guide';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css'];
$extraJs   = [SITE_URL . '/assets/js/bookmark.js'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header container">
  <a href="<?= SITE_URL ?>/airdrops.php" class="btn btn-ghost btn-sm" style="margin-bottom:var(--space-4)">← All airdrops</a>

  <div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:var(--space-5)" data-reveal>
    <div style="flex:1;min-width:280px">
      <div style="display:flex;align-items:center;flex-wrap:wrap;gap:var(--space-2);margin-bottom:var(--space-4)">
        <span class="status-pill status-<?= $airdrop['status'] ?>"><span class="dot"></span> <?= ucfirst($airdrop['status']) ?></span>
        <span class="diff-<?= strtolower($airdrop['difficulty']) ?>" style="font-size:var(--text-sm)"><?= htmlspecialchars($airdrop['difficulty']) ?></span>
        <span class="badge <?= $isPremiumGuide ? 'badge-accent' : 'badge-success' ?>"><?= $isPremiumGuide ? '⭐ Premium guide' : '✓ Free guide' ?></span>
      </div>

      <h1 class="page-header-title"><?= sanitize($airdrop['title']) ?></h1>
      <p style="max-width:620px"><?= sanitize($airdrop['description']) ?></p>

      <div class="airdrop-meta-row">
        <?php if (!empty($airdrop['reward'])): ?>
          <div class="airdrop-meta-chip"><span class="k">Est. reward</span><span class="v" style="color:var(--accent-bright)"><?= sanitize($airdrop['reward']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($airdrop['deadline'])): ?>
          <div class="airdrop-meta-chip"><span class="k">Deadline</span><span class="v"><?= date('M j, Y', strtotime($airdrop['deadline'])) ?></span></div>
        <?php endif; ?>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:var(--space-3);flex-shrink:0">
      <button class="bookmark-btn <?= isBookmarked('airdrop', (int)$airdrop['id']) ? 'active' : '' ?>" style="align-self:flex-end"
              data-type="airdrop" data-id="<?= (int)$airdrop['id'] ?>"
              aria-pressed="<?= isBookmarked('airdrop', (int)$airdrop['id']) ? 'true' : 'false' ?>"
              title="<?= isBookmarked('airdrop', (int)$airdrop['id']) ? 'Remove bookmark' : 'Save for later' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
      </button>
      <?php if (!empty($airdrop['project_url'])): ?>
        <a href="<?= htmlspecialchars($airdrop['project_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">Visit project ↗</a>
      <?php endif; ?>
      <?php if ($isPremiumGuide && $isLocked): ?>
        <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary" style="background:var(--accent-gradient)">Unlock full guide</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="container section">
  <div class="guide-layout">

    <!-- Content column -->
    <div>
      <?php if (!$isLocked): ?>
        <?php if (!empty($airdrop['full_guide'])): ?>
          <div class="card">
            <div class="section-label" style="border-bottom:1px solid var(--border);padding-bottom:var(--space-3);margin-bottom:var(--space-4)">Step-by-step guide</div>
            <div style="font-size:var(--text-sm);line-height:1.75"><?= $airdrop['full_guide'] ?></div>
          </div>
        <?php else: ?>
          <div class="learn-empty">
            <h3>Full guide coming soon</h3>
            <p>Check back later for the complete step-by-step walkthrough.</p>
          </div>
        <?php endif; ?>

      <?php else: ?>
        <!-- LOCKED: Preview + upgrade wall -->
        <div class="card" style="margin-bottom:var(--space-4)">
          <div class="section-label">Preview</div>
          <p><?= nl2br(sanitize($airdrop['description'])) ?></p>
        </div>

        <div class="content-lock">
          <div class="content-blur">
            <div class="card">
              <h3>Step 1: Bridge your assets</h3>
              <p>Navigate to the official bridge and connect your wallet to begin the process.</p>
              <h3>Step 2: Interact with the protocol</h3>
              <p>Complete multiple transactions to build your on-chain activity score. Consistency matters more than volume.</p>
            </div>
          </div>
          <div class="lock-overlay">
            <div class="lock-title">🔒 Premium Guide Locked</div>
            <div class="lock-subtitle">
              Upgrade for full access to all
              <?= (int)$db->query("SELECT COUNT(*) FROM airdrops WHERE access_level='premium' AND deleted_at IS NULL")->fetchColumn() ?>+
              premium airdrop guides.
            </div>
            <div style="display:flex;gap:var(--space-3);flex-wrap:wrap;justify-content:center">
              <?php if (!isLoggedIn()): ?><a href="<?= SITE_URL ?>/login.php" class="btn btn-secondary">Sign in</a><?php endif; ?>
              <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary btn-lg">Upgrade — <?= htmlspecialchars(getPremiumPriceDisplay()) ?></a>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($prevGuide || $nextGuide): ?>
      <div style="display:flex;gap:var(--space-3);margin-top:var(--space-6);flex-wrap:wrap">
        <?php if ($prevGuide): ?>
          <a href="<?= SITE_URL ?>/airdrops/<?= urlencode($prevGuide['slug']) ?>" class="card card-interactive guide-nav-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            <div><div class="dir-label">Previous</div><div class="dir-title"><?= sanitize($prevGuide['title']) ?></div></div>
          </a>
        <?php endif; ?>
        <?php if ($nextGuide): ?>
          <a href="<?= SITE_URL ?>/airdrops/<?= urlencode($nextGuide['slug']) ?>" class="card card-interactive guide-nav-link" style="justify-content:flex-end;text-align:right">
            <div><div class="dir-label">Next</div><div class="dir-title"><?= sanitize($nextGuide['title']) ?></div></div>
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Sidebar -->
    <aside>
      <div class="card guide-sidebar-card">
        <div class="section-label">Guide info</div>
        <div class="guide-info-row"><span style="color:var(--text-muted)">Status</span><span class="status-pill status-<?= $airdrop['status'] ?>"><?= ucfirst($airdrop['status']) ?></span></div>
        <div class="guide-info-row"><span style="color:var(--text-muted)">Difficulty</span><span class="diff-<?= strtolower($airdrop['difficulty']) ?>"><?= htmlspecialchars($airdrop['difficulty']) ?></span></div>
        <div class="guide-info-row"><span style="color:var(--text-muted)">Reward</span><span style="color:var(--accent-bright);font-weight:700"><?= sanitize($airdrop['reward']) ?></span></div>
        <div class="guide-info-row"><span style="color:var(--text-muted)">Access</span><span><?= $isPremiumGuide ? '⭐ Premium' : '✓ Free' ?></span></div>
        <?php if (!empty($airdrop['deadline'])): ?>
        <div class="guide-info-row"><span style="color:var(--text-muted)">Deadline</span><span><?= date('M j, Y', strtotime($airdrop['deadline'])) ?></span></div>
        <?php endif; ?>

        <?php if (!empty($airdrop['project_url'])): ?>
          <a href="<?= htmlspecialchars($airdrop['project_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:var(--space-4)">Official website ↗</a>
        <?php endif; ?>
        <?php if ($isPremiumGuide && $isLocked): ?>
          <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:var(--space-2)">Unlock premium</a>
        <?php endif; ?>
        <a href="<?= SITE_URL ?>/airdrops.php" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:var(--space-2)">All airdrops</a>
      </div>
    </aside>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
