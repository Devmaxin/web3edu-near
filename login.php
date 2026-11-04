<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/login_throttle.php';

if (isLoggedIn()) { header('Location: ' . SITE_URL . '/index.php'); exit; }

$pageTitle = 'Login';
$extraCss  = [SITE_URL . '/assets/css/auth.css'];
$errors    = [];

// Generate CSRF token for AJAX-based NEAR login
csrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_login') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $errors[] = 'Please fill in all fields.';
    } elseif (isLoginThrottled($email)) {
        $errors[] = LOGIN_THROTTLE_GENERIC_MESSAGE;
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND deleted_at IS NULL");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && !empty($user['password']) && password_verify($password, $user['password'])) {
            clearLoginThrottle($email);
            setUserSession($user);
            Analytics::track('login', (int)$user['id'], ['method' => 'email']);
            $redirect = $_SESSION['redirect_after_login'] ?? null;
            unset($_SESSION['redirect_after_login']);
            // New user (registered < 5 min ago) → home; returning → dashboard
            if (!$redirect) {
                $isNew    = (time() - strtotime($user['created_at'])) < 300;
                $redirect = $isNew ? SITE_URL . '/index.php' : SITE_URL . '/dashboard.php';
            }
            setFlash('success', 'Welcome back, ' . ($user['username'] ?? 'Explorer') . '!');
            header('Location: ' . (!$user['profile_completed'] ? SITE_URL . '/onboarding.php' : $redirect));
            exit;
        } elseif ($user && empty($user['password'])) {
            recordFailedLogin($email);
            $errors[] = 'This account uses Google Sign-In. Use the Google button below.';
        } else {
            recordFailedLogin($email);
            $errors[] = 'Invalid email or password.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-page">
  <div class="auth-card">
    <a href="<?= SITE_URL ?>/index.php" class="auth-logo">
      <img src="<?= SITE_URL ?>/assets/images/logo-wordmark.png" alt="Web3Edu" class="auth-logo-img">
    </a>

    <h1 class="auth-title">Welcome back</h1>
    <p class="auth-subtitle">Continue your Web3 learning journey.</p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger" style="margin-bottom:var(--space-4)">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <a href="<?= SITE_URL ?>/auth/google-login.php" class="google-btn">
      <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
      </svg>
      Continue with Google
    </a>

    <div class="auth-divider"><span>or sign in with email</span></div>

    <form method="POST">
      <input type="hidden" name="action" value="email_login">
      <div class="field">
        <label>Email address</label>
        <input type="email" name="email" class="input" placeholder="you@example.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label style="display:flex;justify-content:space-between;align-items:center">
          Password
          <a href="<?= SITE_URL ?>/forgot-password.php" style="font-size:var(--text-xs);font-weight:600;color:var(--accent-bright)">Forgot password?</a>
        </label>
        <div class="password-field">
          <input type="password" id="loginPwd" name="password" class="input" placeholder="Your password" required style="padding-right:44px">
          <button type="button" class="password-toggle" onclick="togglePwd('loginPwd',this)" aria-label="Show password">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">Sign in</button>
    </form>

    <div class="auth-divider"><span>or continue with NEAR wallet</span></div>

    <button id="nearLoginBtn" class="btn near-btn" style="width:100%;background:transparent;border:2px solid var(--primary-bright);color:var(--primary-bright);margin-bottom:var(--space-3)">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px;vertical-align:middle">
        <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
      </svg>
      Connect NEAR Wallet
    </button>

    <div class="auth-footer">Don't have an account? <a href="<?= SITE_URL ?>/register.php">Create one free</a></div>
  </div>
</div>

<script src="<?= SITE_URL ?>/frontend/js/wallet.bundle.js"></script>
<script>
function togglePwd(fieldId, btn) {
  const f = document.getElementById(fieldId);
  f.type = f.type === 'password' ? 'text' : 'password';
}

document.getElementById('nearLoginBtn').addEventListener('click', async () => {
  const btn = document.getElementById('nearLoginBtn');
  const originalText = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Connecting…';

  try {
    // Step 1: Request challenge
    const challenge = await Web3EduNear.requestChallenge('login');

    // Step 2: Connect wallet
    const walletInfo = await Web3EduNear.connectWallet();
    if (!walletInfo) {
      throw new Error('No wallet connected');
    }

    // Step 3: Sign message (NEP-413)
    const nonce = challenge.nonce;
    const signed = await Web3EduNear.signMessage(
  'Log in to Web3Edu',
  nonce
);

    // Step 4: Submit signature for verification
    const result = await Web3EduNear.submitSignature(
  challenge.challenge_id,
  walletInfo.accountId,
  signed.publicKey,
  signed.signature,
      {
        message: 'Log in to Web3Edu',
        nonce: challenge.nonce,
        recipient: challenge.recipient,
      }
    );

    if (result.success && result.is_new) {
      // New wallet-only user redirected to onboarding
      window.location.href = '<?= SITE_URL ?>/onboarding.php';
    } else if (result.success) {
      window.location.href = '<?= SITE_URL ?>/dashboard.php';
    }
  } catch (err) {
    console.error('NEAR login error:', err);
    alert(err.message || 'NEAR login failed. Please try again.');
    btn.disabled = false;
    btn.textContent = originalText;
  }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
