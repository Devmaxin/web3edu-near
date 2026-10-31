<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounty_auth.php';
require_once __DIR__ . '/includes/countries.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];

// Creating a Hosted Bounty now requires an approved Host Profile — Host
// Profile creation/approval happens on become-a-host.php / host-profile.php.
if (!canCreateHostedBounty($userId)) {
    $existingHost = getBountyHost($userId);
    header('Location: ' . SITE_URL . '/' . (!$existingHost ? 'become-a-host.php' : 'host-profile.php'));
    exit;
}

$pageTitle = 'Host a Bounty';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];

$existingHost = getBountyHost($userId);
$categories = getBountyCategories();
$errors = []; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (!hasBountyProfile($userId)) {
        $errors[] = 'Please complete your Bounty Profile first.';
    }

    // Bounty details
    if (empty($errors)) {
        $title = trim($_POST['title'] ?? '');
        if (!$title) $errors[] = 'Bounty title is required.';

        $bountyLogoPath = null;
        if (!empty($_FILES['bounty_logo']['name'])) {
            $bountyLogoPath = handleImageUpload('bounty_logo', 'bounties', $errors);
        }

        $breakdown = [];
        $places = $_POST['prize_place'] ?? [];
        $amounts = $_POST['prize_amount'] ?? [];
        foreach ($places as $i => $place) {
            if (trim($place) !== '' && isset($amounts[$i]) && trim($amounts[$i]) !== '') {
                $breakdown[] = ['place' => trim($place), 'amount' => trim($amounts[$i])];
            }
        }

        if (empty($errors)) {
            try {
                $bountyId = createHostedBounty($userId, (int)$existingHost['id'], [
                    'title'                     => $title,
                    'category_id'               => (int)($_POST['category_id'] ?? 0),
                    'project_name'              => trim($_POST['project_name'] ?? '') ?: $existingHost['organization_name'],
                    'project_logo'              => $bountyLogoPath ?: $existingHost['logo_path'],
                    'description'               => trim($_POST['description'] ?? ''),
                    'requirements'              => trim($_POST['requirements'] ?? ''),
                    'skills'                    => trim($_POST['skills'] ?? ''),
                    'reward_display'            => trim($_POST['reward_display'] ?? ''),
                    'reward_breakdown'          => $breakdown,
                    'num_winners'               => max(1, (int)($_POST['num_winners'] ?? 1)),
                    'difficulty'                => in_array($_POST['difficulty'] ?? '', ['Beginner','Intermediate','Advanced']) ? $_POST['difficulty'] : 'Intermediate',
                    'country'                   => trim($_POST['country'] ?? '') ?: null,
                    'deadline'                  => $_POST['deadline'] ?: null,
                    'winner_announcement_date'  => $_POST['winner_announcement_date'] ?: null,
                ]);
                // PRG: redirect on success is the only way out of this branch.
                // Post-save notifications inside createHostedBounty are already
                // fault-tolerant, so reaching this line means the bounty is safely saved.
                header('Location: ' . SITE_URL . '/bounty-host-dashboard.php?created=1');
                exit;
            } catch (\Throwable $e) {
                // The save itself genuinely failed (DB down, constraint violation, etc.) —
                // nothing was committed, so re-show the form with an error instead of a 500.
                error_log('[Bounty] Hosted bounty submission failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving your bounty. Please try again — if this keeps happening, contact support.';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/bounty_profile_modal.php';
?>

<div class="container section">
  <?php $activeBountyTab = 'bounties'; require __DIR__ . '/includes/bounty_tabs.php'; ?>

  <div class="page-header" style="padding:0 0 var(--space-6)">
    <div class="section-label">Host a Bounty</div>
    <h1 class="page-header-title">Create a new bounty</h1>
    <p class="page-header-subtitle">Web3Edu handles submissions, judging tools, winner announcements, and certificates for you.</p>
  </div>

  <?php if (!empty($errors)): ?><div class="alert alert-danger" style="max-width:640px"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>

  <form method="POST" enctype="multipart/form-data" style="max-width:640px">
    <?= csrfField() ?>

    <div class="card" style="margin-bottom:var(--space-5);display:flex;align-items:center;gap:var(--space-3)">
      <img src="<?= htmlspecialchars(imageUrl($existingHost['logo_path'], 'lesson')) ?>" style="width:44px;height:44px;border-radius:var(--radius-sm);object-fit:cover">
      <div><strong><?= htmlspecialchars($existingHost['organization_name']) ?></strong><div style="font-size:var(--text-xs);color:var(--text-muted)">Hosting as this organization</div></div>
    </div>

    <div class="card">
      <h2 style="font-size:var(--text-lg)">Bounty Details</h2>
      <div class="form-group"><label class="form-label">Title</label><input type="text" name="title" class="form-input" required></div>
      <div class="form-row-2">
        <div class="form-group">
          <label class="form-label">Category</label>
          <select name="category_id" class="form-select"><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= $c['icon'] ?> <?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group"><label class="form-label">Project Name <span style="color:var(--text-muted);font-weight:400">(optional)</span></label><input type="text" name="project_name" class="form-input"></div>
      </div>
      <div class="form-group"><label class="form-label">Bounty Logo <span style="color:var(--text-muted);font-weight:400">(optional — defaults to org logo)</span></label><input type="file" name="bounty_logo" class="form-input" accept="image/*"></div>
      <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-input" rows="4" required></textarea></div>
      <div class="form-group"><label class="form-label">Requirements</label><textarea name="requirements" class="form-input" rows="3"></textarea></div>
      <div class="form-group"><label class="form-label">Skills</label><input type="text" name="skills" class="form-input" placeholder="Comma-separated"></div>

      <div class="form-group">
        <label class="form-label">Reward Summary</label>
        <input type="text" name="reward_display" class="form-input" placeholder="e.g. 5,000 USDC Total Prizes" required>
      </div>
      <div class="form-group">
        <label class="form-label">Prize Breakdown <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
        <?php for ($i = 0; $i < 3; $i++): ?>
          <div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-2)">
            <input type="text" name="prize_place[]" class="form-input" placeholder="<?= ['1st','2nd','3rd'][$i] ?>" style="max-width:100px">
            <input type="text" name="prize_amount[]" class="form-input" placeholder="Amount">
          </div>
        <?php endfor; ?>
      </div>

      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Number of Winners</label><input type="number" name="num_winners" class="form-input" value="1" min="1"></div>
        <div class="form-group">
          <label class="form-label">Difficulty</label>
          <select name="difficulty" class="form-select"><option>Beginner</option><option selected>Intermediate</option><option>Advanced</option></select>
        </div>
      </div>
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Deadline</label><input type="date" name="deadline" class="form-input" required></div>
        <div class="form-group"><label class="form-label">Winner Announcement</label><input type="date" name="winner_announcement_date" class="form-input"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Country <span style="color:var(--text-muted);font-weight:400">(optional — leave blank for Global)</span></label>
        <select name="country" class="form-select"><option value="">— Global —</option><?php foreach (bountyCountryList() as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select>
      </div>

      <p style="font-size:var(--text-xs);color:var(--text-muted)">Your bounty goes to Web3Edu for review. Once approved, you'll be asked to pay the listing fee before it goes live.</p>
      <button type="submit" class="btn btn-primary" id="bountySubmitBtn" style="width:100%;justify-content:center">Submit for Review</button>
    </div>
  </form>
</div>

<script>
// Belt-and-suspenders against double submission (double-click, slow network).
// The PRG redirect on success already prevents duplicates on refresh.
(function () {
  var form = document.getElementById('bountySubmitBtn')?.closest('form');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    var btn = document.getElementById('bountySubmitBtn');
    if (btn.dataset.submitted === '1') { e.preventDefault(); return; }
    btn.dataset.submitted = '1';
    btn.disabled = true;
    btn.textContent = 'Submitting…';
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
