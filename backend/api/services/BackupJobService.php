<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Config\Database;
use MailArchive\Core\Audit;
use PDO;
use RuntimeException;

class BackupJobService
{
    public static function listJobs(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query('
            SELECT 
                j.*,
                m.id AS m_id, m.name AS m_name, m.email_address AS m_email
            FROM backup_jobs j
            INNER JOIN mailboxes m ON j.mailbox_id = m.id
            ORDER BY j.created_at DESC
        ');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return [
                'id' => $r['id'],
                'mailboxId' => $r['mailbox_id'],
                'scheduleCron' => $r['schedule_cron'],
                'status' => $r['status'],
                'lastRunAt' => $r['last_run_at'],
                'nextRunAt' => $r['next_run_at'],
                'createdAt' => $r['created_at'],
                'updatedAt' => $r['updated_at'],
                'mailbox' => [
                    'id' => $r['m_id'],
                    'name' => $r['m_name'],
                    'emailAddress' => $r['m_email']
                ]
            ];
        }, $rows);
    }

    public static function getJobById(string $id): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('
            SELECT 
                j.*,
                m.id AS m_id, m.name AS m_name, m.email_address AS m_email
            FROM backup_jobs j
            INNER JOIN mailboxes m ON j.mailbox_id = m.id
            WHERE j.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$r) {
            throw new RuntimeException('Job di backup non trovato');
        }

        return [
            'id' => $r['id'],
            'mailboxId' => $r['mailbox_id'],
            'scheduleCron' => $r['schedule_cron'],
            'status' => $r['status'],
            'lastRunAt' => $r['last_run_at'],
            'nextRunAt' => $r['next_run_at'],
            'createdAt' => $r['created_at'],
            'updatedAt' => $r['updated_at'],
            'mailbox' => [
                'id' => $r['m_id'],
                'name' => $r['m_name'],
                'emailAddress' => $r['m_email']
            ]
        ];
    }

    public static function createJob(array $input, array $actor): array
    {
        $pdo = Database::pdo();
        $mailboxId = (string) ($input['mailboxId'] ?? '');

        // Verifica esistenza mailbox
        $stmtMb = $pdo->prepare('SELECT id FROM mailboxes WHERE id = :id LIMIT 1');
        $stmtMb->execute([':id' => $mailboxId]);
        if (!$stmtMb->fetch()) {
            throw new RuntimeException('Mailbox associata non trovata');
        }

        $id = Database::uuid();
        $cron = trim((string) ($input['scheduleCron'] ?? '0 * * * *'));
        $status = strtoupper((string) ($input['status'] ?? 'IDLE'));
        $now = Database::now();

        $stmt = $pdo->prepare('
            INSERT INTO backup_jobs (id, mailbox_id, schedule_cron, status, created_at, updated_at)
            VALUES (:id, :mid, :cron, :status, :now, :now)
        ');
        $stmt->execute([
            ':id' => $id,
            ':mid' => $mailboxId,
            ':cron' => $cron,
            ':status' => $status,
            ':now' => $now
        ]);

        Audit::log('BACKUP_JOB_CREATED', 'BACKUP_JOB', $id, ['mailboxId' => $mailboxId, 'cron' => $cron], $actor['id'] ?? null, $actor['email'] ?? null);

        return self::getJobById($id);
    }

    public static function updateJob(string $id, array $input, array $actor): array
    {
        $pdo = Database::pdo();
        $existing = self::getJobById($id);
        $updates = [];
        $params = [':id' => $id, ':now' => Database::now()];

        if (isset($input['scheduleCron'])) {
            $updates[] = 'schedule_cron = :cron';
            $params[':cron'] = trim((string) $input['scheduleCron']);
        }

        if (isset($input['status'])) {
            $updates[] = 'status = :status';
            $params[':status'] = strtoupper((string) $input['status']);
        }

        if (!empty($updates)) {
            $sql = 'UPDATE backup_jobs SET ' . implode(', ', $updates) . ', updated_at = :now WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            Audit::log('BACKUP_JOB_UPDATED', 'BACKUP_JOB', $id, ['fields' => array_keys($input)], $actor['id'] ?? null, $actor['email'] ?? null);
        }

        return self::getJobById($id);
    }

    public static function deleteJob(string $id, array $actor): array
    {
        $pdo = Database::pdo();
        $existing = self::getJobById($id);

        $stmt = $pdo->prepare('DELETE FROM backup_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);

        Audit::log('BACKUP_JOB_DELETED', 'BACKUP_JOB', $id, null, $actor['id'] ?? null, $actor['email'] ?? null);

        return $existing;
    }
}
