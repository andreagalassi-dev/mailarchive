<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Config\Database;
use MailArchive\Config\Config;
use MailArchive\Core\Audit;
use PDO;
use RuntimeException;

class EmailService
{
    public static function listEmails(array $filters, array $actor): array
    {
        $pdo = Database::pdo();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $sortBy = (string) ($filters['sortBy'] ?? 'dateSent');
        $sortOrder = strtoupper((string) ($filters['sortOrder'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $columnMap = [
            'dateSent' => 'e.date_sent',
            'dateArchived' => 'e.date_archived',
            'subject' => 'e.subject',
            'fromAddress' => 'e.from_address',
            'sizeBytes' => 'e.size_bytes',
            'createdAt' => 'e.created_at'
        ];
        $orderCol = $columnMap[$sortBy] ?? 'e.date_sent';

        $where = [];
        $params = [];

        if (!empty($filters['mailboxId'])) {
            $where[] = 'e.mailbox_id = :mid';
            $params[':mid'] = $filters['mailboxId'];
        }

        if (!empty($filters['folder'])) {
            $where[] = 'e.folder = :folder';
            $params[':folder'] = strtoupper((string) $filters['folder']);
        }

        if (!empty($filters['fromAddress'])) {
            $where[] = 'e.from_address LIKE :from';
            $params[':from'] = '%' . $filters['fromAddress'] . '%';
        }

        if (!empty($filters['toAddresses'])) {
            $where[] = 'e.to_addresses LIKE :to';
            $params[':to'] = '%' . $filters['toAddresses'] . '%';
        }

        if (!empty($filters['subject'])) {
            $where[] = 'e.subject LIKE :subj';
            $params[':subj'] = '%' . $filters['subject'] . '%';
        }

        if (!empty($filters['dateSentFrom'])) {
            $where[] = 'e.date_sent >= :ds_from';
            $params[':ds_from'] = $filters['dateSentFrom'];
        }

        if (!empty($filters['dateSentTo'])) {
            $where[] = 'e.date_sent <= :ds_to';
            $params[':ds_to'] = $filters['dateSentTo'];
        }

        if (isset($filters['isSpam'])) {
            $where[] = 'e.is_spam = :spam';
            $params[':spam'] = filter_var($filters['isSpam'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        if (isset($filters['isFlagged'])) {
            $where[] = 'e.is_flagged = :flag';
            $params[':flag'] = filter_var($filters['isFlagged'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Conteggio totale
        $stmtCount = $pdo->prepare("SELECT COUNT(*) AS total FROM emails e {$whereSql}");
        $stmtCount->execute($params);
        $totalCount = (int) $stmtCount->fetchColumn();

        // Query elenco (SAFE_EMAIL_SELECT: esclude categoricamente body_text, body_html e raw_storage_path)
        $sql = "
            SELECT 
                e.id, e.mailbox_id, e.message_id, e.thread_id,
                e.from_address, e.from_name, e.to_addresses, e.cc_addresses, e.bcc_addresses,
                e.subject, e.date_sent, e.date_archived,
                e.has_attachments, e.size_bytes, e.is_spam, e.is_flagged, e.folder, e.created_at,
                m.id AS m_id, m.name AS m_name, m.email_address AS m_email
            FROM emails e
            INNER JOIN mailboxes m ON e.mailbox_id = m.id
            {$whereSql}
            ORDER BY {$orderCol} {$sortOrder}
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $emails = [];
        foreach ($rows as $row) {
            $emails[] = [
                'id' => $row['id'],
                'mailboxId' => $row['mailbox_id'],
                'messageId' => $row['message_id'],
                'threadId' => $row['thread_id'],
                'fromAddress' => $row['from_address'],
                'fromName' => $row['from_name'],
                'toAddresses' => $row['to_addresses'],
                'ccAddresses' => $row['cc_addresses'],
                'bccAddresses' => $row['bcc_addresses'],
                'subject' => $row['subject'],
                'dateSent' => $row['date_sent'],
                'dateArchived' => $row['date_archived'],
                'hasAttachments' => (bool) $row['has_attachments'],
                'sizeBytes' => (int) $row['size_bytes'],
                'isSpam' => (bool) $row['is_spam'],
                'isFlagged' => (bool) $row['is_flagged'],
                'folder' => $row['folder'],
                'createdAt' => $row['created_at'],
                'mailbox' => [
                    'id' => $row['m_id'],
                    'name' => $row['m_name'],
                    'emailAddress' => $row['m_email']
                ]
            ];
        }

        $totalPages = (int) ceil($totalCount / $limit);

        Audit::log('EMAIL_LIST_VIEWED', 'EMAIL', null, [
            'totalResults' => $totalCount,
            'page' => $page,
            'limit' => $limit
        ], $actor['id'] ?? null, $actor['email'] ?? null);

        return [
            'data' => $emails,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalCount' => $totalCount,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1
            ]
        ];
    }

    public static function getEmailStats(array $filters): array
    {
        $pdo = Database::pdo();
        $where = [];
        $params = [];

        if (!empty($filters['mailboxId'])) {
            $where[] = 'mailbox_id = :mid';
            $params[':mid'] = $filters['mailboxId'];
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_emails,
                SUM(CASE WHEN is_spam = 1 THEN 1 ELSE 0 END) AS spam_emails,
                SUM(CASE WHEN is_flagged = 1 THEN 1 ELSE 0 END) AS flagged_emails,
                SUM(CASE WHEN has_attachments = 1 THEN 1 ELSE 0 END) AS att_emails
            FROM emails
            {$whereSql}
        ");
        $stmt->execute($params);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalEmails = (int) ($res['total_emails'] ?? 0);
        $spamEmails = (int) ($res['spam_emails'] ?? 0);
        $flaggedEmails = (int) ($res['flagged_emails'] ?? 0);
        $emailsWithAttachments = (int) ($res['att_emails'] ?? 0);

        // Conteggio per casella
        $stmtMb = $pdo->prepare("
            SELECT mailbox_id, COUNT(*) AS cnt 
            FROM emails 
            {$whereSql}
            GROUP BY mailbox_id
        ");
        $stmtMb->execute($params);
        $mbRows = $stmtMb->fetchAll(PDO::FETCH_ASSOC);

        $mailboxes = array_map(function ($row) {
            return [
                'mailboxId' => $row['mailbox_id'],
                'emailCount' => (int) $row['cnt']
            ];
        }, $mbRows);

        return [
            'totalEmails' => $totalEmails,
            'spamEmails' => $spamEmails,
            'flaggedEmails' => $flaggedEmails,
            'emailsWithAttachments' => $emailsWithAttachments,
            'cleanEmails' => $totalEmails - $spamEmails,
            'mailboxes' => $mailboxes
        ];
    }

    public static function getEmailById(string $id, array $actor): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('
            SELECT 
                e.*,
                m.id AS m_id, m.name AS m_name, m.email_address AS m_email
            FROM emails e
            INNER JOIN mailboxes m ON e.mailbox_id = m.id
            WHERE e.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Email non trovata');
        }

        // Allegati
        $stmtAtt = $pdo->prepare('
            SELECT id, filename, content_type, size_bytes, content_id, created_at
            FROM attachments
            WHERE email_id = :eid
            ORDER BY created_at ASC
        ');
        $stmtAtt->execute([':eid' => $id]);
        $attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

        $safeHtml = (string) ($row['body_html_sanitized'] ?: '');

        // Risoluzione immagini inline CID
        if ($safeHtml !== '' && !empty($attachments)) {
            $safeHtml = preg_replace_callback('/cid:([^"\'\s>]+)/i', function ($match) use ($attachments) {
                $cleanCid = trim($match[1], '<> ');
                foreach ($attachments as $att) {
                    $attCid = trim((string) ($att['content_id'] ?? ''), '<> ');
                    if ($attCid === $cleanCid || $att['filename'] === $cleanCid) {
                        return "/api/v1/attachments/{$att['id']}/download?inline=true";
                    }
                }
                return $match[0];
            }, $safeHtml);
        }

        Audit::log('EMAIL_DETAIL_VIEWED', 'EMAIL', $id, [
            'subject' => $row['subject'],
            'fromAddress' => $row['from_address'],
            'mailboxId' => $row['mailbox_id']
        ], $actor['id'] ?? null, $actor['email'] ?? null);

        return [
            'id' => $row['id'],
            'mailboxId' => $row['mailbox_id'],
            'messageId' => $row['message_id'],
            'threadId' => $row['thread_id'],
            'fromAddress' => $row['from_address'],
            'fromName' => $row['from_name'],
            'toAddresses' => $row['to_addresses'],
            'ccAddresses' => $row['cc_addresses'],
            'bccAddresses' => $row['bcc_addresses'],
            'subject' => $row['subject'],
            'dateSent' => $row['date_sent'],
            'dateArchived' => $row['date_archived'],
            'bodyText' => $row['body_text'],
            'bodyHtmlSanitized' => $safeHtml,
            'hasAttachments' => (bool) $row['has_attachments'],
            'sizeBytes' => (int) $row['size_bytes'],
            'isSpam' => (bool) $row['is_spam'],
            'isFlagged' => (bool) $row['is_flagged'],
            'folder' => $row['folder'],
            'createdAt' => $row['created_at'],
            'mailbox' => [
                'id' => $row['m_id'],
                'name' => $row['m_name'],
                'emailAddress' => $row['m_email']
            ],
            'attachments' => array_map(function ($att) {
                return [
                    'id' => $att['id'],
                    'filename' => $att['filename'],
                    'contentType' => $att['content_type'],
                    'sizeBytes' => (int) $att['size_bytes'],
                    'contentId' => $att['content_id'],
                    'createdAt' => $att['created_at']
                ];
            }, $attachments)
        ];
    }

    public static function getEmlFilePath(string $id, array $actor): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, subject, raw_storage_path FROM emails WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $email = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$email || empty($email['raw_storage_path'])) {
            throw new RuntimeException('File EML non presente per questa email');
        }

        $storageRoot = Config::getStorageDir();
        $fullPath = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $email['raw_storage_path']);

        // Protezione Path Traversal
        $realRoot = realpath($storageRoot);
        $realPath = realpath($fullPath);

        if (!$realPath || !$realRoot || !str_starts_with($realPath, $realRoot)) {
            throw new RuntimeException('Accesso negato: percorso file non consentito');
        }

        if (!file_exists($realPath)) {
            throw new RuntimeException('Il file originale EML non è presente nello storage locale');
        }

        Audit::log('EMAIL_EML_DOWNLOADED', 'EMAIL', $id, ['subject' => $email['subject']], $actor['id'] ?? null, $actor['email'] ?? null);

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) ($email['subject'] ?: 'messaggio')) . '.eml';

        return [
            'filePath' => $realPath,
            'filename' => $safeName
        ];
    }
}
