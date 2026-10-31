<?php
/**
 * My Bounty Profile — view + edit anytime (feature 1). Previously users
 * could only complete this once via the onboarding modal (bounty_profile_modal.php)
 * with no way to come back and change it. This page reuses the exact same
 * backend (saveBountyProfile()) and field set — the modal is still what
 * blocks first-time completion, this page is where you manage it afterwards.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounty_auth.php';
require_once __DIR__ . '/includes/countries.php';
requireLogin();

$userId  = (int)$_SESSION['user_id'];
$profile = getBountyProfile($userId);
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEditBountyProfile($userId, $userId)) {
    requireCsrf();
    if (saveBountyProfile($userId, $_POST)) {
        $success = 'Your Bounty Profile has been saved.';
        $profile = getBountyProfile($userId);
    } else {
        $errors[] = 'Display Name, Country, and Twitter/X are required.';
        // Keep whatever was just typed so the form doesn't lose input on error.
        $profile = array_merge($profile ?? [], $_POST);
    }
}

$pageTitle = 'My Bounty Profile';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];

$v = fn($field) => htmlspecialchars((string)($profile[$field] ?? ''));
$currentCountry = $profile['country'] ?? '';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section">
  <?php $activeBountyTab = 'bounties'; require __DIR__ . '/includes/bounty_tabs.php'; ?>

  <div class="page-header" style="padding:0 0 var(--space-6)">
    <div class="section-label">Bounty Profile</div>
    <h1 class="page-header-title">My Bounty Profile</h1>
    <p class="page-header-subtitle">This is separate from your regular Web3Edu profile — it's what hosts and admins see on your submissions and winnings, and where your payout wallets/bank details live.</p>
  </div>

  <?php if (!empty($errors)): ?><div class="alert alert-danger" style="max-width:640px"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success" style="max-width:640px"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <form method="POST" style="max-width:640px" id="bountyProfileForm">
    <?= csrfField() ?>

    <div class="card" style="margin-bottom:var(--space-5)">
      <h2 style="font-size:var(--text-lg)">Basic Information</h2>
      <div class="form-group"><label class="form-label">Display Name</label><input type="text" name="display_name" class="form-input" maxlength="100" value="<?= $v('display_name') ?>" required></div>
      <div class="form-group"><label class="form-label">Bio <span style="color:var(--text-muted);font-weight:400">(optional)</span></label><textarea name="bio" class="form-input" rows="3" maxlength="500"><?= $v('bio') ?></textarea></div>
      <div class="form-group">
        <label class="form-label">Country</label>
        <select name="country" id="bpCountry" class="form-select" required>
          <option value="">— select —</option>
          <?php foreach (bountyCountryList() as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $currentCountry === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="card" style="margin-bottom:var(--space-5)">
      <h2 style="font-size:var(--text-lg)">Social Links</h2>
      <div class="form-group"><label class="form-label">Twitter / X</label><input type="text" name="twitter" class="form-input" placeholder="@yourhandle" value="<?= $v('twitter') ?>" required></div>
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Discord <span style="color:var(--text-muted);font-weight:400">(optional)</span></label><input type="text" name="discord" class="form-input" value="<?= $v('discord') ?>"></div>
        <div class="form-group"><label class="form-label">Telegram <span style="color:var(--text-muted);font-weight:400">(optional)</span></label><input type="text" name="telegram" class="form-input" value="<?= $v('telegram') ?>"></div>
        <div class="form-group"><label class="form-label">GitHub <span style="color:var(--text-muted);font-weight:400">(optional)</span></label><input type="text" name="github" class="form-input" value="<?= $v('github') ?>"></div>
        <div class="form-group"><label class="form-label">Portfolio Website <span style="color:var(--text-muted);font-weight:400">(optional)</span></label><input type="text" name="portfolio_url" class="form-input" placeholder="https://" value="<?= $v('portfolio_url') ?>"></div>
      </div>
    </div>

    <div class="card" style="margin-bottom:var(--space-5)">
      <h2 style="font-size:var(--text-lg)">Wallets</h2>
      <p style="color:var(--text-secondary);font-size:var(--text-sm)">Only needed if you win a crypto bounty — all optional.</p>
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Solana Wallet</label><input type="text" name="solana_wallet" class="form-input" value="<?= $v('solana_wallet') ?>"></div>
        <div class="form-group"><label class="form-label">Ethereum Wallet</label><input type="text" name="ethereum_wallet" class="form-input" value="<?= $v('ethereum_wallet') ?>"></div>
        <div class="form-group"><label class="form-label">TON Wallet</label><input type="text" name="ton_wallet" class="form-input" value="<?= $v('ton_wallet') ?>"></div>
      </div>
    </div>

    <div class="card" id="bpNigeriaCard" style="margin-bottom:var(--space-5);<?= $currentCountry === 'Nigeria' ? '' : 'display:none' ?>">
      <h2 style="font-size:var(--text-lg)">Bank <span style="color:var(--text-muted);font-weight:400;font-size:var(--text-sm)">(Nigerian users only — for Naira payouts)</span></h2>
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-input" value="<?= $v('bank_name') ?>"></div>
        <div class="form-group"><label class="form-label">Account Number</label><input type="text" name="account_number" class="form-input" value="<?= $v('account_number') ?>"></div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center"><?= $profile ? 'Save Changes' : 'Create Bounty Profile' ?></button>
  </form>
</div>

<script>
(function () {
  var countryEl = document.getElementById('bpCountry');
  var nigeriaCard = document.getElementById('bpNigeriaCard');
  if (!countryEl || !nigeriaCard) return;
  countryEl.addEventListener('change', function () {
    nigeriaCard.style.display = (countryEl.value === 'Nigeria') ? 'block' : 'none';
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
