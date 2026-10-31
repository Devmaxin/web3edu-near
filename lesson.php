<?php
/**
 * Legacy lesson.php — redirects to new /learn/lesson/:id URLs.
 * Supports both ?slug=X (old) and ?id=X (direct).
 * All new links use /learn/lesson/:id directly.
 */
require_once __DIR__ . '/includes/config.php';

$db = getDB();

$slug = trim($_GET['slug'] ?? '');
$id   = intval($_GET['id'] ?? 0);

if ($id) {
    header('Location: ' . SITE_URL . '/learn/lesson/' . $id, true, 301); exit;
}

if ($slug) {
    $stmt = $db->prepare("SELECT id FROM lessons WHERE slug = ?");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if ($row) {
        header('Location: ' . SITE_URL . '/learn/lesson/' . $row['id'], true, 301); exit;
    }
}

// Fallback
header('Location: ' . SITE_URL . '/learn.php'); exit;
