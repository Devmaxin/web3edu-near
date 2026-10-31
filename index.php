<?php
require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Learn Blockchain, DeFi & Web3';
$extraCss  = [SITE_URL . '/assets/css/home.css', SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];
$extraJs   = [SITE_URL . '/assets/js/home.js', SITE_URL . '/assets/js/bookmark.js'];

require_once __DIR__ . '/includes/bounties.php';

$db = getDB();

// ---- Same business logic as v1, preserved exactly (now soft-delete aware) ----
$freeLessons = $db->query(
    "SELECT * FROM lessons WHERE access_level = 'free' AND deleted_at IS NULL
     ORDER BY is_featured DESC, created_at DESC LIMIT 3"
)->fetchAll();

$featuredAirdrops = $db->query(
    "SELECT * FROM airdrops WHERE status = 'active' AND deleted_at IS NULL
     ORDER BY is_featured DESC LIMIT 3"
)->fetchAll();

$featuredBounties = getFeaturedBounties(3);

$featuredBlogs = $db->query(
    "SELECT * FROM blog_posts WHERE is_published = 1 AND deleted_at IS NULL
     ORDER BY is_featured DESC, created_at DESC LIMIT 3"
)->fetchAll();

// ---- New: admin-managed rotating headlines (hero_headlines.headline_text) ----
$headlines = $db->query(
    "SELECT headline_text FROM hero_headlines WHERE is_active = 1 ORDER BY sort_order"
)->fetchAll(PDO::FETCH_COLUMN);
if (empty($headlines)) {
    $headlines = ['Learn Web3 & Blockchain']; // fallback only if admin hasn't seeded any yet
}

// ---- New: admin-managed crypto carousel (crypto_carousel_items) ----
$carouselItems = $db->query(
    "SELECT coin_name, logo_path FROM crypto_carousel_items WHERE is_active = 1 ORDER BY sort_order"
)->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- ====== HERO ====== -->
<section class="hero">
  <div class="container">
    <div class="hero-badge" data-reveal>
      <span class="dot"></span> Web3's #1 Learning Platform
    </div>

    <h1 class="hero-title" data-reveal>
      <span class="hero-rotator">
        <?php foreach ($headlines as $i => $text): ?>
          <span class="hero-rotator-item<?= $i === 0 ? ' active' : '' ?>"><?= htmlspecialchars($text) ?></span>
        <?php endforeach; ?>
      </span>
    </h1>

    <p class="hero-subtitle" data-reveal>
      Master DeFi, NFTs, smart contracts, and crypto trading with structured lessons, live airdrop guides, and a thriving Web3 community.
    </p>

    <div class="hero-cta" data-reveal>
      <?php if (!isLoggedIn()): ?>
        <a href="<?= SITE_URL ?>/register.php" class="btn btn-primary btn-lg">Start learning free</a>
        <a href="<?= SITE_URL ?>/learn.php" class="btn btn-secondary btn-lg">Browse lessons</a>
      <?php else: ?>
        <a href="<?= SITE_URL ?>/guide.php" class="btn btn-primary btn-lg">Continue learning</a>
        <?php if (!isPremium()): ?>
          <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-secondary btn-lg">Upgrade to Premium</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    </div>
  </div>
</section>

<?php if (!empty($carouselItems)): ?>
<!-- ====== CRYPTO CAROUSEL (managed at /admin/carousel.php) ====== -->
<div class="crypto-carousel">
  <div class="crypto-track">
    <?php foreach ([1, 2] as $loop): // duplicated once for a seamless scroll loop ?>
      <?php foreach ($carouselItems as $coin): ?>
        <div class="crypto-item">
          <img src="<?= htmlspecialchars(imageUrl($coin['logo_path'], 'lesson')) ?>" alt="" loading="lazy" width="28" height="28">
          <span><?= htmlspecialchars($coin['coin_name']) ?></span>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ====== FREE LESSONS ====== -->
