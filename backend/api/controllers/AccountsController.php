<?php
declare(strict_types=1);

namespace MailArchive\Controllers;

use MailArchive\Services\AccountService;
use MailArchive\Middleware\AuthMiddleware;
use MailArchive\Core\Response;

class AccountsController
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
        $users = AccountService::listUsers();
        Response::json(['status' => 'ok', 'data' => $users]);
    }

    public static function getById(string $id): void
    {
        AuthMiddleware::requireRole('ADMIN');
        $user = AccountService::getUserById($id);
        Response::json(['status' => 'ok', 'data' => $user]);
    }

    public static function create(): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $body = self::getJsonBody();
        $result = AccountService::createUser($body, $actor);

        Response::json([
            'status' => 'ok',
            'data' => $result['user'],
            'initialPassword' => $result['initialPassword'],
            'wasGenerated' => $result['wasGenerated']
        ], 201);
    }

    public static function update(string $id): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $body = self::getJsonBody();
        $user = AccountService::updateUser($id, $body, $actor);
        Response::json(['status' => 'ok', 'data' => $user]);
    }

    public static function deactivate(string $id): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $user = AccountService::deactivateUser($id, $actor);
        Response::json(['status' => 'ok', 'message' => 'Account disattivato con successo', 'data' => $user]);
    }

    public static function resetPassword(string $id): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $body = self::getJsonBody();
        $result = AccountService::resetPassword($id, $body, $actor);

        Response::json([
            'status' => 'ok',
            'message' => 'Password reimpostata con successo',
            'newPassword' => $result['newPassword'],
            'wasGenerated' => $result['wasGenerated']
        ]);
    }
}
