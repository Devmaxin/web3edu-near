<?php
require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Learn Web3. Build on NEAR.';
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

// ---- Admin-managed rotating headlines ----
$headlines = $db->query(
    "SELECT headline_text FROM hero_headlines WHERE is_active = 1 ORDER BY sort_order"
)->fetchAll(PDO::FETCH_COLUMN);
if (empty($headlines)) {
    $headlines = ['Learn Web3. Build on NEAR.'];
}

// ---- Admin-managed crypto carousel ----
$carouselItems = $db->query(
    "SELECT coin_name, logo_path FROM crypto_carousel_items WHERE is_active = 1 ORDER BY sort_order"
)->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- ====== HERO ====== -->
<section class="hero" data-reveal>
  <div class="container hero-layout">
    <div class="hero-content">
      <div class="hero-kicker">
        <span class="dot" aria-hidden="true"></span>
        NEAR-focused education
      </div>
      <h1 class="hero-title">Learn Web3.<br>Build on NEAR.</h1>
      <p class="hero-subtitle">
        Explore blockchain fundamentals, discover the NEAR ecosystem, develop practical skills and earn verifiable credentials as you progress.
      </p>
      <div class="hero-cta">
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

    <!-- Hero visual: NEAR wallet integration card, positioned below content on mobile -->
    <div class="hero-visual">
      <div class="near-grid">
        <div class="grid-item">
          <span class="label">Account</span>
          <span class="value green">alice.testnet</span>
        </div>
        <div class="grid-item">
          <span class="label">Wallet</span>
          <span class="value">Connected</span>
        </div>
        <div class="grid-item">
          <span class="label">Contract</span>
          <span class="value">web3edu-near.testnet</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ====== INTRODUCE NEAR ====== -->
<section class="section" data-reveal>
  <div class="container">
    <div class="section-head">
      <div class="section-label">Introduce NEAR</div>
      <h2 class="section-title">Your journey into Web3 starts with NEAR.</h2>
      <p class="section-desc">
        NEAR is a carbon-neutral, developer-friendly blockchain built for everyday users.
        Human-readable accounts (alice.testnet instead of hex addresses), fast and cheap
        transactions, and smart contracts anyone can deploy — making it the ideal chain
        to learn Web3 on.
      </p>
    </div>
    <div class="near-facts">
      <div class="fact-card">
        <svg class="fact-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <div class="fact-title">Human-readable accounts</div>
        <div class="fact-desc">alice.testnet instead of 0x hex strings — easy to remember, easy to share.</div>
      </div>
      <div class="fact-card">
        <svg class="fact-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
        <div class="fact-title">Fast, low-cost transactions</div>
        <div class="fact-desc">Sub-second finality and near-zero fees make experimentation accessible to every learner.</div>
      </div>
      <div class="fact-card">
        <svg class="fact-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"/><rect x="8" y="8" width="8" height="4"/><rect x="8" y="14" width="8" height="4"/></svg>
        <div class="fact-title">Smart contracts on-chain</div>
        <div class="fact-desc">Your learning credentials can live as verifiable on-chain records, not just PDFs.</div>
      </div>
    </div>
  </div>
</section>

<!-- ====== LEARNING PATHS ====== -->
<section class="section" data-reveal>
  <div class="container">
    <div class="section-head">
      <div class="section-label">Learning paths</div>
      <h2 class="section-title">Find your path into Web3.</h2>
      <p class="section-desc">
        Choose from existing learning paths built around the NEAR ecosystem. Each path
        guides you from fundamentals to practical skills with verifiable milestones.
      </p>
    </div>
    <div class="paths-grid">
      <div class="path-card">
        <svg class="path-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 19a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H5z"/><polyline points="2 12 10 12 14 18"/></svg>
        <div class="path-title">Web3 Foundations</div>
        <div class="path-desc">Blockchain basics, wallets, transactions, and what makes NEAR different.</div>
        <a href="<?= SITE_URL ?>/guide.php" class="path-link">Start path →</a>
      </div>
      <div class="path-card">
        <svg class="path-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 21V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2z"/><path d="M17 21v-4"/></svg>
        <div class="path-title">NEAR Protocol Fundamentals</div>
        <div class="path-desc">Accounts, wallets, gas, storage, and the NEAR runtime explained for beginners.</div>
        <a href="<?= SITE_URL ?>/guide.php" class="path-link">Start path →</a>
      </div>
      <div class="path-card">
        <svg class="path-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        <div class="path-title">Building on NEAR</div>
        <div class="path-desc">Smart contracts, front-end integration, and deploying your first dApp.</div>
        <a href="<?= SITE_URL ?>/guide.php" class="path-link">Start path →</a>
      </div>
    </div>
  </div>
