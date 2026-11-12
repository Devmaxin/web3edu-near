<?php
/**
 * Web3Edu NEAR — NEP-413 Wallet Authentication Helper
 *
 * Implements server-side NEP-413 message signing verification for NEAR wallet
 * authentication. All functions are safe to call multiple times.
 *
 * NEP-413 signing:
 *   digest = sha256( borsh(u32 2147484061) ‖ borsh({message, nonce, recipient, callbackUrl?}) )
 *   signature = Ed25519_sign(digest, secret_key)
 *
 * Where 2147484061 = 2^31 + 413 (the NEP-413 domain separator).
 *
 * Verification:
 *   1. Reconstruct the Borsh-serialized payload
 *   2. Compute sha256(prefix ‖ payload)
 *   3. Verify Ed25519 signature against the signer's public key
 *   4. Confirm the public key is authorized for the claimed NEAR account
 *      - Named accounts: RPC view_access_key must confirm the key
 *      - Implicit accounts: account_id must equal lowercase hex of public key bytes
 *   5. All RPC failures fail closed (return false)
 *
 * Requires PHP sodium extension (confirmed available).
 */

declare(strict_types=1);

namespace Web3Edu\Near\Auth;

use Exception;

class NearAuth
{
    /** NEP-413 domain separator: u32 (2^31 + 413) in little-endian Borsh = 0x9D010080 */
    private const NEP413_PREFIX = "\x9d\x01\x00\x80";

    /** Challenge expiration: 10 minutes */
    private const CHALLENGE_TTL_SECONDS = 600;

    /** NEAR testnet RPC endpoint for account key lookup */
    private const NEAR_TESTNET_RPC = 'https://test.rpc.fastnear.com';

    /** Application recipient bound to challenges */
    private const RECIPIENT = 'web3edu-near';

    /** Rate limit: max failed attempts per IP per window */
    private const RATE_LIMIT_MAX = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 300;

    // ------------------------------------------------------------------
    // Challenge generation
    // ------------------------------------------------------------------

    /**
     * Generate a fresh NEP-413 authentication challenge.
     *
     * @param string $action   'login' | 'register' | 'link'
     * @param int|null $userId  User ID for linking (nullable for login/register)
     * @return array{challenge_id: string, nonce: string, recipient: string, expires_at: string}
     * @throws Exception
     */
    public static function createChallenge(string $action, ?int $userId = null): array
    {
        if (!in_array($action, ['login', 'register', 'link'], true)) {
            throw new Exception('Invalid challenge action');
        }

        $challengeId = bin2hex(random_bytes(16)); // 32 hex chars
        $nonceBytes = random_bytes(32); // 32-byte nonce
        $nonce = base64_encode($nonceBytes);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::CHALLENGE_TTL_SECONDS);

        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO near_auth_challenges
                (challenge_id, nonce, action, recipient, expires_at, used, user_id)
            VALUES (?, ?, ?, ?, ?, 0, ?)
        ");
        $stmt->execute([$challengeId, $nonce, $action, self::RECIPIENT, $expiresAt, $userId]);

