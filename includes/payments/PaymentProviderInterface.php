<?php
/**
 * Contract every payment provider must implement. Business logic (subscription
 * activation, plan lookups) calls through this interface only — never against
 * a provider SDK directly — so a new provider can be added later by writing
 * one new class, with zero changes to includes/subscriptions.php or any page.
 */
interface PaymentProviderInterface
{
    /**
     * Build whatever the frontend needs to start a charge (public key, amount,
     * reference, etc). Returned array is provider-specific but always includes
     * at minimum: ['reference' => string].
     */
    public function initializeTransaction(array $user, array $plan, string $reference): array;

    /**
     * Verify a completed transaction server-side. Must return:
     * ['success' => bool, 'amount_kobo' => int, 'email' => string, 'raw' => array]
     */
    public function verifyTransaction(string $reference): array;

    /**
     * Start recurring billing for a monthly/yearly plan using a reusable
     * authorization from a successful initial charge. Returns:
     * ['success' => bool, 'subscription_code' => ?string, 'raw' => array]
     * Providers that don't support recurring billing yet may return
     * ['success' => false, 'subscription_code' => null, 'raw' => []].
     */
    public function createSubscription(array $user, array $plan, string $authorizationCode, string $customerCode): array;

    /** Cancel recurring billing on the provider's side. */
    public function cancelSubscription(string $subscriptionCode): bool;
}
