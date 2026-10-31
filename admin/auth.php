<?php
// Admin auth guard
require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function isAdmin(): bool {
    if (!isset($_SESSION['admin_id'])) return false;
    // Re-verify against the admins table on every check — a session flag
    // alone isn't enough if the account was deactivated after login.
    static $verified = null;
    if ($verified !== null) return $verified;

    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM admins WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$_SESSION['admin_id']]);
    $verified = (bool)$stmt->fetch();
    if (!$verified) {
        unset($_SESSION['admin_id'], $_SESSION['admin_email'], $_SESSION['admin_name']);
    }
    return $verified;
}

function requireAdmin(): void {
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/admin/login.php');
        exit;
    }
}

function getCurrentAdmin(): ?array {
    if (!isAdmin()) return null;
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM admins WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$_SESSION['admin_id']]);
    return $stmt->fetch() ?: null;
}
