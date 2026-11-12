<?php
/**
 * Web3Edu NEAR — Credential Issuance Helpers
 *
 * Safe request creation and transaction verification for on-chain credential
 * issuance using local near-cli-rs issuer signing.
 *
 * No private keys are stored or handled by PHP. All transaction signing is
 * performed by the issuer locally using near-cli-rs.
 */

declare(strict_types=1);

namespace Web3Edu\Near\Issuance;

use Exception;

const NEAR_TESTNET_RPC = 'https://test.rpc.fastnear.com';

/**
 * Read a configuration value from the environment in a way that also works on
 * shared hosts (e.g. InfinityFree) where putenv() is disabled or ineffective and
 * includes/config.php's loadEnv() therefore only populates $_ENV.
 *
 * Precedence: getenv() -> $_ENV -> $_SERVER. A source that is missing, not a
 * scalar string/int, empty after trimming, or contains control characters is
 * skipped, so a blank higher-priority value never masks a valid lower one.
 * Returns null when no source yields a usable value. Never logs the value.
 */
function nearEnv(string $name): ?string
{
    $candidates = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];

    foreach ($candidates as $val) {
        if (!is_string($val) && !is_int($val)) {
            continue; // false (getenv miss), null, arrays, floats, bools
        }
        $val = trim((string) $val);
        if ($val === '' || preg_match('/[\x00-\x1F\x7F]/', $val)) {
            continue;
        }
        return $val;
    }
    return null;
}

/**
 * Configured NEAR contract account ID, or '' when missing or malformed.
 * Validated against the NEAR account-ID grammar (2-64 chars, lowercase
 * alphanumerics separated by single "-", "_" or "."), so a garbled value is
 * treated as "not configured" rather than being sent to RPC or shown in UI.
 */
function getNearContractId(): string
{
    $id = nearEnv('NEAR_CONTRACT_ID');
    if ($id === null) {
        return '';
    }
    $len = strlen($id);
    if ($len < 2 || $len > 64
        || !preg_match('/^(?:[a-z\d]+[-_.])*[a-z\d]+$/', $id)) {
        return '';
    }
    return $id;
}

/** NEAR network label (display only). Defaults to testnet. */
function getNearNetwork(): string
{
    $n = nearEnv('NEAR_NETWORK');
    return ($n !== null && preg_match('/^[a-z0-9_-]{1,32}$/i', $n)) ? $n : 'testnet';
}

/** RPC endpoint. Defaults to NEAR testnet; NEAR_RPC_URL may override it (e.g. a different provider). */
function nearRpcUrl(): string
{
    $u = nearEnv('NEAR_RPC_URL');
    if ($u !== null && preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL) !== false) {
        return $u;
    }
    return NEAR_TESTNET_RPC;
}
const NEAR_ISSUER_ACCOUNT = 'web3edu-near.testnet';
const ISSUANCE_STATUS_REQUESTED = 'REQUESTED';
const ISSUANCE_STATUS_UNCERTAIN = 'UNCERTAIN';
const ISSUANCE_STATUS_VERIFIED = 'VERIFIED';
const ISSUANCE_STATUS_FAILED = 'FAILED';
const ISSUANCE_STATUS_EXPIRED = 'EXPIRED';

/**
 * Get the configured issuer admin ID from environment.
 *
 * Returns null if not configured. This is a deliberate fail-closed design:
 * without NEAR_ISSUER_ADMIN_ID, no issuer operations are permitted.
 * Only returns a valid positive integer.
 */
function getIssuerAdminId(): ?int
{
    $val = nearEnv('NEAR_ISSUER_ADMIN_ID');

    // Digits only: rejects "", "0", "-1", "1.5", "1abc", " 1; DROP", "0x1".
    // Also reject absurdly long digit strings that would overflow int.
    if ($val === null || !ctype_digit($val) || strlen($val) > 18) {
        return null;
    }

    $id = (int) $val;

    return $id > 0 ? $id : null;
}

/**
 * Check whether the current session is the configured issuer admin.
 *
 * Requires both:
 *   - isAdmin() validates the session against the admins database table
 *   - $_SESSION['admin_id'] matches the configured NEAR_ISSUER_ADMIN_ID
 *
 * Fails closed if configuration is missing or invalid.
 */
function isIssuerAdmin(): bool
{
    $issuerId = getIssuerAdminId();
    if ($issuerId === null) {
        return false;
    }
    return isset($_SESSION['admin_id']) && isAdmin() && (int)$_SESSION['admin_id'] === $issuerId;
}

/**
 * Create a pending NEAR issuance request for a local certificate.
 *
 * Uses a database transaction with SELECT ... FOR UPDATE on the certificate
 * row to prevent concurrent duplicate active requests.
 *
 * Returns the new issuance row ID, or null if blocked.
 */
