<?php
declare(strict_types=1);

namespace MailArchive\Config;

class Config
{
    private static array $env = [];
    private static bool $loaded = false;

    public static function load(?string $envPath = null): void
    {
        if (self::$loaded) {
            return;
        }

        if ($envPath === null) {
            $envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
        }

        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }

                if (str_contains($trimmed, '=')) {
                    [$key, $value] = explode('=', $trimmed, 2);
                    $key = trim($key);
                    $value = trim($value);

                    // Strip surrounding quotes
                    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                        (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                        $value = substr($value, 1, -1);
                    }

                    self::$env[$key] = $value;
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $value;
                    }
                }
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::$env[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$env[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    public static function isProduction(): bool
    {
        return strtolower((string) self::get('NODE_ENV', self::get('APP_ENV', 'production'))) === 'production';
    }

    public static function getAppUrl(): string
    {
        return rtrim((string) self::get('APP_URL', 'http://localhost'), '/');
    }

    /**
     * Mittente predefinito delle email di sistema: noreply@<host di APP_URL>
     */
    public static function getDefaultSenderEmail(): string
    {
        $host = parse_url(self::getAppUrl(), PHP_URL_HOST);
        return 'noreply@' . (is_string($host) && $host !== '' ? $host : 'localhost');
    }

    public static function getStorageDir(): string
    {
        $dir = (string) self::get('STORAGE_DIR', './storage');
        if (!str_starts_with($dir, '/') && !preg_match('/^[a-zA-Z]:\\\\/', $dir)) {
            $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($dir, './\\');
        }
        return rtrim($dir, '/\\');
    }

    public static function getClientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        // Gli header di inoltro sono falsificabili: li accettiamo solo se la richiesta
        // arriva da un proxy elencato in TRUSTED_PROXIES.
        $trusted = array_filter(array_map('trim', explode(',', (string) self::get('TRUSTED_PROXIES', ''))));
        $headers = in_array($remote, $trusted, true)
            ? ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR']
            : ['REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', (string) $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    public static function getUserAgent(): string
    {
        return (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
    }
}
