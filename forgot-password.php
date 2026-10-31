<?php
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) { header('Location: ' . SITE_URL . '/index.php'); exit; }

$pageTitle = 'Forgot password';
$extraCss  = [SITE_URL . '/assets/css/auth.css'];
$errors    = [];
$success   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, username, email FROM users WHERE email = ? AND deleted_at IS NULL");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always show the same success message whether or not the email
        // exists — prevents leaking which emails are registered.
        if ($user) {
            $token     = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $db->prepare("UPDATE users SET reset_token_hash = ?, reset_token_expires_at = ? WHERE id = ?")
               ->execute([$tokenHash, $expiresAt, $user['id']]);

            $resetLink = SITE_URL . '/reset-password.php?token=' . $token . '&email=' . urlencode($user['email']);
            deliverEmailTemplate('forgot_password', $user['email'], [
                'username'   => $user['username'] ?: 'there',
                'reset_link' => $resetLink,
            ], immediate: true);
        }
        $success = true;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-page">
  <div class="auth-card">
    <a href="<?= SITE_URL ?>/index.php" class="auth-logo">
      <img src="<?= SITE_URL ?>/assets/images/logo-wordmark.png" alt="Web3Edu" class="auth-logo-img">
    </a>

    <?php if ($success): ?>
      <h1 class="auth-title">Check your email</h1>
      <p class="auth-subtitle">If an account exists for that email, we've sent a link to reset your password. The link expires in 1 hour.</p>
      <div class="auth-footer" style="margin-top:0">
        <a href="<?= SITE_URL ?>/login.php">← Back to login</a>
      </div>
    <?php else: ?>
      <h1 class="auth-title">Forgot your password?</h1>
      <p class="auth-subtitle">Enter your email and we'll send you a link to reset it.</p>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" style="margin-bottom:var(--space-4)">
          <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST">
        <div class="field">
          <label>Email address</label>
          <input type="email" name="email" class="input" placeholder="you@example.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">Send reset link</button>
      </form>

      <div class="auth-footer">Remembered it? <a href="<?= SITE_URL ?>/login.php">Sign in</a></div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
