<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Config\Database;
use MailArchive\Core\Crypto;
use MailArchive\Core\Audit;
use PDO;
use RuntimeException;

class AccountService
{
    public static function listUsers(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query('
            SELECT id, email, role, is_active, failed_login_attempts, locked_until, totp_enabled, created_at, updated_at
            FROM users
            ORDER BY created_at DESC
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getUserById(string $id): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('
            SELECT id, email, role, is_active, failed_login_attempts, locked_until, totp_enabled, created_at, updated_at
            FROM users
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException('Utente non trovato');
        }

        return $user;
    }

    public static function createUser(array $input, array $actor): array
    {
        $pdo = Database::pdo();
        $email = strtolower(trim((string) ($input['email'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Indirizzo email non valido');
        }

        // Verifica unicità
        $stmtCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmtCheck->execute([':email' => $email]);
        if ($stmtCheck->fetch()) {
            throw new RuntimeException('Un account con questa email esiste già.');
        }

        $rawPassword = (string) ($input['password'] ?? '');
        $wasGenerated = false;

        if ($rawPassword === '') {
            $wasGenerated = true;
            $rawPassword = Crypto::generateSecureToken(12);
        }

        if (strlen($rawPassword) < 8) {
            throw new RuntimeException('La password deve contenere almeno 8 caratteri');
        }

        $passwordHash = Crypto::hashPassword($rawPassword);
        $role = strtoupper((string) ($input['role'] ?? 'VIEWER')) === 'ADMIN' ? 'ADMIN' : 'VIEWER';
        $id = Database::uuid();
        $now = Database::now();

        $stmt = $pdo->prepare('
            INSERT INTO users (id, email, password_hash, role, is_active, failed_login_attempts, totp_enabled, created_at, updated_at)
            VALUES (:id, :email, :hash, :role, 1, 0, 0, :now, :now)
        ');
        $stmt->execute([
            ':id' => $id,
            ':email' => $email,
            ':hash' => $passwordHash,
            ':role' => $role,
            ':now' => $now
        ]);

        Audit::log('ACCOUNT_CREATED', 'USER', $id, ['email' => $email, 'role' => $role], $actor['id'] ?? null, $actor['email'] ?? null);

        $user = self::getUserById($id);

        return [
            'user' => $user,
            'initialPassword' => $rawPassword,
            'wasGenerated' => $wasGenerated
        ];
    }

    public static function updateUser(string $id, array $input, array $actor): array
    {
        $pdo = Database::pdo();
        $existing = self::getUserById($id);

        // Prevenzione modifica auto-ruolo se l'amministratore modifica se stesso
        if ($id === ($actor['id'] ?? '') && isset($input['role']) && $input['role'] !== 'ADMIN') {
            throw new RuntimeException('Non è consentito revocare i propri privilegi di amministratore.');
        }

        $updates = [];
        $params = [':id' => $id, ':now' => Database::now()];

        if (isset($input['role'])) {
            $role = strtoupper((string) $input['role']) === 'ADMIN' ? 'ADMIN' : 'VIEWER';
            $updates[] = 'role = :role';
            $params[':role'] = $role;
        }

        if (isset($input['isActive'])) {
            $isActive = filter_var($input['isActive'], FILTER_VALIDATE_BOOLEAN);
            if ($id === ($actor['id'] ?? '') && !$isActive) {
                throw new RuntimeException('Non è consentito disattivare il proprio account.');
            }
            $updates[] = 'is_active = :act';
            $params[':act'] = $isActive ? 1 : 0;
        }

        if (!empty($updates)) {
            $sql = 'UPDATE users SET ' . implode(', ', $updates) . ', updated_at = :now WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            Audit::log('ACCOUNT_UPDATED', 'USER', $id, ['fields' => array_keys($input)], $actor['id'] ?? null, $actor['email'] ?? null);
        }

        return self::getUserById($id);
    }

    public static function deactivateUser(string $id, array $actor): array
    {
        if ($id === ($actor['id'] ?? '')) {
            throw new RuntimeException('Non è consentito disattivare il proprio account.');
        }

        $pdo = Database::pdo();
        $user = self::getUserById($id);
        $now = Database::now();

        $stmt = $pdo->prepare('UPDATE users SET is_active = 0, updated_at = :now WHERE id = :id');
        $stmt->execute([':now' => $now, ':id' => $id]);

        // Revoca tutte le sessioni attive dell'utente
        $delSessions = $pdo->prepare('DELETE FROM sessions WHERE user_id = :uid');
        $delSessions->execute([':uid' => $id]);

        Audit::log('ACCOUNT_DEACTIVATED', 'USER', $id, ['email' => $user['email']], $actor['id'] ?? null, $actor['email'] ?? null);

        return self::getUserById($id);
    }

    public static function resetPassword(string $id, array $input, array $actor): array
    {
        $pdo = Database::pdo();
        $user = self::getUserById($id);

        $newPassword = (string) ($input['newPassword'] ?? $input['password'] ?? '');
        $wasGenerated = false;

        if ($newPassword === '') {
            $wasGenerated = true;
            $newPassword = Crypto::generateSecureToken(12);
        }

        if (strlen($newPassword) < 8) {
            throw new RuntimeException('La password deve contenere almeno 8 caratteri');
        }

        $hash = Crypto::hashPassword($newPassword);
        $now = Database::now();

        $stmt = $pdo->prepare('
            UPDATE users 
            SET password_hash = :hash, failed_login_attempts = 0, locked_until = NULL, updated_at = :now 
            WHERE id = :id
        ');
        $stmt->execute([':hash' => $hash, ':now' => $now, ':id' => $id]);

        // Revoca le sessioni attive per forzare un nuovo login
        $delSessions = $pdo->prepare('DELETE FROM sessions WHERE user_id = :uid');
        $delSessions->execute([':uid' => $id]);

        Audit::log('ACCOUNT_PASSWORD_RESET', 'USER', $id, ['email' => $user['email']], $actor['id'] ?? null, $actor['email'] ?? null);

        return [
            'success' => true,
            'newPassword' => $newPassword,
            'wasGenerated' => $wasGenerated
        ];
    }
}
