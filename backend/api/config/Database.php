<?php
declare(strict_types=1);

namespace MailArchive\Config;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;
    private static string $driver = 'mysql';

    public static function resetConnection(): void
    {
        self::$instance = null;
    }

    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        Config::load();
        $dbUrl = (string) Config::get('DATABASE_URL', '');

        // Default to MySQL/MariaDB unless SQLite is explicitly configured
        if (str_starts_with($dbUrl, 'file:') || str_starts_with($dbUrl, 'sqlite:') || Config::get('DB_DRIVER') === 'sqlite') {
            self::$driver = 'sqlite';
            self::$instance = self::createSqliteConnection($dbUrl);
        } else {
            self::$driver = 'mysql';
            self::$instance = self::createMysqlConnection($dbUrl);
        }

        self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return self::$instance;
    }

    private static function createSqliteConnection(string $dbUrl): PDO
    {
        $dbPath = '';
        if (str_starts_with($dbUrl, 'file:')) {
            $dbPath = substr($dbUrl, 5);
        } elseif (str_starts_with($dbUrl, 'sqlite:')) {
            $dbPath = substr($dbUrl, 7);
        } else {
            $dbPath = (string) Config::get('DB_PATH', './mailarchive.db');
        }

        // Clean parameters like ?mode=...
        if (($pos = strpos($dbPath, '?')) !== false) {
            $dbPath = substr($dbPath, 0, $pos);
        }

        if (!str_starts_with($dbPath, '/') && !preg_match('/^[a-zA-Z]:\\\\/', $dbPath)) {
            $dbPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($dbPath, './\\');
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
        return $pdo;
    }

    private static function createMysqlConnection(string $dbUrl): PDO
    {
        $host = (string) Config::get('DB_HOST', 'localhost');
        $port = (int) Config::get('DB_PORT', 3306);
        $dbname = (string) Config::get('DB_NAME', 'mailarchive_prod');
        $user = (string) Config::get('DB_USER', 'root');
        $pass = (string) Config::get('DB_PASS', '');

        if (str_starts_with($dbUrl, 'mysql://') || str_starts_with($dbUrl, 'mariadb://')) {
            $parsed = parse_url($dbUrl);
            if ($parsed !== false) {
                $host = $parsed['host'] ?? $host;
                $port = $parsed['port'] ?? $port;
                $user = isset($parsed['user']) ? urldecode($parsed['user']) : $user;
                $pass = isset($parsed['pass']) ? urldecode($parsed['pass']) : $pass;
                if (!empty($parsed['path'])) {
                    $dbname = ltrim($parsed['path'], '/');
                }
            }
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[constant('PDO::MYSQL_ATTR_INIT_COMMAND')] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
        }

        try {
            return new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new RuntimeException("Errore di connessione al database MariaDB/MySQL: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public static function pdo(): PDO
    {
        return self::getConnection();
    }

    public static function isSqlite(): bool
    {
        self::getConnection();
        return self::$driver === 'sqlite';
    }

    public static function isMysql(): bool
    {
        self::getConnection();
        return self::$driver === 'mysql';
    }

    /**
     * Genera un UUID v4 crittograficamente sicuro (RFC 4122)
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // Version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // Variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Restituisce il timestamp formattato per il database
     */
    public static function now(): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
