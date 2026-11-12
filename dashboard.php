<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/gamification.php';
require_once __DIR__ . '/includes/subscriptions.php';
require_once __DIR__ . '/includes/career_paths.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/opportunities.php';
require_once __DIR__ . '/includes/notes_highlights.php';
requireLogin();
requireProfileComplete();

$pageTitle = 'Dashboard';
$extraCss  = [SITE_URL . '/assets/css/dashboard.css'];
$user = getCurrentUser();
$db   = getDB();

// ---- Stats (existing business logic, unchanged) ----
$totalLessons   = $db->query("SELECT COUNT(*) FROM lessons WHERE deleted_at IS NULL")->fetchColumn();
$freeLessons    = $db->query("SELECT COUNT(*) FROM lessons WHERE access_level='free' AND deleted_at IS NULL")->fetchColumn();
$premiumLessons = $db->query("SELECT COUNT(*) FROM lessons WHERE access_level='premium' AND deleted_at IS NULL")->fetchColumn();
$activeAirdrops = $db->query("SELECT COUNT(*) FROM airdrops WHERE status='active' AND deleted_at IS NULL")->fetchColumn();

// Recent purchases (up to 20)
$stmt = $db->prepare("SELECT * FROM purchases WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$user['id']]);
$purchases = $stmt->fetchAll();

// Recent lessons (latest 6) — doubles as "Recent Activity" content source
$recentLessons = $db->query("SELECT * FROM lessons WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 6")->fetchAll();

// ---- Gamification data (existing helpers, unchanged) ----
$userBadges   = getUserBadges();
$lastLesson   = getLastLesson();
$completedCnt = getUserCompletedCount();
$progressPct  = $totalLessons > 0 ? (int)round($completedCnt / $totalLessons * 100) : 0;

$firstGuide    = $db->query("SELECT * FROM guide_paths WHERE is_active=1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1")->fetch();
$guideProgress = $firstGuide ? getGuideProgress((int)$firstGuide['id']) : null;
$nextLesson    = $firstGuide ? getNextGuideLesson((int)$firstGuide['id']) : null;

// ---- XP & Level (new — columns added in the v2 migration, default to 0/1 gracefully) ----
$totalXp      = (int)($user['total_xp'] ?? 0);
$currentLevel = (int)($user['current_level'] ?? 1);
$streakDays   = (int)($user['current_streak_days'] ?? 0);
$streakFreezes = (int)($user['streak_freeze_count'] ?? 0);

// One-time onboarding reward celebration (read-once flash — never re-shows on refresh)
$onboardingReward = getOnboardingRewardFlash();

$levelRow = $db->prepare("SELECT * FROM levels WHERE level_number = ?");
$levelRow->execute([$currentLevel]);
$levelInfo = $levelRow->fetch();
$nextLevelRow = $db->prepare("SELECT * FROM levels WHERE level_number = ?");
$nextLevelRow->execute([$currentLevel + 1]);
$nextLevelInfo = $nextLevelRow->fetch();
$xpToNext = $nextLevelInfo ? max(0, (int)$nextLevelInfo['xp_required'] - $totalXp) : 0;
$currentLevelFloor = (int)($levelInfo['xp_required'] ?? 0);
$levelRangeSpan = $nextLevelInfo ? max(1, (int)$nextLevelInfo['xp_required'] - $currentLevelFloor) : 1;
$levelProgressPct = $nextLevelInfo ? min(100, (int)round(($totalXp - $currentLevelFloor) / $levelRangeSpan * 100)) : 100;

// ---- Subscription (Monthly / Yearly plans) ----
$subscription = getUserSubscription($user['id']);

// ---- Phase 3: Career Path / Roadmap / Continue Learning (reuses career_paths.php as-is) ----
$dashActivePath = getActiveUserCareerPath($user['id']);
$dashRoadmap = null;
$dashNextItem = null;
$dashRelevantOpportunities = [];
$dashRelatedProjects = [];
if ($dashActivePath) {
    $dashRoadmap = computeCareerPathRoadmap($user['id'], (int)$dashActivePath['career_path_id']);
    $dashNextItem = resolveNextLearningItem($user['id'], (int)$dashActivePath['career_path_id']);
    $dashRelevantOpportunities = getCareerPathOpportunities((int)$dashActivePath['career_path_id'], 3);
    $dashRelatedProjects = getRelatedProjects($dashNextItem['guide_id'] ?? null, null, 3);
}

// ---- Phase 3: recent certificates (reuses the Phase 2 portfolio helper) ----
$dashCertificates = array_slice(getUserCertificatesForPortfolio($user['id']), 0, 3);

// ---- Phase 3: AI Mentor daily recommendation (cached once/day — only the
// FIRST dashboard visit each day triggers a live AI call; every subsequent
// visit that day reads the cached ai_daily_insights row instead) ----
$dashAiRecommendation = null;
try {
    require_once __DIR__ . '/includes/ai/AIService.php';
    $aiRec = AIService::getDailyRecommendation($user['id']);
    if ($aiRec['success']) $dashAiRecommendation = $aiRec['content'];
} catch (\Throwable $e) {
    // AI Mentor is supplementary — never break the dashboard if it's unavailable.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_subscription') {
    cancelSubscription($user['id']);
    setFlash('success', 'Your subscription will not renew. You keep access until it expires.');
    header('Location: ' . SITE_URL . '/dashboard.php'); exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container dash-wrap" data-reveal>
  <div class="dash-grid">

    <!-- ====== SIDEBAR ====== -->
    <aside class="dash-sidebar">
      <div class="card dash-user-block">
        <img src="<?= htmlspecialchars(avatarUrl($user['avatar'] ?? '')) ?>" alt="" class="dash-avatar">
        <div class="dash-user-name"><?= htmlspecialchars($user['username']) ?></div>
        <div class="dash-user-email"><?= htmlspecialchars($user['email']) ?></div>
        <div style="margin-top:var(--space-3)">
          <span class="badge <?= isPremium() ? 'badge-accent' : '' ?>"><?= isPremium() ? 'Premium member' : 'Free member' ?></span>
        </div>
      </div>

      <nav class="dash-nav card">
        <a href="<?= SITE_URL ?>/dashboard.php" class="dash-nav-item active">Dashboard</a>
        <a href="<?= SITE_URL ?>/learn.php" class="dash-nav-item">Learn</a>
        <a href="<?= SITE_URL ?>/airdrops.php" class="dash-nav-item">Airdrops</a>
        <a href="<?= SITE_URL ?>/profile.php" class="dash-nav-item">Profile</a>
        <?php if (!isPremium()): ?>
          <a href="<?= SITE_URL ?>/upgrade.php" class="dash-nav-item" style="color:var(--accent-bright)">Upgrade to Premium</a>
        <?php endif; ?>
        <a href="<?= SITE_URL ?>/logout.php" class="dash-nav-item" style="color:var(--danger)">Logout</a>
      </nav>
    </aside>

    <!-- ====== MAIN ====== -->
    <main>
      <h1 style="margin-bottom:var(--space-1)">Welcome back, <span style="background:var(--accent-gradient);-webkit-background-clip:text;background-clip:text;color:transparent"><?= htmlspecialchars($user['username']) ?></span></h1>
<p style="margin-bottom:var(--space-5);font-size:var(--text-sm);color:var(--text-secondary)">Your Web3Edu NEAR learning overview.</p>
      <p style="margin-bottom:var(--space-5)">Here's your Web3Edu learning overview.</p>

      <!-- XP / Level -->
      <div class="card" data-reveal style="margin-bottom:var(--space-5)">
        <div class="xp-ring">
          <div class="xp-ring-level">L<?= $currentLevel ?></div>
          <div style="flex:1">
            <div style="display:flex;justify-content:space-between;font-size:var(--text-sm);font-weight:600">
              <span><?= htmlspecialchars($levelInfo['title'] ?? 'Newcomer') ?></span>
              <span class="mono" style="color:var(--accent-bright)"><?= number_format($totalXp) ?> XP</span>
            </div>
            <?php if ($nextLevelInfo): ?>
              <div class="progress-track"><div class="progress-fill" style="width:<?= $levelProgressPct ?>%"></div></div>
              <div style="font-size:var(--text-xs);color:var(--text-muted)"><?= number_format($xpToNext) ?> XP to <?= htmlspecialchars($nextLevelInfo['title']) ?></div>
            <?php else: ?>
              <div style="font-size:var(--text-xs);color:var(--text-muted);margin-top:var(--space-1)">Max level reached</div>
            <?php endif; ?>
          </div>
          <?php if ($streakDays > 0): ?>
            <div class="badge badge-warning">🔥 <?= $streakDays ?>d streak</div>
          <?php endif; ?>
          <?php if ($streakFreezes > 0): ?>
            <div class="badge badge-accent" title="Protects your streak if you miss a day">🧊 <?= $streakFreezes ?> freeze<?= $streakFreezes !== 1 ? 's' : '' ?></div>
          <?php endif; ?>
        </div>

        <!-- Overall lesson progress -->
        <div style="margin-bottom:var(--space-4)">
          <div style="display:flex;justify-content:space-between;font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-1)">
            <span>Overall progress</span>
            <span class="mono"><?= $completedCnt ?>/<?= $totalLessons ?> · <?= $progressPct ?>%</span>
          </div>
          <div class="progress-track"><div class="progress-fill" style="width:<?= $progressPct ?>%"></div></div>
        </div>

        <?php if ($guideProgress && $firstGuide): ?>
        <div style="margin-bottom:var(--space-4)">
          <div style="display:flex;justify-content:space-between;font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-1)">
            <span>Guide: <?= htmlspecialchars($firstGuide['title']) ?></span>
            <span class="mono"><?= $guideProgress['completed'] ?>/<?= $guideProgress['total'] ?> · <?= $guideProgress['pct'] ?>%</span>
          </div>
          <div class="progress-track"><div class="progress-fill" style="width:<?= $guideProgress['pct'] ?>%"></div></div>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:var(--space-3);flex-wrap:wrap">
          <?php if ($nextLesson && $firstGuide): ?>
            <a href="<?= SITE_URL ?>/guides/<?= (int)$firstGuide['id'] ?>/lesson/<?= (int)$nextLesson['id'] ?>" class="btn btn-primary btn-sm">▶ Next: <?= htmlspecialchars(mb_strimwidth($nextLesson['title'], 0, 26, '…')) ?></a>
          <?php elseif ($lastLesson): ?>
            <a href="<?= SITE_URL ?>/learn/lesson.php?id=<?= (int)$lastLesson['id'] ?>" class="btn btn-secondary btn-sm">Continue: <?= htmlspecialchars(mb_strimwidth($lastLesson['title'], 0, 24, '…')) ?></a>
          <?php else: ?>
            <a href="<?= SITE_URL ?>/learn.php" class="btn btn-primary btn-sm">Start learning →</a>
          <?php endif; ?>
          <a href="<?= SITE_URL ?>/learn.php?view=guides" class="btn btn-ghost btn-sm">View guide</a>
        </div>

        <?php if (!empty($userBadges)): ?>
        <div class="chain-divider"></div>
        <div class="section-label" style="margin-bottom:var(--space-2)">Your badges</div>
        <div style="display:flex;flex-wrap:wrap;gap:var(--space-2)">
          <?php foreach ($userBadges as $b): ?>
            <span class="badge badge-accent" title="<?= htmlspecialchars($b['description']) ?>"><?= htmlspecialchars($b['icon']) ?> <?= htmlspecialchars($b['name']) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Phase 3: Career Path / Roadmap / Continue Learning (personalized, additive — the generic "firstGuide" card above still shows for users with no active path) -->
      <?php if ($dashActivePath && $dashRoadmap): ?>
      <div class="card" data-reveal style="margin-bottom:var(--space-5)">
        <div class="card-header">
          <h2 style="font-size:var(--text-lg);margin:0"><?= sanitize($dashActivePath['icon'] ?: '⭐') ?> <?= sanitize($dashActivePath['name']) ?></h2>
          <a href="<?= SITE_URL ?>/roadmap.php" class="btn btn-ghost btn-sm">View Roadmap →</a>
        </div>
        <div style="margin:var(--space-3) 0">
          <div style="display:flex;justify-content:space-between;font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-1)">
            <span>Roadmap progress</span>
            <span class="mono"><?= $dashRoadmap['progress']['completed'] ?>/<?= $dashRoadmap['progress']['total'] ?> · <?= $dashRoadmap['progress']['pct'] ?>%</span>
          </div>
          <div class="progress-track"><div class="progress-fill" style="width:<?= $dashRoadmap['progress']['pct'] ?>%"></div></div>
        </div>
        <?php if ($dashNextItem): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
          <div style="font-size:var(--text-sm);color:var(--text-muted)">Continue: <strong style="color:var(--text-secondary)"><?= sanitize($dashNextItem['lesson_title'] ?? $dashNextItem['guide_title'] ?? $dashNextItem['project_title'] ?? $dashNextItem['assessment_title'] ?? $dashNextItem['resource_title'] ?? '') ?></strong></div>
          <a href="<?= SITE_URL ?>/roadmap.php" class="btn btn-primary btn-sm">Continue Learning →</a>
        </div>
        <?php elseif ($dashRoadmap['is_complete']): ?>
          <div style="font-size:var(--text-sm);color:#0B7958">🎉 This path is complete!</div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Phase 3: AI Mentor daily recommendation -->
      <?php if ($dashAiRecommendation): ?>
      <div class="card" style="margin-bottom:var(--space-5);border-color:rgba(11,121,88,0.35);background:linear-gradient(145deg, rgba(11,121,88,0.06), transparent)">
        <div class="section-label" style="margin-bottom:var(--space-2)">🧠 AI Mentor Recommends</div>
        <div style="font-size:var(--text-sm);color:var(--text-secondary);line-height:1.7;white-space:pre-line"><?= sanitize($dashAiRecommendation) ?></div>
      </div>
      <?php endif; ?>

      <!-- Phase 3: Certificates / Opportunities / Recommended Projects -->
      <?php if (!empty($dashCertificates) || !empty($dashRelevantOpportunities) || !empty($dashRelatedProjects)): ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--space-4);margin-bottom:var(--space-5)">
        <?php if (!empty($dashCertificates)): ?>
        <div class="card">
          <div class="section-label" style="margin-bottom:var(--space-2)">🎓 Recent Certificates</div>
          <?php foreach ($dashCertificates as $c): ?>
          <div style="font-size:var(--text-sm);padding:6px 0;border-bottom:1px solid var(--border)"><?= sanitize($c['type_icon'] ?: '🎓') ?> <?= sanitize($c['achievement_title'] ?: $c['type_label']) ?></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($dashRelevantOpportunities)): ?>
        <div class="card">
          <div class="section-label" style="margin-bottom:var(--space-2)">🚀 Upcoming Opportunities</div>
          <?php foreach ($dashRelevantOpportunities as $o): ?>
          <a href="<?= SITE_URL ?>/opportunity.php?id=<?= (int)$o['id'] ?>" style="display:block;font-size:var(--text-sm);padding:6px 0;border-bottom:1px solid var(--border);color:inherit"><?= sanitize($o['title']) ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($dashRelatedProjects)): ?>
        <div class="card">
          <div class="section-label" style="margin-bottom:var(--space-2)">🛠️ Recommended Projects</div>
          <?php foreach ($dashRelatedProjects as $p): ?>
          <a href="<?= SITE_URL ?>/projects/submit.php?project_id=<?= (int)$p['id'] ?>" style="display:block;font-size:var(--text-sm);padding:6px 0;border-bottom:1px solid var(--border);color:inherit"><?= sanitize($p['title']) ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($subscription && $subscription['status'] === 'active'): ?>
      <div class="card" data-reveal style="margin-bottom:var(--space-5)">
        <div class="card-header">
          <h2 style="font-size:var(--text-lg);margin:0">Your subscription</h2>
          <span class="badge badge-success">Active</span>
        </div>
        <div class="settings-row">
          <div><div style="font-weight:600">Plan</div><div class="field-hint" style="margin:0"><?= htmlspecialchars($subscription['plan_name']) ?></div></div>
          <div style="text-align:right">
            <?php if ($subscription['expires_at']): ?>
              <div style="font-weight:600"><?= $subscription['cancel_at_period_end'] ? 'Ends' : 'Renews' ?> <?= date('M j, Y', strtotime($subscription['expires_at'])) ?></div>
              <div class="field-hint" style="margin:0"><?= $subscription['cancel_at_period_end'] ? 'Cancelled — access continues until then' : 'Auto-renews' ?></div>
            <?php else: ?>
              <div style="font-weight:600">Permanent access</div>
              <div class="field-hint" style="margin:0">Never expires</div>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($subscription['expires_at'] && !$subscription['cancel_at_period_end']): ?>
        <form method="POST" onsubmit="return confirm('Cancel your subscription? You will keep access until it expires.');" style="margin-top:var(--space-3)">
          <input type="hidden" name="action" value="cancel_subscription">
          <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)">Cancel subscription</button>
        </form>
        <?php endif; ?>
      </div>
      <?php elseif (!isPremium()): ?>
      <div class="alert alert-warning" style="justify-content:space-between;align-items:center;margin-bottom:var(--space-5)">
        <div>
          <strong><?= (int)$premiumLessons ?> premium lessons locked</strong>
          <div style="font-size:var(--text-sm)">Choose a plan for full access to all content, airdrops, and guides.</div>
        </div>
        <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary btn-sm" style="flex-shrink:0">View plans</a>
      </div>
      <?php endif; ?>

      <!-- Quick actions -->
      <div class="card" data-reveal style="margin-bottom:var(--space-5)">
        <div class="quick-actions">
          <a href="<?= SITE_URL ?>/learn.php" class="quick-action"><span class="quick-action-icon">▶</span> Continue learning</a>
          <a href="<?= SITE_URL ?>/learn.php?view=guides" class="quick-action"><span class="quick-action-icon">◧</span> Browse guides</a>
          <a href="<?= SITE_URL ?>/airdrops.php" class="quick-action"><span class="quick-action-icon">◈</span> View airdrops</a>
          <a href="<?= SITE_URL ?>/ai-tutor.php" class="quick-action"><span class="quick-action-icon">✦</span> Ask AI Tutor</a>
        </div>
      </div>

      <!-- Stat cards -->
      <div class="grid-4 stat-grid" style="margin-bottom:var(--space-6)">
        <div class="stat-chip"><span class="value mono"><?= (int)$totalLessons ?></span><span class="label">Total lessons</span></div>
        <div class="stat-chip"><span class="value mono"><?= (int)$freeLessons ?></span><span class="label">Free lessons</span></div>
        <div class="stat-chip"><span class="value mono"><?= (int)$premiumLessons ?></span><span class="label">Premium lessons</span></div>
        <div class="stat-chip"><span class="value mono"><?= (int)$activeAirdrops ?></span><span class="label">Active airdrops</span></div>
      </div>

      <!-- Recent Activity -->
      <div class="card" data-reveal style="margin-bottom:var(--space-5)">
        <div class="card-header">
          <h2 style="font-size:var(--text-lg);margin:0">Recent activity</h2>
          <a href="<?= SITE_URL ?>/learn.php" class="btn btn-ghost btn-sm">View all →</a>
        </div>
        <?php foreach ($recentLessons as $lesson): $locked = $lesson['access_level'] === 'premium' && !isPremium(); ?>
          <div class="activity-row">
            <div class="activity-icon"><?= $lesson['access_level'] === 'premium' ? '◆' : '●' ?></div>
            <div class="activity-text">
              <div class="activity-title"><?= htmlspecialchars($lesson['title']) ?></div>
              <div class="activity-meta"><?= htmlspecialchars($lesson['category']) ?> · <?= htmlspecialchars($lesson['duration']) ?></div>
            </div>
            <?php if ($locked): ?>
              <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-secondary btn-sm">Unlock</a>
            <?php else: ?>
              <a href="<?= SITE_URL ?>/learn/lesson.php?id=<?= (int)$lesson['id'] ?>" class="btn btn-ghost btn-sm">Read →</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Payment History -->
      <div class="card">
        <div class="card-header"><h2 style="font-size:var(--text-lg);margin:0">Payment history</h2></div>
        <?php if (!empty($purchases)): ?>
          <?php foreach ($purchases as $p):
            $status = $p['status'] ?? 'pending';
            $raw = (float)$p['amount'];
            $amountN = number_format($raw > 100000 ? $raw / 100 : $raw, 2);
          ?>
          <div class="txn-row">
            <div class="txn-icon <?= htmlspecialchars($status) ?>"><?= $status === 'success' ? '✓' : ($status === 'failed' ? '✕' : '…') ?></div>
            <div class="txn-details">
              <div style="font-weight:600">Premium purchase</div>
              <div class="txn-ref"><?= htmlspecialchars(substr($p['reference'], 0, 30)) ?></div>
            </div>
            <div class="txn-right">
              <div class="txn-amount">₦<?= $amountN ?></div>
              <div class="activity-meta"><?= date('d M Y', strtotime($p['created_at'])) ?> · <?= ucfirst($status) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="learn-empty">
            <div style="font-weight:600;margin-bottom:var(--space-1)">No transactions yet</div>
            <p style="font-size:var(--text-sm)">Your payment history will appear here after your first purchase.</p>
            <?php if (!isPremium()): ?><a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary btn-sm">Upgrade to Premium</a><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>

<?php if ($onboardingReward): ?>
  <?php require_once __DIR__ . '/includes/partials/reward_celebration_modal.php'; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