</section>

<!-- ====== LEARN BY DOING ====== -->
<section class="section" data-reveal style="border-top:1px solid var(--border);border-bottom:1px solid var(--border)">
  <div class="container">
    <div class="section-head" style="justify-content:center;text-align:center;flex-direction:column">
      <div class="section-label">Learn by doing</div>
      <h2 class="section-title">Go beyond theory. Build your Web3 skills.</h2>
    </div>
    <div class="grid-3">
      <?php foreach ($freeLessons as $lesson): ?>
        <article class="card content-card card-interactive">
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
  </div>
</section>

<!-- ====== VERIFIABLE CREDENTIALS ====== -->
<section class="section" data-reveal>
  <div class="container">
    <div class="section-head">
      <div class="section-label">Verifiable credentials</div>
      <h2 class="section-title">Your learning can live on-chain.</h2>
      <p class="section-desc">
        Completing a lesson earns you a locally generated certificate. A credential becomes
        an on-chain credential only after successful issuance and verification on NEAR —
        not every learner automatically has a blockchain credential.
      </p>
    </div>
    <div class="grid-2">
      <div class="card credential-card">
        <svg class="credential-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"/><rect x="8" y="8" width="8" height="4"/><rect x="8" y="14" width="8" height="4"/></svg>
        <div class="credential-title">Local certificate</div>
        <div class="credential-desc">Generated when you complete a lesson or quiz. Stored in your account, verifiable on the platform.</div>
      </div>
      <div class="card credential-card">
        <svg class="credential-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <div class="credential-title">NEAR credential</div>
        <div class="credential-desc">Issued to the NEAR smart contract after successful on-chain verification. Lives on testnet as a permanent record.</div>
      </div>
    </div>
    <div style="text-align:center;margin-top:var(--space-6)">
      <a href="<?= SITE_URL ?>/verify-near.php" class="btn btn-secondary btn-lg">Verify a credential →</a>
    </div>
  </div>
</section>

<!-- ====== SKILL PASSPORT ====== -->
<section class="section" data-reveal>
  <div class="container">
    <div class="section-head">
      <div class="section-label">Skill Passport</div>
      <h2 class="section-title">Make your learning journey visible.</h2>
      <p class="section-desc">
        Your Skill Passport presents your learning achievements and verified NEAR credentials
        in one public profile. Share it with anyone — no login required to view.
      </p>
    </div>
    <div class="grid-3">
      <div class="card passport-card">
        <svg class="passport-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="8 14 6 14"/></svg>
        <div class="passport-title">Your achievements</div>
        <div class="passport-desc">Lessons completed, quizzes passed, certificates earned — all in one place.</div>
      </div>
      <div class="card passport-card">
        <svg class="passport-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 13V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h9"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
        <div class="passport-title">Linked NEAR wallet</div>
        <div class="passport-desc">Connect your NEAR account to display on-chain credentials alongside your local certificates.</div>
      </div>
      <div class="card passport-card">
        <svg class="passport-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        <div class="passport-title">Public verification</div>
        <div class="passport-desc">Anyone can verify your credentials using your public Skill Passport URL.</div>
      </div>
    </div>
    <div style="text-align:center;margin-top:var(--space-6)">
      <a href="<?= SITE_URL ?>/skill-passport.php?u=1" class="btn btn-secondary btn-lg">View example passport →</a>
    </div>
  </div>
</section>

<!-- ====== FINAL CTA ====== -->
<section class="section" style="border-top:1px solid var(--border)">
  <div class="container">
    <div style="text-align:center;max-width:640px;margin:0 auto">
      <div class="section-label">Ready to start?</div>
      <h2 class="section-title">Your Web3 journey starts on NEAR.</h2>
      <p style="margin-bottom:var(--space-6)">
        Start learning, explore the ecosystem and build the skills to participate in Web3.
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