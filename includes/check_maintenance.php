<?php
// ============================================================
// includes/check_maintenance.php
// ============================================================
// Include this file at the very top of every public-facing page
// (AFTER config.php, which starts the session).
//
// Access logic:
//   maintenance_mode OFF  →  everyone passes
//   maintenance_mode ON   →  admin session  → passes
//                         →  exception user → passes
//                         →  anyone else    → redirected to maintenance.php
//
// Admin identity: $_SESSION['admin_id']  (set by admin/auth.php)
// User identity:  $_SESSION['user_id']   (set by includes/config.php auth helpers)
// ============================================================

// config.php must already be included (it starts the session).
// Guard against double-inclusion.
if (defined('MAINTENANCE_CHECK_DONE')) return;
define('MAINTENANCE_CHECK_DONE', true);

// ------------------------------------------------------------------
// 1. Fast exit: maintenance mode is OFF — nothing to do.
// ------------------------------------------------------------------
$_maintenanceOn = (getSetting('maintenance_mode', '0') === '1');
if (!$_maintenanceOn) return;

// ------------------------------------------------------------------
// 2. Admins always bypass (admin session from admin/auth.php).
// ------------------------------------------------------------------
if (!empty($_SESSION['admin_id'])) return;

// ------------------------------------------------------------------
// 3. Check if the logged-in regular user is in maintenance_exceptions.
// ------------------------------------------------------------------
if (!empty($_SESSION['user_id'])) {
    try {
        $__db   = getDB();
        $__stmt = $__db->prepare(
            "SELECT COUNT(*) FROM maintenance_exceptions WHERE user_id = ? LIMIT 1"
        );
        $__stmt->execute([(int)$_SESSION['user_id']]);
        if ((int)$__stmt->fetchColumn() > 0) return; // exception user — let through
    } catch (\Throwable $__e) {
        // Table may not exist yet; fail-closed (send to maintenance page).
    }
}

// ------------------------------------------------------------------
// 4. Block everyone else — send to maintenance page.
// ------------------------------------------------------------------
// Avoid redirect loop: if we ARE already on maintenance.php, stop.
if (basename($_SERVER['PHP_SELF']) === 'maintenance.php') return;

header('Location: ' . SITE_URL . '/maintenance.php');
exit;