function createIssuanceRequest(
    int $localCertId,
    int $userId,
    string $nearAccountId,
    string $courseId,
    ?string $metadataHash = null
): ?int {
    $db = getDB();

    // Callers may already be inside a transaction; only manage our own.
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }

    try {
        // Lock the certificate row to prevent concurrent duplicate requests
        $certStmt = $db->prepare(
            "SELECT id, user_id, is_revoked FROM certificates WHERE id = ? FOR UPDATE"
        );
        $certStmt->execute([$localCertId]);
        $cert = $certStmt->fetch();

        if (!$cert || (int)$cert['user_id'] !== $userId || (int)$cert['is_revoked'] === 1) {
            if ($ownsTransaction) { $db->rollBack(); }
            return null;
        }

        // Block while a request is in flight OR already confirmed on-chain.
        // VERIFIED must block too, otherwise a second on-chain credential
        // could be requested for the same certificate.
        $activeStmt = $db->prepare(
            "SELECT id FROM near_credential_issuances
             WHERE local_cert_id = ?
             AND status IN ('REQUESTED', 'UNCERTAIN', 'VERIFIED')
             LIMIT 1"
        );
        $activeStmt->execute([$localCertId]);
        if ($activeStmt->fetch()) {
            if ($ownsTransaction) { $db->rollBack(); }
            return null;
        }

        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // attention threshold only

        $insertStmt = $db->prepare(
            "INSERT INTO near_credential_issuances
                (local_cert_id, user_id, near_account_id, course_id,
                 metadata_hash, status, attempt_count, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'REQUESTED', 0, ?, NOW(), NOW())"
        );
        $insertStmt->execute([
            $localCertId, $userId, $nearAccountId, $courseId, $metadataHash, $expiresAt,
        ]);

        $newId = (int)$db->lastInsertId();
        if ($ownsTransaction) { $db->commit(); }

        return $newId;
    } catch (\Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        // Never swallow silently: leave a diagnosable trace, then let the
        // caller see the failure.
        error_log(sprintf(
            '[web3edu-near] createIssuanceRequest failed cert=%d user=%d: %s',
            $localCertId, $userId, $e->getMessage()
        ));
        throw $e;
    }
}

/**
 * Get an issuance request by ID, with optional status filter.
 */
function getIssuanceRequest(int $issuanceId, ?string $expectedStatus = null): ?array
{
    $db = getDB();

    if ($expectedStatus !== null) {
        $stmt = $db->prepare(
            "SELECT * FROM near_credential_issuances
             WHERE id = ? AND status = ?"
        );
        $stmt->execute([$issuanceId, $expectedStatus]);
    } else {
        $stmt = $db->prepare(
            "SELECT * FROM near_credential_issuances WHERE id = ?"
        );
        $stmt->execute([$issuanceId]);
    }

    return $stmt->fetch() ?: null;
}

/**
 * Get all pending (REQUESTED or UNCERTAIN) issuance requests for issuer review.
 */
function getPendingIssuances(): array
{
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT n.*, c.certificate_code, c.achievement_title, u.username
         FROM near_credential_issuances n
         INNER JOIN certificates c ON c.id = n.local_cert_id
         INNER JOIN users u ON u.id = n.user_id
         WHERE n.status IN ('REQUESTED', 'UNCERTAIN')
         ORDER BY n.created_at ASC"
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get issuance history for a specific certificate.
 */
function getIssuanceHistory(int $localCertId): array
{
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT * FROM near_credential_issuances
         WHERE local_cert_id = ?
         ORDER BY created_at DESC"
    );
    $stmt->execute([$localCertId]);
    return $stmt->fetchAll();
}

/**
 * Mark an issuance request as UNCERTAIN (transaction hash submitted, outcome pending).
 */
function markUncertain(int $issuanceId, string $transactionHash): bool
{
    $db = getDB();
    $stmt = $db->prepare(
        "UPDATE near_credential_issuances
         SET status = 'UNCERTAIN',
             transaction_hash = ?,
             submitted_at = NOW(),
             attempt_count = attempt_count + 1,
             updated_at = NOW()
         WHERE id = ? AND status = 'REQUESTED'"
    );
    return (bool)$stmt->execute([$transactionHash, $issuanceId]);
}

/**
 * Mark an issuance request as VERIFIED (transaction confirmed on-chain).
 */
