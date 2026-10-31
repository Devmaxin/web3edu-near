<?php
/**
 * Web3Edu NEAR Hackathon — Database Connection
 * Reads credentials from environment variables (.env).
 * Uses PDO with the dedicated web3edu_near user (limited to hackathon DB only).
 */

declare(strict_types=1);

namespace Web3Edu\Near\Config;

use PDO;

class Database
{
    private static ?PDO $pdo = null;

    private function __construct() {}

    private static function getEnv(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }

    private static function loadEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, "\"'");
                if (!getenv($k)) {
                    putenv("$k=$v");
                }
            }
        }
    }

    public static function connect(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $projectRoot = dirname(__DIR__, 2);
        self::loadEnv($projectRoot . '/.env');

        $host = self::getEnv('DB_HOST', 'localhost');
        $port = self::getEnv('DB_PORT', '3306');
        $name = self::getEnv('DB_NAME', 'web3edu_near_hackathon');
        $user = self::getEnv('DB_USER', 'web3edu_near');
        $pass = self::getEnv('DB_PASS', '');

        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        self::$pdo = new PDO($dsn, $user, $pass, $options);
        return self::$pdo;
    }
}
