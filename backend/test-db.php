<?php
/**
 * Web3Edu NEAR Hackathon — Database Connection Test
 * 
 * Usage:
 *   "C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe" backend/test-db.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

use Web3Edu\Near\Config\Database;

$errors = [];
$successes = [];

// --- Test 1: PDO MySQL extension ---
if (extension_loaded('pdo_mysql')) {
    $successes[] = 'PDO MySQL extension: loaded';
} else {
    $errors[] = 'PDO MySQL extension: NOT loaded';
}

// --- Test 2: Sodium extension ---
if (extension_loaded('sodium')) {
    $successes[] = 'Sodium extension: loaded';
} else {
    $errors[] = 'Sodium extension: NOT loaded';
}

// --- Test 3: Database connection ---
try {
    $pdo = Database::connect();
    $stmt = $pdo->query('SELECT 1 AS connected, VERSION() AS mysql_version');
    $row = $stmt->fetch();
    $successes[] = 'Database connection: OK (MySQL ' . htmlspecialchars((string)$row['mysql_version']) . ')';
    $successes[] = 'Database: web3edu_near_hackathon';
    $successes[] = 'User: web3edu_near@localhost';
} catch (Throwable $e) {
    $errors[] = 'Database connection: FAILED — ' . $e->getMessage();
}

// --- Output ---
echo "=== Web3Edu NEAR — PHP Database Connection Test ===\n\n";

if ($successes) {
    echo "Passed:\n";
    foreach ($successes as $s) {
        echo "  ✅ $s\n";
    }
    echo "\n";
}

if ($errors) {
    echo "Failed:\n";
    foreach ($errors as $e) {
        echo "  ❌ $e\n";
    }
    echo "\n";
    exit(1);
}

echo "All checks passed.\n";
exit(0);
