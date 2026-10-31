<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/gamification.php';
requireLogin();
requireProfileComplete();

$db      = getDB();
$user    = getCurrentUser();
$tab     = in_array($_GET['tab'] ?? '', ['profile', 'settings', 'security'], true) ? $_GET['tab'] : 'profile';
$errors  = [];
$success = null;
$avatars = getAvatarList();
$notifPrefs = getUserNotificationPreferences((int)$user['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $avatar   = trim($_POST['avatar'] ?? $user['avatar']);

        if (empty($username)) {
            $errors[] = 'Username cannot be empty.';
        } else {
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $user['id']]);
            if ($stmt->fetch()) {
                $errors[] = 'That username is already taken.';
            } else {
                $db->prepare("UPDATE users SET username=?, avatar=? WHERE id=?")->execute([$username, $avatar, $user['id']]);
                $_SESSION['username'] = $username;
                $user = getCurrentUser();
                $success = 'Profile updated successfully.';
            }
        }
        $tab = 'profile';
    }

    if ($action === 'update_notification_preferences') {
        saveUserNotificationPreferences(
            (int)$user['id'],
            isset($_POST['inactivity_reminders']),
            isset($_POST['achievement_notifications'])
        );
        $success = 'Notification preferences updated.';
        $tab = 'settings';
    }

    if ($action === 'change_password') {
        $current = trim($_POST['current_password'] ?? '');
        $new     = trim($_POST['new_password'] ?? '');
        $confirm = trim($_POST['confirm_password'] ?? '');

        if (empty($user['password'])) {
            $errors[] = 'Your account uses Google Sign-In and has no password to change.';
        } elseif (!password_verify($current, $user['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            deliverEmailTemplate('password_changed', $user['email'], ['username' => $user['username']], immediate: true);
            $success = 'Password changed successfully.';
        }
        $tab = 'security';
    }
}

$pageTitle = 'Profile';
$extraCss  = [SITE_URL . '/assets/css/auth.css'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="container profile-hero">
  <div class="card profile-card">
    <div class="profile-avatar-wrap">
      <img src="<?= htmlspecialchars(avatarUrl($user['avatar'] ?? '')) ?>" alt="" class="profile-avatar-img">
      <?php if (isPremium()): ?><span class="profile-avatar-badge" title="Premium member">★</span><?php endif; ?>
    </div>
    <div>
      <h1 style="margin-bottom:var(--space-1)">Welcome, <?= htmlspecialchars($user['username']) ?></h1>
      <span class="badge <?= isPremium() ? 'badge-accent' : '' ?>"><?= isPremium() ? 'Premium member' : 'Free member' ?></span>
      <div style="font-size:var(--text-xs);color:var(--text-muted);margin-top:var(--space-2)">Member since <?= date('F Y', strtotime($user['created_at'])) ?></div>
    </div>
    <?php if (!isPremium()): ?>
      <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary" style="margin-left:auto">Upgrade</a>
    <?php endif; ?>
  </div>
</div>

<div class="container" style="padding-bottom:var(--space-8)">
  <div class="profile-tabs">
    <a href="?tab=profile" class="<?= $tab === 'profile' ? 'active' : '' ?>">Profile</a>
    <a href="?tab=settings" class="<?= $tab === 'settings' ? 'active' : '' ?>">Settings</a>
    <a href="?tab=security" class="<?= $tab === 'security' ? 'active' : '' ?>">Security</a>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger" style="margin-bottom:var(--space-5)"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-5)"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <?php if ($tab === 'profile'): ?>
    <div class="card" style="max-width:600px;margin:0 auto">
      <h2 style="font-size:var(--text-lg);margin-bottom:var(--space-4)">Edit profile</h2>
      <form method="POST">
        <input type="hidden" name="action" value="update_profile">
        <div class="field">
          <label>Username</label>
          <input type="text" name="username" class="input" value="<?= htmlspecialchars($user['username']) ?>" required>
        </div>
        <div class="field">
          <label>Email</label>
          <input type="email" class="input" value="<?= htmlspecialchars($user['email']) ?>" disabled>
          <div class="field-hint">Email address cannot be changed.</div>
        </div>
        <div class="field">
          <label>Choose an avatar</label>
          <div class="avatar-grid">
            <?php foreach ($avatars as $file => $label): ?>
              <div class="avatar-option">
                <input type="radio" name="avatar" id="pav_<?= htmlspecialchars($file) ?>" value="<?= htmlspecialchars($file) ?>" <?= $user['avatar'] === $file ? 'checked' : '' ?>>
                <label for="pav_<?= htmlspecialchars($file) ?>" class="avatar-label">
                  <img src="<?= htmlspecialchars(avatarUrl($file)) ?>" alt="<?= htmlspecialchars($label) ?>" class="avatar-img" loading="lazy">
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="submit" class="btn btn-primary">Save changes</button>
      </form>
    </div>

  <?php elseif ($tab === 'settings'): ?>
    <div class="card" style="max-width:600px;margin:0 auto">
      <h2 style="font-size:var(--text-lg);margin-bottom:var(--space-4)">Account settings</h2>
      <div class="settings-row">
        <div>
          <div style="font-weight:600">Membership type</div>
          <div class="field-hint" style="margin:0">Your current plan</div>
        </div>
        <span class="badge <?= isPremium() ? 'badge-accent' : '' ?>"><?= isPremium() ? 'Premium' : 'Free' ?></span>
      </div>
      <div class="settings-row">
        <div>
          <div style="font-weight:600">Member since</div>
          <div class="field-hint" style="margin:0">Account creation date</div>
        </div>
        <span class="mono" style="color:var(--text-secondary)"><?= date('F j, Y', strtotime($user['created_at'])) ?></span>
      </div>
      <div class="settings-row">
        <div>
          <div style="font-weight:600">Sign-in method</div>
          <div class="field-hint" style="margin:0"><?= $user['login_provider'] === 'google' ? 'Google Sign-In' : 'Email & password' ?></div>
        </div>
      </div>
      <?php if (!isPremium()): ?>
      <div class="settings-row">
        <div>
          <div style="font-weight:600">Upgrade to Premium</div>
          <div class="field-hint" style="margin:0">Monthly or yearly plans — cancel anytime</div>
        </div>
        <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary btn-sm">Upgrade — <?= htmlspecialchars(getPremiumPriceDisplay()) ?></a>
      </div>
      <?php endif; ?>
    </div>

    <div class="card" style="max-width:600px;margin:var(--space-5) auto 0">
      <h2 style="font-size:var(--text-lg);margin-bottom:var(--space-2)">Notification preferences</h2>
      <p class="field-hint" style="margin:0 0 var(--space-4)">Control which reminder emails you receive. Essential account emails (password resets, receipts) are unaffected.</p>
      <form method="POST">
        <input type="hidden" name="action" value="update_notification_preferences">
        <div class="settings-row">
          <div>
            <div style="font-weight:600">Inactivity reminders</div>
            <div class="field-hint" style="margin:0">Occasional nudges if you've been away for a while, including comeback bonuses.</div>
          </div>
          <input type="checkbox" name="inactivity_reminders" <?= !empty($notifPrefs['inactivity_reminders']) ? 'checked' : '' ?>>
        </div>
        <div class="settings-row">
          <div>
            <div style="font-weight:600">Achievement notifications</div>
            <div class="field-hint" style="margin:0">In-app alerts when you earn a badge, milestone, or level up.</div>
          </div>
          <input type="checkbox" name="achievement_notifications" <?= !empty($notifPrefs['achievement_notifications']) ? 'checked' : '' ?>>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:var(--space-3)">Save preferences</button>
      </form>
    </div>

    <div class="card" style="max-width:600px;margin:var(--space-5) auto 0">
      <div class="settings-row" style="border:1px solid var(--danger);background:var(--danger-soft)">
        <div>
          <div style="font-weight:600;color:var(--danger)">Log out</div>
          <div class="field-hint" style="margin:0">End your current session on this device.</div>
        </div>
        <a href="<?= SITE_URL ?>/logout.php" class="btn btn-danger btn-sm">Logout</a>
      </div>
    </div>

  <?php elseif ($tab === 'security'): ?>
    <div class="card" style="max-width:600px;margin:0 auto">
      <h2 style="font-size:var(--text-lg);margin-bottom:var(--space-4)">Change password</h2>
      <?php if (empty($user['password'])): ?>
        <div class="alert alert-warning">Your account uses Google Sign-In and doesn't have a password.</div>
      <?php else: ?>
        <form method="POST">
          <input type="hidden" name="action" value="change_password">
          <div class="field">
            <label>Current password</label>
            <input type="password" name="current_password" class="input" required>
          </div>
          <div class="field">
            <label>New password</label>
            <input type="password" name="new_password" class="input" required>
          </div>
          <div class="field">
            <label>Confirm new password</label>
            <input type="password" name="confirm_password" class="input" required>
          </div>
          <button type="submit" class="btn btn-primary">Update password</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
