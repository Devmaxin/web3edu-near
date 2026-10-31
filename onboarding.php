<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/gamification.php';
requireLogin();

$user = getCurrentUser();
if (!empty($user['profile_completed'])) {
    header('Location: ' . SITE_URL . '/dashboard.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // trackOnce — a user can revisit /onboarding.php (back button, refresh)
    // any number of times before completing it; only the first view per
    // user should count as the funnel's "onboarding_started" step.
    Analytics::trackOnce('onboarding_started', (int)$user['id']);
}

$pageTitle = 'Complete your profile';
$extraCss  = [SITE_URL . '/assets/css/auth.css'];
$errors    = [];
$avatars   = getAvatarList();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $avatar   = trim($_POST['avatar'] ?? '');

    if (empty($username)) {
        $errors[] = 'Please choose a username.';
    } elseif (!array_key_exists($avatar, $avatars)) {
        $errors[] = 'Please select an avatar.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $user['id']]);
        if ($stmt->fetch()) {
            $errors[] = 'That username is already taken.';
        } else {
           $db->prepare("UPDATE users SET username=?, avatar=?, profile_completed=1 WHERE id=?")
   ->execute([$username, $avatar, $user['id']]);

// Refresh the session with the updated user so isProfileComplete() picks up
// profile_completed=1 immediately — otherwise dashboard.php bounces back to
// onboarding.php (which sees the DB is already complete and bounces to
// dashboard.php), causing an infinite redirect loop.
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
setUserSession($stmt->fetch());

Analytics::track('onboarding_completed', (int)$user['id']);

// Server-side reward: onboarding is only ever completed once per user (the
// profile_completed=1 guard at the top of this file prevents re-entry), and
// awardOnboardingReward() itself is idempotent on top of that. The reward
// screen on dashboard.php reads this flash exactly once.
$reward = awardOnboardingReward((int)$user['id']);
if ($reward['xp_awarded'] > 0 || $reward['badge']) {
    setOnboardingRewardFlash([
        'xp'       => $reward['xp_awarded'],
        'total_xp' => $reward['total_xp'],
        'badge'    => $reward['badge'] ? [
            'name'        => $reward['badge']['name'],
            'description' => $reward['badge']['description'],
            'icon'        => $reward['badge']['icon'],
        ] : null,
    ]);
}

setFlash('success', 'Welcome to Web3Edu, ' . htmlspecialchars($username) . '!');
header('Location: ' . SITE_URL . '/dashboard.php');
exit;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-page">
  <div class="auth-card" style="max-width:480px">
    <h1 class="auth-title">One last step</h1>
    <p class="auth-subtitle">Pick a username and an avatar to finish setting up your account.</p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger" style="margin-bottom:var(--space-4)">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" class="input" placeholder="yourname" required value="<?= htmlspecialchars($user['username'] ?? '') ?>">
      </div>

      <div class="field">
        <label>Choose an avatar</label>
        <div class="avatar-grid">
          <?php $firstAvatar = true; foreach ($avatars as $file => $label): ?>
            <div class="avatar-option">
              <input type="radio" name="avatar" id="av_<?= htmlspecialchars($file) ?>" value="<?= htmlspecialchars($file) ?>" <?= $firstAvatar ? 'checked' : '' ?>>
              <label for="av_<?= htmlspecialchars($file) ?>" class="avatar-label">
                <img src="<?= htmlspecialchars(avatarUrl($file)) ?>" alt="<?= htmlspecialchars($label) ?>" class="avatar-img" loading="lazy">
              </label>
            </div>
          <?php $firstAvatar = false; endforeach; ?>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:var(--space-4)">Finish setup →</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