function markVerified(
    int $issuanceId,
    string $transactionHash,
    string $nearCredentialId,
    ?string $blockHash = null,
    ?int $blockHeight = null
): bool {
    $db = getDB();
    $stmt = $db->prepare(
        "UPDATE near_credential_issuances
         SET status = 'VERIFIED',
             transaction_hash = ?,
             near_credential_id = ?,
             block_hash = ?,
             block_height = ?,
             updated_at = NOW()
         WHERE id = ? AND status IN ('UNCERTAIN', 'REQUESTED')"
    );
    return (bool)$stmt->execute([
        $transactionHash,
        $nearCredentialId,
        $blockHash,
        $blockHeight,
        $issuanceId,
    ]);
}

/**
 * Mark an issuance request as FAILED.
 */
function markFailed(int $issuanceId, string $errorMessage): bool
{
    $db = getDB();
    $stmt = $db->prepare(
        "UPDATE near_credential_issuances
         SET status = 'FAILED',
             error_message = ?,
             updated_at = NOW()
         WHERE id = ? AND status IN ('REQUESTED', 'UNCERTAIN')"
    );
    return (bool)$stmt->execute([$errorMessage, $issuanceId]);
}

/**
 * Expiry threshold handling.
 *
 * REQUESTED and UNCERTAIN records remain blocking indefinitely.
 * An expired expires_at timestamp is an attention threshold only — it does
 * NOT make the request retryable, and no automatic status transition to
 * EXPIRED occurs. A transaction may already have been broadcast even if
 * the issuer has not submitted its hash; automatically retrying could
 * result in duplicate on-chain credentials.
 *
 * Issuer reconciliation is required before any REQUESTED or UNCERTAIN
 * record can be safely resolved. This function is intentionally not
 * called anywhere in the codebase; no automatic expiry process exists.
 */

/**
 * NEAR RPC helper: query EXPERIMENTAL_tx_status.
 *
 * Returns the raw decoded JSON result, or null on error/timeout.
 */
function rpcTxStatus(string $transactionHash, string $signerAccountId): ?array
{
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'EXPERIMENTAL_tx_status',
        'params' => [$transactionHash, $signerAccountId],
    ]);

    $ch = curl_init(nearRpcUrl());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!$data || isset($data['error'])) {
        return null;
    }

    return $data['result'] ?? null;
}

/**
 * NEAR RPC helper: query get_credential (read-only view call).
 *
 * Returns the decoded credential array, or null if not found/error.
 */
