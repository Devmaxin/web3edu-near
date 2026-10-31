<?php
/**
 * AIHttpClientTrait
 * ============================================================
 * Tiny shared cURL helper so every provider doesn't reinvent
 * request handling, timeouts, and JSON-error parsing.
 * ============================================================
 */
trait AIHttpClientTrait
{
    /**
     * POST JSON to a URL with given headers, return decoded JSON body.
     *
     * @throws AIProviderException
     */
    protected function postJson(string $url, array $headers, array $body, int $timeoutSeconds = 30): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new AIProviderException("Network error contacting AI provider: {$error}");
        }

        $decoded = json_decode((string)$response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $decoded['error']['message'] ?? $decoded['error'] ?? ('HTTP ' . $httpCode);
            throw new AIProviderException("AI provider error ({$httpCode}): " . (is_string($msg) ? $msg : json_encode($msg)));
        }

        if (!is_array($decoded)) {
            throw new AIProviderException('AI provider returned an invalid response.');
        }

        return $decoded;
    }
}
