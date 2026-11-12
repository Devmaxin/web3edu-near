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

// Fetch linked NEAR wallets
$nearWallets = [];
if (isLoggedIn()) {
    $stmt = $db->prepare("SELECT id, near_account_id, public_key, is_primary, created_at FROM user_wallets WHERE user_id = ? ORDER BY is_primary DESC, created_at ASC");
    $stmt->execute([$user['id']]);
    $nearWallets = $stmt->fetchAll();
}

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

    // --- NEAR Wallet Management ---

    if ($action === 'link_near_wallet') {
        if (!isLoggedIn()) {
            $errors[] = 'Not logged in.';
            $tab = 'security';
        } else {
            $nearAccountId = trim($_POST['near_account_id'] ?? '');
            $publicKey     = trim($_POST['public_key'] ?? '');
            $signature     = trim($_POST['signature'] ?? '');
            $challengeId   = trim($_POST['challenge_id'] ?? '');
            $signedPayload = json_decode($_POST['signed_payload'] ?? '{}', true);

            if (!$nearAccountId || !$publicKey || !$signature || !$challengeId || !$signedPayload) {
                $errors[] = 'Missing wallet linking data.';
            } else {
                // Verify the challenge
                $challenge = Web3Edu\Near\Auth\NearAuth::consumeChallenge($challengeId);
                if (!$challenge || $challenge['action'] !== 'link') {
                    $errors[] = 'Invalid or expired linking challenge.';
                } else {
                    // Verify NEP-413 signature
                    $valid = Web3Edu\Near\Auth\NearAuth::verifyNep413Signature(
                        $challenge,
                        $nearAccountId,
                        $publicKey,
                        $signature,
                        $signedPayload
                    );

                    if (!$valid) {
                        $errors[] = 'Invalid wallet signature.';
                    } else {
                        // Check if wallet already linked to another user
                        $stmt = $db->prepare("SELECT user_id FROM user_wallets WHERE near_account_id = ?");
                        $stmt->execute([$nearAccountId]);
                        $existing = $stmt->fetch();
                        if ($existing && (int)$existing['user_id'] !== (int)$user['id']) {
                            $errors[] = 'This NEAR wallet is already linked to another account.';
                        } else {
                            // Check if already linked to current user
                            if ($existing) {
                                $errors[] = 'This NEAR wallet is already linked to your account.';
                            } else {
                                // Determine if primary
                                $stmt = $db->prepare("SELECT COUNT(*) FROM user_wallets WHERE user_id = ?");
                                $stmt->execute([$user['id']]);
                                $count = (int)$stmt->fetchColumn();
                                $isPrimary = $count === 0 ? 1 : 0;

                                $db->prepare("
                                    INSERT INTO user_wallets (user_id, near_account_id, public_key, is_primary)
                                    VALUES (?, ?, ?, ?)
                                ")->execute([$user['id'], $nearAccountId, $publicKey, $isPrimary]);

                                $success = 'NEAR wallet linked successfully.';
                            }
                        }
                    }
                }
            }
            $tab = 'security';
        }
    }

    if ($action === 'unlink_near_wallet') {
        if (!isLoggedIn()) {
            $errors[] = 'Not logged in.';
            $tab = 'security';
        } else {
            $walletId = intval($_POST['wallet_id'] ?? 0);
            if (!$walletId) {
                $errors[] = 'Invalid wallet.';
            } else {
                $stmt = $db->prepare("SELECT user_id, is_primary FROM user_wallets WHERE id = ?");
                $stmt->execute([$walletId]);
                $wallet = $stmt->fetch();

                if (!$wallet || (int)$wallet['user_id'] !== (int)$user['id']) {
                    $errors[] = 'Wallet not found or not owned by you.';
                } else {
                    $wasPrimary = (int)$wallet['is_primary'] === 1;
                    $db->prepare("DELETE FROM user_wallets WHERE id = ?")->execute([$walletId]);

                    // If we removed the primary, promote another wallet to primary
                    if ($wasPrimary) {
                        $stmt = $db->prepare("SELECT id FROM user_wallets WHERE user_id = ? ORDER BY created_at ASC LIMIT 1");
                        $stmt->execute([$user['id']]);
                        $next = $stmt->fetch();
                        if ($next) {
                            $db->prepare("UPDATE user_wallets SET is_primary = 1 WHERE id = ?")->execute([(int)$next['id']]);
                        }
                    }

                    $success = 'NEAR wallet unlinked.';
                }
            }
            $tab = 'security';
        }
    }

    if ($action === 'set_primary_near_wallet') {
        if (!isLoggedIn()) {
            $errors[] = 'Not logged in.';
            $tab = 'security';
        } else {
            $walletId = intval($_POST['wallet_id'] ?? 0);
            if (!$walletId) {
                $errors[] = 'Invalid wallet.';
            } else {
                // Verify ownership
                $stmt = $db->prepare("SELECT id FROM user_wallets WHERE id = ? AND user_id = ?");
                $stmt->execute([$walletId, $user['id']]);
                if (!$stmt->fetch()) {
                    $errors[] = 'Wallet not found or not owned by you.';
                } else {
                    // Unset all primaries for this user
                    $db->prepare("UPDATE user_wallets SET is_primary = 0 WHERE user_id = ?")->execute([$user['id']]);
                    // Set new primary
                    $db->prepare("UPDATE user_wallets SET is_primary = 1 WHERE id = ?")->execute([$walletId]);
                    $success = 'Primary wallet updated.';
                }
            }
            $tab = 'security';
        }
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

    <div class="card" style="max-width:600px;margin:var(--space-5) auto 0">
      <h2 style="font-size:var(--text-lg);margin-bottom:var(--space-4)">Connected NEAR Wallets</h2>
      <p class="field-hint" style="margin:0 0 var(--space-4)">Link your NEAR wallet to sign in without a password. You can link multiple wallets.</p>

      <?php if (empty($nearWallets)): ?>
        <div class="alert alert-info" style="margin-bottom:var(--space-4)">No NEAR wallet linked yet.</div>
        <button id="nearLinkBtn" class="btn near-btn" style="background:transparent;border:2px solid var(--accent-bright);color:var(--accent-bright)">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;vertical-align:middle">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
          </svg>
          Link NEAR Wallet
        </button>
      <?php else: ?>
        <div style="margin-bottom:var(--space-4)">
          <?php foreach ($nearWallets as $wallet): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:var(--space-3);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:var(--space-2);background:<?= $wallet['is_primary'] ? 'var(--accent-soft)' : 'transparent' ?>">
            <span style="font-family:monospace;font-size:var(--text-sm);color:var(--text-secondary);flex:1"><?= htmlspecialchars($wallet['near_account_id']) ?></span>
            <?php if ($wallet['is_primary']): ?>
              <span class="badge badge-accent" style="font-size:var(--text-xs)">Primary</span>
            <?php endif; ?>
            <div style="display:flex;gap:6px">
              <?php if (!$wallet['is_primary']): ?>
              <button onclick="setPrimaryNearWallet(<?= (int)$wallet['id'] ?>)" class="btn btn-ghost btn-sm" style="font-size:var(--text-xs)">Set Primary</button>
              <?php endif; ?>
              <button onclick="unlinkNearWallet(<?= (int)$wallet['id'] ?>)" class="btn btn-danger btn-sm" style="font-size:var(--text-xs)">Unlink</button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <button id="nearLinkBtn" class="btn near-btn" style="background:transparent;border:2px solid var(--accent-bright);color:var(--accent-bright)">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;vertical-align:middle">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
          </svg>
          Link Another Wallet
        </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script src="<?= SITE_URL ?>/frontend/js/wallet.bundle.js"></script>
<script>
async function linkNearWallet() {
  const btn = document.getElementById('nearLinkBtn');
  const originalText = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Connecting…';

  try {
    // Step 1: Request link challenge
    const challenge = await Web3EduNear.requestChallenge('link');

    // Step 2: Connect wallet
    const walletInfo = await Web3EduNear.connectWallet();
    if (!walletInfo) {
      throw new Error('No wallet connected');
    }

    // Step 3: Sign message
    const nonce = challenge.nonce;
    const signed = await Web3EduNear.signMessage(
      'Link NEAR wallet to Web3Edu account',
      nonce,
      window.location.origin
    );

    // Step 4: Submit signature
    const result = await Web3EduNear.submitSignature(
      challenge.challenge_id,
      walletInfo.accountId,
      walletInfo.publicKey,
      signed.signature,
      {
        message: 'Link NEAR wallet to Web3Edu account',
        nonce: challenge.nonce,
        recipient: challenge.recipient,
        callbackUrl: window.location.origin,
      },
      {
        near_account_id: walletInfo.accountId,
        public_key: walletInfo.publicKey,
        signature: signed.signature,
        challenge_id: challenge.challenge_id,
        signed_payload: JSON.stringify({
          message: 'Link NEAR wallet to Web3Edu account',
          nonce: challenge.nonce,
          recipient: challenge.recipient,
          callbackUrl: window.location.origin,
        }),
      }
    );

    if (result.success) {
      window.location.reload();
    }
  } catch (err) {
    console.error('NEAR link error:', err);
    alert(err.message || 'Failed to link NEAR wallet.');
    btn.disabled = false;
    btn.textContent = originalText;
  }
}

async function setPrimaryNearWallet(walletId) {
  try {
    const response = await fetch('<?= SITE_URL ?>/profile.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=set_primary_near_wallet&wallet_id=' + walletId + '&<?= csrfLink() ?>'
    });
    if (response.ok) {
      window.location.reload();
    } else {
      alert('Failed to set primary wallet.');
    }
  } catch (err) {
    alert('Error: ' + err.message);
  }
}

async function unlinkNearWallet(walletId) {
  if (!confirm('Are you sure you want to unlink this NEAR wallet?')) return;
  try {
    const response = await fetch('<?= SITE_URL ?>/profile.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=unlink_near_wallet&wallet_id=' + walletId + '&<?= csrfLink() ?>'
    });
    if (response.ok) {
      window.location.reload();
    } else {
      alert('Failed to unlink wallet.');
    }
  } catch (err) {
    alert('Error: ' + err.message);
  }
}

document.getElementById('nearLinkBtn').addEventListener('click', linkNearWallet);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
