<?php
require_once __DIR__ . '/includes/config.php';
if (isLoggedIn()) {
    Analytics::track('logout', (int)$_SESSION['user_id']);
}
session_destroy();
header('Location: ' . SITE_URL . '/index.php');
exit;