function rpcGetCredential(string $contractId, string $credentialId): ?array
{
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'query',
        'params' => [
            'request_type' => 'call_function',
            'finality' => 'final',
            'account_id' => $contractId,
            'method_name' => 'get_credential',
            'args_base64' => base64_encode(json_encode(['credential_id' => $credentialId])),
        ],
    ]);

    $ch = curl_init(nearRpcUrl());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!$data || isset($data['error']) || !isset($data['result'])) {
        return null;
    }

    $resultBytes = $data['result']['result'] ?? null;
    if (!is_array($resultBytes)) {
        return null;
    }

    $decodedStr = implode('', array_map('chr', $resultBytes));
    $decoded = json_decode($decodedStr, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Verify a submitted NEAR transaction independently.
 *
 * Checks:
 *   - Transaction exists and belongs to the expected signer/receiver
 *   - Action is FunctionCall to issue_credential
 *   - Arguments match the pending record (learner_id, course_id, metadata_hash)
 *   - Execution status is SuccessValue (not Failure)
 *   - Returned credential ID is valid JSON string
 *   - On-chain get_credential cross-check matches all fields
 *
 * Returns ['verified' => true, 'credential_id' => '...'] on success,
 * or ['verified' => false, 'error' => '...'] on failure.
 */
function verifyIssuanceTransaction(
    string $transactionHash,
    array $pendingRecord
): array {
    $contractId = getNearContractId();
    if ($contractId === '') {
        return ['verified' => false, 'error' => 'NEAR_CONTRACT_ID not configured'];
    }

    // Step 1: Query transaction status via RPC
    $txResult = rpcTxStatus($transactionHash, NEAR_ISSUER_ACCOUNT);
    if ($txResult === null) {
        // Not a definitive failure: the node may be down or the tx may not have
        // propagated yet. Callers must NOT mark the request FAILED on this.
        return ['verified' => false, 'retryable' => true, 'error' => 'RPC unavailable or transaction not yet visible; try again shortly'];
    }

    // Step 2: Verify signer and receiver
    $transaction = $txResult['transaction'] ?? null;
    if (!$transaction) {
        return ['verified' => false, 'error' => 'Transaction object missing from RPC response'];
    }

    if (($transaction['signer_id'] ?? '') !== NEAR_ISSUER_ACCOUNT) {
        return ['verified' => false, 'error' => 'Transaction signer mismatch'];
    }

    if (($transaction['receiver_id'] ?? '') !== $contractId) {
        return ['verified' => false, 'error' => 'Transaction receiver mismatch'];
    }

    // Step 3: Verify FunctionCall action
    $actions = $transaction['actions'] ?? [];
    $functionCall = null;
    foreach ($actions as $action) {
        if (isset($action['FunctionCall'])) {
            $functionCall = $action['FunctionCall'];
            break;
        }
    }

    if (!$functionCall) {
        return ['verified' => false, 'error' => 'No FunctionCall action found'];
    }

    if (($functionCall['method_name'] ?? '') !== 'issue_credential') {
        return ['verified' => false, 'error' => 'Unexpected method name'];
    }

    // Step 4: Verify arguments match pending record
    $argsBase64 = $functionCall['args'] ?? '';
    $argsJson = base64_decode($argsBase64, true);
    if ($argsJson === false) {
        return ['verified' => false, 'error' => 'Failed to decode transaction arguments'];
    }

    $args = json_decode($argsJson, true);
    if (!is_array($args)) {
        return ['verified' => false, 'error' => 'Invalid argument JSON'];
    }

    if (($args['learner_id'] ?? '') !== ($pendingRecord['near_account_id'] ?? '')) {
        return ['verified' => false, 'error' => 'Learner ID mismatch'];
    }

    if (($args['course_id'] ?? '') !== ($pendingRecord['course_id'] ?? '')) {
        return ['verified' => false, 'error' => 'Course ID mismatch'];
    }

    $pendingMetadataHash = $pendingRecord['metadata_hash'] ?? null;
    $argsMetadataHash = $args['metadata_hash'] ?? null;
    if ($pendingMetadataHash !== $argsMetadataHash) {
        return ['verified' => false, 'error' => 'Metadata hash mismatch'];
    }

    // Step 5: Verify execution status (top-level result.status)
    $status = $txResult['status'] ?? null;
    if (!$status || !isset($status['SuccessValue'])) {
        // Check receipts_outcome as cross-reference, but top-level is authoritative
        $receipts = $txResult['receipts_outcome'] ?? [];
        $hasSuccess = false;
        foreach ($receipts as $receipt) {
            $outcome = $receipt['outcome'] ?? null;
            if ($outcome && isset($outcome['status']['SuccessValue'])) {
                $hasSuccess = true;
                break;
            }
        }
        if (!$hasSuccess) {
            return ['verified' => false, 'error' => 'Transaction execution did not succeed'];
        }
    }

    // Step 6: Decode SuccessValue (base64 → JSON string, NOT Borsh)
    $successValue = $status['SuccessValue'] ?? null;
    if ($successValue === null) {
        // Try receipts_outcome as fallback
        $receipts = $txResult['receipts_outcome'] ?? [];
        foreach ($receipts as $receipt) {
            $outcome = $receipt['outcome'] ?? null;
            if ($outcome && isset($outcome['status']['SuccessValue'])) {
                $successValue = $outcome['status']['SuccessValue'];
                break;
            }
        }
    }

    if ($successValue === null) {
        return ['verified' => false, 'error' => 'No SuccessValue in execution result'];
    }

    $successBytes = base64_decode($successValue, true);
    if ($successBytes === false) {
        return ['verified' => false, 'error' => 'Failed to base64-decode SuccessValue'];
    }

    $successJson = json_decode($successBytes, true);
    if (!is_string($successJson)) {
        return ['verified' => false, 'error' => 'SuccessValue is not a JSON string'];
    }

    $nearCredentialId = $successJson;

    // Step 7: Cross-check with get_credential on-chain view call
    $onChain = rpcGetCredential($contractId, $nearCredentialId);
    if ($onChain === null) {
        return ['verified' => false, 'retryable' => true, 'error' => 'Credential not yet readable on-chain; try again shortly'];
    }

    if (($onChain['learner_id'] ?? '') !== ($pendingRecord['near_account_id'] ?? '')) {
        return ['verified' => false, 'error' => 'On-chain learner_id mismatch'];
    }

    if (($onChain['course_id'] ?? '') !== ($pendingRecord['course_id'] ?? '')) {
        return ['verified' => false, 'error' => 'On-chain course_id mismatch'];
    }

    if (($onChain['issuer_id'] ?? '') !== $contractId) {
        return ['verified' => false, 'error' => 'On-chain issuer_id mismatch'];
    }

    if (isset($onChain['metadata_hash']) && ($onChain['metadata_hash'] ?? null) !== ($pendingRecord['metadata_hash'] ?? null)) {
        return ['verified' => false, 'error' => 'On-chain metadata_hash mismatch'];
    }

    if (!empty($onChain['revoked'])) {
        return ['verified' => false, 'error' => 'Credential is revoked on-chain'];
    }

    return ['verified' => true, 'credential_id' => $nearCredentialId];
}

/**
 * Check if a transaction hash has already been recorded in any issuance.
 */
function transactionHashExists(string $transactionHash): bool
{
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT id FROM near_credential_issuances WHERE transaction_hash = ? LIMIT 1"
    );
    $stmt->execute([$transactionHash]);
    return (bool)$stmt->fetch();
}

