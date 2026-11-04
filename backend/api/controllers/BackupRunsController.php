<?php
declare(strict_types=1);

namespace MailArchive\Controllers;

use MailArchive\Services\BackupRunService;
use MailArchive\Middleware\AuthMiddleware;
use MailArchive\Core\Response;

class BackupRunsController
{
    public static function list(): void
    {
        AuthMiddleware::requireRole('VIEWER');
        $result = BackupRunService::listRuns($_GET);
        Response::json([
            'status' => 'ok',
            'data' => $result['data'],
            'pagination' => $result['pagination']
        ]);
    }

    public static function getById(string $id): void
    {
        AuthMiddleware::requireRole('VIEWER');
        $run = BackupRunService::getRunById($id);
        Response::json(['status' => 'ok', 'data' => $run]);
    }
}
