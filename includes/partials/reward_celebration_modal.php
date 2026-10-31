<?php
/**
 * Onboarding reward celebration modal. Included by dashboard.php only when
 * a one-time onboarding reward flash is present (getOnboardingRewardFlash()
 * in includes/gamification.php — read-once, so this never reappears on
 * refresh). Expects $onboardingReward = ['xp'=>int,'total_xp'=>int,'badge'=>?array].
 */
$badge = $onboardingReward['badge'] ?? null;
?>
<div class="reward-modal-overlay" id="rewardModalOverlay" role="dialog" aria-modal="true" aria-labelledby="rewardModalTitle">
  <div class="reward-modal">
    <button type="button" class="reward-modal-close" id="rewardModalClose" aria-label="Close">✕</button>
    <div class="reward-modal-emoji">🎉</div>
    <h2 id="rewardModalTitle" class="reward-modal-title">Welcome to Web3Edu!</h2>

    <?php if ($badge): ?>
      <div class="reward-modal-badge">
        <span class="reward-modal-badge-icon"><?= sanitize($badge['icon'] ?: '🏅') ?></span>
        <span class="reward-modal-badge-name"><?= sanitize($badge['name']) ?></span>
        <?php if (!empty($badge['description'])): ?>
          <span class="reward-modal-badge-desc"><?= sanitize($badge['description']) ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="reward-modal-xp">+<?= (int)($onboardingReward['xp'] ?? 0) ?> Points</div>
    <p class="reward-modal-subtitle">Your Web3 learning journey starts here.</p>

    <button type="button" class="btn btn-primary reward-modal-cta" id="rewardModalCta" style="width:100%">Start learning →</button>
  </div>
</div>

<style>
.reward-modal-overlay {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(8, 8, 16, 0.72);
  display: flex; align-items: center; justify-content: center;
  padding: var(--space-4, 16px);
  opacity: 0; pointer-events: none;
  transition: opacity 0.2s ease;
}
.reward-modal-overlay.is-open { opacity: 1; pointer-events: auto; }
.reward-modal {
  position: relative;
  background: var(--bg-card, #14141f);
  border: 1px solid var(--border, rgba(255,255,255,0.08));
  border-radius: var(--radius-lg, 16px);
  max-width: 380px; width: 100%;
  padding: var(--space-6, 32px) var(--space-5, 24px);
  text-align: center;
  transform: translateY(12px) scale(0.98);
  opacity: 0;
  transition: transform 0.25s ease, opacity 0.25s ease;
}
.reward-modal-overlay.is-open .reward-modal { transform: translateY(0) scale(1); opacity: 1; }
@media (prefers-reduced-motion: reduce) {
  .reward-modal-overlay, .reward-modal { transition: none; }
}
.reward-modal-close {
  position: absolute; top: 12px; right: 12px;
  background: none; border: none; color: var(--text-muted, #888);
  font-size: 16px; cursor: pointer; line-height: 1;
}
.reward-modal-emoji { font-size: 2.5rem; margin-bottom: var(--space-2, 8px); }
.reward-modal-title { font-size: 1.3rem; font-weight: 700; margin-bottom: var(--space-4, 16px); }
.reward-modal-badge {
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  background: var(--bg-card2, rgba(255,255,255,0.04));
  border-radius: var(--radius-md, 12px);
  padding: var(--space-4, 16px);
  margin-bottom: var(--space-3, 12px);
}
.reward-modal-badge-icon { font-size: 2rem; }
.reward-modal-badge-name { font-weight: 700; }
.reward-modal-badge-desc { font-size: var(--text-xs, 0.78rem); color: var(--text-muted, #888); }
.reward-modal-xp {
  font-size: 1.4rem; font-weight: 800;
  background: var(--accent-gradient); -webkit-background-clip: text; background-clip: text; color: transparent;
  margin-bottom: var(--space-2, 8px);
}
.reward-modal-subtitle { font-size: var(--text-sm, 0.85rem); color: var(--text-secondary, #aaa); margin-bottom: var(--space-4, 16px); }
</style>

<script>
(function () {
  var overlay = document.getElementById('rewardModalOverlay');
  if (!overlay) return;
  requestAnimationFrame(function () { overlay.classList.add('is-open'); });
  function close() { overlay.classList.remove('is-open'); }
  document.getElementById('rewardModalClose').addEventListener('click', close);
  document.getElementById('rewardModalCta').addEventListener('click', close);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
})();
</script>
