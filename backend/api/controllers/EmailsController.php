<?php
declare(strict_types=1);

namespace MailArchive\Controllers;

use MailArchive\Services\EmailService;
use MailArchive\Services\ExportService;
use MailArchive\Middleware\AuthMiddleware;
use MailArchive\Core\Response;

class EmailsController
{
    public static function list(): void
    {
        $actor = AuthMiddleware::requireRole('VIEWER');
        $result = EmailService::listEmails($_GET, $actor);

        Response::json([
            'status' => 'ok',
            'data' => $result['data'],
            'pagination' => $result['pagination']
        ]);
    }

    public static function stats(): void
    {
        AuthMiddleware::requireRole('VIEWER');
        $stats = EmailService::getEmailStats($_GET);
        Response::json(['status' => 'ok', 'data' => $stats]);
    }

    public static function getById(string $id): void
    {
        $actor = AuthMiddleware::requireRole('VIEWER');
        $email = EmailService::getEmailById($id, $actor);
        Response::json(['status' => 'ok', 'data' => $email]);
    }

    public static function downloadEml(string $id): void
    {
        $actor = AuthMiddleware::requireRole('VIEWER');
        $fileInfo = EmailService::getEmlFilePath($id, $actor);

        header('Content-Type: message/rfc822');
        header('Content-Disposition: attachment; filename="' . rawurlencode($fileInfo['filename']) . '"');
        header('Content-Length: ' . filesize($fileInfo['filePath']));
        header('Cache-Control: no-store, private');

        readfile($fileInfo['filePath']);
        exit;
    }

    public static function export(): void
    {
        $actor = AuthMiddleware::requireRole('VIEWER');
        ExportService::export($_GET, $actor);
    }
}
