<?php
declare(strict_types=1);

namespace MailArchive\Middleware;

use MailArchive\Config\Config;
use MailArchive\Core\Crypto;
use MailArchive\Core\Response;

class CsrfMiddleware
{
    public static function getCookieName(): string
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                   (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                   Config::isProduction();

        return $isHttps ? '__Host-mailarchive.x-csrf-token' : 'mailarchive.x-csrf-token';
    }

    public static function generateToken(): string
    {
        $token = Crypto::generateSecureToken(32);
        $cookieName = self::getCookieName();
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                   (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                   Config::isProduction();

        setcookie($cookieName, $token, [
            'expires' => time() + (24 * 3600),
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => $isHttps ? 'Strict' : 'Lax'
        ]);

        return $token;
    }

    public static function validate(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!$headerToken && isset($_POST['_csrf'])) {
            $headerToken = (string) $_POST['_csrf'];
        }

        $cookieName = self::getCookieName();
        $normalizedName = str_replace(['.', ' '], '_', $cookieName);
        $cookieToken = $_COOKIE[$cookieName] ?? $_COOKIE[$normalizedName] ?? null;

        // Fallback su parsing diretto dell'header HTTP_COOKIE (per evitare la normalizzazione automatica dei punti in PHP)
        if (!$cookieToken && !empty($_SERVER['HTTP_COOKIE'])) {
            if (preg_match('/(?:^|;\s*)' . preg_quote($cookieName, '/') . '=([^;]+)/', $_SERVER['HTTP_COOKIE'], $m)) {
                $cookieToken = urldecode($m[1]);
            }
        }

        if (!$headerToken || !$cookieToken || !hash_equals((string) $cookieToken, (string) $headerToken)) {
            Response::error('Token CSRF non valido o mancante', 403);
        }
    }
}
