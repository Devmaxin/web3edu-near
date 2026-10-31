<?php
/**
 * Contract every email provider must implement. Business logic
 * (EmailService::deliverEmailTemplate, the queue worker) calls through this
 * interface only — never against a provider SDK/API directly — so a new
 * provider can be added later by writing one new class.
 */
interface EmailProviderInterface
{
    /**
     * Send one email. Returns ['success' => bool, 'error' => ?string].
     */
    public function send(string $toEmail, string $subject, string $htmlBody): array;
}