<section class="section">
  <div class="container">
    <div class="section-head" data-reveal>
      <div>
        <div class="section-label">Free for everyone</div>
        <h2 class="section-title">Start your Web3 journey</h2>
      </div>
    </div>

    <div class="grid-3">
      <?php foreach ($freeLessons as $lesson): ?>
        <article class="card content-card card-interactive" data-reveal>
          <div class="content-card-thumb">
            <?php if (!empty($lesson['thumbnail'])): ?>
              <img src="<?= htmlspecialchars(imageUrl($lesson['thumbnail'], 'lesson')) ?>" alt="<?= htmlspecialchars($lesson['title']) ?>" loading="lazy">
            <?php endif; ?>
            <span class="badge badge-success content-card-badge">Free</span>
          </div>
          <div class="content-card-body">
            <span class="badge"><?= htmlspecialchars($lesson['category']) ?></span>
            <h3 style="font-size:var(--text-lg);margin:var(--space-2) 0"><?= htmlspecialchars($lesson['title']) ?></h3>
            <p style="font-size:var(--text-sm)"><?= htmlspecialchars(substr($lesson['preview_content'], 0, 110)) ?>…</p>
            <div class="content-card-footer">
              <span class="content-card-meta" style="margin:0"><?= htmlspecialchars($lesson['duration']) ?> · <span class="mono">+10 XP</span></span>
              <a href="<?= SITE_URL ?>/lesson.php?slug=<?= urlencode($lesson['slug']) ?>" class="btn btn-ghost btn-sm">Read →</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center;margin-top:var(--space-6)" data-reveal>
      <?php if (!isLoggedIn()): ?>
        <p style="color:var(--text-muted)">Sign up to access all premium lessons, airdrops, and exclusive content</p>
        <a href="<?= SITE_URL ?>/register.php" class="btn btn-primary btn-lg">View all lessons — it's free</a>
      <?php else: ?>
        <a href="<?= SITE_URL ?>/learn.php" class="btn btn-primary btn-lg">View all lessons →</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ====== WHY WEB3EDU ====== -->
<section class="section" style="border-top:1px solid var(--border);border-bottom:1px solid var(--border)">
  <div class="container">
    <div class="section-head" style="justify-content:center;text-align:center;flex-direction:column" data-reveal>
      <div class="section-label">Why Web3Edu</div>
      <h2 class="section-title">Built different</h2>
    </div>
    <div class="grid-4" data-reveal>
      <?php
      $features = [
          ['title' => 'Structured learning', 'desc' => 'Progressive curriculum from fundamentals to advanced DeFi and smart contract development.'],
          ['title' => 'Premium airdrops', 'desc' => 'Exclusive step-by-step airdrop guides with estimated rewards and deadline tracking.'],
          ['title' => 'Live updates', 'desc' => 'Real-time airdrop and DeFi opportunity updates. Never miss a profitable opportunity.'],
          ['title' => 'Flexible Premium', 'desc' => 'Monthly or yearly plans, cancel anytime. Full access to every premium lesson and guide.'],
      ];
      foreach ($features as $f): ?>
        <div class="card feature-card">
          <div class="feature-icon" aria-hidden="true"><span class="mono">●</span></div>
          <h3 style="font-size:var(--text-base)"><?= htmlspecialchars($f['title']) ?></h3>
          <p style="font-size:var(--text-sm)"><?= htmlspecialchars($f['desc']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ====== LIVE AIRDROPS ====== -->
<section class="section">
  <div class="container">
    <div class="section-head" data-reveal>
      <div>
        <div class="section-label">Live opportunities</div>
        <h2 class="section-title">Active airdrops</h2>
      </div>
      <a href="<?= SITE_URL ?>/airdrops.php" class="btn btn-ghost">View all →</a>
    </div>
    <div class="grid-3">
      <?php foreach ($featuredAirdrops as $airdrop): ?>
        <div class="card airdrop-card card-interactive" data-reveal>
          <div class="airdrop-card-head">
            <h3 style="font-size:var(--text-lg);margin:0"><?= htmlspecialchars($airdrop['title']) ?></h3>
            <span class="badge badge-success"><?= htmlspecialchars(ucfirst($airdrop['status'])) ?></span>
          </div>
          <p style="font-size:var(--text-sm)"><?= htmlspecialchars(substr($airdrop['description'], 0, 110)) ?>…</p>
          <div class="content-card-meta">
            <span>Reward: <?= htmlspecialchars($airdrop['reward']) ?></span>
            <span>Difficulty: <?= htmlspecialchars($airdrop['difficulty']) ?></span>
          </div>
          <a href="<?= SITE_URL ?>/airdrops.php#airdrop-<?= (int)$airdrop['id'] ?>" class="btn btn-secondary" style="width:100%;justify-content:center">
            <?= ($airdrop['access_level'] === 'premium' && !isPremium()) ? 'Premium guide' : 'View guide' ?>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if (!empty($featuredBounties)): ?>
<!-- ====== FEATURED BOUNTIES ====== -->
<section class="section" style="border-top:1px solid var(--border)">
  <div class="container">
    <div class="section-head" data-reveal>
      <div>
        <div class="section-label">Earn by building</div>
        <h2 class="section-title">Featured bounties</h2>
      </div>
      <a href="<?= SITE_URL ?>/bounty.php" class="btn btn-ghost">View All Bounties →</a>
    </div>
    <div class="grid-3">
      <?php foreach ($featuredBounties as $b): $badge = bountyTypeBadge($b); ?>
        <?php include __DIR__ . '/includes/partials/bounty_card.php'; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($featuredBlogs)): ?>
