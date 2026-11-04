<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Config\Database;
use MailArchive\Config\Config;
use MailArchive\Core\Crypto;
use MailArchive\Core\Audit;
use PDO;
use RuntimeException;
use Throwable;

class MailboxService
{
    public static function listMailboxes(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query('
            SELECT 
                id, name, email_address, provider, auth_type, status,
                incoming_host, incoming_port, incoming_encryption,
                outgoing_host, outgoing_port, outgoing_encryption,
                host, port, encryption, username,
                sync_frequency_minutes, is_active,
                last_sync_at, last_sync_status, last_error_message,
                created_at, updated_at
            FROM mailboxes
            ORDER BY created_at DESC
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getMailboxById(string $id): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('
            SELECT 
                id, name, email_address, provider, auth_type, status,
                incoming_host, incoming_port, incoming_encryption,
                outgoing_host, outgoing_port, outgoing_encryption,
                host, port, encryption, username,
                sync_frequency_minutes, is_active,
                last_sync_at, last_sync_status, last_error_message,
                created_at, updated_at
            FROM mailboxes
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $mb = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mb) {
            throw new RuntimeException('Mailbox non trovata');
        }

        return $mb;
    }

    public static function getMailboxWithDecryptedCredentials(string $id): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM mailboxes WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $mb = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mb) {
            throw new RuntimeException('Mailbox non trovata');
        }

