<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/gamification.php';
require_once __DIR__ . '/includes/career_paths.php';
require_once __DIR__ . '/includes/opportunities.php';

if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = SITE_URL . '/roadmap.php';
    setFlash('info', 'Sign in to view your roadmap.');
    header('Location: ' . SITE_URL . '/login.php'); exit;
}
requireProfileComplete();

$userId = (int)$_SESSION['user_id'];
$active = getActiveUserCareerPath($userId);

if (!$active) {
    setFlash('info', 'Choose a Career Path first to see your personalized roadmap.');
    header('Location: ' . SITE_URL . '/learn.php'); exit;
}

$careerPathId = (int)$active['career_path_id'];
$roadmap      = computeCareerPathRoadmap($userId, $careerPathId);
$nextItem     = resolveNextLearningItem($userId, $careerPathId);
$relevantOpportunities = getCareerPathOpportunities($careerPathId, 3);

$pageTitle = $active['name'] . ' Roadmap';
$extraCss  = [SITE_URL . '/assets/css/learn.css', SITE_URL . '/assets/css/career-paths.css', SITE_URL . '/assets/css/roadmap.css'];

require_once __DIR__ . '/includes/header.php';
?>

<section class="learn-hero container">
  <div class="section-label" style="text-align:center">Your roadmap</div>
  <h1 style="text-align:center"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/><polyline points="12 2 12 24"/><polyline points="2 12 22 12"/></svg> <?= sanitize($active['name']) ?></h1>
  <?php if (!empty($active['description'])): ?>
    <p style="text-align:center;max-width:560px;margin:0 auto"><?= sanitize($active['description']) ?></p>
  <?php endif; ?>
</section>

<section class="container" style="padding-bottom:var(--space-8)">

  <div class="rm-progress-bar">
    <div class="progress-track"><div class="progress-fill" style="width:<?= (int)$roadmap['progress']['pct'] ?>%"></div></div>
    <span class="lp-progress-pct"><?= (int)$roadmap['progress']['completed'] ?>/<?= (int)$roadmap['progress']['total'] ?> required lessons · <?= (int)$roadmap['progress']['pct'] ?>%</span>
  </div>

  <?php if ($nextItem):
        $niTitle = $nextItem['lesson_title'] ?? $nextItem['quiz_title'] ?? $nextItem['project_title'] ?? $nextItem['assessment_title'] ?? $nextItem['resource_title'] ?? '';
        $niTypeLabel = ['lesson' => 'Continue Learning', 'quiz' => 'Take the Quiz', 'project' => 'Work on your Project', 'assessment' => 'Take the Final Assessment', 'resource' => 'Check out this Resource'][$nextItem['type']] ?? 'Continue Learning';
        switch ($nextItem['type']) {
            case 'lesson':
                $niLink = SITE_URL . '/guides/lesson.php?guide_id=' . (int)$nextItem['guide_id'] . '&id=' . (int)$nextItem['id'];
                $niCta = 'Resume →';
                break;
            case 'project':
                $niLink = SITE_URL . '/projects/submit.php?project_id=' . (int)$nextItem['id'] . '&guide_id=' . (int)$nextItem['guide_id'];
                $niCta = 'Open Project →';
                break;
            case 'resource':
                $niLink = SITE_URL . '/guide.php?guide=' . (int)$nextItem['guide_id'];
                $niCta = 'View Resource →';
                break;
            default: // quiz, assessment — both live inline on the guide page
                $niLink = SITE_URL . '/guide.php?guide=' . (int)$nextItem['guide_id'];
                $niCta = 'Open Guide →';
        }
  ?>
    <div class="rm-continue card">
      <div>
        <div class="section-label" style="margin-bottom:4px"><?= sanitize($niTypeLabel) ?></div>
        <p style="margin:0;font-weight:600"><?= sanitize($niTitle) ?></p>
        <p style="margin:2px 0 0;font-size:var(--text-xs);color:var(--text-muted)">in <?= sanitize($nextItem['guide_title'] ?? '') ?></p>
      </div>
      <a class="btn-primary btn-sm" href="<?= $niLink ?>"><?= $niCta ?></a>
    </div>
  <?php elseif ($roadmap['is_complete']): ?>
    <div class="rm-continue card">
      <div>
        <div class="section-label" style="margin-bottom:4px">Path Complete</div>
        <p style="margin:0;color:var(--text-muted)">You've completed every required guide on this path.</p>
      </div>
    </div>
  <?php endif; ?>

  <?php if (empty($roadmap['guides'])): ?>
    <div class="learn-empty">
      <h3>No guides attached yet</h3>
      <p>An admin hasn't added guides to this Career Path yet — check back soon.</p>
    </div>
  <?php else: ?>
    <?php foreach ($roadmap['grouped'] as $group): ?>
      <?php if ($group['milestone']): ?>
        <div class="rm-milestone-header">
          <h2><?= sanitize($group['milestone']['title']) ?></h2>
          <?php if (!empty($group['milestone']['description'])): ?>
            <p><?= sanitize($group['milestone']['description']) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php foreach ($group['guides'] as $g): ?>
        <div class="card guide-row card-interactive rm-guide rm-guide--<?= $g['status'] ?><?= $g['is_current'] ? ' rm-guide--current' : '' ?>">
          <div class="guide-row-thumb"><?= $g['status'] === 'locked' ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' : ($g['status'] === 'completed' ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' : strtoupper(substr($g['title'], 0, 2))) ?></div>
          <div class="guide-row-body">
            <h3 style="font-size:var(--text-lg);margin:0">
              <?= sanitize($g['title']) ?>
              <?php if ($g['requirement'] === 'optional'): ?><span class="rm-optional-tag">Optional</span><?php endif; ?>
              <?php if ($g['is_current']): ?><span class="rm-current-tag">Current</span><?php endif; ?>
            </h3>
            <div class="guide-row-meta">
              <span><?= (int)$g['total_lessons'] ?> lessons</span>
              <span><?= sanitize($g['difficulty'] ?? 'Beginner') ?></span>
            </div>
            <div class="guide-row-progress">
              <div class="progress-track"><div class="progress-fill" style="width:<?= (int)$g['pct'] ?>%"></div></div>
              <span class="mono" style="font-size:var(--text-xs);color:var(--text-muted)"><?= (int)$g['done_lessons'] ?>/<?= (int)$g['total_lessons'] ?></span>
            </div>
          </div>
          <?php if ($g['status'] === 'locked'): ?>
            <span class="rm-locked-label">Locked</span>
          <?php else: ?>
            <a href="<?= SITE_URL ?>/guide.php?guide=<?= (int)$g['guide_id'] ?>" class="guide-row-action">
              <?= $g['is_complete'] ? 'Review' : ($g['done_lessons'] > 0 ? 'Continue' : 'Start') ?> →
            </a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>

