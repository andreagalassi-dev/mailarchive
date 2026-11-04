<?php
declare(strict_types=1);

namespace MailArchive\Controllers;

use MailArchive\Services\AttachmentService;
use MailArchive\Middleware\AuthMiddleware;
use MailArchive\Core\Response;

class AttachmentsController
{
    public static function listByEmail(string $emailId): void
    {
        AuthMiddleware::requireRole('VIEWER');
        $attachments = AttachmentService::listAttachmentsByEmailId($emailId);
        Response::json(['status' => 'ok', 'data' => $attachments]);
    }

    public static function getById(string $id): void
    {
        AuthMiddleware::requireRole('VIEWER');
        $attachment = AttachmentService::getAttachmentById($id);
        Response::json(['status' => 'ok', 'data' => $attachment]);
    }

    public static function download(string $id): void
    {
        $actor = AuthMiddleware::requireRole('VIEWER');
        $att = AttachmentService::getAttachmentForDownload($id, $actor);

        $safeMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $isInline = (!empty($_GET['inline']) && in_array(strtolower($att['contentType']), $safeMimes, true));
        $disposition = $isInline ? 'inline' : 'attachment';

        $safeFilename = preg_replace('/["\r\n\\]/', '_', $att['filename']);
        $encodedFilename = rawurlencode($att['filename']);

        header("Content-Type: {$att['contentType']}");
        header("Content-Length: {$att['sizeBytes']}");
        header("Content-Disposition: {$disposition}; filename=\"{$safeFilename}\"; filename*=UTF-8''{$encodedFilename}");
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');

        readfile($att['filePath']);
        exit;
    }
}
