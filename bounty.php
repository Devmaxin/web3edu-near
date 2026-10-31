<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounties.php';
require_once __DIR__ . '/includes/countries.php';

$pageTitle = 'Bounty Marketplace';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];
$extraJs   = [SITE_URL . '/assets/js/bookmark.js'];

$search     = trim($_GET['q'] ?? '');
$categoryId = (int)($_GET['category'] ?? 0);
$sort       = $_GET['sort'] ?? 'featured';

$categories = getBountyCategories();

$bounties = listBounties([
    'status' => 'published',
    'search' => $search ?: null,
    'category_id' => $categoryId ?: null,
    'sort' => $sort,
    'limit' => 30,
]);

$featured     = getFeaturedBounties(4);
$recentWinners = getRecentWinners(6);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/bounty_profile_modal.php';
?>

<div class="container section">
  <?php $activeBountyTab = 'bounties'; require __DIR__ . '/includes/bounty_tabs.php'; ?>

  <div class="bounty-welcome" data-reveal>
    <div class="section-label" style="color:rgba(255,255,255,0.8)">Bounty Marketplace</div>
    <h1 class="page-header-title" style="color:#fff;margin-bottom:var(--space-2)">Earn by building, <span style="opacity:.85">not just learning</span></h1>
    <p style="color:rgba(255,255,255,0.85);max-width:560px">Find paid bounties from Web3Edu, verified hosts, and top platforms like Superteam Earn — all in one place.</p>
  </div>

  <form method="GET" class="bounty-search-bar" data-reveal>
    <?php if ($sort): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
    <input type="text" name="q" class="form-input" placeholder="Search bounties, skills, projects…" value="<?= htmlspecialchars($search) ?>">
    <button type="submit" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px"><?= lucide('search', ['size' => 14]) ?> Search</button>
  </form>

  <div class="bounty-filter-row" data-reveal>
    <a href="?<?= http_build_query(array_merge($_GET, ['category' => ''])) ?>" class="bounty-filter-chip <?= !$categoryId ? 'active' : '' ?>">All</a>
    <?php foreach ($categories as $cat): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['category' => $cat['id']])) ?>" class="bounty-filter-chip <?= $categoryId === (int)$cat['id'] ? 'active' : '' ?>">
        <?= lucide(bountyCategoryIconKey($cat['slug']), ['size' => 12]) ?> <?= htmlspecialchars($cat['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="bounty-filter-row" data-reveal>
    <?php foreach (['featured' => 'Featured', 'newest' => 'Newest', 'ending_soon' => 'Ending Soon', 'highest_reward' => 'Highest Rewards'] as $s => $label): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['sort' => $s])) ?>" class="bounty-filter-chip <?= $sort === $s ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($bounties)): ?>
    <div class="learn-empty">
      <?= lucide('inbox', ['size' => 32]) ?>
      <h3>No bounties match your filters</h3>
      <p>Try a different category or check back soon.</p>
    </div>
  <?php else: ?>
    <div class="grid-3">
      <?php foreach ($bounties as $b): $badge = bountyTypeBadge($b); ?>
        <?php include __DIR__ . '/includes/partials/bounty_card.php'; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($recentWinners)): ?>
  <div class="section-head" style="margin-top:var(--space-8)" data-reveal>
    <div>
      <div class="section-label">Hall of fame</div>
      <h2 class="section-title">Recent winners</h2>
    </div>
  </div>
  <div class="grid-3" data-reveal>
    <?php foreach ($recentWinners as $w): ?>
      <div class="card winner-mini-card card-interactive">
        <img class="winner-mini-avatar" src="<?= htmlspecialchars(imageUrl($w['avatar'], 'avatar')) ?>" alt="">
        <div>
          <div style="font-weight:700;font-size:var(--text-sm)"><?= htmlspecialchars($w['username']) ?></div>
          <div class="winner-mini-place">
            <?= lucide(['1'=>'trophy','2'=>'medal','3'=>'medal'][(string)$w['placement']] ?? 'award', ['size' => 12]) ?> <?= ['1'=>'1st','2'=>'2nd','3'=>'3rd'][(string)$w['placement']] ?? $w['placement'] . 'th' ?>
            in <?= htmlspecialchars($w['bounty_title']) ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card" style="margin-top:var(--space-8);text-align:center;padding:var(--space-7)" data-reveal>
    <h2 style="font-size:var(--text-xl);margin-bottom:var(--space-2)">Have a bounty to share?</h2>
    <p style="color:var(--text-secondary);margin-bottom:var(--space-5)">Host your own bounty on Web3Edu, or share one you found on another platform.</p>
    <div style="display:flex;gap:var(--space-3);justify-content:center;flex-wrap:wrap">
      <a href="<?= SITE_URL ?>/become-a-host.php" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px"><?= lucide('gavel', ['size' => 14]) ?> Host a Bounty</a>
      <a href="<?= SITE_URL ?>/bounty-submit.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px"><?= lucide('send', ['size' => 14]) ?> Submit a Bounty</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