/**
 * True if the hash is recorded on a DIFFERENT issuance request. Lets the
 * issuer re-submit the same hash for the same UNCERTAIN request (retry after
 * an RPC delay) without allowing one transaction to satisfy two requests.
 */
function transactionHashUsedByOther(string $transactionHash, int $issuanceId): bool
{
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT id FROM near_credential_issuances WHERE transaction_hash = ? AND id <> ? LIMIT 1"
    );
    $stmt->execute([$transactionHash, $issuanceId]);
    return (bool)$stmt->fetch();
}

/**
 * Check if a near_credential_id has already been recorded.
 */
function nearCredentialIdExists(string $credentialId): bool
{
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT id FROM near_credential_issuances WHERE near_credential_id = ? LIMIT 1"
    );
    $stmt->execute([$credentialId]);
    return (bool)$stmt->fetch();
}

/**
 * Generate the PowerShell command template for the issuer.
 *
 * Does NOT execute anything. Returns the command string and the JSON argument
 * content that should be written to a file.
 */
function generateIssuanceCommand(
    string $learnerId,
    string $courseId,
    ?string $metadataHash,
    string $argsFilePath
): array {
    $args = [
        'learner_id' => $learnerId,
        'course_id' => $courseId,
        'metadata_hash' => $metadataHash,
    ];
    $json = json_encode($args, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    $command = sprintf(
        '"C:\\Users\\maxin\\.cargo\\bin\\near.exe" contract call-function as-transaction %s issue_credential file-args %s prepaid-gas 300000000000000 attached-deposit 0 sign-as %s network-config testnet-fastnear sign-with-keychain send',
        NEAR_ISSUER_ACCOUNT,
        $argsFilePath,
        NEAR_ISSUER_ACCOUNT
    );

    return [
        'command' => $command,
        'args_json' => $json,
        'gas_estimate' => 300000000000000,
        'gas_note' => 'Initial estimate pending verification with a real testnet issuance',
    ];
}


// ---------------------------------------------------------------------------
// Certificate -> NEAR issuance queue integration
// ---------------------------------------------------------------------------

/**
 * NEAR issuance is only active when the contract and the issuer admin are
 * configured (same condition certificates.php uses to show its NEAR UI).
 */
function isNearIssuanceEnabled(): bool
{
    return getNearContractId() !== '' && getIssuerAdminId() !== null;
}

/**
 * Deterministic SHA-256 over the immutable identity of a local certificate.
 * Stored on the issuance row, sent on-chain as metadata_hash, and recomputed
 * by the verification page. Mutable fields (download counts, revocation,
 * template) are deliberately excluded.
 */
function computeCertificateMetadataHash(array $cert): string
{
    $canonical = [
        'certificate_code'  => (string)$cert['certificate_code'],
        'user_id'           => (int)$cert['user_id'],
        'issuable_type'     => (string)$cert['issuable_type'],
        'issuable_id'       => (int)$cert['issuable_id'],
        'achievement_title' => (string)($cert['achievement_title'] ?? ''),
        'issued_at'         => (string)$cert['issued_at'],
    ];
    return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Decide whether a local certificate may be queued for NEAR issuance.
 *
 * Returns ['eligible' => bool, 'reason' => string, 'cert' => ?array,
 *          'near_account_id' => ?string]. Reasons are stable codes.
 */
function evaluateNearEligibility(int $certId): array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM certificates WHERE id = ?");
    $stmt->execute([$certId]);
    $cert = $stmt->fetch();

    if (!$cert) {
        return ['eligible' => false, 'reason' => 'certificate_not_found', 'cert' => null, 'near_account_id' => null];
    }
    $fail = static fn(string $r, ?string $acct = null) =>
        ['eligible' => false, 'reason' => $r, 'cert' => $cert, 'near_account_id' => $acct];

    if ((int)$cert['is_revoked'] === 1) {
        return $fail('revoked');
    }
    if (!empty($cert['expires_at']) && strtotime((string)$cert['expires_at']) < time()) {
        return $fail('expired');
    }
    if (trim((string)($cert['achievement_title'] ?? '')) === '' || trim((string)$cert['certificate_code']) === '') {
        return $fail('incomplete_certificate');
    }

    $u = $db->prepare("SELECT id FROM users WHERE id = ?");
    $u->execute([(int)$cert['user_id']]);
    if (!$u->fetch()) {
        return $fail('learner_not_found');
    }

    $w = $db->prepare(
        "SELECT near_account_id FROM user_wallets WHERE user_id = ?
         ORDER BY is_primary DESC, created_at ASC LIMIT 1"
    );
    $w->execute([(int)$cert['user_id']]);
    $acct = $w->fetchColumn();
    if (!$acct) {
        return $fail('no_near_wallet');
    }

    $b = $db->prepare(
        "SELECT status FROM near_credential_issuances
         WHERE local_cert_id = ? AND status IN ('REQUESTED','UNCERTAIN','VERIFIED')
         ORDER BY FIELD(status,'VERIFIED','UNCERTAIN','REQUESTED') LIMIT 1"
    );
    $b->execute([$certId]);
    $existing = $b->fetchColumn();
    if ($existing === 'VERIFIED') {
        return $fail('already_on_chain', (string)$acct);
    }
    if ($existing) {
        return $fail('already_queued', (string)$acct);
    }

    return ['eligible' => true, 'reason' => 'ok', 'cert' => $cert, 'near_account_id' => (string)$acct];
}

/**
 * Queue one local certificate for NEAR issuance. Idempotent and safe to call
 * repeatedly or concurrently.
 *
 * Returns ['status' => 'queued'|'skipped'|'error', 'reason' => string,
 *          'issuance_id' => ?int]. 'skipped' is a normal outcome (no wallet,
 * already queued, ...). 'error' means a database/system failure, which is
 * also written to the PHP error log.
 */
function queueCertificateForNear(int $certId, string $source = 'auto'): array
{
    if (!isNearIssuanceEnabled()) {
        return ['status' => 'skipped', 'reason' => 'near_not_configured', 'issuance_id' => null];
    }

    try {
        $elig = evaluateNearEligibility($certId);
        if (!$elig['eligible']) {
            return ['status' => 'skipped', 'reason' => $elig['reason'], 'issuance_id' => null];
        }

        $cert = $elig['cert'];
        $courseId = mb_substr(trim((string)$cert['achievement_title']), 0, 255);
        $id = createIssuanceRequest(
            $certId,
            (int)$cert['user_id'],
            $elig['near_account_id'],
            $courseId,
            computeCertificateMetadataHash($cert)
        );

        if ($id === null) {
            // Lost a race with a concurrent call, or the cert changed state.
            return ['status' => 'skipped', 'reason' => 'already_queued_or_ineligible', 'issuance_id' => null];
        }

        return ['status' => 'queued', 'reason' => 'ok', 'issuance_id' => $id];
    } catch (\Throwable $e) {
        error_log(sprintf(
            '[web3edu-near] queueCertificateForNear failed (source=%s cert=%d): %s',
            $source, $certId, $e->getMessage()
        ));
        return ['status' => 'error', 'reason' => 'database_error', 'issuance_id' => null];
    }
}

/**
 * Existing certificates that have no active or confirmed issuance row, each
 * annotated with eligibility. Used by the admin-controlled backfill.
 */
function getBackfillCandidates(int $limit = 200): array
{
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT c.id FROM certificates c
         WHERE NOT EXISTS (
             SELECT 1 FROM near_credential_issuances n
             WHERE n.local_cert_id = c.id AND n.status IN ('REQUESTED','UNCERTAIN','VERIFIED')
         )
         ORDER BY c.issued_at ASC LIMIT " . (int)$limit
    );
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $certId) {
        $e = evaluateNearEligibility((int)$certId);
        if ($e['cert'] === null) { continue; }
        $uname = $db->prepare("SELECT username FROM users WHERE id = ?");
        $uname->execute([(int)$e['cert']['user_id']]);
        $rows[] = [
            'id'                => (int)$e['cert']['id'],
            'certificate_code'  => $e['cert']['certificate_code'],
            'achievement_title' => $e['cert']['achievement_title'],
            'username'          => $uname->fetchColumn() ?: 'Unknown',
            'issued_at'         => $e['cert']['issued_at'],
            'eligible'          => $e['eligible'],
            'reason'            => $e['reason'],
            'near_account_id'   => $e['near_account_id'],
        ];
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// Public verification: local certificate  ->  issuance record  ->  on-chain credential
// ---------------------------------------------------------------------------

/**
 * Read one credential from the contract and report WHY it could not be read:
 *   ['state' => 'ok',        'credential' => array]
 *   ['state' => 'not_found']   contract answered, but no such credential
 *   ['state' => 'rpc_error']   network/RPC problem - says nothing about the credential
 */
function fetchOnChainCredential(string $contractId, string $credentialId): array
{
    $payload = json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'query',
        'params' => [
            'request_type' => 'call_function', 'finality' => 'final',
            'account_id' => $contractId, 'method_name' => 'get_credential',
            'args_base64' => base64_encode(json_encode(['credential_id' => $credentialId])),
        ],
    ]);
    $ch = curl_init(nearRpcUrl());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $http !== 200) {
        return ['state' => 'rpc_error'];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || isset($data['error']) || !isset($data['result']) || !is_array($data['result'])) {
        return ['state' => 'rpc_error'];
    }
    $bytes = $data['result']['result'] ?? null;
    if (!is_array($bytes)) {
        // View call that panicked inside the contract, e.g. "credential not found".
        $msg = (string)($data['result']['error'] ?? '');
        return preg_match('/not\s*found|no such|does not exist/i', $msg)
            ? ['state' => 'not_found'] : ['state' => 'rpc_error'];
    }
    $decoded = json_decode(implode('', array_map('chr', $bytes)), true);
    return is_array($decoded) ? ['state' => 'ok', 'credential' => $decoded] : ['state' => 'not_found'];
}

