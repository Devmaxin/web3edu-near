<?php
/**
 * Web3Edu NEAR — Issuer Pending Requests
 *
 * Issuer-only endpoint: lists all REQUESTED and UNCERTAIN issuance requests
 * that need the issuer's local near-cli-rs signing.
 *
 * GET /api/near-issuer-pending.php
 *   Requires: admin authentication + designated issuer ID match
 *   Returns: JSON array of pending requests with public data only
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../includes/near-issuance.php';

use function Web3Edu\Near\Issuance\getPendingIssuances;
use function Web3Edu\Near\Issuance\isIssuerAdmin;

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

$requests = getPendingIssuances();

echo json_encode([
    'success' => true,
    'contract_id' => \Web3Edu\Near\Issuance\getNearContractId(),
    'network' => 'testnet-fastnear',
    'requests' => array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'local_certificate_id' => (int)$r['local_cert_id'],
            'certificate_code' => $r['certificate_code'],
            'achievement_title' => $r['achievement_title'],
            'learner_username' => $r['username'],
            'near_account_id' => $r['near_account_id'],
            'course_id' => $r['course_id'],
            'metadata_hash' => $r['metadata_hash'],
            'status' => $r['status'],
            'attempt_count' => (int)$r['attempt_count'],
            'created_at' => $r['created_at'],
            'expires_at' => $r['expires_at'],
            'submitted_at' => $r['submitted_at'],
            'transaction_hash' => $r['transaction_hash'],
            'near_credential_id' => $r['near_credential_id'],
            'error_message' => $r['error_message'],
        ];
    }, $requests),
]);
