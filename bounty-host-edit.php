<?php
/**
 * Host Dashboard -> "Edit Bounty" (feature 7). Lets a host edit their own
 * Hosted bounty's content — title, description, reward, deadline, etc.
 * Gated entirely through canEditBounty() from the new authorization layer;
 * admins can also reach this (they can edit anything), but their primary
 * editing surface stays admin/bounty-edit.php which additionally exposes
 * status/type/featured controls that are intentionally not host-editable.
 *
 * IMPORTANT: updateBounty() does NOT fall back to the existing value for
 * category_id / is_featured / is_premium_only when they're missing from the
 * submitted data — it treats "missing" as "clear it". So this form always
 * carries category_id forward, and preserves is_featured via a hidden
 * field (hosts don't get a UI to change that — it's an admin promotional
 * flag) while exposing is_premium_only as a real toggle.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounty_auth.php';
require_once __DIR__ . '/includes/countries.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$bountyId = (int)($_GET['id'] ?? 0);
$bounty = $bountyId ? getBounty($bountyId) : null;

if (!$bounty || !canEditBounty($userId, $bountyId)) {
    http_response_code(403);
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="container section"><div class="alert alert-danger">You don\'t have permission to edit this bounty.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$categories = getBountyCategories();
$errors = []; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $title = trim($_POST['title'] ?? '');
    if (!$title) $errors[] = 'Bounty title is required.';

    $bountyLogoPath = $bounty['project_logo'];
    if (!empty($_FILES['bounty_logo']['name'])) {
        $bountyLogoPath = handleImageUpload('bounty_logo', 'bounties', $errors) ?: $bountyLogoPath;
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
            updateBounty($bountyId, [
                'category_id'               => $_POST['category_id'] ?? $bounty['category_id'],
                'title'                     => $title,
                'project_name'              => trim($_POST['project_name'] ?? '') ?: null,
                'project_logo'              => $bountyLogoPath,
                'description'               => trim($_POST['description'] ?? ''),
                'requirements'              => trim($_POST['requirements'] ?? ''),
                'skills'                    => trim($_POST['skills'] ?? ''),
                'reward_display'            => trim($_POST['reward_display'] ?? ''),
                'reward_breakdown'          => $breakdown,
                'num_winners'               => max(1, (int)($_POST['num_winners'] ?? 1)),
                'difficulty'                => $_POST['difficulty'] ?? $bounty['difficulty'],
                'country'                   => trim($_POST['country'] ?? '') ?: null,
                'deadline'                  => $_POST['deadline'] ?: null,
                'winner_announcement_date'  => $_POST['winner_announcement_date'] ?: null,
                'is_featured'               => $bounty['is_featured'], // preserved — not host-editable
                'is_premium_only'           => !empty($_POST['is_premium_only']) ? 1 : 0,
            ]);
            header('Location: ' . SITE_URL . '/bounty-host-dashboard.php?tab=bounties&saved=1');
            exit;
        } catch (\Throwable $e) {
            error_log('[Bounty] Host edit failed for bounty #' . $bountyId . ': ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
    $bounty = array_merge($bounty, $_POST); // re-show what they typed
}

$pageTitle = 'Edit Bounty';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];
$skillsVal = is_array($bounty['skills'] ?? null) ? implode(',', $bounty['skills']) : ($bounty['skills'] ?? '');

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section">
  <?php $activeBountyTab = 'bounties'; require __DIR__ . '/includes/bounty_tabs.php'; ?>

  <div class="page-header" style="padding:0 0 var(--space-6)">
    <div class="section-label">Host Dashboard</div>
    <h1 class="page-header-title">Edit Bounty</h1>
    <a href="<?= SITE_URL ?>/bounty-host-dashboard.php?tab=bounties" style="font-size:var(--text-sm);color:var(--text-muted);display:inline-flex;align-items:center;gap:4px"><?= lucide('arrow-left', ['size' => 13]) ?> Back to My Bounties</a>
  </div>

  <?php if (!empty($errors)): ?><div class="alert alert-danger" style="max-width:640px"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>

  <form method="POST" enctype="multipart/form-data" style="max-width:640px">
    <?= csrfField() ?>
    <div class="card">
      <h2 style="font-size:var(--text-lg)">Bounty Details</h2>
      <div class="form-group"><label class="form-label">Title</label><input type="text" name="title" class="form-input" value="<?= htmlspecialchars($bounty['title']) ?>" required></div>
      <div class="form-row-2">
        <div class="form-group">
          <label class="form-label">Category</label>
          <select name="category_id" class="form-select"><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= (int)$bounty['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= $c['icon'] ?> <?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group"><label class="form-label">Project Name</label><input type="text" name="project_name" class="form-input" value="<?= htmlspecialchars($bounty['project_name'] ?? '') ?>"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Bounty Logo</label>
        <img src="<?= htmlspecialchars(imageUrl($bounty['project_logo'], 'lesson')) ?>" style="width:36px;height:36px;border-radius:var(--radius-sm);object-fit:cover;margin-bottom:6px;display:block">
        <input type="file" name="bounty_logo" class="form-input" accept="image/*">
      </div>
      <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-input" rows="4" required><?= htmlspecialchars($bounty['description']) ?></textarea></div>
      <div class="form-group"><label class="form-label">Requirements</label><textarea name="requirements" class="form-input" rows="3"><?= htmlspecialchars($bounty['requirements'] ?? '') ?></textarea></div>
      <div class="form-group"><label class="form-label">Skills</label><input type="text" name="skills" class="form-input" value="<?= htmlspecialchars($skillsVal) ?>" placeholder="Comma-separated"></div>

      <div class="form-group"><label class="form-label">Reward Summary</label><input type="text" name="reward_display" class="form-input" value="<?= htmlspecialchars($bounty['reward_display'] ?? '') ?>" required></div>
      <div class="form-group">
        <label class="form-label">Prize Breakdown</label>
        <?php
        $existingBreakdown = json_decode($bounty['reward_breakdown'] ?? '[]', true) ?: [];
        for ($i = 0; $i < 3; $i++):
            $place = $existingBreakdown[$i]['place'] ?? '';
            $amount = $existingBreakdown[$i]['amount'] ?? '';
        ?>
          <div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-2)">
            <input type="text" name="prize_place[]" class="form-input" value="<?= htmlspecialchars($place) ?>" placeholder="<?= ['1st','2nd','3rd'][$i] ?>" style="max-width:100px">
            <input type="text" name="prize_amount[]" class="form-input" value="<?= htmlspecialchars($amount) ?>" placeholder="Amount">
          </div>
        <?php endfor; ?>
      </div>

      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Number of Winners</label><input type="number" name="num_winners" class="form-input" value="<?= (int)$bounty['num_winners'] ?>" min="1"></div>
        <div class="form-group">
          <label class="form-label">Difficulty</label>
          <select name="difficulty" class="form-select">
            <?php foreach (['Beginner','Intermediate','Advanced'] as $d): ?><option <?= $bounty['difficulty'] === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Deadline</label><input type="date" name="deadline" class="form-input" value="<?= $bounty['deadline'] ? date('Y-m-d', strtotime($bounty['deadline'])) : '' ?>" required></div>
        <div class="form-group"><label class="form-label">Winner Announcement</label><input type="date" name="winner_announcement_date" class="form-input" value="<?= $bounty['winner_announcement_date'] ? date('Y-m-d', strtotime($bounty['winner_announcement_date'])) : '' ?>"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Country</label>
        <select name="country" class="form-select"><option value="">— Global —</option><?php foreach (bountyCountryList() as $c): ?><option value="<?= $c ?>" <?= $bounty['country'] === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;font-size:var(--text-sm)"><input type="checkbox" name="is_premium_only" value="1" <?= !empty($bounty['is_premium_only']) ? 'checked' : '' ?>> Premium-only bounty</label>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Save Changes</button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