/**
 * Full public verification of an identifier typed on verify-near.php.
 * The identifier is normally a Web3Edu certificate code (WEB3-YYYY-NNNNNN); a NEAR
 * credential ID (e.g. "cred-0") is also accepted and resolved to its local certificate.
 *
 * The database is used ONLY to locate the certificate, its issuance record and the
 * expected values. A VERIFIED status (or an "On-chain" badge) is never treated as
 * proof: the credential is always re-read from the contract and compared.
 *
 * Returns:
 *   'lookup'  certificate_code | credential_id | none
 *   'local'   null | [id, certificate_code, username, achievement_title, issued_at, status valid|revoked|expired]
 *   'near'    [status, message, credential_id, on_chain (array|null), checks (name => bool), failed (list)]
 *             status: verified | not_issued | pending | not_configured | unavailable |
 *                     not_found_on_chain | revoked_on_chain | mismatch | on_chain_only
 *   'overall' verified | local_only | near_failed | local_invalid | on_chain_only | not_found
 */
function verifyCertificateOnNear(string $input): array
{
    $input = trim($input);
    $out = ['input' => $input, 'lookup' => 'none', 'local' => null, 'overall' => 'not_found',
            'near' => ['status' => 'not_issued', 'message' => '', 'credential_id' => null,
                       'on_chain' => null, 'checks' => [], 'failed' => []]];
    if ($input === '' || strlen($input) > 128) {
        return $out;
    }
    $db = getDB();

    // 1) Local certificate by its real certificate code.
    $st = $db->prepare(
        "SELECT c.*, u.username FROM certificates c
         INNER JOIN users u ON u.id = c.user_id
         WHERE c.certificate_code = ? LIMIT 1"
    );
    $st->execute([$input]);
    $cert = $st->fetch() ?: null;
    $issuance = null;

    if ($cert) {
        $out['lookup'] = 'certificate_code';
    } else {
        // 2) Fallback: the identifier may be a NEAR credential ID recorded by an issuance.
        $st = $db->prepare(
            "SELECT * FROM near_credential_issuances
             WHERE near_credential_id = ? AND status = 'VERIFIED' ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$input]);
        $issuance = $st->fetch() ?: null;
        if ($issuance) {
            $st = $db->prepare(
                "SELECT c.*, u.username FROM certificates c
                 INNER JOIN users u ON u.id = c.user_id WHERE c.id = ?"
            );
            $st->execute([(int)$issuance['local_cert_id']]);
            $cert = $st->fetch() ?: null;
            $out['lookup'] = $cert ? 'credential_id' : 'none';
        }
    }

    $contractId = getNearContractId();

    if (!$cert) {
        // Unknown locally. Only an on-chain-only view is possible for a credential-ID-shaped input.
        if ($contractId !== '' && preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $input)) {
            $r = fetchOnChainCredential($contractId, $input);
            if ($r['state'] === 'ok') {
                $out['lookup'] = 'credential_id';
                $out['near'] = ['status' => 'on_chain_only', 'credential_id' => $input, 'on_chain' => $r['credential'],
                    'checks' => [], 'failed' => [],
                    'message' => 'Credential exists on NEAR but has no matching Web3Edu certificate record.'];
                if (!empty($r['credential']['revoked'])) {
                    $out['near']['status'] = 'revoked_on_chain';
                    $out['near']['message'] = 'This credential has been revoked on NEAR.';
                }
                $out['overall'] = 'on_chain_only';
            } elseif ($r['state'] === 'rpc_error') {
                $out['near']['status'] = 'unavailable';
                $out['near']['message'] = 'The NEAR network could not be reached. Try again shortly.';
            }
        }
        return $out;
    }

    // Local certificate state (independent of NEAR).
    $localStatus = 'valid';
    if ((int)$cert['is_revoked'] === 1) {
        $localStatus = 'revoked';
    } elseif (!empty($cert['expires_at']) && strtotime((string)$cert['expires_at']) < time()) {
        $localStatus = 'expired';
    }
    $out['local'] = [
        'id' => (int)$cert['id'], 'certificate_code' => $cert['certificate_code'],
        'username' => $cert['username'], 'achievement_title' => $cert['achievement_title'],
        'issued_at' => $cert['issued_at'], 'status' => $localStatus,
    ];

    // Issuance record for this certificate (latest VERIFIED one that names a credential).
    if (!$issuance || (int)$issuance['local_cert_id'] !== (int)$cert['id']) {
        $st = $db->prepare(
            "SELECT * FROM near_credential_issuances
             WHERE local_cert_id = ? AND status = 'VERIFIED' AND near_credential_id IS NOT NULL
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([(int)$cert['id']]);
        $issuance = $st->fetch() ?: null;
    }

    $near = &$out['near'];
    if (!$issuance) {
        $st = $db->prepare(
            "SELECT COUNT(*) FROM near_credential_issuances
             WHERE local_cert_id = ? AND status IN ('REQUESTED','UNCERTAIN')"
        );
        $st->execute([(int)$cert['id']]);
        if ((int)$st->fetchColumn() > 0) {
            $near['status'] = 'pending';
            $near['message'] = 'A NEAR credential has been requested but is not confirmed on-chain yet.';
        } else {
            $near['status'] = 'not_issued';
            $near['message'] = 'No NEAR credential has been issued for this certificate.';
        }
    } elseif ($contractId === '') {
        $near['status'] = 'not_configured';
        $near['message'] = 'NEAR verification is not configured on this server.';
    } else {
        $credId = (string)$issuance['near_credential_id'];
        $near['credential_id'] = $credId;
        $r = fetchOnChainCredential($contractId, $credId);

        if ($r['state'] === 'rpc_error') {
            $near['status'] = 'unavailable';
            $near['message'] = 'The NEAR network could not be reached, so the on-chain credential could not be checked. Try again shortly.';
        } elseif ($r['state'] === 'not_found') {
            $near['status'] = 'not_found_on_chain';
            $near['message'] = 'The credential recorded for this certificate was not found on the NEAR contract.';
        } else {
            $c = $r['credential'];
            $near['on_chain'] = $c;
            $expectedHash = computeCertificateMetadataHash($cert);
            $title = mb_substr(trim((string)$cert['achievement_title']), 0, 255);
            $onHash = isset($c['metadata_hash']) && is_string($c['metadata_hash']) ? $c['metadata_hash'] : '';
            $checks = [
                'learner'       => ($c['learner_id'] ?? null) === $issuance['near_account_id'],
                'course'        => ($c['course_id'] ?? null) === $issuance['course_id'] && $issuance['course_id'] === $title,
                'metadata_hash' => $onHash !== '' && hash_equals($expectedHash, $onHash)
                                   && (empty($issuance['metadata_hash']) || hash_equals((string)$issuance['metadata_hash'], $onHash)),
                'issuer'        => ($c['issuer_id'] ?? null) === NEAR_ISSUER_ACCOUNT || ($c['issuer_id'] ?? null) === $contractId,
            ];
            $near['checks'] = $checks;
            $near['failed'] = array_keys(array_filter($checks, static fn($ok) => !$ok));
            if (!empty($c['revoked'])) {
                $near['status'] = 'revoked_on_chain';
                $near['message'] = 'This credential has been revoked on NEAR.';
            } elseif ($near['failed']) {
                $near['status'] = 'mismatch';
                $near['message'] = 'The on-chain credential does not match this certificate (' . implode(', ', $near['failed']) . ').';
            } else {
                $near['status'] = 'verified';
                $near['message'] = 'Learner, course and metadata hash on NEAR match this certificate.';
            }
        }
    }

    $nearStatus = $near['status'];
    unset($near);
    if ($localStatus !== 'valid') {
        $out['overall'] = 'local_invalid';
    } elseif ($nearStatus === 'verified') {
        $out['overall'] = 'verified';
    } elseif (in_array($nearStatus, ['mismatch', 'revoked_on_chain', 'not_found_on_chain'], true)) {
        $out['overall'] = 'near_failed';
    } else {
        $out['overall'] = 'local_only';
    }
    return $out;
}
