<?php
require_once __DIR__ . '/PaymentProviderInterface.php';

/**
 * Paystack implementation. The initial charge for Monthly/Yearly plans goes
 * through initializeTransaction/verifyTransaction (Paystack's standard
 * Transaction API). createSubscription() then attaches recurring billing to
 * that same card via Paystack's Subscription API, using the reusable
 * authorization code returned on the first successful charge.
 */
class PaystackProvider implements PaymentProviderInterface
{
    private string $secretKey;
    private string $publicKey;

    public function __construct()
    {
        $this->secretKey = PAYSTACK_SECRET_KEY;
        $this->publicKey = PAYSTACK_PUBLIC_KEY;
    }

    public function initializeTransaction(array $user, array $plan, string $reference): array
    {
        return [
            'reference'  => $reference,
            'public_key' => $this->publicKey,
            'email'      => $user['email'],
            'amount'     => (int)$plan['price_kobo'],
        ];
    }

    public function verifyTransaction(string $reference): array
    {
        $result = $this->request('GET', "/transaction/verify/" . urlencode($reference));

        if (!$result || empty($result['status']) || ($result['data']['status'] ?? '') !== 'success') {
            return ['success' => false, 'amount_kobo' => 0, 'email' => '', 'raw' => $result ?? []];
        }

        return [
            'success'             => true,
            'amount_kobo'         => (int)$result['data']['amount'],
            'email'               => $result['data']['customer']['email'] ?? '',
            'customer_code'       => $result['data']['customer']['customer_code'] ?? null,
            'authorization_code'  => $result['data']['authorization']['authorization_code'] ?? null,
            'authorization_reusable' => (bool)($result['data']['authorization']['reusable'] ?? false),
            'raw'                 => $result['data'],
        ];
    }

    public function createSubscription(array $user, array $plan, string $authorizationCode, string $customerCode): array
    {
        // Requires a provider-side Plan code to exist for this billing cycle.
        // If the plan hasn't been synced to Paystack yet (provider_plan_code
        // column empty), recurring billing is skipped gracefully — the user
        // still has active access via expires_at, they just won't auto-renew
        // until an admin syncs the plan or renews manually.
        if (empty($plan['provider_plan_code'])) {
            return ['success' => false, 'subscription_code' => null, 'raw' => []];
        }

        $result = $this->request('POST', '/subscription', [
            'customer'      => $customerCode,
            'plan'          => $plan['provider_plan_code'],
            'authorization' => $authorizationCode,
        ]);

        if (!$result || empty($result['status'])) {
            return ['success' => false, 'subscription_code' => null, 'raw' => $result ?? []];
        }

        return [
            'success'            => true,
            'subscription_code'  => $result['data']['subscription_code'] ?? null,
            'raw'                => $result['data'],
        ];
    }

    public function cancelSubscription(string $subscriptionCode): bool
    {
        $result = $this->request('POST', '/subscription/disable', [
            'code'  => $subscriptionCode,
            'token' => $subscriptionCode,
        ]);
        return (bool)($result['status'] ?? false);
    }

    private function request(string $method, string $path, array $body = []): ?array
    {
        $ch = curl_init();
        $options = [
            CURLOPT_URL            => "https://api.paystack.co" . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer " . $this->secretKey,
                "Content-Type: application/json",
                "Cache-Control: no-cache",
            ],
        ];
        if ($method === 'POST') {
            $options[CURLOPT_CUSTOMREQUEST] = 'POST';
            $options[CURLOPT_POSTFIELDS]    = json_encode($body);
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error || !$response) {
            error_log('[Paystack] request failed: ' . $error);
            return null;
        }
        return json_decode($response, true);
    }
}