        $mb['decryptedPassword'] = Crypto::decryptSecret($mb['encrypted_password']);
        return $mb;
    }

    public static function createMailbox(array $input, array $actor): array
    {
        $pdo = Database::pdo();
        $email = strtolower(trim((string) ($input['emailAddress'] ?? $input['email_address'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Indirizzo email non valido');
        }

        // Verifica unicità
        $stmtCheck = $pdo->prepare('SELECT id FROM mailboxes WHERE email_address = :email LIMIT 1');
        $stmtCheck->execute([':email' => $email]);
        if ($stmtCheck->fetch()) {
            throw new RuntimeException("Una casella con l'indirizzo {$email} esiste già.");
        }

        $id = Database::uuid();
        $name = trim((string) ($input['name'] ?? $email));
        $provider = strtoupper((string) ($input['provider'] ?? 'CUSTOM'));
        $authType = strtoupper((string) ($input['authType'] ?? $input['auth_type'] ?? 'PASSWORD'));

        $incomingHost = trim((string) ($input['incomingHost'] ?? $input['host'] ?? ''));
        $incomingPort = (int) ($input['incomingPort'] ?? $input['port'] ?? 993);
        $incomingEnc = strtoupper((string) ($input['incomingEncryption'] ?? $input['encryption'] ?? 'SSL'));

        $outgoingHost = trim((string) ($input['outgoingHost'] ?? ''));
        $outgoingPort = (int) ($input['outgoingPort'] ?? 465);
        $outgoingEnc = strtoupper((string) ($input['outgoingEncryption'] ?? 'SSL'));

        $username = trim((string) ($input['username'] ?? $email));
        $rawPassword = (string) ($input['password'] ?? '');

        if ($rawPassword === '') {
            throw new RuntimeException('La password della casella è obbligatoria');
        }

        $encryptedPassword = Crypto::encryptSecret($rawPassword);
        $syncFreq = max(5, (int) ($input['syncFrequencyMinutes'] ?? $input['sync_frequency_minutes'] ?? 60));
        $now = Database::now();

        $stmt = $pdo->prepare('
            INSERT INTO mailboxes (
                id, name, email_address, provider, auth_type, status,
                incoming_host, incoming_port, incoming_encryption,
                outgoing_host, outgoing_port, outgoing_encryption,
                host, port, encryption, username, encrypted_password,
                sync_frequency_minutes, is_active, created_at, updated_at
            ) VALUES (
                :id, :name, :email, :provider, :auth_type, "ACTIVE",
                :in_host, :in_port, :in_enc,
                :out_host, :out_port, :out_enc,
                :host, :port, :enc, :username, :enc_pass,
                :sync_freq, 1, :now, :now
            )
        ');

        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':email' => $email,
            ':provider' => $provider,
            ':auth_type' => $authType,
            ':in_host' => $incomingHost,
            ':in_port' => $incomingPort,
            ':in_enc' => $incomingEnc,
            ':out_host' => $outgoingHost,
            ':out_port' => $outgoingPort,
            ':out_enc' => $outgoingEnc,
            ':host' => $incomingHost,
            ':port' => $incomingPort,
            ':enc' => $incomingEnc,
            ':username' => $username,
            ':enc_pass' => $encryptedPassword,
            ':sync_freq' => $syncFreq,
            ':now' => $now
        ]);

        // Creazione automatica BackupJob predefinito
        $jobId = Database::uuid();
        $stmtJob = $pdo->prepare('
            INSERT INTO backup_jobs (id, mailbox_id, schedule_cron, status, created_at, updated_at)
            VALUES (:id, :mid, "0 * * * *", "IDLE", :now, :now)
        ');
        $stmtJob->execute([':id' => $jobId, ':mid' => $id, ':now' => $now]);

        Audit::log('MAILBOX_CREATED', 'MAILBOX', $id, ['emailAddress' => $email, 'name' => $name], $actor['id'] ?? null, $actor['email'] ?? null);

        return self::getMailboxById($id);
    }

    public static function updateMailbox(string $id, array $input, array $actor): array
    {
        $pdo = Database::pdo();
        $existing = self::getMailboxById($id);
        $now = Database::now();

        $updates = [];
        $params = [':id' => $id, ':now' => $now];

        if (isset($input['name'])) {
            $updates[] = 'name = :name';
            $params[':name'] = trim((string) $input['name']);
        }

        if (isset($input['incomingHost']) || isset($input['host'])) {
            $host = trim((string) ($input['incomingHost'] ?? $input['host']));
            $updates[] = 'incoming_host = :in_host, host = :host';
            $params[':in_host'] = $host;
            $params[':host'] = $host;
        }

        if (isset($input['incomingPort']) || isset($input['port'])) {
            $port = (int) ($input['incomingPort'] ?? $input['port']);
            $updates[] = 'incoming_port = :in_port, port = :port';
            $params[':in_port'] = $port;
            $params[':port'] = $port;
        }

        if (isset($input['incomingEncryption']) || isset($input['encryption'])) {
            $enc = strtoupper((string) ($input['incomingEncryption'] ?? $input['encryption']));
            $updates[] = 'incoming_encryption = :in_enc, encryption = :enc';
            $params[':in_enc'] = $enc;
            $params[':enc'] = $enc;
        }

        if (isset($input['outgoingHost'])) {
            $updates[] = 'outgoing_host = :out_host';
            $params[':out_host'] = trim((string) $input['outgoingHost']);
        }

        if (isset($input['outgoingPort'])) {
            $updates[] = 'outgoing_port = :out_port';
            $params[':out_port'] = (int) $input['outgoingPort'];
        }

        if (isset($input['outgoingEncryption'])) {
            $updates[] = 'outgoing_encryption = :out_enc';
            $params[':out_enc'] = strtoupper((string) $input['outgoingEncryption']);
        }

        if (isset($input['username'])) {
            $updates[] = 'username = :username';
            $params[':username'] = trim((string) $input['username']);
        }

        if (!empty($input['password'])) {
            $updates[] = 'encrypted_password = :enc_pass';
            $params[':enc_pass'] = Crypto::encryptSecret((string) $input['password']);
        }

        if (isset($input['syncFrequencyMinutes'])) {
            $updates[] = 'sync_frequency_minutes = :freq';
            $params[':freq'] = max(5, (int) $input['syncFrequencyMinutes']);
        }

        if (isset($input['isActive'])) {
            $updates[] = 'is_active = :act, status = :status';
            $isActive = filter_var($input['isActive'], FILTER_VALIDATE_BOOLEAN);
            $params[':act'] = $isActive ? 1 : 0;
            $params[':status'] = $isActive ? 'ACTIVE' : 'PAUSED';
        }

        if (!empty($updates)) {
            $sql = 'UPDATE mailboxes SET ' . implode(', ', $updates) . ', updated_at = :now WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            Audit::log('MAILBOX_UPDATED', 'MAILBOX', $id, ['updatedFields' => array_keys($input)], $actor['id'] ?? null, $actor['email'] ?? null);
        }

        return self::getMailboxById($id);
    }

    public static function removeMailbox(string $id, array $actor): array
    {
        $pdo = Database::pdo();
        $mb = self::getMailboxById($id);
        $now = Database::now();

        $stmt = $pdo->prepare('
            UPDATE mailboxes 
            SET is_active = 0, status = "REMOVED", updated_at = :now 
            WHERE id = :id
        ');
        $stmt->execute([':now' => $now, ':id' => $id]);

        // Disattiva anche i backup_jobs
        $stmtJob = $pdo->prepare('UPDATE backup_jobs SET status = "PAUSED", updated_at = :now WHERE mailbox_id = :mid');
        $stmtJob->execute([':now' => $now, ':mid' => $id]);

        Audit::log('MAILBOX_DEACTIVATED', 'MAILBOX', $id, ['emailAddress' => $mb['email_address']], $actor['id'] ?? null, $actor['email'] ?? null);

        return self::getMailboxById($id);
    }

    public static function purgeMailboxPermanently(string $id, array $input, array $actor): array
    {
        $pdo = Database::pdo();
        $mb = self::getMailboxById($id);

        // 1. Verifica conferma scritta
        $expectedConfirmation = "ELIMINA {$mb['email_address']}";
        $confirmation = trim((string) ($input['confirmationText'] ?? ''));
        if ($confirmation !== $expectedConfirmation) {
            throw new RuntimeException("Testo di conferma non valido. Digitare esattamente: {$expectedConfirmation}");
        }

        // 2. Verifica password amministratore
        $stmtAdmin = $pdo->prepare('SELECT password_hash, totp_secret, totp_enabled FROM users WHERE id = :uid LIMIT 1');
        $stmtAdmin->execute([':uid' => $actor['id']]);
        $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

        if (!$admin || !Crypto::verifyPassword((string) ($input['adminPassword'] ?? ''), $admin['password_hash'])) {
            throw new RuntimeException('Password dell’amministratore non corretta.');
        }

        // 3. Verifica codice 2FA se attivo
        if ($admin['totp_enabled'] && !empty($admin['totp_secret'])) {
            $totpCode = trim((string) ($input['totpCode'] ?? ''));
            if (!TotpService::verifyToken($totpCode, $admin['totp_secret'])) {
                throw new RuntimeException('Codice di sicurezza 2FA non valido o scaduto.');
            }
        }

        // 4. Rimozione file fisici su filesystem
        $storageDir = Config::getStorageDir();

        // Elimina allegati
        $stmtAtt = $pdo->prepare('
            SELECT a.storage_path 
            FROM attachments a
            INNER JOIN emails e ON a.email_id = e.id
            WHERE e.mailbox_id = :mid
        ');
        $stmtAtt->execute([':mid' => $id]);
        $attachments = $stmtAtt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($attachments as $relPath) {
            $fullPath = $storageDir . DIRECTORY_SEPARATOR . ltrim((string) $relPath, '/\\');
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        // Elimina cartelle allegati per email
        $stmtEmails = $pdo->prepare('SELECT id, raw_storage_path FROM emails WHERE mailbox_id = :mid');
        $stmtEmails->execute([':mid' => $id]);
        $emails = $stmtEmails->fetchAll(PDO::FETCH_ASSOC);

        foreach ($emails as $em) {
            if (!empty($em['raw_storage_path'])) {
                $emlPath = $storageDir . DIRECTORY_SEPARATOR . ltrim((string) $em['raw_storage_path'], '/\\');
                if (file_exists($emlPath)) {
                    @unlink($emlPath);
                }
            }
            $attDir = $storageDir . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR . $em['id'];
            if (is_dir($attDir)) {
                @rmdir($attDir);
            }
        }

        // 5. Cancellazione a cascata sul database
        $stmtDel = $pdo->prepare('DELETE FROM mailboxes WHERE id = :id');
        $stmtDel->execute([':id' => $id]);

        Audit::log('MAILBOX_PURGED_PERMANENTLY', 'MAILBOX', $id, [
            'emailAddress' => $mb['email_address'],
            'purgedEmailsCount' => count($emails)
        ], $actor['id'] ?? null, $actor['email'] ?? null);

        return [
            'success' => true,
            'message' => "La casella {$mb['email_address']} e tutti i messaggi archiviati sono stati eliminati definitivamente."
        ];
    }

    public static function testConnection(array $params): array
    {
        $host = trim((string) ($params['incomingHost'] ?? $params['host'] ?? ''));
        $port = (int) ($params['incomingPort'] ?? $params['port'] ?? 993);
        $enc = strtoupper((string) ($params['incomingEncryption'] ?? $params['encryption'] ?? 'SSL'));
        $username = trim((string) ($params['username'] ?? ''));
        $password = (string) ($params['password'] ?? '');

        if ($host === '' || $username === '' || $password === '') {
            return ['success' => false, 'error' => 'Host, username e password sono obbligatori'];
        }

        $isSsl = ($enc === 'SSL' || $enc === 'TLS' || $port === 993);
        $protocol = $isSsl ? 'ssl://' : '';

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $socket = @stream_socket_client(
            "{$protocol}{$host}:{$port}",
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $ctx
        );

        if (!$socket) {
            return ['success' => false, 'error' => "Impossibile connettersi a {$host}:{$port} - {$errstr} ({$errno})"];
        }

        stream_set_timeout($socket, 15);
        $banner = fgets($socket, 1024);

        if (!$banner || !str_starts_with($banner, '* OK')) {
            fclose($socket);
            return ['success' => false, 'error' => "Risposta non valida dal server IMAP: {$banner}"];
        }

        // Esegui LOGIN
        $tag = 'TAG1';
        $userEscaped = addcslashes($username, '"\\');
        $passEscaped = addcslashes($password, '"\\');
        fputs($socket, "{$tag} LOGIN \"{$userEscaped}\" \"{$passEscaped}\"\r\n");

        $loginResponse = '';
        while ($line = fgets($socket, 1024)) {
            $loginResponse .= $line;
            if (str_starts_with($line, "{$tag} OK") || str_starts_with($line, "{$tag} NO") || str_starts_with($line, "{$tag} BAD")) {
                break;
            }
        }

        fputs($socket, "TAG2 LOGOUT\r\n");
        fclose($socket);

        if (str_contains($loginResponse, "{$tag} OK")) {
            return [
                'success' => true,
                'message' => 'Connessione stabilita con successo e credenziali IMAP validate.'
            ];
        }

        return [
            'success' => false,
            'error' => "Autenticazione IMAP fallita: " . trim($loginResponse)
        ];
    }
}
