<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Config\Database;
use MailArchive\Config\Config;
use MailArchive\Core\Crypto;
use MailArchive\Core\Audit;
use PDO;
use RuntimeException;

class AuthService
{
    private const DUMMY_BCRYPT_HASH = '$2a$12$W9xLw5sR5Q0hC3O6rU2bYeN8hB7vD6eM9yX8zW7vP4kL1jH2gF3sA';

    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }
        $name = $parts[0];
        $domain = $parts[1];

        if (strlen($name) <= 2) {
            return $name[0] . '*@' . $domain;
        }

        $masked = $name[0] . str_repeat('*', min(strlen($name) - 2, 4)) . substr($name, -1);
        return $masked . '@' . $domain;
    }

    public static function login(string $email, string $password, array $context): array
    {
        $genericError = 'Credenziali non valide o account temporaneamente bloccato.';
        $normalizedEmail = strtolower(trim($email));
        $pdo = Database::pdo();
        $ip = $context['ip'] ?? Config::getClientIp();
        $ua = $context['userAgent'] ?? Config::getUserAgent();
        $now = Database::now();

        // 1. Ricerca utente nel DB
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $normalizedEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Se l'utente non esiste, verifica dummy hash per tempi di risposta costanti (anti-enumeration)
        if (!$user) {
            Crypto::verifyPassword($password, self::DUMMY_BCRYPT_HASH);

            $stmtLog = $pdo->prepare('
                INSERT INTO login_attempts (id, email, ip_address, user_agent, successful, failure_reason, attempted_at)
                VALUES (:id, :email, :ip, :ua, 0, "USER_NOT_FOUND", :now)
            ');
            $stmtLog->execute([
                ':id' => Database::uuid(),
                ':email' => $normalizedEmail,
                ':ip' => $ip,
                ':ua' => $ua,
                ':now' => $now
            ]);

            Audit::log('AUTH_LOGIN_FAILED_UNKNOWN_USER', 'USER', null, ['emailAttempted' => $normalizedEmail], null, null, $ip, $ua);
            return ['success' => false, 'message' => $genericError];
        }

        // 2. Controllo blocco temporaneo (Lockout)
        if (!empty($user['locked_until']) && $user['locked_until'] > $now) {
            $stmtLog = $pdo->prepare('
                INSERT INTO login_attempts (id, email, ip_address, user_agent, successful, failure_reason, attempted_at)
                VALUES (:id, :email, :ip, :ua, 0, "ACCOUNT_TEMPORARILY_LOCKED", :now)
            ');
            $stmtLog->execute([
                ':id' => Database::uuid(),
                ':email' => $user['email'],
                ':ip' => $ip,
                ':ua' => $ua,
                ':now' => $now
            ]);

            Audit::log('AUTH_LOGIN_REJECTED_LOCKED', 'USER', $user['id'], ['lockedUntil' => $user['locked_until']], $user['id'], $user['email'], $ip, $ua);
            return ['success' => false, 'message' => $genericError];
        }

        // 3. Controllo se l'account è disattivato
        if (!$user['is_active']) {
            Crypto::verifyPassword($password, $user['password_hash']);

            $stmtLog = $pdo->prepare('
                INSERT INTO login_attempts (id, email, ip_address, user_agent, successful, failure_reason, attempted_at)
                VALUES (:id, :email, :ip, :ua, 0, "ACCOUNT_INACTIVE", :now)
            ');
            $stmtLog->execute([
                ':id' => Database::uuid(),
                ':email' => $user['email'],
                ':ip' => $ip,
                ':ua' => $ua,
                ':now' => $now
            ]);

            Audit::log('AUTH_LOGIN_FAILED_INACTIVE', 'USER', $user['id'], null, $user['id'], $user['email'], $ip, $ua);
            return ['success' => false, 'message' => $genericError];
        }

        // 4. Verifica password con Bcrypt
        if (!Crypto::verifyPassword($password, $user['password_hash'])) {
            $newAttempts = (int) $user['failed_login_attempts'] + 1;
            $maxAttempts = (int) Config::get('LOGIN_MAX_ATTEMPTS', 5);
            $lockoutMin = (int) Config::get('LOGIN_LOCKOUT_MINUTES', 15);
            $lockedUntil = null;
            $failureReason = 'INVALID_PASSWORD';

            if ($newAttempts >= $maxAttempts) {
                $lockedUntil = gmdate('Y-m-d H:i:s', time() + ($lockoutMin * 60));
                $failureReason = 'ACCOUNT_LOCKED_THRESHOLD_REACHED';
            }

            $upd = $pdo->prepare('UPDATE users SET failed_login_attempts = :attempts, locked_until = :locked WHERE id = :id');
            $upd->execute([
                ':attempts' => $newAttempts,
                ':locked' => $lockedUntil,
                ':id' => $user['id']
            ]);

            $stmtLog = $pdo->prepare('
                INSERT INTO login_attempts (id, email, ip_address, user_agent, successful, failure_reason, attempted_at)
                VALUES (:id, :email, :ip, :ua, 0, :reason, :now)
            ');
            $stmtLog->execute([
                ':id' => Database::uuid(),
                ':email' => $user['email'],
                ':ip' => $ip,
                ':ua' => $ua,
                ':reason' => $failureReason,
                ':now' => $now
            ]);

            Audit::log(
                $lockedUntil ? 'AUTH_ACCOUNT_LOCKED' : 'AUTH_LOGIN_FAILED',
                'USER',
                $user['id'],
                ['attempts' => $newAttempts, 'lockedUntil' => $lockedUntil],
                $user['id'],
                $user['email'],
                $ip,
                $ua
            );

            return ['success' => false, 'message' => $genericError];
        }

        // 5. Successo password: Reset contatore fallimenti
        $upd = $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id');
        $upd->execute([':id' => $user['id']]);

        // 6. Verifica richiesta 2FA
        $req2fa = (bool) $user['totp_enabled'] || filter_var(Config::get('REQUIRE_2FA', false), FILTER_VALIDATE_BOOLEAN);

        if ($req2fa) {
            $tempToken = Crypto::generateSecureToken(32);
            $tokenHash = Crypto::hashToken($tempToken);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 300); // 5 minuti

            // Pulizia vecchie sfide
            $pdo->exec('DELETE FROM two_factor_challenges WHERE expires_at < "' . $now . '"');

            $stmt2fa = $pdo->prepare('
                INSERT INTO two_factor_challenges (id, token_hash, user_id, attempts, expires_at, created_at)
                VALUES (:id, :hash, :user_id, 0, :expires_at, :now)
            ');
            $stmt2fa->execute([
                ':id' => Database::uuid(),
                ':hash' => $tokenHash,
                ':user_id' => $user['id'],
                ':expires_at' => $expiresAt,
                ':now' => $now
            ]);

            $methods = ['EMAIL'];
            if ($user['totp_enabled'] && !empty($user['totp_secret'])) {
                array_unshift($methods, 'TOTP');
            }

            Audit::log('AUTH_2FA_CHALLENGE_ISSUED', 'USER', $user['id'], ['methods' => $methods], $user['id'], $user['email'], $ip, $ua);

            return [
                'success' => true,
                'requires2fa' => true,
                'tempToken' => $tempToken,
                'emailMasked' => self::maskEmail($user['email']),
                'methods' => $methods
            ];
        }

        // 7. Rilascio sessione definitiva
        return self::createSessionForUser($user, $ip, $ua);
    }

    public static function createSessionForUser(array $user, string $ip, string $ua): array
    {
        $pdo = Database::pdo();
        $now = Database::now();

        // Pulizia vecchie sessioni scadute
        $del = $pdo->prepare('DELETE FROM sessions WHERE user_id = :uid AND expires_at < :now');
        $del->execute([':uid' => $user['id'], ':now' => $now]);

        $rawSessionToken = Crypto::generateSecureToken(32);
        $tokenHash = Crypto::hashToken($rawSessionToken);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (24 * 3600)); // 24 ore

        $stmtSession = $pdo->prepare('
            INSERT INTO sessions (id, user_id, session_token_hash, ip_address, user_agent, expires_at, created_at, last_active_at)
            VALUES (:id, :user_id, :hash, :ip, :ua, :expires_at, :now, :now)
        ');
        $stmtSession->execute([
            ':id' => Database::uuid(),
            ':user_id' => $user['id'],
            ':hash' => $tokenHash,
            ':ip' => $ip,
            ':ua' => $ua,
            ':expires_at' => $expiresAt,
            ':now' => $now
        ]);

        $stmtLog = $pdo->prepare('
            INSERT INTO login_attempts (id, email, ip_address, user_agent, successful, failure_reason, attempted_at)
            VALUES (:id, :email, :ip, :ua, 1, NULL, :now)
        ');
        $stmtLog->execute([
            ':id' => Database::uuid(),
            ':email' => $user['email'],
            ':ip' => $ip,
            ':ua' => $ua,
            ':now' => $now
        ]);

        Audit::log('AUTH_LOGIN_SUCCESS', 'USER', $user['id'], null, $user['id'], $user['email'], $ip, $ua);

        return [
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role']
            ],
            'sessionToken' => $rawSessionToken,
            'expiresAt' => $expiresAt
        ];
    }

    public static function verify2fa(string $tempToken, string $rawCode, array $context): array
    {
        $pdo = Database::pdo();
        $ip = $context['ip'] ?? Config::getClientIp();
        $ua = $context['userAgent'] ?? Config::getUserAgent();
        $now = Database::now();

        $tokenHash = Crypto::hashToken($tempToken);
        $stmt = $pdo->prepare('SELECT * FROM two_factor_challenges WHERE token_hash = :hash LIMIT 1');
        $stmt->execute([':hash' => $tokenHash]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge || $challenge['expires_at'] < $now) {
            if ($challenge) {
                $pdo->exec('DELETE FROM two_factor_challenges WHERE id = "' . $challenge['id'] . '"');
            }
            throw new RuntimeException('Sessione di verifica 2FA scaduta o non valida. Effettua nuovamente il login.');
        }

        $stmtU = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmtU->execute([':id' => $challenge['user_id']]);
        $user = $stmtU->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$user['is_active']) {
            $pdo->exec('DELETE FROM two_factor_challenges WHERE id = "' . $challenge['id'] . '"');
            throw new RuntimeException('Utente non trovato o account non attivo.');
        }

        $code = trim(preg_replace('/\s+/', '', $rawCode) ?? '');
        if (strlen($code) !== 6) {
            throw new RuntimeException('Il codice di verifica deve essere composto da 6 cifre.');
        }

        // Incremento tentativi
        $attempts = (int) $challenge['attempts'] + 1;
        $upd = $pdo->prepare('UPDATE two_factor_challenges SET attempts = :att WHERE id = :id');
        $upd->execute([':att' => $attempts, ':id' => $challenge['id']]);

        if ($attempts > 3) {
            $pdo->exec('DELETE FROM two_factor_challenges WHERE id = "' . $challenge['id'] . '"');
            Audit::log('AUTH_2FA_LOCKOUT_ATTEMPTS_EXCEEDED', 'USER', $user['id'], null, $user['id'], $user['email'], $ip, $ua);
            throw new RuntimeException('Troppi tentativi errati. La sessione di verifica 2FA è stata revocata per sicurezza.');
        }

        $isValid = false;
        $methodUsed = '';

        // 1. Verifica con TOTP
        if ($user['totp_enabled'] && !empty($user['totp_secret'])) {
            if (TotpService::verifyToken($code, $user['totp_secret'])) {
                $isValid = true;
                $methodUsed = 'TOTP';
            }
        }

        // 2. Verifica con Email OTP
        if (!$isValid && !empty($challenge['email_otp_code'])) {
            if (!empty($challenge['email_otp_expires_at']) &&
                $challenge['email_otp_expires_at'] > $now &&
                hash_equals((string) $challenge['email_otp_code'], $code)) {
                $isValid = true;
                $methodUsed = 'EMAIL';
            }
        }

        if (!$isValid) {
            Audit::log('AUTH_2FA_VERIFY_FAILED', 'USER', $user['id'], ['attemptNumber' => $attempts], $user['id'], $user['email'], $ip, $ua);
            throw new RuntimeException('Codice di verifica 2FA non corretto o scaduto. Riprova.');
        }

        // Successo: rimuovi challenge
        $pdo->exec('DELETE FROM two_factor_challenges WHERE id = "' . $challenge['id'] . '"');

        Audit::log('AUTH_LOGIN_SUCCESS_2FA', 'USER', $user['id'], ['method' => $methodUsed], $user['id'], $user['email'], $ip, $ua);

        return self::createSessionForUser($user, $ip, $ua);
    }

    public static function send2faEmail(string $tempToken): array
    {
        $pdo = Database::pdo();
        $now = Database::now();
        $tokenHash = Crypto::hashToken($tempToken);

        $stmt = $pdo->prepare('SELECT * FROM two_factor_challenges WHERE token_hash = :hash LIMIT 1');
        $stmt->execute([':hash' => $tokenHash]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge || $challenge['expires_at'] < $now) {
            if ($challenge) {
                $pdo->exec('DELETE FROM two_factor_challenges WHERE id = "' . $challenge['id'] . '"');
            }
            throw new RuntimeException('Sessione 2FA scaduta o non valida. Effettua nuovamente il login.');
        }

        $stmtU = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmtU->execute([':id' => $challenge['user_id']]);
        $user = $stmtU->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$user['is_active']) {
            $pdo->exec('DELETE FROM two_factor_challenges WHERE id = "' . $challenge['id'] . '"');
            throw new RuntimeException('Utente non trovato o account non attivo.');
        }

        $otpCode = (string) random_int(100000, 999999);
        $otpExpires = gmdate('Y-m-d H:i:s', time() + 600); // 10 minuti

        $upd = $pdo->prepare('UPDATE two_factor_challenges SET email_otp_code = :code, email_otp_expires_at = :exp WHERE id = :id');
        $upd->execute([':code' => $otpCode, ':exp' => $otpExpires, ':id' => $challenge['id']]);

        $masked = self::maskEmail($user['email']);

        // Invio email tramite AlertService
        $subject = "[MailArchive] Codice di verifica 2FA: {$otpCode}";
        $html = "
            <div style='font-family: sans-serif; max-width: 500px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                <h2 style='color: #1e3a8a; text-align: center;'>MailArchive</h2>
                <p style='text-align: center;'>Il tuo codice di verifica temporaneo:</p>
                <div style='font-size: 32px; font-weight: bold; letter-spacing: 6px; text-align: center; padding: 16px; background: #f1f5f9; border-radius: 6px;'>
                    {$otpCode}
                </div>
                <p style='color: #64748b; font-size: 13px; text-align: center; margin-top: 16px;'>Valido per 10 minuti. Non condividerlo con nessuno.</p>
            </div>
        ";

        AlertService::sendEmailNotification($user['email'], $subject, $html);

        Audit::log('AUTH_2FA_EMAIL_CODE_SENT', 'USER', $user['id'], ['emailMasked' => $masked], $user['id'], $user['email']);

        return [
            'success' => true,
            'message' => "Codice di verifica inviato all'indirizzo {$masked}. Controlla la tua casella di posta."
        ];
    }

    public static function setupTotp(string $userId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException('Utente non trovato');
        }

        $secret = TotpService::generateSecret(16);
        $uri = TotpService::getOtpAuthUri($user['email'], $secret);
        $qrCodeDataUrl = TotpService::getQrCodeDataUrl($uri);

        return [
            'secret' => $secret,
            'uri' => $uri,
            'qrCodeDataUrl' => $qrCodeDataUrl
        ];
    }

    public static function enableTotp(string $userId, string $secret, string $code, array $context): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT email, password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException('Utente non trovato');
        }

        if (!TotpService::verifyToken($code, $secret)) {
            throw new RuntimeException('Codice di verifica errato. Assicurati di inserire il codice a 6 cifre generato dalla tua app Authenticator.');
        }

        $upd = $pdo->prepare('UPDATE users SET totp_secret = :sec, totp_enabled = 1 WHERE id = :id');
        $upd->execute([':sec' => $secret, ':id' => $userId]);

        Audit::log('AUTH_2FA_ENABLED', 'USER', $userId, null, $userId, $user['email'], $context['ip'] ?? null, $context['userAgent'] ?? null);
    }

    public static function disableTotp(string $userId, string $password, array $context): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT email, password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException('Utente non trovato');
        }

        if (!Crypto::verifyPassword($password, $user['password_hash'])) {
            throw new RuntimeException('Password non corretta. Impossibile disabilitare il 2FA.');
        }

        $upd = $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = :id');
        $upd->execute([':id' => $userId]);

        Audit::log('AUTH_2FA_DISABLED', 'USER', $userId, null, $userId, $user['email'], $context['ip'] ?? null, $context['userAgent'] ?? null);
    }

    public static function logout(string $sessionId, ?string $userId, ?string $userEmail, array $context): void
    {
        $pdo = Database::pdo();
        $del = $pdo->prepare('DELETE FROM sessions WHERE id = :id');
        $del->execute([':id' => $sessionId]);

        Audit::log('AUTH_LOGOUT', 'SESSION', $sessionId, null, $userId, $userEmail, $context['ip'] ?? null, $context['userAgent'] ?? null);
    }
}
