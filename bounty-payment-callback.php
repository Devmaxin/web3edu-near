<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounties.php';
require_once __DIR__ . '/includes/payments/PaystackProvider.php';
requireLogin();

$bountyId = (int)($_GET['bounty_id'] ?? 0);
$reference = trim($_GET['reference'] ?? '');

if (!$bountyId || !$reference) {
    setFlash('error', 'Invalid payment reference.');
    header('Location: ' . SITE_URL . '/bounty-host-dashboard.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$bounty = getBounty($bountyId);
$host = $bounty && $bounty['host_id'] ? getBountyHostById((int)$bounty['host_id']) : null;
if (!$bounty || !$host || (int)$host['user_id'] !== $userId) {
    setFlash('error', 'This payment page isn\'t available to your account.');
    header('Location: ' . SITE_URL . '/bounty-host-dashboard.php');
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM bounty_payments WHERE reference = ? AND bounty_id = ?");
$stmt->execute([$reference, $bountyId]);
$payment = $stmt->fetch();

if ($payment && $payment['status'] === 'approved') {
    setFlash('info', 'This payment has already been processed.');
    header('Location: ' . SITE_URL . '/bounty-host-dashboard.php');
    exit;
}

if (!$payment) {
    setFlash('error', 'We could not find this payment. Please contact support if you were charged.');
    header('Location: ' . SITE_URL . '/bounty-host-dashboard.php');
    exit;
}

$provider = new PaystackProvider();
$verification = $provider->verifyTransaction($reference);

if ($verification['success']) {
    approvePaystackBountyPayment($reference);
    setFlash('success', 'Payment confirmed — your bounty is now live!');
    header('Location: ' . SITE_URL . '/bounty-detail.php?slug=' . urlencode(getBounty($bountyId)['slug'] ?? ''));
    exit;
} else {
    // Verification failure is distinct from an admin manually rejecting a
    // crypto payment — mark it 'failed' so the host can simply retry rather
    // than reading it as "an admin looked at this and said no".
    $db->prepare("UPDATE bounty_payments SET status='failed', rejection_reason='Verification failed' WHERE id=? AND status NOT IN ('approved')")->execute([$payment['id']]);
    setFlash('error', 'Payment verification failed. Please contact support if you were charged.');
    header('Location: ' . SITE_URL . '/bounty-payment.php?id=' . $bountyId);
    exit;
}
