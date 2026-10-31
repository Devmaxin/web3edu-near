<?php
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) { header('Location: ' . SITE_URL . '/index.php'); exit; }

$pageTitle = 'Reset password';
$extraCss  = [SITE_URL . '/assets/css/auth.css'];
$errors    = [];

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$db    = getDB();

// Validate the token before rendering the form at all.
$user = null;
if ($token && $email) {
    $tokenHash = hash('sha256', $token);
    $stmt = $db->prepare("
        SELECT id, username, email FROM users
        WHERE email = ? AND reset_token_hash = ? AND reset_token_expires_at > NOW() AND deleted_at IS NULL
    ");
    $stmt->execute([$email, $tokenHash]);
    $user = $stmt->fetch();
}

$tokenValid = (bool)$user;
$success    = false;

if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirm     = trim($_POST['confirm_password'] ?? '');

    if (strlen($newPassword) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    } elseif ($newPassword !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password = ?, reset_token_hash = NULL, reset_token_expires_at = NULL WHERE id = ?")
           ->execute([$hash, $user['id']]);

        deliverEmailTemplate('password_changed', $user['email'], ['username' => $user['username'] ?: 'there'], immediate: true);

        setFlash('success', 'Your password has been reset. Please sign in.');
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-page">
  <div class="auth-card">
    <a href="<?= SITE_URL ?>/index.php" class="auth-logo">
      <img src="<?= SITE_URL ?>/assets/images/logo-wordmark.png" alt="Web3Edu" class="auth-logo-img">
    </a>

    <?php if (!$tokenValid): ?>
      <h1 class="auth-title">Link expired or invalid</h1>
      <p class="auth-subtitle">This password reset link is no longer valid. Request a new one below.</p>
      <a href="<?= SITE_URL ?>/forgot-password.php" class="btn btn-primary" style="width:100%;justify-content:center">Request new link</a>
      <div class="auth-footer"><a href="<?= SITE_URL ?>/login.php">← Back to login</a></div>
    <?php else: ?>
      <h1 class="auth-title">Choose a new password</h1>
      <p class="auth-subtitle">Resetting password for <strong><?= htmlspecialchars($user['email']) ?></strong></p>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" style="margin-bottom:var(--space-4)">
          <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
        <div class="field">
          <label>New password</label>
          <div class="password-field">
            <input type="password" id="newPwd" name="new_password" class="input" placeholder="At least 6 characters" required style="padding-right:44px">
            <button type="button" class="password-toggle" onclick="togglePwd('newPwd')" aria-label="Show password">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <div class="field">
          <label>Confirm new password</label>
          <input type="password" name="confirm_password" class="input" placeholder="Re-enter new password" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">Reset password</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<script>
function togglePwd(fieldId) {
  const f = document.getElementById(fieldId);
  f.type = f.type === 'password' ? 'text' : 'password';
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
