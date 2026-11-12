<?php
/**
 * Web3Edu NEAR — Request Credential Issuance
 *
 * Learner endpoint: requests a pending NEAR issuance for a local certificate.
 * The actual NEAR transaction is signed locally by the issuer using near-cli-rs.
 * This endpoint only creates a pending request record and returns public
 * transaction arguments for the issuer to execute locally.
 *
 * POST /api/near-request-issuance.php
 *   Body: {"certificate_id": int}
 *   Returns: JSON with public transaction arguments and request ID
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/near-issuance.php';

use function Web3Edu\Near\Issuance\queueCertificateForNear;
use function Web3Edu\Near\Issuance\getIssuerAdminId;
use function Web3Edu\Near\Issuance\generateIssuanceCommand;

// certificates.php submits a normal HTML form (urlencoded), while API callers
// send JSON. Support both; form posts get a redirect back with a flash message.
$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody ?: '', true);
$isForm = !is_array($input);
if ($isForm) {
    $input = $_POST;
}

/** Emit the result as JSON (API) or flash + redirect (HTML form). */
$respond = function (int $http, array $payload) use ($isForm): void {
    if ($isForm) {
        $_SESSION['near_flash'] = [
            'type'    => !empty($payload['success']) ? 'success' : 'error',
            'message' => $payload['success'] ?? false
                ? 'NEAR issuance requested. The issuer will sign it shortly.'
                : ($payload['error'] ?? 'Request failed.'),
        ];
        header('Location: ' . SITE_URL . '/certificates.php');
        exit;
    }
    header('Content-Type: application/json');
    http_response_code($http);
    echo json_encode($payload);
    exit;
};

requireLogin();
requireCsrfAjax();

$issuerAdminId = getIssuerAdminId();
if ($issuerAdminId === null) {
    $respond(503, ['success' => false, 'error' => 'NEAR issuer not configured']);
}

if (!$input) {
    $respond(400, ['success' => false, 'error' => 'Missing request body']);
}

$certificateId = $input['certificate_id'] ?? null;
if (!$certificateId || !is_numeric($certificateId)) {
    $respond(400, ['success' => false, 'error' => 'Invalid certificate ID']);
}

$certificateId = (int)$certificateId;
$userId = (int)$_SESSION['user_id'];

$db = getDB();

// Ownership is checked here (a learner may only request their own certificate);
// every other eligibility rule lives in evaluateNearEligibility().
$certStmt = $db->prepare("SELECT id, user_id FROM certificates WHERE id = ?");
$certStmt->execute([$certificateId]);
$cert = $certStmt->fetch();

if (!$cert) {
    $respond(404, ['success' => false, 'error' => 'Certificate not found']);
}
if ((int)$cert['user_id'] !== $userId) {
    $respond(403, ['success' => false, 'error' => 'Not authorized for this certificate']);
}

$result = queueCertificateForNear($certificateId, 'learner_request');

if ($result['status'] !== 'queued') {
    $messages = [
        'revoked'                       => [409, 'Certificate is revoked'],
        'expired'                       => [409, 'Certificate has expired'],
        'incomplete_certificate'        => [422, 'Certificate is missing required details'],
        'no_near_wallet'                => [400, 'No NEAR wallet linked to your account'],
        'already_queued'                => [409, 'An issuance request is already pending for this certificate'],
        'already_queued_or_ineligible'  => [409, 'An issuance request is already pending for this certificate'],
        'already_on_chain'              => [409, 'This certificate is already issued on-chain'],
        'near_not_configured'           => [503, 'NEAR issuance is not configured'],
        'database_error'                => [500, 'Failed to create issuance request'],
    ];
    [$http, $msg] = $messages[$result['reason']] ?? [422, 'Certificate is not eligible for NEAR issuance'];
    $respond($http, ['success' => false, 'error' => $msg, 'reason' => $result['reason']]);
}

$issuanceId = (int)$result['issuance_id'];

$requestStmt = $db->prepare("SELECT * FROM near_credential_issuances WHERE id = ?");
$requestStmt->execute([$issuanceId]);
$request = $requestStmt->fetch();

$certRowStmt = $db->prepare("SELECT certificate_code FROM certificates WHERE id = ?");
$certRowStmt->execute([$certificateId]);

// Generate the issuer command info (public data only)
$argsFilePath = sprintf(
    'C:\\Users\\maxin\\AppData\\Local\\Temp\\opencode\\near_issuance_args_%d.json',
    $issuanceId
);
$commandInfo = generateIssuanceCommand(
    $request['near_account_id'],
    $request['course_id'],
    $request['metadata_hash'],
    $argsFilePath
);

$respond(200, [
    'success' => true,
    'request_id' => $issuanceId,
    'local_certificate_id' => $certificateId,
    'certificate_code' => $certRowStmt->fetchColumn(),
    'near_account_id' => $request['near_account_id'],
    'course_id' => $request['course_id'],
    'metadata_hash' => $request['metadata_hash'],
    'status' => 'REQUESTED',
    'expires_at' => $request['expires_at'],
    'contract_id' => \Web3Edu\Near\Issuance\getNearContractId(),
    'network' => 'testnet-fastnear',
    'command_info' => $commandInfo,
]);
