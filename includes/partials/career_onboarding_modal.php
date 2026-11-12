<?php
/**
 * Welcome Modal (first Learn visit) and Change Path modal share this markup.
 * Expects $careerPaths (array from getActiveCareerPaths()) already loaded
 * by the including page. $mode is 'onboarding' or 'switch'.
 * Optional $autoOpen (bool) opens the modal automatically on page load.
 */
$mode     = $mode ?? 'onboarding';
$autoOpen = $autoOpen ?? false;
?>
<div class="cp-modal-overlay" id="cpModalOverlay" data-mode="<?= sanitize($mode) ?>" data-auto-open="<?= $autoOpen ? '1' : '0' ?>" hidden>
  <div class="cp-modal" role="dialog" aria-modal="true" aria-labelledby="cpModalTitle">

    <!-- STEP 1: choose a path -->
    <div class="cp-modal-step" data-step="choose">
      <button type="button" class="cp-modal-close" id="cpModalSkip" aria-label="Close">✕</button>
      <h2 id="cpModalTitle" class="cp-modal-title">
        <?= $mode === 'switch' ? 'Change your Career Path' : 'Welcome to Web3Edu' ?>
      </h2>
      <p class="cp-modal-subtitle">
        <?php if ($mode === 'switch'): ?>
          Your progress is saved on every path — switch anytime, pick back up right where you left off.
        <?php else: ?>
          Let's personalize your learning journey.<br>We'll recommend the best roadmap based on your goal.
        <?php endif; ?>
      </p>

      <div class="cp-card-grid">
        <?php foreach ($careerPaths as $cp): ?>
          <button type="button" class="cp-card" data-id="<?= (int)$cp['id'] ?>" style="--cp-color: <?= sanitize($cp['theme_color'] ?: '#0B7958') ?>">
            <span class="cp-card-icon"><?= sanitize($cp['icon'] ?: '⭐') ?></span>
            <span class="cp-card-name"><?= sanitize($cp['name']) ?></span>
            <?php if (!empty($cp['description'])): ?>
              <span class="cp-card-desc"><?= sanitize($cp['description']) ?></span>
            <?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>

      <?php if ($mode === 'onboarding'): ?>
        <button type="button" class="cp-skip-link" id="cpSkipForNow">Skip for now</button>
      <?php endif; ?>
    </div>

    <!-- STEP 2: animated build-out -->
    <div class="cp-modal-step" data-step="building" hidden>
      <div class="cp-building">
        <div class="cp-spinner"></div>
        <ul class="cp-building-list">
          <li data-i="0">Understanding your goal</li>
          <li data-i="1">Building your roadmap</li>
          <li data-i="2">Organizing your learning experience</li>
          <li data-i="3">Almost ready...</li>
        </ul>
      </div>
    </div>

    <!-- STEP 3: ready -->
    <div class="cp-modal-step" data-step="ready" hidden>
      <div class="cp-ready">
        <div class="cp-ready-check">✓</div>
        <h2 class="cp-modal-title">Your learning path is ready!</h2>
        <button type="button" class="btn-primary cp-start-btn" id="cpStartLearning">Start Learning</button>
      </div>
    </div>

  </div>
</div>
