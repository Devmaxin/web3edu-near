<?php
/**
 * Reusable bounty card. Expects $b (a row from listBounties()/getFeaturedBounties(),
 * with joined category_name/icon, host_name/logo/is_verified, bookmark_count,
 * submission_count) and $badge (from bountyTypeBadge($b)).
 */
$deadlineTs = $b['deadline'] ? strtotime($b['deadline']) : null;
$daysLeft   = $deadlineTs ? ceil(($deadlineTs - time()) / 86400) : null;
$logo       = $b['project_logo'] ?: ($b['host_logo'] ?? null);
$isBookmarked = isBookmarked('bounty', (int)$b['id']);
?>
<div class="card bounty-card card-interactive" data-reveal>
  <?php if ($b['is_featured']): ?><span class="airdrop-featured-badge"><?= lucide('star', ['size' => 11]) ?> Featured</span><?php endif; ?>
  <?php if (bountyIsPremiumOnly($b)): ?><span class="bounty-premium-corner" title="Premium Only"><?= lucide('lock', ['size' => 11]) ?></span><?php endif; ?>

  <div class="bounty-card-head">
    <img class="bounty-logo" src="<?= htmlspecialchars(imageUrl($logo, 'lesson')) ?>" alt="" onerror="this.style.visibility='hidden'">
    <div class="bounty-card-title-wrap">
      <span class="bounty-badge <?= $badge['class'] ?>"><?= $badge['icon'] ?> <?= htmlspecialchars($badge['label']) ?></span>
      <h3 class="bounty-card-title" style="margin-top:4px"><?= htmlspecialchars($b['title']) ?></h3>
      <?php if (!empty($b['project_name'])): ?><div class="bounty-card-project"><?= htmlspecialchars($b['project_name']) ?></div><?php endif; ?>
    </div>
    <button class="bookmark-btn <?= $isBookmarked ? 'active' : '' ?>" data-type="bounty" data-id="<?= (int)$b['id'] ?>"
            aria-pressed="<?= $isBookmarked ? 'true' : 'false' ?>" title="<?= $isBookmarked ? 'Remove bookmark' : 'Save for later' ?>">
      <?= lucide('bookmark', ['size' => 16]) ?>
    </button>
  </div>

  <div class="bounty-card-meta-row">
    <span class="bounty-reward"><?= htmlspecialchars($b['reward_display'] ?: 'TBD') ?></span>
    <?php if ($daysLeft !== null): ?>
      <span class="bounty-deadline-chip <?= $daysLeft <= 2 ? 'urgent' : '' ?>"><?= lucide('clock', ['size' => 11]) ?> <?= $daysLeft > 0 ? "Due in {$daysLeft}d" : 'Ending today' ?></span>
    <?php endif; ?>
    <?php if (!empty($b['category_name'])): ?><span style="display:inline-flex;align-items:center;gap:3px"><?= lucide(bountyCategoryIconKey($b['category_slug'] ?? null), ['size' => 11]) ?> <?= htmlspecialchars($b['category_name']) ?></span><?php endif; ?>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:auto;padding-top:var(--space-2)">
    <span style="font-size:var(--text-xs);color:var(--text-muted)"><?= (int)$b['submission_count'] ?> submissions</span>
    <a href="<?= SITE_URL ?>/bounty-detail.php?slug=<?= urlencode($b['slug']) ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:4px">View <?= lucide('arrow-right', ['size' => 12]) ?></a>
  </div>
</div>
