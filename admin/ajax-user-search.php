<?php
/**
 * Admin-only JSON endpoint: search users by username/email for the manual
 * certificate issuance picker (admin/certificates-issued.php).
 */
require_once __DIR__ . '/auth.php';
requireAdmin();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

$db = getDB();
$stmt = $db->prepare("SELECT id, username, email FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY username ASC LIMIT 10");
$like = '%' . $q . '%';
$stmt->execute([$like, $like]);
echo json_encode($stmt->fetchAll());
