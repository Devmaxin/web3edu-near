<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/login_throttle.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_SESSION['admin_id'])) {
    header('Location: ' . SITE_URL . '/admin/');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (isLoginThrottled($email, 'admin')) {
        $errors[] = LOGIN_THROTTLE_GENERIC_MESSAGE;
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM admins WHERE email = ? AND deleted_at IS NULL");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            clearLoginThrottle($email, 'admin');
            session_regenerate_id(true);
            $_SESSION['admin_id']    = $admin['id'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_name']  = $admin['name'];
            header('Location: ' . SITE_URL . '/admin/');
            exit;
        } else {
            recordFailedLogin($email, 'admin');
            $errors[] = 'Invalid credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Web3edu</title>
    <link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@700;800&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css">
</head>
<body>
<div class="bg-glow bg-glow-1"></div>

<div class="auth-page">
    <div class="auth-card" style="position:relative;z-index:1">
        <div style="text-align:center;margin-bottom:28px">
            <div style="font-size:2rem;color:var(--primary-bright);margin-bottom:8px">⬡</div>
            <h1 class="auth-title">Admin Access</h1>
            <p class="auth-subtitle">Web3edu Control Panel</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:var(--radius);padding:14px;margin-bottom:20px;color:#fca5a5;font-size:0.85rem">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <?= sanitize($errors[0]) ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">Admin Email</label>
                <input type="email" name="email" class="form-input" placeholder="admin@web3edu.com" required>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-input" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;padding:13px">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg> Login to Admin
            </button>
        </form>

        <div class="auth-footer" style="margin-top:20px">
            <a href="<?= SITE_URL ?>/index.php" style="color:var(--text-muted);font-size:0.82rem">← Back to site</a>
        </div>
    </div>
</div>
</body>
</html>
