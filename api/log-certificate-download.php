<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/certificates.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }

$code = trim($_POST['code'] ?? '');
if (!$code) { http_response_code(400); echo json_encode(['ok'=>false]); exit; }

$db = getDB();
$stmt = $db->prepare("SELECT id, user_id, is_revoked FROM certificates WHERE certificate_code = ?");
$stmt->execute([$code]);
$cert = $stmt->fetch();
if (!$cert || $cert['is_revoked']) { http_response_code(404); echo json_encode(['ok'=>false]); exit; }

$viewerId = isLoggedIn() ? (int)$_SESSION['user_id'] : null;
logCertificateDownload((int)$cert['id'], $viewerId, 'pdf');

echo json_encode(['ok'=>true]);
