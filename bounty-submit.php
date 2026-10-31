<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounties.php';
require_once __DIR__ . '/includes/countries.php';
requireLogin();

$pageTitle = 'Submit a Bounty';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];

$mode = $_GET['mode'] ?? '';
$categories = getBountyCategories();
$errors = []; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'external') {
    requireCsrf();
    if (!hasBountyProfile((int)$_SESSION['user_id'])) {
        $errors[] = 'Please complete your Bounty Profile first.';
    } else {
        $data = [
            'title'              => trim($_POST['title'] ?? ''),
            'external_platform'  => trim($_POST['external_platform'] ?? ''),
            'project_name'       => trim($_POST['project_name'] ?? ''),
            'external_url'       => trim($_POST['external_url'] ?? ''),
            'reward_display'     => trim($_POST['reward_display'] ?? ''),
            'deadline'           => $_POST['deadline'] ?? null,
            'description'        => trim($_POST['description'] ?? ''),
            'skills'             => trim($_POST['skills'] ?? ''),
            'category_id'        => (int)($_POST['category_id'] ?? 0),
            'country'            => trim($_POST['country'] ?? '') ?: null,
            'difficulty'         => 'Intermediate',
        ];
        if (!$data['title'] || !$data['external_url'] || !$data['external_platform']) {
            $errors[] = 'Title, hosting platform, and original URL are required.';
        } elseif (!filter_var($data['external_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Please provide a valid URL.';
        } else {
            try {
                $result = createExternalBounty((int)$_SESSION['user_id'], $data);
                if ($result['success']) {
                    // PRG: redirect so a refresh re-GETs this page instead of
                    // re-posting the form (and re-running the save).
                    header('Location: ' . SITE_URL . '/bounty-submit.php?mode=external&submitted=1');
                    exit;
                } else {
                    $errors[] = $result['error'];
                }
            } catch (\Throwable $e) {
                error_log('[Bounty] External bounty submission failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving your submission. Please try again.';
            }
        }
    }
}

if (isset($_GET['submitted'])) {
    $success = 'Thanks! Your bounty was submitted for review — you\'ll be notified once it\'s approved.';
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/bounty_profile_modal.php';
?>

<div class="container section">
  <?php $activeBountyTab = 'bounties'; require __DIR__ . '/includes/bounty_tabs.php'; ?>

  <?php if ($mode === ''): ?>
    <div class="page-header" style="padding:0 0 var(--space-6)">
      <div class="section-label">Submit a Bounty</div>
      <h1 class="page-header-title">How would you like to submit?</h1>
    </div>

    <div class="grid-2">
      <a href="<?= SITE_URL ?>/become-a-host.php" class="card card-interactive" style="text-decoration:none;display:block">
        <div style="margin-bottom:var(--space-3);color:var(--accent-bright)"><?= lucide('gavel', ['size' => 32]) ?></div>
        <h2 style="font-size:var(--text-lg)">Host a Bounty on Web3Edu</h2>
        <p style="color:var(--text-secondary);font-size:var(--text-sm)">For projects that want to host their bounty directly inside Web3Edu — submissions, judging, and winners all happen here.</p>
      </a>
      <a href="?mode=external" class="card card-interactive" style="text-decoration:none;display:block">
        <div style="margin-bottom:var(--space-3);color:var(--accent-bright)"><?= lucide('link', ['size' => 32]) ?></div>
        <h2 style="font-size:var(--text-lg)">Share an External Bounty</h2>
        <p style="color:var(--text-secondary);font-size:var(--text-sm)">Found a great bounty on Superteam Earn, Gitcoin, DoraHacks, Galxe, Layer3, or Zealy? Share it with the Web3Edu community.</p>
      </a>
    </div>

  <?php elseif ($mode === 'external'): ?>
    <div class="page-header" style="padding:0 0 var(--space-6)">
      <div class="section-label">Share an External Bounty</div>
      <h1 class="page-header-title">Bounty details</h1>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <a href="<?= SITE_URL ?>/bounty.php" class="btn btn-primary" style="margin-top:var(--space-4);display:inline-flex;align-items:center;gap:6px"><?= lucide('arrow-left', ['size' => 14]) ?> Back to Bounties</a>
    <?php else: ?>
      <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>
      <form method="POST" class="card" style="max-width:640px">
        <?= csrfField() ?>
        <div class="form-group"><label class="form-label">Bounty Title</label><input type="text" name="title" class="form-input" required></div>
        <div class="form-row-2">
          <div class="form-group">
            <label class="form-label">Hosting Platform</label>
            <select name="external_platform" class="form-select" required>
              <option value="">— choose —</option>
              <?php foreach (['Superteam Earn','Gitcoin','DoraHacks','Galxe','Layer3','Zealy','Other'] as $p): ?>
              <option value="<?= $p ?>"><?= $p ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Project Name</label><input type="text" name="project_name" class="form-input"></div>
        </div>
        <div class="form-group"><label class="form-label">Original URL</label><input type="text" name="external_url" class="form-input" placeholder="https://" required></div>
        <div class="form-row-2">
          <div class="form-group"><label class="form-label">Reward</label><input type="text" name="reward_display" class="form-input" placeholder="e.g. 5,000 USDC"></div>
          <div class="form-group"><label class="form-label">Deadline</label><input type="date" name="deadline" class="form-input"></div>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-input" rows="4" required></textarea></div>
        <div class="form-row-2">
          <div class="form-group"><label class="form-label">Skills</label><input type="text" name="skills" class="form-input" placeholder="Comma-separated, e.g. Rust, Solana"></div>
          <div class="form-group">
            <label class="form-label">Category</label>
            <select name="category_id" class="form-select">
              <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= $c['icon'] ?> <?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Country <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
          <select name="country" class="form-select"><option value="">— Global —</option><?php foreach (bountyCountryList() as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select>
        </div>
        <p style="font-size:var(--text-xs);color:var(--text-muted)">Submitted for admin review. Once approved, it's published as an External Bounty and you'll earn XP.</p>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Submit for Review</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