        return [
            'challenge_id' => $challengeId,
            'nonce'        => $nonce,
            'recipient'    => self::RECIPIENT,
            'expires_at'   => $expiresAt,
        ];
    }

    /**
     * Consume and verify a challenge atomically.
     * Uses a conditional UPDATE (used = 0 → used = 1) so that concurrent
     * requests cannot both consume the same challenge. Returns the challenge
     * row or null if already used, expired, or not found.
     */
    public static function consumeChallenge(string $challengeId): ?array
    {
        $db = getDB();

        // Atomic consume: only mark as used if it's still unused and not expired.
        // The affected-row check guarantees exactly-once consumption even under
        // concurrent requests.
        $stmt = $db->prepare("
            UPDATE near_auth_challenges
            SET used = 1
            WHERE id = (
                SELECT id FROM (
                    SELECT id FROM near_auth_challenges
                    WHERE challenge_id = ? AND used = 0 AND expires_at > UTC_TIMESTAMP()
                    LIMIT 1
                ) AS sub
            ) AND used = 0
        ");
        // NOTE: The outer AND used = 0 is redundant with the subquery but makes
        // the intent explicit. MySQL will evaluate the subquery first.
        $stmt->execute([$challengeId]);

        if ($stmt->rowCount() === 0) {
            return null; // Already used, expired, or not found
        }

        // Fetch the challenge row (now marked as used)
        $stmt = $db->prepare("SELECT * FROM near_auth_challenges WHERE challenge_id = ?");
        $stmt->execute([$challengeId]);
        $challenge = $stmt->fetch();

        return $challenge;
    }

    /**
     * Clean up expired challenges.
     */
    public static function cleanupExpiredChallenges(): void
    {
        $db = getDB();
        $db->prepare("DELETE FROM near_auth_challenges WHERE expires_at <= UTC_TIMESTAMP()")->execute();
    }

    // ------------------------------------------------------------------
    // NEP-413 verification
    // ------------------------------------------------------------------

    /**
     * Verify a NEP-413 signature against the challenge.
     *
     * @param array $challenge      Challenge row from near_auth_challenges
     * @param string $accountId     NEAR account ID claimed by the client
     * @param string $publicKey     Public key (ed25519:base58 or raw base58)
     * @param string $signature     Base64-encoded Ed25519 signature
     * @param array $signedPayload  The payload that was signed {message, nonce, recipient, callbackUrl?}
     * @return bool
     * @throws Exception
     */
    public static function verifyNep413Signature(
        array $challenge,
        string $accountId,
        string $publicKey,
        string $signature,
        array $signedPayload,
?string &$diagnosticCode = null,
array &$diagnosticDetails = []
    ): bool {
        // 1. Verify the nonce matches the challenge (exact string match)
        $receivedNonce = $signedPayload['nonce'] ?? '';
        if ($receivedNonce !== $challenge['nonce']) {
            $diagnosticCode = 'NONCE_MISMATCH';
            $diagnosticDetails = [];
            error_log('[NEAR-VERIFY-DEBUG] NONCE_MISMATCH callbackUrlPresent=' . (isset($signedPayload['callbackUrl']) && $signedPayload['callbackUrl'] !== '' ? '1' : '0'));
            return false;
        }

        // 2. Verify the nonce decodes to exactly 32 bytes
        $nonceBytes = base64_decode($receivedNonce, true);
        if ($nonceBytes === false || strlen($nonceBytes) !== 32) {
            $diagnosticCode = 'NONCE_INVALID_LENGTH';
            $diagnosticDetails = [];
            error_log('[NEAR-VERIFY-DEBUG] NONCE_INVALID_LENGTH callbackUrlPresent=' . (isset($signedPayload['callbackUrl']) && $signedPayload['callbackUrl'] !== '' ? '1' : '0'));
            return false;
        }

        // 3. Verify the recipient matches
        $receivedRecipient = $signedPayload['recipient'] ?? '';
        if ($receivedRecipient !== $challenge['recipient']) {
            $diagnosticCode = 'RECIPIENT_MISMATCH';
            $diagnosticDetails = [];
            error_log('[NEAR-VERIFY-DEBUG] RECIPIENT_MISMATCH callbackUrlPresent=' . (isset($signedPayload['callbackUrl']) && $signedPayload['callbackUrl'] !== '' ? '1' : '0'));
            return false;
        }

        // 4. Verify the action matches (challenge binds action server-side;
        //    client cannot switch login/register/link by changing fields)
        //    The challenge['action] was set at creation and cannot be altered
        //    by the client. No client-submitted action field is trusted.

        // 5. Reconstruct the signed message (Borsh serialization)
        $signedMessage = self::serializeNep413Payload($signedPayload);
        $prefix = self::NEP413_PREFIX;
        $digestInput = $prefix . $signedMessage;
        $digest = hash('sha256', $digestInput, true);

        // 6. Decode the signature (base64 -> exactly 64 bytes for Ed25519)
        $sigBytes = base64_decode($signature, true);
        if ($sigBytes === false || strlen($sigBytes) !== 64) {
            $diagnosticCode = 'SIGNATURE_INVALID_LENGTH';
            $diagnosticDetails = [];
            error_log('[NEAR-VERIFY-DEBUG] SIGNATURE_INVALID_LENGTH callbackUrlPresent=' . (isset($signedPayload['callbackUrl']) && $signedPayload['callbackUrl'] !== '' ? '1' : '0'));
            return false;
        }

        // 7. Decode the public key (ed25519:base58 -> exactly 32 bytes)
        $pkBytes = self::decodeNearPublicKey($publicKey);
        if ($pkBytes === false || strlen($pkBytes) !== 32) {
            $diagnosticCode = 'PUBLIC_KEY_INVALID_LENGTH';
            $diagnosticDetails = [];
            error_log('[NEAR-VERIFY-DEBUG] PUBLIC_KEY_INVALID_LENGTH callbackUrlPresent=' . (isset($signedPayload['callbackUrl']) && $signedPayload['callbackUrl'] !== '' ? '1' : '0'));
            return false;
        }

        // 8. Verify Ed25519 signature cryptographically
        $callbackUrlPresent = isset($signedPayload['callbackUrl']) && $signedPayload['callbackUrl'] !== '' ? '1' : '0';
        $ed25519Result = sodium_crypto_sign_verify_detached($sigBytes, $digest, $pkBytes);
        if (!$ed25519Result) {
            // Diagnostic: if callbackUrl was present, try verification without it
            // to determine whether the wallet omitted callbackUrl from the signed payload.
            // This never changes the original verification result.
            $altVerified = '0';
            if ($callbackUrlPresent === '1') {
                $altPayload = $signedPayload;
                $altPayload['callbackUrl'] = null;
                $altMessage = self::serializeNep413Payload($altPayload);
                $altDigestInput = self::NEP413_PREFIX . $altMessage;
                $altDigest = hash('sha256', $altDigestInput, true);
                $altVerified = sodium_crypto_sign_verify_detached($sigBytes, $altDigest, $pkBytes) ? '1' : '0';
            }
            $diagnosticCode = 'ED25519_VERIFICATION_FAILED';
            $diagnosticDetails = [];
            if ($callbackUrlPresent === '1') {
                $diagnosticDetails['verified_without_callback_url'] = $altVerified === '1';
            }
            error_log('[NEAR-VERIFY-DEBUG] ED25519_VERIFICATION_FAILED callbackUrlPresent=' . $callbackUrlPresent . ' verifiedWithoutCallbackUrl=' . $altVerified);
            return false;
        }

// 9. Verify the public key is authorized for the claimed NEAR account.
        $rpcResult = self::verifyAccountKey($accountId, $pkBytes, $publicKey);
        if (!$rpcResult) {
            $diagnosticCode = 'ACCOUNT_KEY_AUTHORIZATION_FAILED';
            $diagnosticDetails = [];
        } else {
            $diagnosticCode = 'VERIFICATION_SUCCESS';
            $diagnosticDetails = [];
        }
        error_log('[NEAR-VERIFY-DEBUG] ' . $diagnosticCode . ' callbackUrlPresent=' . (isset($signedPayload['callbackUrl']) && $signedPayload['callbackUrl'] !== '' ? '1' : '0'));
        return $rpcResult;
    }

    /**
     * Borsh-serialize a NEP-413 payload.
     *
     * Payload structure: {message: string, nonce: string, recipient: string, callbackUrl?: string}
     * The optional callbackUrl is included exactly when present (Some) or
     * omitted as None when absent.
     */
    private static function serializeNep413Payload(array $payload): string
    {
        $out = '';

        // message (string)
        $out .= self::borshString($payload['message'] ?? '');

        // nonce (fixed 32-byte array, not a Borsh string)
        $nonceBytes = base64_decode($payload['nonce'] ?? '', true);
        if ($nonceBytes === false || strlen($nonceBytes) !== 32) {
            throw new Exception('Invalid NEP-413 nonce');
        }
        $out .= $nonceBytes;

        // recipient (string)
        $out .= self::borshString($payload['recipient'] ?? '');

        // callbackUrl (option string): 0x00 = None, 0x01 = Some + string
        $callbackUrl = $payload['callbackUrl'] ?? null;
        if ($callbackUrl === null) {
            $out .= "\x00"; // None
        } else {
            $out .= "\x01"; // Some
            $out .= self::borshString($callbackUrl);
        }

        return $out;
    }

    /**
     * Borsh-serialize a string: u32 length (LE) + UTF-8 bytes.
     */
    private static function borshString(string $s): string
    {
        $bytes = mb_strlen($s, '8bit');
        $out = pack('V', $bytes); // u32 little-endian
        $out .= $s;
        return $out;
    }

    /**
     * Decode a NEAR public key to raw 32 bytes.
     * Accepts 'ed25519:base58' or raw base58.
     * Returns false on malformed input.
     */
    private static function decodeNearPublicKey(string $publicKey): string|false
    {
        // Strip 'ed25519:' prefix if present
        if (str_starts_with($publicKey, 'ed25519:')) {
            $publicKey = substr($publicKey, 8);
        }

        // Base58 decode (Bitcoin alphabet)
        $decoded = self::base58Decode($publicKey);
        if ($decoded === false) {
            return false;
        }

        // NEAR public keys are exactly 32 bytes (Ed25519)
        if (strlen($decoded) !== 32) {
            return false;
        }

        return $decoded;
    }

    /**
     * Base58 decode (Bitcoin alphabet: 123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz).
     * Returns false on invalid characters.
     */
    private static function base58Decode(string $input): string|false
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base = strlen($alphabet); // 58

        if ($input === '') {
            return false;
        }

        // Count leading zeros (character '1' in base58)
        $leadingZeros = 0;
        while ($leadingZeros < strlen($input) && $input[$leadingZeros] === '1') {
            $leadingZeros++;
        }

        // Convert to big integer using bcmath
        $num = '0';
        for ($i = 0; $i < strlen($input); $i++) {
            $pos = strpos($alphabet, $input[$i]);
            if ($pos === false) {
                return false; // Invalid base58 character
            }
            $num = bcadd(bcmul($num, (string)$base), (string)$pos);
        }

        // Convert to bytes
        $bytes = '';
        while (bccomp($num, '0', 0) > 0) {
            $mod = bcmod($num, '256');
            $num = bcdiv($num, '256', 0);
            $bytes = chr((int)$mod) . $bytes;
        }

        // Add leading zero bytes
        for ($i = 0; $i < $leadingZeros; $i++) {
            $bytes = "\x00" . $bytes;
        }

        return $bytes;
    }

    /**
     * Verify that the public key is authorized for the claimed NEAR account.
     *
     * For named accounts (e.g. alice.testnet): queries the NEAR testnet RPC
     * via view_access_key to confirm the public key is an authorized access key
     * for that account. RPC failures fail closed (return false).
     *
     * For implicit accounts (account_id == lowercase hex of public key bytes):
     * verifies the account ID matches exactly. No RPC needed.
     *
     * @param string $accountId NEAR account ID
     * @param string $pkBytes   Raw 32-byte Ed25519 public key
     * @return bool
     */
    private static function verifyAccountKey(string $accountId, string $pkBytes, string $publicKey): bool
    {
        // --- Implicit account check ---
        // An implicit NEAR account ID is the lowercase hex encoding of its
        // public key bytes (no access key needed).
        $pkHex = strtolower(bin2hex($pkBytes));
        $rpcPublicKey = str_starts_with($publicKey, 'ed25519:')
    ? $publicKey
    : 'ed25519:' . $publicKey;
        if ($accountId === $pkHex) {
            return true;
        }

        // --- Named account check via RPC ---
        // Query NEAR testnet for the account's access keys. The public key must
        // be listed as an authorized access key for the account.
    

        try {
            $ch = curl_init(self::NEAR_TESTNET_RPC);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'jsonrpc' => '2.0',
                    'id'      => 'verify',
                    'method'  => 'query',
                    'params'  => [
                        'request_type' => 'view_access_key',
                        'finality'     => 'final',
                        'account_id'   => $accountId,
                        'public_key'   => $rpcPublicKey,
                    ],
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Network error or HTTP error → fail closed
            if ($response === false || $httpCode >= 400) {
                error_log('[NEAR verifyAccountKey] curl failure: httpCode=' . var_export($httpCode, true) . ' curlError=' . curl_error($ch));
                return false;
            }

            $data = json_decode($response, true);
            if (!$data) {
                error_log('[NEAR verifyAccountKey] JSON decode failed');
                return false;
            }
            if (isset($data['error'])) {
                $err = $data['error'];
                error_log('[NEAR verifyAccountKey] RPC error: name=' . ($err['name'] ?? 'unknown') . ' code=' . ($err['code'] ?? 'unknown') . ' message=' . ($err['message'] ?? 'unknown'));
                return false;
            }

            // Key exists on account → authorized
            // view_access_key returns access-key fields directly under result (not nested under result.access_key)
            $hasKey = is_array($data['result']) && isset($data['result']['nonce']);
            return $hasKey;
        } catch (Exception $e) {
            // Any exception → fail closed
            error_log('[NEAR verifyAccountKey] exception: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Rate limiting (active enforcement)
    // ------------------------------------------------------------------

    /**
     * Get the client IP address.
     */
    private static function getClientIp(): string
    {
        $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
            return $cfIp;
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Check if an IP is rate-limited for a given auth action.
     * Returns true if the limit has been exceeded.
     */
    public static function isRateLimited(string $action): bool
    {
        if (!in_array($action, ['login', 'register', 'link'], true)) {
            return false;
        }

        $db = getDB();
        $ip = self::getClientIp();
        $windowStart = gmdate('Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW_SECONDS);

        $stmt = $db->prepare("
            SELECT attempt_count FROM near_rate_limits
            WHERE ip_address = ? AND action = ? AND window_start > ?
        ");
        $stmt->execute([$ip, $action, $windowStart]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        return (int)$row['attempt_count'] >= self::RATE_LIMIT_MAX;
    }

    /**
     * Record a failed auth attempt for rate limiting.
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic increment.
     */
    public static function recordFailedAttempt(string $action): void
    {
        if (!in_array($action, ['login', 'register', 'link'], true)) {
            return;
        }

        $db = getDB();
        $ip = self::getClientIp();
        // Compute window start as a clean integer timestamp
        $windowStartTs = (int)(time() / self::RATE_LIMIT_WINDOW_SECONDS) * self::RATE_LIMIT_WINDOW_SECONDS;
        $windowStart = gmdate('Y-m-d H:i:s', $windowStartTs);

        $stmt = $db->prepare("
            INSERT INTO near_rate_limits (ip_address, action, attempt_count, window_start)
            VALUES (?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE attempt_count = attempt_count + 1
        ");
        $stmt->execute([$ip, $action, $windowStart]);
    }

    /**
     * Clear rate limit entries for a successful auth (optional cleanup).
     */
    public static function clearRateLimit(string $action): void
    {
        if (!in_array($action, ['login', 'register', 'link'], true)) {
            return;
        }

        $db = getDB();
        $ip = self::getClientIp();
        $db->prepare("DELETE FROM near_rate_limits WHERE ip_address = ? AND action = ?")
           ->execute([$ip, $action]);
    }
}
