<?php
declare(strict_types=1);

namespace MailArchive\Controllers;

use MailArchive\Services\BackupJobService;
use MailArchive\Middleware\AuthMiddleware;
use MailArchive\Core\Response;

class BackupJobsController
{
    private static function getJsonBody(): array
    {
        $input = file_get_contents('php://input');
        if (!$input) {
            return $_POST;
        }
        $data = json_decode($input, true);
        return is_array($data) ? $data : $_POST;
    }

    public static function list(): void
    {
        AuthMiddleware::requireRole('ADMIN');
        $jobs = BackupJobService::listJobs();
        Response::json(['status' => 'ok', 'data' => $jobs]);
    }

    public static function getById(string $id): void
    {
        AuthMiddleware::requireRole('ADMIN');
        $job = BackupJobService::getJobById($id);
        Response::json(['status' => 'ok', 'data' => $job]);
    }

    public static function create(): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $body = self::getJsonBody();
        $job = BackupJobService::createJob($body, $actor);
        Response::json(['status' => 'ok', 'data' => $job], 201);
    }

    public static function update(string $id): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $body = self::getJsonBody();
        $job = BackupJobService::updateJob($id, $body, $actor);
        Response::json(['status' => 'ok', 'data' => $job]);
    }

    public static function delete(string $id): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $job = BackupJobService::deleteJob($id, $actor);
        Response::json(['status' => 'ok', 'data' => $job]);
    }
}
