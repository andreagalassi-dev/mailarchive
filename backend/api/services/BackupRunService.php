<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Config\Database;
use MailArchive\Core\Audit;
use PDO;
use RuntimeException;

class BackupRunService
{
    public static function listRuns(array $filters): array
    {
        $pdo = Database::pdo();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        if (!empty($filters['mailboxId'])) {
            $where[] = 'r.mailbox_id = :mid';
            $params[':mid'] = $filters['mailboxId'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'r.status = :status';
            $params[':status'] = strtoupper((string) $filters['status']);
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM backup_runs r {$whereSql}");
        $stmtCount->execute($params);
        $totalCount = (int) $stmtCount->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT 
                r.*,
                m.id AS m_id, m.name AS m_name, m.email_address AS m_email
            FROM backup_runs r
            INNER JOIN mailboxes m ON r.mailbox_id = m.id
            {$whereSql}
            ORDER BY r.started_at DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $runs = array_map(function ($r) {
            return [
                'id' => $r['id'],
                'jobId' => $r['job_id'],
                'mailboxId' => $r['mailbox_id'],
                'startedAt' => $r['started_at'],
                'completedAt' => $r['completed_at'],
                'status' => $r['status'],
                'emailsImported' => (int) $r['emails_imported'],
                'emailsSkipped' => (int) $r['emails_skipped'],
                'totalSizeBytes' => (int) $r['total_size_bytes'],
                'errorMessage' => $r['error_message'],
                'logOutput' => $r['log_output'],
                'mailbox' => [
                    'id' => $r['m_id'],
                    'name' => $r['m_name'],
                    'emailAddress' => $r['m_email']
                ]
            ];
        }, $rows);

        $totalPages = (int) ceil($totalCount / $limit);

        return [
            'data' => $runs,
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

    public static function getRunById(string $id): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('
            SELECT 
                r.*,
                m.id AS m_id, m.name AS m_name, m.email_address AS m_email
            FROM backup_runs r
            INNER JOIN mailboxes m ON r.mailbox_id = m.id
            WHERE r.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$r) {
            throw new RuntimeException('Esecuzione di backup non trovata');
        }

        return [
            'id' => $r['id'],
            'jobId' => $r['job_id'],
            'mailboxId' => $r['mailbox_id'],
            'startedAt' => $r['started_at'],
            'completedAt' => $r['completed_at'],
            'status' => $r['status'],
            'emailsImported' => (int) $r['emails_imported'],
            'emailsSkipped' => (int) $r['emails_skipped'],
            'totalSizeBytes' => (int) $r['total_size_bytes'],
            'errorMessage' => $r['error_message'],
            'logOutput' => $r['log_output'],
            'mailbox' => [
                'id' => $r['m_id'],
                'name' => $r['m_name'],
                'emailAddress' => $r['m_email']
            ]
        ];
    }
}
