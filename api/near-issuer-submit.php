<?php
/**
 * Web3Edu NEAR — Issuer Submit Transaction Hash
 *
 * Issuer-only endpoint: submits the near-cli-rs transaction hash for
 * independent PHP RPC verification before recording success.
 *
 * POST /api/near-issuer-submit.php
 *   Body: {"request_id": int, "transaction_hash": string}
 *   Returns: JSON with verification result
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../includes/near-issuance.php';

use function Web3Edu\Near\Issuance\getIssuanceRequest;
use function Web3Edu\Near\Issuance\markUncertain;
use function Web3Edu\Near\Issuance\isIssuerAdmin;
use function Web3Edu\Near\Issuance\verifyIssuanceTransaction;
use function Web3Edu\Near\Issuance\markVerified;
use function Web3Edu\Near\Issuance\markFailed;
use function Web3Edu\Near\Issuance\transactionHashUsedByOther;
use function Web3Edu\Near\Issuance\nearCredentialIdExists;
use function Web3Edu\Near\Issuance\rpcTxStatus;

header('Content-Type: application/json');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin authentication required']);
    exit;
}

if (!isIssuerAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Issuer access required']);
    exit;
}

// State-changing admin action: enforce the same CSRF token the admin page sends
// (X-CSRF-Token header) — previously sent but never verified here.
requireCsrfAjax();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$requestId = $input['request_id'] ?? null;
$transactionHash = trim($input['transaction_hash'] ?? '');

if (!$requestId || !is_numeric($requestId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request ID']);
    exit;
}

if (!$transactionHash) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Transaction hash is required']);
    exit;
}

$requestId = (int)$requestId;

// A hash may only ever satisfy ONE request. Re-submitting the same hash for the
// same UNCERTAIN request (retry after an RPC delay) is allowed.
if (transactionHashUsedByOther($transactionHash, $requestId)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Transaction hash already recorded']);
    exit;
}

// Get the pending request (must be REQUESTED or UNCERTAIN)
$request = getIssuanceRequest($requestId);
if (!$request) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Issuance request not found']);
    exit;
}

if (!in_array($request['status'], ['REQUESTED', 'UNCERTAIN'], true)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Request is not in a submittable state']);
    exit;
}

// An UNCERTAIN request already holds a hash; it may only be retried with that
// same hash. Switching hashes would orphan a possibly-successful transaction.
if ($request['status'] === 'UNCERTAIN') {
    if (($request['transaction_hash'] ?? '') !== $transactionHash) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This request already has a different transaction hash recorded']);
        exit;
    }
} elseif (!markUncertain($requestId, $transactionHash)) {
    // Mark as UNCERTAIN with the submitted transaction hash
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Request state changed concurrently']);
    exit;
}

// Independently verify the transaction via NEAR RPC
$verification = verifyIssuanceTransaction($transactionHash, $request);

if ($verification['verified']) {
    // Mark as VERIFIED with on-chain credential data
    $blockHeight = null;
    $blockHash = null;

    // Try to extract block info from RPC if available
    $txResult = \Web3Edu\Near\Issuance\rpcTxStatus($transactionHash, \Web3Edu\Near\Issuance\NEAR_ISSUER_ACCOUNT);
    if ($txResult) {
        $blockHash = $txResult['block_hash'] ?? null;
        // block_height may be in transaction_outcome or receipts_outcome
    }

    markVerified(
        $requestId,
        $transactionHash,
        $verification['credential_id'],
        $blockHash,
        $blockHeight
    );

    echo json_encode([
        'success' => true,
        'status' => 'VERIFIED',
        'request_id' => $requestId,
        'near_credential_id' => $verification['credential_id'],
        'transaction_hash' => $transactionHash,
        'message' => 'Transaction verified and recorded on-chain',
    ]);
} elseif (!empty($verification['retryable'])) {
    // RPC outage / tx not yet visible: NOT a failure. Leave the request
    // UNCERTAIN so the same hash can be re-submitted; never mark VERIFIED
    // without confirmation and never FAILED on a transient error.
    echo json_encode([
        'success' => false,
        'status' => 'UNCERTAIN',
        'request_id' => $requestId,
        'transaction_hash' => $transactionHash,
        'retryable' => true,
        'error' => $verification['error'],
    ]);
} else {
    // Definitive verification failure
    if (!markFailed($requestId, $verification['error'] ?? 'Verification failed')) {
        error_log("[web3edu-near] markFailed did not update request {$requestId}");
    }

    echo json_encode([
        'success' => false,
        'status' => 'FAILED',
        'request_id' => $requestId,
        'transaction_hash' => $transactionHash,
        'error' => $verification['error'],
    ]);
}
