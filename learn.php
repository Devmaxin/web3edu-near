<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/gamification.php';
require_once __DIR__ . '/includes/career_paths.php';

if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = SITE_URL . '/learn.php';
    setFlash('info', 'Sign in to access Web3Edu\'s learning paths.');
    header('Location: ' . SITE_URL . '/login.php'); exit;
}
requireProfileComplete();

$pageTitle = 'Learn';
$extraCss  = [SITE_URL . '/assets/css/learn.css', SITE_URL . '/assets/css/career-paths.css'];
$extraJs   = [SITE_URL . '/assets/js/learn.js', SITE_URL . '/assets/js/bookmark.js', SITE_URL . '/assets/js/career-onboarding.js'];

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ---- CAREER PATHS: "Your Learning Path" premium section ----
$careerPaths       = getActiveCareerPaths();
$activeCareerPath  = getActiveUserCareerPath($userId);
$roadmap           = null;
$nextItem          = null;
if ($activeCareerPath) {
    $roadmap  = computeCareerPathRoadmap($userId, (int)$activeCareerPath['career_path_id']);
    $nextItem = resolveNextLearningItem($userId, (int)$activeCareerPath['career_path_id']);
}
$showOnboarding = !hasSeenLearnOnboarding($userId);
$onboardingModalMode = $activeCareerPath ? 'switch' : 'onboarding';

// ---- GUIDES (guide_paths — the platform's one learning-path entity) ----
$guides = $db->query("
    SELECT * FROM guide_paths WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id ASC
")->fetchAll();

foreach ($guides as &$g) {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM guide_path_lessons WHERE guide_id = ?");
    $countStmt->execute([$g['id']]);
    $g['lesson_count'] = (int)$countStmt->fetchColumn();
    $g['progress'] = getGuideProgress((int)$g['id']);
}
unset($g);

require_once __DIR__ . '/includes/header.php';
?>

<section class="learn-hero container">
  <div class="section-label" style="text-align:center">Structured learning</div>
  <h1 style="text-align:center">Pick up where you left off</h1>
  <p style="text-align:center;max-width:520px;margin:0 auto">Follow a guided path from zero to confident, or browse every lesson on the platform at your own pace.</p>

  <div class="learn-toggle-wrap">
    <div class="pill-toggle" id="learnToggle">
      <button data-panel="panel-guides" class="active">Guide</button>
      <a href="<?= SITE_URL ?>/lessons.php">Lessons</a>
    </div>
  </div>
</section>

<section class="container lp-section">
  <?php
    $currentStageTitle = null;
    if ($roadmap) {
        foreach ($roadmap['guides'] as $g) {
            if ($g['is_current']) { $currentStageTitle = $g['title']; break; }
        }
        if ($currentStageTitle === null && $roadmap['is_complete']) $currentStageTitle = 'Path Complete';
    }
  ?>
  <h2 style="margin-bottom:var(--space-4)">Your Learning Path</h2>
  <div class="lp-card">
    <?php if (!$activeCareerPath): ?>
      <div class="lp-empty">
        <div style="font-size:34px">🧭</div>
        <p>You haven't chosen a Career Path yet. Pick a goal and we'll build your personalized roadmap.</p>
        <button type="button" class="btn-primary cp-trigger-open">Choose Your Path</button>
      </div>
    <?php else: ?>
      <div class="lp-card-header">
        <div class="lp-path-badge">
          <span class="lp-path-icon"><?= sanitize($activeCareerPath['icon'] ?: '⭐') ?></span>
          <div>
            <p class="lp-path-name"><?= sanitize($activeCareerPath['name']) ?></p>
            <p class="lp-path-stage">Current Stage: <?= sanitize($currentStageTitle ?? '—') ?></p>
          </div>
        </div>
        <button type="button" class="lp-change-btn cp-trigger-open">Change Path</button>
      </div>

      <div class="lp-progress-row">
        <div class="progress-track"><div class="progress-fill" style="width:<?= (int)$roadmap['progress']['pct'] ?>%"></div></div>
        <span class="lp-progress-pct"><?= (int)$roadmap['progress']['completed'] ?>/<?= (int)$roadmap['progress']['total'] ?> · <?= (int)$roadmap['progress']['pct'] ?>%</span>
      </div>

      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <?php if ($nextItem): ?>
          <a href="<?= SITE_URL ?>/roadmap.php" class="btn-primary btn-sm">Continue Learning →</a>
        <?php elseif ($roadmap['is_complete']): ?>
          <a href="<?= SITE_URL ?>/roadmap.php" class="btn-primary btn-sm">View Completed Path</a>
        <?php else: ?>
          <a href="<?= SITE_URL ?>/roadmap.php" class="btn-primary btn-sm">View Roadmap</a>
        <?php endif; ?>
        <a href="<?= SITE_URL ?>/roadmap.php" class="btn-ghost btn-sm">View Full Roadmap</a>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="container" style="padding-bottom:var(--space-8)">
  <h2 class="lp-guide-library-heading">Browse All Guides</h2>

  <!-- ====== GUIDE PANEL ====== -->
  <div class="learn-panel active" id="panel-guides">
    <?php if (empty($guides)): ?>
      <div class="learn-empty">
        <h3>No learning paths yet</h3>
        <p>Guides are being prepared — check back soon.</p>
      </div>
    <?php else: ?>
      <?php foreach ($guides as $guide): ?>
        <div class="card guide-row card-interactive">
          <div class="guide-row-thumb"><?= strtoupper(substr($guide['title'], 0, 2)) ?></div>
          <div class="guide-row-body">
            <h3 style="font-size:var(--text-lg);margin:0"><?= htmlspecialchars($guide['title']) ?></h3>
            <div class="guide-row-meta">
              <span><?= (int)$guide['lesson_count'] ?> lessons</span>
              <span><?= htmlspecialchars($guide['difficulty'] ?? 'Beginner') ?></span>
              <?php if (!empty($guide['xp_reward'])): ?><span class="mono">+<?= (int)$guide['xp_reward'] ?> XP</span><?php endif; ?>
            </div>
            <div class="guide-row-progress">
              <div class="progress-track"><div class="progress-fill" style="width:<?= (int)$guide['progress']['pct'] ?>%"></div></div>
              <span class="mono" style="font-size:var(--text-xs);color:var(--text-muted)"><?= (int)$guide['progress']['completed'] ?>/<?= (int)$guide['lesson_count'] ?></span>
            </div>
          </div>
          <button class="bookmark-btn <?= isBookmarked('guide_path', (int)$guide['id']) ? 'active' : '' ?>"
                  data-type="guide_path" data-id="<?= (int)$guide['id'] ?>"
                  aria-pressed="<?= isBookmarked('guide_path', (int)$guide['id']) ? 'true' : 'false' ?>"
                  title="<?= isBookmarked('guide_path', (int)$guide['id']) ? 'Remove bookmark' : 'Save for later' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          </button>
          <a href="<?= SITE_URL ?>/guide.php?guide=<?= (int)$guide['id'] ?>" class="guide-row-action">
            <?= $guide['progress']['pct'] > 0 ? 'Continue' : 'Start' ?> →
          </a>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</section>

<?php
  $mode     = $onboardingModalMode;
  $autoOpen = $showOnboarding;
  require_once __DIR__ . '/includes/partials/career_onboarding_modal.php';
?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
