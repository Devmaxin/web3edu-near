<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/bounties.php';
require_once __DIR__ . '/../includes/payments/PaystackProvider.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'Please sign in.']); exit; }
requireCsrfAjax();

$userId = (int)$_SESSION['user_id'];
$bountyId = (int)($_POST['bounty_id'] ?? 0);
$bounty = $bountyId ? getBounty($bountyId) : null;
$host = $bounty && $bounty['host_id'] ? getBountyHostById((int)$bounty['host_id']) : null;

if (!$bounty || !$host || (int)$host['user_id'] !== $userId || $bounty['status'] !== 'awaiting_payment') {
    echo json_encode(['success' => false, 'error' => 'This bounty is not awaiting payment.']);
    exit;
}

if (!bountyPaymentMethodEnabled('paystack')) {
    echo json_encode(['success' => false, 'error' => 'Paystack payments are currently disabled.']);
    exit;
}

$user = getCurrentUser();
$plan = ['price_kobo' => (int)$bounty['listing_fee_kobo']];
$reference = 'bounty_' . $bountyId . '_' . bin2hex(random_bytes(6));

$provider = new PaystackProvider();
$init = $provider->initializeTransaction($user, $plan, $reference);

createPaystackBountyPayment($bountyId, $userId, (int)$plan['price_kobo'], $reference);

echo json_encode(array_merge(['success' => true], $init));