<!-- ====== BLOG ====== -->
<section class="section" style="border-top:1px solid var(--border)">
  <div class="container">
    <div class="section-head" data-reveal>
      <div>
        <div class="section-label">Latest insights</div>
        <h2 class="section-title">From the blog</h2>
      </div>
      <a href="<?= SITE_URL ?>/blog.php" class="btn btn-ghost">All posts →</a>
    </div>
    <div class="grid-3">
      <?php foreach ($featuredBlogs as $post): ?>
        <article class="card content-card card-interactive" data-reveal>
          <div class="content-card-thumb" style="background:var(--accent-gradient)"></div>
          <div class="content-card-body">
            <span class="badge badge-accent">Web3 insights</span>
            <h3 style="font-size:var(--text-lg);margin:var(--space-2) 0"><?= htmlspecialchars($post['title']) ?></h3>
            <p style="font-size:var(--text-sm)"><?= htmlspecialchars(substr($post['excerpt'], 0, 100)) ?>…</p>
            <div class="content-card-footer">
              <span class="content-card-meta" style="margin:0"><?= timeAgo($post['created_at']) ?></span>
              <a href="<?= SITE_URL ?>/blog-post.php?slug=<?= urlencode($post['slug']) ?>" class="btn btn-ghost btn-sm">Read →</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ====== CTA ====== -->
<section class="section">
  <div class="container">
    <div style="text-align:center;max-width:640px;margin:0 auto" data-reveal>
      <div class="section-label">Join thousands of learners</div>
      <h2 class="section-title">Ready to master Web3?</h2>
      <p style="margin-bottom:var(--space-6)">
        Get full access to all premium lessons, exclusive airdrop guides, and become part of the next generation of Web3 builders.
      </p>
      <?php if (!isLoggedIn()): ?>
        <a href="<?= SITE_URL ?>/register.php" class="btn btn-primary btn-lg">Create free account →</a>
      <?php elseif (!isPremium()): ?>
        <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary btn-lg">Upgrade to Premium — <?= htmlspecialchars(getPremiumPriceDisplay()) ?></a>
      <?php else: ?>
        <a href="<?= SITE_URL ?>/guide.php" class="btn btn-primary btn-lg">Continue learning →</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
