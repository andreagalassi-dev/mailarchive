<?php
declare(strict_types=1);

namespace MailArchive\Services;

use MailArchive\Config\Database;
use MailArchive\Config\Config;
use MailArchive\Core\Audit;
use PDO;
use RuntimeException;

class AttachmentService
{
    public static function listAttachmentsByEmailId(string $emailId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('
            SELECT id, email_id, filename, content_type, size_bytes, content_id, checksum_sha256, created_at
            FROM attachments
            WHERE email_id = :eid
            ORDER BY created_at ASC
        ');
        $stmt->execute([':eid' => $emailId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAttachmentById(string $id): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('
            SELECT id, email_id, filename, content_type, size_bytes, content_id, checksum_sha256, created_at
            FROM attachments
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $att = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$att) {
            throw new RuntimeException('Allegato non trovato');
        }

        return $att;
    }

    public static function getAttachmentForDownload(string $id, array $actor): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM attachments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $att = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$att) {
            throw new RuntimeException('Allegato non trovato');
        }

        $storageRoot = Config::getStorageDir();
        $fullPath = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $att['storage_path']);

        $realRoot = realpath($storageRoot);
        $realPath = realpath($fullPath);

        if (!$realPath || !$realRoot || !str_starts_with($realPath, $realRoot)) {
            throw new RuntimeException('Accesso negato: percorso file non consentito');
        }

        if (!file_exists($realPath)) {
            throw new RuntimeException('File dell’allegato non trovato sullo storage');
        }

        Audit::log('ATTACHMENT_DOWNLOADED', 'ATTACHMENT', $id, [
            'filename' => $att['filename'],
            'emailId' => $att['email_id'],
            'sizeBytes' => $att['size_bytes']
        ], $actor['id'] ?? null, $actor['email'] ?? null);

        return [
            'id' => $att['id'],
            'filename' => $att['filename'],
            'contentType' => $att['content_type'] ?: 'application/octet-stream',
            'sizeBytes' => (int) $att['size_bytes'],
            'filePath' => $realPath
        ];
    }
}