<?php if (!empty($relevantOpportunities)): ?>
  <div style="margin-top:var(--space-7)">
    <h2 style="font-size:var(--text-lg,1.1rem);margin-bottom:14px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px"><path d="M17.5 17.29L17 3a1 1 0 0 0-1.15-1.97L18.5 4.47a1 1 0 0 0 1.15.97v2.39L21 10.47a1 1 0 0 0 1.15-.97l1.48-1.77a1 1 0 0 0-1.47-1.44L18.28 6.72A2.53 2.53 0 0 0 14.5 6H10a2.53 2.53 0 0 0-2.53 2.53L7.32 9H4.5a1 1 0 0 0-1 1v3c0 .73.29 1.41.74 1.93l1.74 1.96a1 1 0 0 0 1.15.97l2.64 1.9a1 1 0 0 0 1.14 0l3.39-2.47a1 1 0 0 0 1-1.94l-1.44-1.71a1 1 0 0 0-.16-.37l2.19-2.17a1 1 0 0 1 .28-.31c.12-.19.28-.37.48-.53L20.72 9.7a1 1 0 0 0-.45-1.7Z"/></svg> Relevant Opportunities</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px">
      <?php foreach ($relevantOpportunities as $ro): ?>
      <a href="<?= SITE_URL ?>/opportunity.php?id=<?= (int)$ro['id'] ?>" class="card" style="padding:14px">
        <span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:0.68rem;font-weight:700;background:var(--accent-soft);color:var(--accent-bright);margin-bottom:6px"><?= sanitize(OPPORTUNITY_CATEGORIES[$ro['category']] ?? $ro['category']) ?></span>
        <div style="font-size:0.85rem;font-weight:700;color:inherit"><?= sanitize($ro['title']) ?></div>
        <?php if ($ro['organization']): ?><div style="font-size:0.72rem;color:var(--text-muted)"><?= sanitize($ro['organization']) ?></div><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div style="margin-top:var(--space-6);text-align:center">
    <a href="<?= SITE_URL ?>/learn.php" class="btn-ghost btn-sm">← Back to Learn</a>
  </div>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
