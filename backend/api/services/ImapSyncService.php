<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Config\Database;
use MailArchive\Config\Config;
use MailArchive\Core\Audit;
use PDO;
use RuntimeException;
use Throwable;

class ImapSyncService
{
    private static array $activeSyncs = [];

    public static function isSyncing(string $mailboxId): bool
    {
        return isset(self::$activeSyncs[$mailboxId]);
    }

    public static function syncMailbox(string $mailboxId, ?string $jobId = null, ?array $actor = null): array
    {
        if (self::isSyncing($mailboxId)) {
            throw new RuntimeException('Sincronizzazione già in corso per questa casella di posta.');
        }

        self::$activeSyncs[$mailboxId] = true;
        try {
            return self::executeSync($mailboxId, $jobId, $actor);
        } finally {
            unset(self::$activeSyncs[$mailboxId]);
        }
    }

    private static function executeSync(string $mailboxId, ?string $jobId = null, ?array $actor = null): array
    {
        $pdo = Database::pdo();
        $mailbox = MailboxService::getMailboxWithDecryptedCredentials($mailboxId);

        if (!$mailbox['is_active']) {
            return ['success' => false, 'message' => 'La casella email è disattivata'];
        }

        $now = Database::now();
        $runId = Database::uuid();

        // 1. Creazione record BackupRun in stato RUNNING
        $stmtRun = $pdo->prepare('
            INSERT INTO backup_runs (id, job_id, mailbox_id, started_at, status, emails_imported, emails_skipped, total_size_bytes)
            VALUES (:id, :jid, :mid, :now, "RUNNING", 0, 0, 0)
        ');
        $stmtRun->execute([
            ':id' => $runId,
            ':jid' => $jobId,
            ':mid' => $mailboxId,
            ':now' => $now
        ]);

        $logs = [];
        $addLog = function (string $msg) use (&$logs, $mailbox) {
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
            if (count($logs) >= 150) {
                array_shift($logs);
            }
            $logs[] = $line;
        };

        $addLog("Avvio sincronizzazione per: {$mailbox['email_address']} ({$mailbox['incoming_host']}:{$mailbox['incoming_port']})");

        Audit::log('SYNC_STARTED', 'MAILBOX', $mailboxId, [
            'runId' => $runId,
            'jobId' => $jobId,
            'emailAddress' => $mailbox['email_address']
        ], $actor['id'] ?? null, $actor['email'] ?? null);

        $emailsImported = 0;
        $emailsSkipped = 0;
        $totalSizeBytes = 0;
        $socket = null;

        try {
            $host = $mailbox['incoming_host'] ?: $mailbox['host'];
            $port = (int) ($mailbox['incoming_port'] ?: $mailbox['port'] ?: 993);
            $enc = strtoupper((string) ($mailbox['incoming_encryption'] ?: $mailbox['encryption'] ?: 'SSL'));
            $isSsl = ($enc === 'SSL' || $enc === 'TLS' || $port === 993);
            $proto = $isSsl ? 'ssl://' : '';

            $ctx = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);

            $addLog("Connessione al server IMAP ({$host}:{$port})...");
            $socket = @stream_socket_client("{$proto}{$host}:{$port}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);

            if (!$socket) {
                throw new RuntimeException("Connessione IMAP fallita: {$errstr} ({$errno})");
            }

            stream_set_timeout($socket, 60);
            $banner = fgets($socket, 2048);

            // Funzioni helper comunicazione IMAP
            $tagIndex = 1;
            $sendCmd = function (string $cmd) use ($socket, &$tagIndex) {
                $tag = sprintf('A%04d', $tagIndex++);
                fputs($socket, "{$tag} {$cmd}\r\n");
                return $tag;
            };

            $readResponse = function (string $tag) use ($socket) {
                $lines = [];
                while ($line = fgets($socket, 8192)) {
                    $lines[] = $line;
                    if (str_starts_with($line, "{$tag} OK") ||
                        str_starts_with($line, "{$tag} NO") ||
                        str_starts_with($line, "{$tag} BAD")) {
                        break;
                    }
                }
                return $lines;
            };

            // Login IMAP
            $userEscaped = addcslashes($mailbox['username'], '"\\');
            $passEscaped = addcslashes($mailbox['decryptedPassword'], '"\\');
            $tag = $sendCmd("LOGIN \"{$userEscaped}\" \"{$passEscaped}\"");
            $loginLines = $readResponse($tag);
            $loginStatus = end($loginLines);

            if (!str_contains((string) $loginStatus, "{$tag} OK")) {
                throw new RuntimeException("Autenticazione IMAP fallita: " . trim((string) $loginStatus));
            }

            $addLog("Autenticazione IMAP completata con successo");

            // Rilevamento cartelle (INBOX e Sent)
            $folders = [
                ['name' => 'INBOX', 'type' => 'INBOX']
            ];

            $tagList = $sendCmd('LIST "" "*"');
            $listLines = $readResponse($tagList);

            foreach ($listLines as $l) {
                $lLower = strtolower($l);
                if (str_contains($lLower, '\\sent') ||
                    str_contains($lLower, '"sent"') ||
                    str_contains($lLower, '"posta inviata"') ||
                    str_contains($lLower, '"sent items"') ||
                    str_contains($lLower, '"sent messages"')) {
                    if (preg_match('/"([^"]+)"$/', trim($l), $m)) {
                        $folderName = $m[1];
                        if (strtoupper($folderName) !== 'INBOX') {
                            $folders[] = ['name' => $folderName, 'type' => 'SENT'];
                            $addLog("Rilevata cartella Posta Inviata: {$folderName}");
                            break;
                        }
                    }
                }
            }

            $storageDir = Config::getStorageDir();

            // Sincronizzazione cartelle
            foreach ($folders as $folderInfo) {
                $folderName = $folderInfo['name'];
                $folderType = $folderInfo['type'];
                $addLog("Apertura cartella {$folderName} ({$folderType})...");

                $tagSel = $sendCmd("SELECT \"{$folderName}\"");
                $selLines = $readResponse($tagSel);
                $selStatus = end($selLines);

                if (!str_contains((string) $selStatus, "{$tagSel} OK")) {
                    $addLog("Impossibile selezionare la cartella {$folderName}: " . trim((string) $selStatus));
                    continue;
                }

                // Cerca tutti gli UID
                $tagSearch = $sendCmd('UID SEARCH ALL');
                $searchLines = $readResponse($tagSearch);

                $uids = [];
                foreach ($searchLines as $sLine) {
                    if (str_starts_with($sLine, '* SEARCH')) {
                        $parts = explode(' ', trim(substr($sLine, 8)));
                        foreach ($parts as $p) {
                            $uidVal = trim($p);
                            if (ctype_digit($uidVal)) {
                                $uids[] = (int) $uidVal;
                            }
                        }
                    }
                }

                $addLog("Totale messaggi presenti in {$folderName}: " . count($uids));

                foreach ($uids as $uid) {
                    // Controllo se già archiviata
                    $stmtExists = $pdo->prepare('
                        SELECT id FROM emails 
                        WHERE mailbox_id = :mid AND folder = :folder AND message_id LIKE :msg_uid
                        LIMIT 1
                    ');
                    $msgUidPattern = "%<uid-{$folderType}-{$uid}@%";
                    $stmtExists->execute([':mid' => $mailboxId, ':folder' => $folderType, ':msg_uid' => $msgUidPattern]);

                    if ($stmtExists->fetch()) {
                        $emailsSkipped++;
                        continue;
                    }

                    // Scarica raw MIME tramite BODY.PEEK[]
                    $tagFetch = $sendCmd("UID FETCH {$uid} (BODY.PEEK[])");
                    $rawMime = '';
                    $fetchHeader = fgets($socket, 2048);

                    if ($fetchHeader && preg_match('/\{(\d+)\}/', $fetchHeader, $matchSize)) {
                        $literalSize = (int) $matchSize[1];
                        $readBytes = 0;
                        while ($readBytes < $literalSize && !feof($socket)) {
                            $chunk = fread($socket, min(8192, $literalSize - $readBytes));
                            $rawMime .= $chunk;
                            $readBytes += strlen($chunk);
                        }
                    }

                    $fetchTrailing = $readResponse($tagFetch);

                    if (empty($rawMime)) {
                        $emailsSkipped++;
                        continue;
                    }

                    $msgSize = strlen($rawMime);
                    if ($msgSize > 25 * 1024 * 1024) {
                        $addLog("⚠️ Messaggio UID {$uid} supera 25MB. Saltato per salvaguardia memoria.");
                        $emailsSkipped++;
                        continue;
                    }

                    // Parsing MIME RFC 822
                    $parsed = self::parseMimeMessage($rawMime, $uid, $folderType, $host);

                    // Verifica deduplicazione su Message-ID RFC 5322
                    if (!empty($parsed['messageId'])) {
                        $stmtDup = $pdo->prepare('
                            SELECT id FROM emails 
                            WHERE mailbox_id = :mid AND folder = :folder AND message_id = :mid_str
                            LIMIT 1
                        ');
                        $stmtDup->execute([
                            ':mid' => $mailboxId,
                            ':folder' => $folderType,
                            ':mid_str' => $parsed['messageId']
                        ]);
                        if ($stmtDup->fetch()) {
                            $emailsSkipped++;
                            continue;
                        }
                    }

                    // Salvataggio copia raw .eml su disco
                    $dateSent = $parsed['dateSent'];
                    $year = date('Y', strtotime($dateSent));
                    $month = date('m', strtotime($dateSent));

                    $rawRelDir = "raw/{$year}/{$month}";
                    $rawFullDir = $storageDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rawRelDir);
                    if (!is_dir($rawFullDir)) {
                        mkdir($rawFullDir, 0755, true);
                    }

                    $rawFileId = Database::uuid();
                    $rawRelPath = "{$rawRelDir}/{$rawFileId}.eml";
                    $rawFullPath = $storageDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rawRelPath);
                    file_put_contents($rawFullPath, $rawMime);

                    $emailId = Database::uuid();
                    $hasAttachments = !empty($parsed['attachments']);

                    // Inserimento email nel DB
                    $stmtEmail = $pdo->prepare('
                        INSERT INTO emails (
                            id, mailbox_id, message_id, thread_id,
                            from_address, from_name, to_addresses, cc_addresses, bcc_addresses,
                            subject, date_sent, date_archived,
                            body_text, body_html_sanitized,
                            has_attachments, size_bytes, raw_storage_path,
                            is_spam, is_flagged, folder, created_at
                        ) VALUES (
                            :id, :mid, :msg_id, :thread_id,
                            :from_addr, :from_name, :to_addr, :cc_addr, :bcc_addr,
                            :subject, :date_sent, :date_archived,
                            :body_text, :body_html,
                            :has_att, :size, :raw_path,
                            0, 0, :folder, :now
                        )
                    ');

                    $stmtEmail->execute([
                        ':id' => $emailId,
                        ':mid' => $mailboxId,
                        ':msg_id' => $parsed['messageId'],
                        ':thread_id' => $parsed['threadId'],
                        ':from_addr' => substr($parsed['fromAddress'], 0, 255),
                        ':from_name' => $parsed['fromName'] ? substr($parsed['fromName'], 0, 255) : null,
                        ':to_addr' => $parsed['toAddresses'],
                        ':cc_addr' => $parsed['ccAddresses'],
                        ':bcc_addr' => $parsed['bccAddresses'],
                        ':subject' => substr($parsed['subject'], 0, 255),
                        ':date_sent' => $dateSent,
                        ':date_archived' => $now,
                        ':body_text' => $parsed['bodyText'],
                        ':body_html' => $parsed['bodyHtmlSanitized'],
                        ':has_att' => $hasAttachments ? 1 : 0,
                        ':size' => $msgSize,
                        ':raw_path' => $rawRelPath,
                        ':folder' => $folderType,
                        ':now' => $now
                    ]);

                    // Salvataggio allegati
                    if ($hasAttachments) {
                        $attRelDir = "attachments/{$emailId}";
                        $attFullDir = $storageDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $attRelDir);
                        if (!is_dir($attFullDir)) {
                            mkdir($attFullDir, 0755, true);
                        }

                        foreach ($parsed['attachments'] as $att) {
                            $attId = Database::uuid();
                            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $att['filename']) ?: 'allegato';
                            $storageFilename = "{$attId}_{$safeFilename}";
                            $attRelPath = "{$attRelDir}/{$storageFilename}";
                            $attFullPath = $storageDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $attRelPath);

                            file_put_contents($attFullPath, $att['content']);
                            $checksum = hash('sha256', $att['content']);

                            $stmtAtt = $pdo->prepare('
                                INSERT INTO attachments (
                                    id, email_id, filename, content_type,
                                    size_bytes, content_id, storage_path, checksum_sha256, created_at
                                ) VALUES (
                                    :id, :eid, :filename, :ctype,
                                    :size, :cid, :path, :sha256, :now
                                )
                            ');
                            $stmtAtt->execute([
                                ':id' => $attId,
                                ':eid' => $emailId,
                                ':filename' => substr($att['filename'], 0, 255),
                                ':ctype' => substr($att['contentType'] ?: 'application/octet-stream', 0, 190),
                                ':size' => strlen($att['content']),
                                ':cid' => $att['contentId'] ? substr($att['contentId'], 0, 190) : null,
                                ':path' => $attRelPath,
                                ':sha256' => $checksum,
                                ':now' => $now
                            ]);
                        }
                    }

                    $emailsImported++;
                    $totalSizeBytes += $msgSize;
                }
            }

            // Logout IMAP
            $sendCmd('LOGOUT');
            fclose($socket);
            $socket = null;

            $addLog("Sincronizzazione completata: {$emailsImported} importate, {$emailsSkipped} saltate, {$totalSizeBytes} byte");

            // Aggiorna BackupRun a SUCCESS
            $updRun = $pdo->prepare('
                UPDATE backup_runs 
                SET status = "SUCCESS", completed_at = :now, emails_imported = :imp,
                    emails_skipped = :skip, total_size_bytes = :size, log_output = :logs
                WHERE id = :id
            ');
            $updRun->execute([
                ':now' => Database::now(),
                ':imp' => $emailsImported,
                ':skip' => $emailsSkipped,
                ':size' => $totalSizeBytes,
                ':logs' => implode("\n", $logs),
                ':id' => $runId
            ]);

            // Aggiorna Mailbox
            $updMb = $pdo->prepare('
                UPDATE mailboxes 
                SET last_sync_at = :now, last_sync_status = "SUCCESS", last_error_message = NULL 
                WHERE id = :id
            ');
            $updMb->execute([':now' => Database::now(), ':id' => $mailboxId]);

            Audit::log('SYNC_COMPLETED', 'MAILBOX', $mailboxId, [
                'runId' => $runId,
                'emailsImported' => $emailsImported,
                'emailsSkipped' => $emailsSkipped,
                'totalSizeBytes' => $totalSizeBytes
            ], $actor['id'] ?? null, $actor['email'] ?? null);

            return [
                'success' => true,
                'runId' => $runId,
                'emailsImported' => $emailsImported,
                'emailsSkipped' => $emailsSkipped,
                'totalSizeBytes' => $totalSizeBytes
            ];
        } catch (Throwable $e) {
            if ($socket) {
                @fclose($socket);
            }

            $errMsg = $e->getMessage();
            $addLog("ERRORE: {$errMsg}");

            // Aggiorna BackupRun a FAILED
            $updRun = $pdo->prepare('
                UPDATE backup_runs 
                SET status = "FAILED", completed_at = :now, emails_imported = :imp,
                    emails_skipped = :skip, total_size_bytes = :size, error_message = :err, log_output = :logs
                WHERE id = :id
            ');
            $updRun->execute([
                ':now' => Database::now(),
                ':imp' => $emailsImported,
                ':skip' => $emailsSkipped,
                ':size' => $totalSizeBytes,
                ':err' => $errMsg,
                ':logs' => implode("\n", $logs),
                ':id' => $runId
            ]);

            // Aggiorna Mailbox
            $updMb = $pdo->prepare('
                UPDATE mailboxes 
                SET last_sync_at = :now, last_sync_status = "ERROR", last_error_message = :err 
                WHERE id = :id
            ');
            $updMb->execute([':now' => Database::now(), ':err' => $errMsg, ':id' => $mailboxId]);

            // Crea allarme di sistema CRITICAL
            AlertService::createAlert('CRITICAL', 'BACKUP', "Fallimento sincronizzazione [{$mailbox['name']}]", "Errore casella {$mailbox['email_address']}: {$errMsg}");

            Audit::log('SYNC_FAILED', 'MAILBOX', $mailboxId, [
                'runId' => $runId,
                'error' => $errMsg
            ], $actor['id'] ?? null, $actor['email'] ?? null);

            return [
                'success' => false,
                'runId' => $runId,
                'error' => $errMsg
            ];
        }
    }

    /**
     * Parser MIME RFC 822 autonomo e leggero in puro PHP
     */
    private static function parseMimeMessage(string $rawMime, int $uid, string $folderType, string $host): array
    {
        // Separa intestazioni dal corpo
        $parts = preg_split('/\r?\n\r?\n/', $rawMime, 2);
        $headerText = $parts[0] ?? '';
        $bodyTextRaw = $parts[1] ?? '';

        // Unfolding intestazioni su più righe
        $unfoldedHeaders = preg_replace('/\r?\n[ \t]+/', ' ', $headerText);
        $headerLines = preg_split('/\r?\n/', (string) $unfoldedHeaders);

        $headers = [];
        foreach ($headerLines as $line) {
            if (str_contains($line, ':')) {
                [$name, $val] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($val);
            }
        }

        $decodeHeader = function (?string $val): string {
            if ($val === null || $val === '') return '';
            if (function_exists('mb_decode_mimeheader')) {
                return mb_decode_mimeheader($val);
            }
            return iconv_mime_decode($val, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        };

        $subject = $decodeHeader($headers['subject'] ?? '(Nessun Oggetto)');
        $fromRaw = $decodeHeader($headers['from'] ?? 'sconosciuto@localhost');
        $toRaw = $decodeHeader($headers['to'] ?? '');
        $ccRaw = isset($headers['cc']) ? $decodeHeader($headers['cc']) : null;
        $bccRaw = isset($headers['bcc']) ? $decodeHeader($headers['bcc']) : null;

        // Estrai nome e indirizzo mittente
        $fromName = null;
        $fromAddress = $fromRaw;
        if (preg_match('/^(.*?)\s*<([^>]+)>$/', $fromRaw, $mFrom)) {
            $fromName = trim($mFrom[1], " \t\n\r\0\x0B\"'");
            $fromAddress = trim($mFrom[2]);
        }

        $messageId = $headers['message-id'] ?? "<uid-{$folderType}-{$uid}@{$host}>";
        $threadId = $headers['in-reply-to'] ?? $headers['references'] ?? null;

        $dateSent = date('Y-m-d H:i:s');
        if (!empty($headers['date'])) {
            $ts = strtotime($headers['date']);
            if ($ts !== false) {
                $dateSent = date('Y-m-d H:i:s', $ts);
            }
        }

        $contentTypeHeader = $headers['content-type'] ?? 'text/plain';
        $transferEncoding = strtolower($headers['content-transfer-encoding'] ?? '');

        $bodyText = '';
        $bodyHtml = '';
        $attachments = [];

        // Verifica se multipart
        if (preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentTypeHeader, $mBoundary)) {
            $boundary = $mBoundary[1] ?: $mBoundary[2];
            self::parseMultipart($bodyTextRaw, $boundary, $bodyText, $bodyHtml, $attachments);
        } else {
            // Messaggio singolo
            $content = self::decodeBodyContent($bodyTextRaw, $transferEncoding);
            if (str_contains(strtolower($contentTypeHeader), 'text/html')) {
                $bodyHtml = $content;
            } else {
                $bodyText = $content;
            }
        }

        // Sanitizzazione HTML
        $safeHtml = '';
        if ($bodyHtml !== '') {
            $safeHtml = self::sanitizeHtml($bodyHtml);
        } elseif ($bodyText !== '') {
            $safeHtml = '<pre style="white-space: pre-wrap; word-break: break-word; font-family: inherit;">' . htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8') . '</pre>';
        }

        return [
            'messageId' => $messageId,
            'threadId' => $threadId,
            'fromAddress' => $fromAddress,
            'fromName' => $fromName,
            'toAddresses' => $toRaw,
            'ccAddresses' => $ccRaw,
            'bccAddresses' => $bccRaw,
            'subject' => $subject,
            'dateSent' => $dateSent,
            'bodyText' => $bodyText,
            'bodyHtmlSanitized' => $safeHtml,
            'attachments' => $attachments
        ];
    }

    private static function parseMultipart(string $body, string $boundary, string &$bodyText, string &$bodyHtml, array &$attachments): void
    {
        $delimiter = '--' . $boundary;
        $sections = explode($delimiter, $body);

        foreach ($sections as $section) {
            $trimmed = trim($section);
            if ($trimmed === '' || $trimmed === '--') continue;

            $parts = preg_split('/\r?\n\r?\n/', $trimmed, 2);
            $partHeader = $parts[0] ?? '';
            $partBody = $parts[1] ?? '';

            $partHeadersUnfolded = preg_replace('/\r?\n[ \t]+/', ' ', $partHeader);
            $pHeaders = [];
            foreach (preg_split('/\r?\n/', (string) $partHeadersUnfolded) as $hl) {
                if (str_contains($hl, ':')) {
                    [$hn, $hv] = explode(':', $hl, 2);
                    $pHeaders[strtolower(trim($hn))] = trim($hv);
                }
            }

            $pContentType = $pHeaders['content-type'] ?? 'text/plain';
            $pEncoding = strtolower($pHeaders['content-transfer-encoding'] ?? '');
            $pDisposition = $pHeaders['content-disposition'] ?? '';
            $pContentId = isset($pHeaders['content-id']) ? trim($pHeaders['content-id'], '<> ') : null;

            // Se nested multipart
            if (preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $pContentType, $subBound)) {
                $subBoundary = $subBound[1] ?: $subBound[2];
                self::parseMultipart($partBody, $subBoundary, $bodyText, $bodyHtml, $attachments);
                continue;
            }

            $decodedPart = self::decodeBodyContent($partBody, $pEncoding);

            // Controllo se è un allegato
            $filename = null;
            if (preg_match('/filename\*?=(?:UTF-8\'\'|")?([^";\r\n]+)"?/i', $pDisposition . ';' . $pContentType, $mFile)) {
                $filename = urldecode(trim($mFile[1], '" '));
            }

            if ($filename !== null || str_contains(strtolower($pDisposition), 'attachment') || $pContentId !== null) {
                $attachments[] = [
                    'filename' => $filename ?: 'allegato',
                    'contentType' => explode(';', $pContentType)[0],
                    'contentId' => $pContentId,
                    'content' => $decodedPart
                ];
            } elseif (str_contains(strtolower($pContentType), 'text/html') && empty($bodyHtml)) {
                $bodyHtml = $decodedPart;
            } elseif (str_contains(strtolower($pContentType), 'text/plain') && empty($bodyText)) {
                $bodyText = $decodedPart;
            }
        }
    }

    private static function decodeBodyContent(string $data, string $encoding): string
    {
        if ($encoding === 'base64') {
            return (string) base64_decode($data);
        }
        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($data);
        }
        return $data;
    }

    private static function sanitizeHtml(string $html): string
    {
        // Rimuove tag pericolosi (<script>, <object>, <embed>, <iframe>, <link>, <form>)
        $clean = preg_replace('/<(script|object|embed|iframe|link|meta|style|form)[^>]*>.*?<\/\1>/is', '', $html);
        $clean = preg_replace('/<(script|object|embed|iframe|link|meta|style|form)[^>]*>/is', '', (string) $clean);

        // Rimuove attributi on* (event handlers: onload, onerror, onclick, ecc.)
        $clean = preg_replace('/\s*on[a-z]+\s*=\s*(["\']).*?\1/is', '', (string) $clean);
        $clean = preg_replace('/\s*on[a-z]+\s*=[^ >]+/is', '', (string) $clean);

        // Rimuove javascript: e vbscript: negli URL
        $clean = preg_replace('/href\s*=\s*(["\'])\s*(javascript|vbscript):.*?\1/is', 'href="#"', (string) $clean);
        $clean = preg_replace('/src\s*=\s*(["\'])\s*(javascript|vbscript):.*?\1/is', 'src=""', (string) $clean);

        return (string) $clean;
    }
}
