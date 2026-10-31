<?php
require_once __DIR__ . '/lucide.php';
/**
 * Shared tab strip between the Airdrops and Bounties sections.
 * Include with $activeBountyTab set to 'airdrops' or 'bounties'.
 */
$activeBountyTab = $activeBountyTab ?? 'airdrops';
?>
<div class="section-tabs" data-reveal>
  <a href="<?= SITE_URL ?>/airdrops.php" class="section-tab <?= $activeBountyTab === 'airdrops' ? 'active' : '' ?>">
    <?= lucide('package', ['size' => 14]) ?> Airdrops
  </a>
  <a href="<?= SITE_URL ?>/bounty.php" class="section-tab <?= $activeBountyTab === 'bounties' ? 'active' : '' ?>">
    <?= lucide('briefcase', ['size' => 14]) ?> Bounties
  </a>
</div>
