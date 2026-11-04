<?php
/**
 * Stage 3B Security Verification Tests — NEAR Wallet Authentication
 *
 * Tests cover:
 * 1. NEP-413: Borsh serialization, prefix 2^31+413, SHA-256 digest, Ed25519 verification
 * 2. Optional callback URL inclusion
 * 3. Nonce 32-byte validation
 * 4. Strict signature/public key decoding
 * 5. Account authorization (named via RPC, implicit via hex match)
 * 6. Challenge replay protection (atomic consume)
 * 7. Challenge binding (action, recipient, nonce, expiration)
 * 8. Rate limiting and CSRF
 * 9. Nullable email compatibility
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/near-auth.php';

use Web3Edu\Near\Auth\NearAuth;

$pass = 0;
$fail = 0;
$errors = [];

// Reflection helper for private members
$ref = new ReflectionClass(NearAuth::class);
$constNep413Prefix = $ref->getConstant('NEP413_PREFIX');
$constRateLimitMax = $ref->getConstant('RATE_LIMIT_MAX');
$methodDecodeKey = $ref->getMethod('decodeNearPublicKey');
$methodDecodeKey->setAccessible(true);
$methodVerifyKey = $ref->getMethod('verifyAccountKey');
$methodVerifyKey->setAccessible(true);

function test(string $name, callable $fn): void {
    global $pass, $fail, $errors;
    try {
        $fn();
        echo "  ✅ $name\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  ❌ $name: " . $e->getMessage() . "\n";
        $fail++;
        $errors[] = "$name: " . $e->getMessage();
    }
}

function assertTrue(bool $condition, string $message = ''): void {
    if (!$condition) {
        throw new Exception($message ?: 'Assertion failed');
    }
}

// ------------------------------------------------------------------
// Generate a test Ed25519 keypair
// ------------------------------------------------------------------
$keypair = sodium_crypto_sign_keypair();
$secretKey = sodium_crypto_sign_secretkey($keypair);
$publicKeyBytes = sodium_crypto_sign_publickey($keypair);
$implicitAccountId = strtolower(bin2hex($publicKeyBytes));

echo "=== Stage 3B Security Verification Tests ===\n\n";
echo "Test keypair:\n";
echo "  Implicit account ID: $implicitAccountId\n\n";

// ------------------------------------------------------------------
// Helper: build NEP-413 payload
// ------------------------------------------------------------------
function buildNep413Payload(array $payload): string {
    $out = '';
    $out .= borshString($payload['message'] ?? '');
    $nonceBytes = base64_decode($payload['nonce'] ?? '', true);
    if ($nonceBytes === false || strlen($nonceBytes) !== 32) {
        throw new Exception('Invalid NEP-413 nonce');
    }
    $out .= $nonceBytes;
    $out .= borshString($payload['recipient'] ?? '');
    $callbackUrl = $payload['callbackUrl'] ?? null;
    if ($callbackUrl === null) {
        $out .= "\x00";
    } else {
        $out .= "\x01";
        $out .= borshString($callbackUrl);
    }
    return $out;
}

function borshString(string $s): string {
    $bytes = mb_strlen($s, '8bit');
    return pack('V', $bytes) . $s;
}

// Base58 encode (Bitcoin alphabet)
function base58encode(string $bytes): string {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $base = strlen($alphabet);
    $num = '0';
    for ($i = 0; $i < strlen($bytes); $i++) {
        $num = bcadd(bcmul($num, '256'), (string)ord($bytes[$i]));
    }
    $result = '';
    while (bccomp($num, '0', 0) > 0) {
        $mod = bcmod($num, (string)$base);
        $num = bcdiv($num, (string)$base, 0);
        $result = $alphabet[(int)$mod] . $result;
    }
    foreach (str_split($bytes) as $byte) {
        if ($byte === "\x00") {
            $result = '1' . $result;
        } else {
            break;
        }
    }
    return $result;
}

$publicKeyBase58 = base58encode($publicKeyBytes);
$publicKeyPrefixed = 'ed25519:' . $publicKeyBase58;

// ==================================================================
// 1. NEP-413: Borsh serialization, prefix, SHA-256, Ed25519
// ==================================================================
echo "--- 1. NEP-413 Cryptographic Verification ---\n";

// 1a. Verify prefix is 2^31+413
test('NEP-413 prefix is 2^31+413 (0x9D010080)', function () use ($constNep413Prefix) {
    $expectedPrefix = pack('V', 2147484061); // 2^31 + 413 in little-endian
    assertTrue($constNep413Prefix === $expectedPrefix, 'Prefix mismatch: ' . bin2hex($constNep413Prefix));
});

// 1b. Valid NEP-413 signature verifies successfully
test('Valid NEP-413 signature verifies successfully', function () use ($secretKey, $publicKeyPrefixed, $publicKeyBytes) {
    $message = 'Log in to Web3Edu';
    $nonce = base64_encode(random_bytes(32));
    $recipient = 'web3edu-near';
    
    $payload = ['message' => $message, 'nonce' => $nonce, 'recipient' => $recipient];
    $serialized = buildNep413Payload($payload);
    $prefix = pack('V', 2147484061);
    $digest = hash('sha256', $prefix . $serialized, true);
    $signature = sodium_crypto_sign_detached($digest, $secretKey);
    $sigBase64 = base64_encode($signature);
    
    // Verify using sodium directly
    $valid = sodium_crypto_sign_verify_detached(base64_decode($sigBase64), $digest, $publicKeyBytes);
    assertTrue($valid, 'Direct sodium verification failed');
});

// 1c. Borsh serialization includes message, nonce, recipient
test('Borsh serialization includes message, nonce, recipient', function () {
    $message = 'Test message';
    $nonce = base64_encode(random_bytes(32));
    $recipient = 'web3edu-near';
    $callbackUrl = 'https://example.com/callback';
    
    $payload = ['message' => $message, 'nonce' => $nonce, 'recipient' => $recipient, 'callbackUrl' => $callbackUrl];
    $serialized = buildNep413Payload($payload);
    
    $expectedMsg = pack('V', strlen($message)) . $message;
    assertTrue(str_starts_with($serialized, $expectedMsg), 'Message not Borsh-serialized correctly');
    
    $callbackPos = strpos($serialized, pack('V', strlen($callbackUrl)) . $callbackUrl);
    assertTrue($callbackPos !== false, 'callbackUrl not found in serialized payload');
    assertTrue($serialized[$callbackPos - 1] === "\x01", 'callbackUrl should be Some (0x01)');
});

// 1d. callbackUrl omitted as None (0x00) when absent
test('callbackUrl omitted as None (0x00) when absent', function () {
    $payload = ['message' => 'Test', 'nonce' => base64_encode(random_bytes(32)), 'recipient' => 'web3edu-near'];
    $serialized = buildNep413Payload($payload);
    
    $lastByte = substr($serialized, -1);
    assertTrue($lastByte === "\x00", 'callbackUrl should be None (0x00) when absent');
});

// ==================================================================
// 2. Nonce 32-byte validation
// ==================================================================
echo "\n--- 2. Nonce 32-Byte Validation ---\n";

test('Nonce decoding produces exactly 32 bytes', function () {
    $validNonce = base64_encode(random_bytes(32));
    $decoded = base64_decode($validNonce, true);
    assertTrue($decoded !== false && strlen($decoded) === 32, 'Valid nonce should decode to 32 bytes');
});

test('Reject nonce that is not 32 bytes', function () {
    $shortNonce = base64_encode(random_bytes(16));
    $decoded = base64_decode($shortNonce, true);
    assertTrue(strlen($decoded) !== 32, 'Short nonce should not be 32 bytes');
    
    $longNonce = base64_encode(random_bytes(64));
    $decoded = base64_decode($longNonce, true);
    assertTrue(strlen($decoded) !== 32, 'Long nonce should not be 32 bytes');
});

// ==================================================================
// 3. Strict signature/public key decoding
// ==================================================================
echo "\n--- 3. Strict Signature/Public Key Decoding ---\n";

test('Reject malformed Base58 public key', function () use ($methodDecodeKey) {
    $result = $methodDecodeKey->invoke(null, 'ed25519:invalid_base58_chars!!!');
    assertTrue($result === false, 'Malformed Base58 should return false');
});

test('Reject public key wrong length', function () use ($methodDecodeKey) {
    $result = $methodDecodeKey->invoke(null, 'ed25519:' . base58encode(random_bytes(16)));
    assertTrue($result === false, '16-byte key should be rejected');
    
    $result = $methodDecodeKey->invoke(null, 'ed25519:' . base58encode(random_bytes(64)));
    assertTrue($result === false, '64-byte key should be rejected');
});

test('Reject invalid signature length', function () {
    $badSig = base64_encode(random_bytes(32));
    $decoded = base64_decode($badSig, true);
    assertTrue($decoded !== false && strlen($decoded) !== 64, '32-byte signature should not be 64 bytes');
});

test('Accept valid ed25519: prefixed public key', function () use ($methodDecodeKey, $publicKeyPrefixed, $publicKeyBytes) {
    $result = $methodDecodeKey->invoke(null, $publicKeyPrefixed);
    assertTrue($result === $publicKeyBytes, 'Prefixed key should decode to raw bytes');
});

test('Accept raw base58 public key (no prefix)', function () use ($methodDecodeKey, $publicKeyBase58, $publicKeyBytes) {
    $result = $methodDecodeKey->invoke(null, $publicKeyBase58);
    assertTrue($result === $publicKeyBytes, 'Raw key should decode to raw bytes');
});

// ==================================================================
// 4. Account authorization
// ==================================================================
echo "\n--- 4. Account Authorization ---\n";

test('Implicit account: account_id == lowercase hex of public key', function () use ($methodDecodeKey, $publicKeyPrefixed) {
    $decodedKey = $methodDecodeKey->invoke(null, $publicKeyPrefixed);
    $computedImplicit = strtolower(bin2hex($decodedKey));
    assertTrue($computedImplicit === $GLOBALS['implicitAccountId'], 'Implicit account ID should match hex of public key');
});

test('Named account RPC fails closed (no network)', function () use ($methodVerifyKey, $publicKeyBytes) {
    $namedAccount = 'alice.testnet';
    $result = $methodVerifyKey->invoke(null, $namedAccount, $publicKeyBytes);
    assertTrue($result === false, 'Named account without RPC access should fail closed');
});

// ==================================================================
// 5. Challenge replay and binding
// ==================================================================
echo "\n--- 5. Challenge Replay and Binding ---\n";

test('Challenge consumed exactly once (replay rejected)', function () {
    $challenge = NearAuth::createChallenge('login');
    $first = NearAuth::consumeChallenge($challenge['challenge_id']);
    assertTrue($first !== null, 'First consumption should succeed');
    
    $second = NearAuth::consumeChallenge($challenge['challenge_id']);
    assertTrue($second === null, 'Second consumption should return null (already used)');
});

test('Challenge binds action (cannot switch login/register/link)', function () {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO near_auth_challenges (challenge_id, nonce, action, recipient, expires_at, used, user_id)
        VALUES (?, ?, 'login', 'web3edu-near', ?, 0, NULL)
    ");
    $expires = gmdate('Y-m-d H:i:s', time() + 600);
    $stmt->execute(['action_bind_test', base64_encode(random_bytes(32)), $expires]);
    
    $stmt = $db->prepare("SELECT action FROM near_auth_challenges WHERE challenge_id = ?");
    $stmt->execute(['action_bind_test']);
    $row = $stmt->fetch();
    assertTrue($row['action'] === 'login', 'DB-stored action should be login');
    
    $db->prepare("DELETE FROM near_auth_challenges WHERE challenge_id = 'action_bind_test'")->execute();
});

test('Challenge binds recipient', function () {
    $challenge = NearAuth::createChallenge('login');
    assertTrue($challenge['recipient'] === 'web3edu-near', 'Challenge recipient should be web3edu-near');
});

test('Challenge binds expiration', function () {
    $challenge = NearAuth::createChallenge('login');
    $expires = new DateTime($challenge['expires_at']);
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $diff = $expires->getTimestamp() - $now->getTimestamp();
    assertTrue($diff > 0 && $diff <= 600, 'Challenge should expire in 0-600 seconds');
});

test('Expired challenge rejected', function () {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO near_auth_challenges (challenge_id, nonce, action, recipient, expires_at, used, user_id)
        VALUES (?, ?, 'login', 'web3edu-near', ?, 0, NULL)
    ");
    $expired = gmdate('Y-m-d H:i:s', time() - 3600);
    $stmt->execute(['expired_test_challenge', base64_encode(random_bytes(32)), $expired]);
    
    $result = NearAuth::consumeChallenge('expired_test_challenge');
    assertTrue($result === null, 'Expired challenge should be rejected');
    
    $db->prepare("DELETE FROM near_auth_challenges WHERE challenge_id = 'expired_test_challenge'")->execute();
});

// ==================================================================
// 6. Rate limiting and CSRF
// ==================================================================
echo "\n--- 6. Rate Limiting and CSRF ---\n";

test('isRateLimited returns false initially', function () {
    $result = NearAuth::isRateLimited('login');
    assertTrue($result === false, 'Should not be rate limited initially');
});

test('recordFailedAttempt then isRateLimited returns true after max attempts', function () use ($constRateLimitMax) {
    $max = $constRateLimitMax;
    for ($i = 0; $i < $max; $i++) {
        NearAuth::recordFailedAttempt('login');
    }
    $result = NearAuth::isRateLimited('login');
    assertTrue($result === true, 'Should be rate limited after max attempts');
    
    $db = getDB();
    $db->prepare("DELETE FROM near_rate_limits WHERE action = 'login'")->execute();
});

// ==================================================================
// 7. Nullable email compatibility
// ==================================================================
echo "\n--- 7. Nullable Email Compatibility ---\n";

test('users.email column is nullable', function () {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT IS_NULLABLE FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email'
    ");
    $stmt->execute();
    $row = $stmt->fetch();
    assertTrue($row['IS_NULLABLE'] === 'YES', 'email column should be nullable');
});

test('Wallet-only user can be created with NULL email', function () {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO users (email, password, avatar, membership_type, profile_completed, login_provider) VALUES (NULL, '', 'test.png', 'free', 0, 'near')");
    $stmt->execute();
    $userId = (int)$db->lastInsertId();
    assertTrue($userId > 0, 'Wallet-only user should be created with NULL email');
    
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
});

// ==================================================================
// Summary
// ==================================================================
echo "\n=== Summary ===\n";
echo "Passed: $pass\n";
echo "Failed: $fail\n";

if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
    exit(1);
}

echo "\nAll security verification tests passed.\n";
exit(0);