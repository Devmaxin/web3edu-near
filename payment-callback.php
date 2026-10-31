<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/subscriptions.php';
requireLogin();

$reference = trim($_GET['reference'] ?? '');
if (empty($reference)) {
    setFlash('error', 'Invalid payment reference.');
    header('Location: ' . SITE_URL . '/upgrade.php');
    exit;
}

$db = getDB();

// Look up the pending purchase created when checkout started, to recover which plan was chosen.
$stmt = $db->prepare("SELECT * FROM purchases WHERE reference = ? AND user_id = ?");
$stmt->execute([$reference, $_SESSION['user_id']]);
$pending = $stmt->fetch();

if ($pending && $pending['status'] === 'success') {
    setFlash('info', 'This payment has already been processed.');
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

if (!$pending || empty($pending['plan_id'])) {
    setFlash('error', 'We could not find this checkout session. Please contact support if you were charged.');
    header('Location: ' . SITE_URL . '/upgrade.php');
    exit;
}

$plan = getPlanById((int)$pending['plan_id']);
if (!$plan) {
    setFlash('error', 'That plan is no longer available. Please contact support if you were charged.');
    header('Location: ' . SITE_URL . '/upgrade.php');
    exit;
}

$verification = getPaymentProvider()->verifyTransaction($reference);

if ($verification['success']) {
    activateSubscription((int)$_SESSION['user_id'], $plan, $verification, $reference);

    setFlash('success', "Welcome to Premium! Your {$plan['name']} plan is now active.");
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
} else {
    $db->prepare("UPDATE purchases SET status='failed' WHERE reference=?")->execute([$reference]);

    Analytics::track('payment_failed', (int)$_SESSION['user_id'], [
        'plan_id' => $plan['id'], 'plan_name' => $plan['name'], 'payment_reference' => $reference,
    ]);
    $__user = getCurrentUser();
    notifyAdmins('payment_failed_admin', [
        'username'  => $__user['username'] ?? $__user['email'] ?? 'A user',
        'email'     => $__user['email'] ?? '',
        'plan_name' => $plan['name'],
        'reference' => $reference,
        'link'      => SITE_URL . '/admin/payments.php',
    ]);

    setFlash('error', 'Payment verification failed. Please contact support if you were charged.');
    header('Location: ' . SITE_URL . '/upgrade.php');
    exit;
}
