<?php
declare(strict_types=1);

namespace MailArchive\Middleware;

use MailArchive\Config\Database;
use MailArchive\Config\Config;
use MailArchive\Core\Crypto;
use MailArchive\Core\Response;
use PDO;

class AuthMiddleware
{
    public const SESSION_COOKIE_NAME = 'mailarchive_session';

    private static ?array $currentUser = null;
    private static ?array $currentSession = null;

    public static function authenticate(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        $rawToken = $_COOKIE[self::SESSION_COOKIE_NAME] ?? null;

        // Fallback su header Authorization: Bearer <token>
        if (!$rawToken && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s+(\S+)/i', (string) $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
                $rawToken = $matches[1];
            }
        }

        if (!$rawToken || !is_string($rawToken)) {
            return null;
        }

        $tokenHash = Crypto::hashToken($rawToken);
        $pdo = Database::pdo();
        $now = Database::now();

        $stmt = $pdo->prepare('
            SELECT 
                s.id AS session_id,
                s.user_id,
                s.expires_at,
                s.last_active_at,
                u.id,
                u.email,
                u.role,
                u.is_active,
                u.totp_enabled
            FROM sessions s
            INNER JOIN users u ON s.user_id = u.id
            WHERE s.session_token_hash = :hash
            LIMIT 1
        ');
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Verifica scadenza sessione
        if ($row['expires_at'] < $now) {
            $del = $pdo->prepare('DELETE FROM sessions WHERE id = :id');
            $del->execute([':id' => $row['session_id']]);
            return null;
        }

        // Verifica utente attivo
        if (!$row['is_active']) {
            return null;
        }

        self::$currentUser = [
            'id' => $row['id'],
            'email' => $row['email'],
            'role' => $row['role'],
            'isActive' => (bool) $row['is_active'],
            'totpEnabled' => (bool) $row['totp_enabled']
        ];

        self::$currentSession = [
            'id' => $row['session_id'],
            'userId' => $row['user_id'],
            'expiresAt' => $row['expires_at']
        ];

        // Throttled update di last_active_at ogni 5 minuti per minimizzare le scritture DB
        $lastActiveTs = strtotime($row['last_active_at'] . ' UTC');
        if (time() - $lastActiveTs > 300) {
            $upd = $pdo->prepare('UPDATE sessions SET last_active_at = :now WHERE id = :id');
            $upd->execute([':now' => $now, ':id' => $row['session_id']]);
        }

        return self::$currentUser;
    }

    public static function requireAuth(): array
    {
        $user = self::authenticate();
        if ($user === null) {
            Response::error('Autenticazione richiesta per accedere a questa risorsa', 401);
        }
        return $user;
    }

    public static function requireRole(string $allowedRole): array
    {
        $user = self::requireAuth();

        if ($allowedRole === 'ADMIN' && $user['role'] !== 'ADMIN') {
            Response::error('Accesso negato: privilegi di amministratore richiesti', 403);
        }

        return $user;
    }

    public static function getUser(): ?array
    {
        return self::$currentUser ?? self::authenticate();
    }

    public static function getSession(): ?array
    {
        if (self::$currentSession === null) {
            self::authenticate();
        }
        return self::$currentSession;
    }
}
