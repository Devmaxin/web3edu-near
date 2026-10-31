<?php
/**
 * Paystack webhook receiver — handles recurring billing events so renewals
 * happen automatically once plans are synced to Paystack Plan codes
 * (premium_plans.provider_plan_code). Safe to deploy now even before that
 * sync happens: unrecognized/unlinked events are logged and ignored.
 *
 * Configure this URL in the Paystack dashboard: SITE_URL/api/paystack-webhook.php
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/subscriptions.php';

$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

// Verify the request genuinely came from Paystack
if (!$signature || !hash_equals(hash_hmac('sha512', $payload, PAYSTACK_SECRET_KEY), $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($payload, true);
if (!$event || empty($event['event'])) { http_response_code(400); exit('Bad payload'); }

$db = getDB();

try {
    switch ($event['event']) {

        case 'charge.success':
            // Recurring renewal charge (not the initial checkout, which goes through payment-callback.php)
            $data = $event['data'];
            if (!empty($data['metadata']['subscription_id'])) {
                $subId = (int)$data['metadata']['subscription_id'];
                $reference = $data['reference'] ?? '';

                // Idempotency: Paystack retries webhooks that don't get a fast 200
                // (and can occasionally redeliver even successful ones). Without this
                // check, a redelivered event would call calculateExpiry() again on an
                // *already-extended* expires_at and push the renewal date out twice
                // for one charge.
                $already = $db->prepare("SELECT id FROM purchases WHERE reference = ? AND status = 'success'");
                $already->execute([$reference]);
                if ($already->fetch()) {
                    error_log("[Paystack Webhook] Duplicate charge.success for {$reference} — already processed, skipping.");
                    break;
                }

                $stmt = $db->prepare("SELECT us.*, pp.billing_cycle, pp.name AS plan_name FROM user_subscriptions us INNER JOIN premium_plans pp ON pp.id = us.plan_id WHERE us.id = ?");
                $stmt->execute([$subId]);
                $sub = $stmt->fetch();

                if ($sub) {
                    $newExpiry = calculateExpiry($sub['billing_cycle'], $sub['expires_at']);
                    $db->prepare("UPDATE user_subscriptions SET status='active', expires_at=?, renews_at=? WHERE id=?")
                       ->execute([$newExpiry, $newExpiry, $subId]);
                    syncMembershipCache((int)$sub['user_id']);

                    $amountNaira = $data['amount'] / 100;
                    $db->prepare("
                        INSERT INTO purchases (user_id, reference, amount, status, plan_id, subscription_id, provider, billing_cycle)
                        VALUES (?,?,?,'success',?,?,'paystack',?)
                        ON DUPLICATE KEY UPDATE status='success', amount=VALUES(amount)
                    ")->execute([$sub['user_id'], $reference, $amountNaira, $sub['plan_id'], $subId, $sub['billing_cycle']]);

                    logSubscriptionHistory($subId, (int)$sub['user_id'], 'renewed', $sub['plan_name'], $reference, $amountNaira, 'Recurring charge via Paystack webhook');

                    Analytics::track('payment_completed', (int)$sub['user_id'], [
                        'plan_id' => $sub['plan_id'], 'plan_name' => $sub['plan_name'], 'amount' => $amountNaira,
                        'currency' => 'NGN', 'payment_reference' => $reference, 'source' => 'webhook',
                    ]);
                    Analytics::track('subscription_renewal', (int)$sub['user_id'], [
                        'plan_id' => $sub['plan_id'], 'plan_name' => $sub['plan_name'], 'source' => 'webhook',
                    ]);

                    $userStmt = $db->prepare("SELECT username, email FROM users WHERE id = ?");
                    $userStmt->execute([$sub['user_id']]);
                    $userInfo = $userStmt->fetch();
                    if ($userInfo) {
                        deliverEmailTemplate('premium_renewed', $userInfo['email'], [
                            'username'     => $userInfo['username'],
                            'plan_name'    => $sub['plan_name'],
                            'renewal_date' => date('F j, Y', strtotime($newExpiry)),
                        ], immediate: true);
                    }

                    notifySubscriptionAdmins(
                        'subscription_renewed',
                        'Subscription auto-renewed',
                        ($userInfo['username'] ?? $userInfo['email'] ?? 'A user') . " renewed the {$sub['plan_name']} plan (₦" . number_format($amountNaira, 2) . ') via recurring billing.',
                        SITE_URL . '/admin/subscriptions.php?action=view&id=' . $subId
                    );
                } else {
                    error_log("[Paystack Webhook] charge.success referenced unknown subscription_id={$subId}");
                }
            }
            break;

        case 'subscription.disable':
        case 'subscription.not_renew':
            $subCode = $event['data']['subscription_code'] ?? null;
            if ($subCode) {
                $stmt = $db->prepare("SELECT us.id, us.user_id, pp.name AS plan_name FROM user_subscriptions us INNER JOIN premium_plans pp ON pp.id = us.plan_id WHERE us.provider_subscription_code = ?");
                $stmt->execute([$subCode]);
                if ($row = $stmt->fetch()) {
                    $db->prepare("UPDATE user_subscriptions SET status='cancelled', cancelled_at=NOW() WHERE id=?")->execute([$row['id']]);
                    syncMembershipCache((int)$row['user_id']);
                    logSubscriptionHistory((int)$row['id'], (int)$row['user_id'], 'cancelled', $row['plan_name'], null, null, 'Cancelled via Paystack (' . $event['event'] . ')');
                    Analytics::track('subscription_cancelled', (int)$row['user_id'], ['plan_name' => $row['plan_name'], 'initiated_by' => 'paystack']);
                }
            }
            break;

        default:
            error_log('[Paystack Webhook] Unhandled event: ' . $event['event']);
    }
} catch (Throwable $e) {
    // A 5xx here tells Paystack to retry later instead of silently dropping
    // the event, and this keeps the failure out of the visitor-facing side
    // of the app entirely (this endpoint has no human audience).
    error_log('[Paystack Webhook] Exception processing ' . $event['event'] . ': ' . $e->getMessage());
    http_response_code(500);
    exit('Internal error');
}

http_response_code(200);
echo 'ok';
