<?php
require_once __DIR__ . '/EmailProviderInterface.php';

/**
 * Resend (https://resend.com) implementation. Plain cURL against their REST
 * API — no SDK dependency, keeps the vendor footprint to this one file.
 */
class ResendProvider implements EmailProviderInterface
{
    public function send(string $toEmail, string $subject, string $htmlBody): array
    {
        $payload = [
            'from'    => getEmailFromName() . ' <' . getEmailFromAddress() . '>',
            'to'      => [$toEmail],
            'subject' => $subject,
            'html'    => $htmlBody,
        ];

        $replyTo = getEmailReplyTo();
        if ($replyTo !== '') $payload['reply_to'] = [$replyTo];

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . getResendApiKey(),
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL error: ' . $curlError];
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'error' => null];
        }

        $decoded = json_decode($response, true);
        return ['success' => false, 'error' => $decoded['message'] ?? "HTTP {$httpCode}: {$response}"];
    }
}
