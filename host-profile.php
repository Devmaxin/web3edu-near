<?php
/**
 * My Host Profile — status page for the Host Profile approval workflow
 * (Phase 1). Distinct from bounty-profile.php ("My Bounty Profile", the
 * participant identity used across submissions/winnings) — this page is
 * about the organization applying to host bounties.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounty_auth.php';
require_once __DIR__ . '/includes/countries.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$host = getBountyHost($userId);

if (!$host) {
    header('Location: ' . SITE_URL . '/become-a-host.php');
    exit;
}

$pageTitle = 'My Host Profile';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];
$organizationTypes = ['DAO', 'Startup', 'Protocol', 'Foundation', 'Community / DAO Guild', 'Educational Platform', 'Individual / Creator', 'Other'];
$errors = []; $success = '';

// Approved hosts can keep their org details up to date here without
// re-triggering the approval workflow. Suspended hosts are read-only until
// an admin reinstates them. Pending applications have nothing to fix yet.
$canEdit = in_array($host['status'], ['request_changes', 'rejected', 'approved'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canManageHostProfile($userId, (int)$host['id']) && $canEdit) {
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
        $data = [
            'organization_name' => $orgName, 'logo_path' => $logoPath, 'website' => trim($_POST['website'] ?? ''),
            'contact_email' => $contactEmail, 'description' => trim($_POST['description'] ?? ''), 'country' => $country,
            'organization_type' => trim($_POST['organization_type'] ?? ''), 'twitter' => trim($_POST['twitter'] ?? ''),
            'discord' => trim($_POST['discord'] ?? ''), 'telegram' => trim($_POST['telegram'] ?? ''),
        ];
        try {
            $ok = match ($host['status']) {
                'rejected' => reapplyHostApplication((int)$host['id'], $data),
                'approved' => updateApprovedHostProfile((int)$host['id'], $data),
                default    => resubmitHostApplication((int)$host['id'], $data),
            };
            if ($ok) {
                $redirectFlag = $host['status'] === 'approved' ? 'saved=1' : 'resubmitted=1';
                header('Location: ' . SITE_URL . '/host-profile.php?' . $redirectFlag);
                exit;
            }
            $errors[] = 'Something went wrong while saving your Host Profile. Please try again.';
        } catch (\Throwable $e) {
            error_log('[BountyHost] Host profile update failed for host #' . $host['id'] . ': ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving your Host Profile. Please try again.';
        }
    }
    $host = array_merge($host, $_POST); // keep what was typed if we're re-showing the form
}

if (isset($_GET['submitted'])) $success = 'Your Host Profile is awaiting review.';
if (isset($_GET['resubmitted'])) $success = 'Your Host Profile has been resubmitted for review.';
if (isset($_GET['saved'])) $success = 'Your Host Profile has been updated.';

$statusCopy = [
    'pending_approval' => ['icon' => 'clock', 'title' => 'Your Host Profile is awaiting review.', 'body' => "We'll email you as soon as it's reviewed."],
    'request_changes'  => ['icon' => 'alert-triangle', 'title' => 'Changes requested', 'body' => 'Please update your Host Profile below and resubmit.'],
    'rejected'         => ['icon' => 'x-circle', 'title' => 'Your Host Profile application was not approved.', 'body' => "You're welcome to submit a new application below."],
    'suspended'        => ['icon' => 'ban', 'title' => 'Your Host Profile is suspended.', 'body' => 'Hosting is disabled while your profile is suspended.'],
    'approved'         => ['icon' => 'shield-check', 'title' => 'Your Host Profile is approved.', 'body' => 'You can update your organization details below any time.'],
];
$copy = $statusCopy[$host['status']] ?? ['icon' => 'info', 'title' => hostStatusLabel($host['status']), 'body' => ''];
$v = fn($field) => htmlspecialchars((string)($host[$field] ?? ''));

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section">
  <?php $activeBountyTab = 'bounties'; require __DIR__ . '/includes/bounty_tabs.php'; ?>

  <div class="page-header" style="padding:0 0 var(--space-5)">
    <div class="section-label">Host Profile</div>
    <h1 class="page-header-title"><?= htmlspecialchars($host['organization_name']) ?></h1>
  </div>

  <?php if ($success): ?><div class="alert alert-success" style="max-width:640px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?><div class="alert alert-danger" style="max-width:640px"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>

  <div class="card" style="max-width:640px;margin-bottom:var(--space-5)">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap">
      <span class="status-chip status-<?= $host['status'] ?>"><?= htmlspecialchars(hostStatusLabel($host['status'])) ?></span>
      <?php if ($host['status'] === 'approved'): ?>
        <span class="status-chip <?= $host['is_verified'] ? 'status-approved' : 'status-pending_approval' ?>">
          <?= $host['is_verified'] ? '✔ Verified Host' : '🟢 Community Host' ?>
        </span>
        <span class="status-chip status-pending" title="Set by Web3Edu admins on your Host application">
          Fee Policy: <?= $host['fee_policy'] === 'exempt' ? 'Fee Exempt' : 'Standard' ?>
        </span>
      <?php endif; ?>
    </div>
    <h2 style="font-size:var(--text-lg);margin-bottom:4px"><?= htmlspecialchars($copy['title']) ?></h2>
    <p style="color:var(--text-secondary);font-size:var(--text-sm)"><?= htmlspecialchars($copy['body']) ?></p>
    <?php if (!empty($host['admin_message']) && in_array($host['status'], ['request_changes', 'rejected', 'suspended'], true)): ?>
      <div style="margin-top:var(--space-3);padding:var(--space-3);border-radius:var(--radius-sm);background:var(--surface-raised)">
        <strong style="font-size:var(--text-xs);color:var(--text-muted);text-transform:uppercase">Message from the Web3Edu team</strong>
        <p style="margin-top:4px;font-size:var(--text-sm)"><?= nl2br(htmlspecialchars($host['admin_message'])) ?></p>
      </div>
    <?php endif; ?>
    <?php if ($host['status'] === 'approved'): ?>
      <a href="<?= SITE_URL ?>/bounty-host-dashboard.php" class="btn btn-secondary btn-sm" style="margin-top:var(--space-3);display:inline-flex;align-items:center;gap:6px"><?= lucide('layout-dashboard', ['size' => 13]) ?> Go to Host Dashboard</a>
    <?php endif; ?>
  </div>

  <?php if ($canEdit): ?>
  <form method="POST" enctype="multipart/form-data" class="card" style="max-width:640px">
    <h2 style="font-size:var(--text-lg)"><?= $host['status'] === 'rejected' ? 'Submit a new application' : 'Organization details' ?></h2>
    <div class="form-group"><label class="form-label">Organization Name</label><input type="text" name="organization_name" class="form-input" value="<?= $v('organization_name') ?>" required></div>
    <div class="form-group"><label class="form-label">Logo <span style="color:var(--text-muted);font-weight:400">(leave blank to keep current)</span></label><input type="file" name="logo" class="form-input" accept="image/*"></div>
    <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-input" rows="3"><?= $v('description') ?></textarea></div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Website</label><input type="text" name="website" class="form-input" value="<?= $v('website') ?>"></div>
      <div class="form-group"><label class="form-label">Public Contact Email</label><input type="email" name="contact_email" class="form-input" value="<?= $v('contact_email') ?>" required></div>
    </div>
    <div class="form-row-2">
      <div class="form-group">
        <label class="form-label">Country</label>
        <select name="country" class="form-select" required>
          <option value="">— select —</option>
          <?php foreach (bountyCountryList() as $c): ?><option value="<?= htmlspecialchars($c) ?>" <?= ($host['country'] ?? '') === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Organization Type</label>
        <select name="organization_type" class="form-select">
          <?php foreach ($organizationTypes as $t): ?><option value="<?= htmlspecialchars($t) ?>" <?= ($host['organization_type'] ?? '') === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Twitter / X</label><input type="text" name="twitter" class="form-input" value="<?= $v('twitter') ?>"></div>
      <div class="form-group"><label class="form-label">Discord</label><input type="text" name="discord" class="form-input" value="<?= $v('discord') ?>"></div>
      <div class="form-group"><label class="form-label">Telegram</label><input type="text" name="telegram" class="form-input" value="<?= $v('telegram') ?>"></div>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:var(--space-3)">
      <?= match ($host['status']) { 'rejected' => 'Submit New Application', 'approved' => 'Save Changes', default => 'Resubmit for Review' } ?>
    </button>
  </form>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
