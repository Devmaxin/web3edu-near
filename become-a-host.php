<?php
/**
 * Become a Host — Phase 1 of the bounty host-approval overhaul.
 *
 * This page creates ONLY a Host Profile (organization details). It never
 * creates a bounty — that now only happens from bounty-host.php, and only
 * once the Host Profile is approved (see includes/bounty_auth.php's
 * canCreateHostedBounty()).
 *
 * Smart routing: if the user already has a Host Profile, there's nothing to
 * "become" — send them to the page that matches their current status
 * (host-profile.php for a status view/edit, or straight into bounty
 * creation if they're already approved).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounty_auth.php';
require_once __DIR__ . '/includes/countries.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$existingHost = getBountyHost($userId);

if ($existingHost) {
    if ($existingHost['status'] === 'approved') {
        header('Location: ' . SITE_URL . '/bounty-host.php');
    } else {
        header('Location: ' . SITE_URL . '/host-profile.php');
    }
    exit;
}

$pageTitle = 'Become a Host';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];

$organizationTypes = ['DAO', 'Startup', 'Protocol', 'Foundation', 'Community / DAO Guild', 'Educational Platform', 'Individual / Creator', 'Other'];
$errors = []; $success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (!hasBountyProfile($userId)) {
        $errors[] = 'Please complete your Bounty Profile first.';
    }

    $orgName = trim($_POST['organization_name'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $country = trim($_POST['country'] ?? '');
    if (!$orgName) $errors[] = 'Organization name is required.';
    if (!$contactEmail || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid public contact email is required.';
    if (!$country) $errors[] = 'Country is required.';

    $logoPath = null;
    if (empty($errors) && !empty($_FILES['logo']['name'])) {
        $logoPath = handleImageUpload('logo', 'bounties', $errors);
    }

    if (empty($errors)) {
        try {
            createHostApplication($userId, [
                'organization_name' => $orgName,
                'logo_path'         => $logoPath,
                'website'           => trim($_POST['website'] ?? ''),
                'contact_email'     => $contactEmail,
                'description'       => trim($_POST['description'] ?? ''),
                'country'           => $country,
                'organization_type' => trim($_POST['organization_type'] ?? ''),
                'twitter'           => trim($_POST['twitter'] ?? ''),
                'discord'           => trim($_POST['discord'] ?? ''),
                'telegram'          => trim($_POST['telegram'] ?? ''),
            ]);
            header('Location: ' . SITE_URL . '/host-profile.php?submitted=1');
            exit;
        } catch (\Throwable $e) {
            error_log('[BountyHost] Host application creation failed for user #' . $userId . ': ' . $e->getMessage());
            $errors[] = 'Something went wrong while submitting your Host Profile. Please try again.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/bounty_profile_modal.php';
?>

<div class="container section">
  <?php $activeBountyTab = 'bounties'; require __DIR__ . '/includes/bounty_tabs.php'; ?>

  <div class="page-header" style="padding:0 0 var(--space-6)">
    <div class="section-label">Become a Host</div>
    <h1 class="page-header-title">Set up your Host Profile</h1>
    <p class="page-header-subtitle">Tell us about your organization. Once approved, you'll be able to create Hosted Bounties on Web3Edu.</p>
  </div>

  <?php if (!empty($errors)): ?><div class="alert alert-danger" style="max-width:640px"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="card" style="max-width:640px">
    <?= csrfField() ?>
    <div class="form-group"><label class="form-label">Organization Name</label><input type="text" name="organization_name" class="form-input" value="<?= htmlspecialchars($_POST['organization_name'] ?? '') ?>" required></div>
    <div class="form-group"><label class="form-label">Logo</label><input type="file" name="logo" class="form-input" accept="image/*"></div>
    <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-input" rows="3"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea></div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Website</label><input type="text" name="website" class="form-input" placeholder="https://" value="<?= htmlspecialchars($_POST['website'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Public Contact Email</label><input type="email" name="contact_email" class="form-input" value="<?= htmlspecialchars($_POST['contact_email'] ?? '') ?>" required></div>
    </div>
    <div class="form-row-2">
      <div class="form-group">
        <label class="form-label">Country</label>
        <select name="country" class="form-select" required>
          <option value="">— select —</option>
          <?php foreach (bountyCountryList() as $c): ?><option value="<?= htmlspecialchars($c) ?>" <?= ($_POST['country'] ?? '') === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Organization Type</label>
        <select name="organization_type" class="form-select">
          <?php foreach ($organizationTypes as $t): ?><option value="<?= htmlspecialchars($t) ?>" <?= ($_POST['organization_type'] ?? '') === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <h2 style="font-size:var(--text-lg);margin-top:var(--space-5)">Social Links <span style="color:var(--text-muted);font-weight:400;font-size:var(--text-sm)">(optional)</span></h2>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Twitter / X</label><input type="text" name="twitter" class="form-input" placeholder="@yourorg" value="<?= htmlspecialchars($_POST['twitter'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Discord</label><input type="text" name="discord" class="form-input" value="<?= htmlspecialchars($_POST['discord'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Telegram</label><input type="text" name="telegram" class="form-input" value="<?= htmlspecialchars($_POST['telegram'] ?? '') ?>"></div>
    </div>

    <p style="font-size:var(--text-xs);color:var(--text-muted);margin-top:var(--space-3)">Your Host Profile goes to Web3Edu for review. This does not create a bounty — once approved, you'll be able to create Hosted Bounties from your Host Dashboard.</p>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:var(--space-3)">Submit for Review</button>
  </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
