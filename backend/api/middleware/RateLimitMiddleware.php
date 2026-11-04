<?php
declare(strict_types=1);

namespace MailArchive\Middleware;

use MailArchive\Config\Database;
use MailArchive\Config\Config;
use MailArchive\Core\Response;
use PDO;
use Throwable;

class RateLimitMiddleware
{
    private static array $blockedCrawlerUris = [
        '/wp-login.php', '/wp-admin', '/xmlrpc.php',
        '/.env', '/.git', '/actuator', '/telescope',
        '/phpmyadmin', '/pma', '/adminer.php', '/eval-stdin.php'
    ];

    public static function checkCrawler(): void
    {
        $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
        foreach (self::$blockedCrawlerUris as $blocked) {
            if (str_contains($uri, $blocked)) {
                http_response_code(404);
                header('Content-Type: text/plain');
                echo 'Not Found';
                exit;
            }
        }

        // Blocco attivo bot di scansione e motori di ricerca via User-Agent
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua !== '') {
            $botPattern = '/googlebot|bingbot|yandex|baiduspider|duckduckbot|slurp|semrush|ahrefs|petalbot|censys|shodan|mj12bot|dotbot|rogerbot|exabot|screaming frog|bytespider/i';
            if (preg_match($botPattern, $ua)) {
                http_response_code(404);
                header('Content-Type: text/plain');
                echo 'Not Found';
                exit;
            }
        }
    }

    public static function checkLoginRateLimit(string $email, string $ip): void
    {
        try {
            $pdo = Database::pdo();
            $maxAttempts = (int) Config::get('LOGIN_MAX_ATTEMPTS', 5);
            $lockoutMinutes = (int) Config::get('LOGIN_LOCKOUT_MINUTES', 15);
            $since = gmdate('Y-m-d H:i:s', time() - ($lockoutMinutes * 60));

            $stmt = $pdo->prepare('
                SELECT COUNT(*) AS failed_count
                FROM login_attempts
                WHERE ip_address = :ip
                  AND successful = 0
                  AND attempted_at >= :since
            ');
            $stmt->execute([':ip' => $ip, ':since' => $since]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && (int) $row['failed_count'] >= ($maxAttempts * 3)) {
                Response::error('Troppi tentativi di accesso falliti da questo indirizzo IP. Riprova più tardi.', 429);
            }
        } catch (Throwable) {
            // Non bloccare se la tabella non è ancora pronta
        }
    }
}
