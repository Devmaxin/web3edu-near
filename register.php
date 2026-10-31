<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/waitlist_rewards.php';

if (isLoggedIn()) { header('Location: ' . SITE_URL . '/index.php'); exit; }

$pageTitle = 'Create your account';
$extraCss  = [SITE_URL . '/assets/css/auth.css'];
$errors    = [];
$old       = ['email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm_password'] ?? '');
    $old      = ['email' => $email];

    if (empty($email) || empty($password)) {
        $errors[] = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $avatar = getRandomAvatar();
            $stmt = $db->prepare("
                INSERT INTO users (username, email, password, avatar, membership_type, profile_completed, login_provider)
                VALUES (NULL, ?, ?, ?, 'free', 0, 'email')
            ");
            try {
                $stmt->execute([$email, $hash, $avatar]);
            } catch (PDOException $e) {
                // Two simultaneous sign-ups with the same email: the UNIQUE key lets exactly one win. The loser gets the
                // normal "already exists" message instead of a fatal error, and never reaches the reward step below.
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                $errors[] = 'An account with that email already exists.';
            }
        }
        if (empty($errors)) {
            $newUserId = (int)$db->lastInsertId();

            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$newUserId]);
            $newUser = $stmt->fetch();

            setUserSession($newUser);
            Analytics::track('signup', $newUserId, ['method' => 'email']);

            // Shared post-registration step (also used by Google sign-up): welcome bonus via the existing XP rules,
            // then any admin-approved waitlist reward for this email, credited exactly once. Never blocks registration.
            $rewards = wlrOnRegistration($newUserId, 'password');
            if ($rewards['waitlist_xp'] > 0) {
                setFlash('success', 'Your early-access rewards were added to your account: ' . (int)$rewards['waitlist_xp'] . ' XP.');
            }
            deliverEmailTemplate('welcome', $email, ['username' => $email], immediate: true);
            header('Location: ' . SITE_URL . '/onboarding.php');
            exit;
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

    <h1 class="auth-title">Create your account</h1>
    <p class="auth-subtitle">Start learning Web3 for free — no card required.</p>

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

    <div class="auth-divider"><span>or sign up with email</span></div>

    <form method="POST">
      <div class="field">
        <label>Email address</label>
        <input type="email" name="email" class="input" placeholder="you@example.com" required value="<?= htmlspecialchars($old['email']) ?>">
      </div>
      <div class="field">
        <label>Password</label>
        <div class="password-field">
          <input type="password" id="regPwd" name="password" class="input" placeholder="At least 6 characters" required style="padding-right:44px">
          <button type="button" class="password-toggle" onclick="togglePwd('regPwd')" aria-label="Show password">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
      <div class="field">
        <label>Confirm password</label>
        <input type="password" name="confirm_password" class="input" placeholder="Re-enter your password" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">Create free account</button>
    </form>

    <div class="auth-footer">Already have an account? <a href="<?= SITE_URL ?>/login.php">Sign in</a></div>
  </div>
</div>

<script>
function togglePwd(fieldId) {
  const f = document.getElementById(fieldId);
  f.type = f.type === 'password' ? 'text' : 'password';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
