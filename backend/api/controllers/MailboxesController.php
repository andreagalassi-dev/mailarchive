<?php
declare(strict_types=1);

namespace MailArchive\Controllers;

use MailArchive\Services\MailboxService;
use MailArchive\Services\ImapSyncService;
use MailArchive\Middleware\AuthMiddleware;
use MailArchive\Core\Response;

class MailboxesController
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
        $mailboxes = MailboxService::listMailboxes();
        Response::json(['status' => 'ok', 'data' => $mailboxes]);
    }

    public static function getById(string $id): void
    {
        AuthMiddleware::requireRole('ADMIN');
        $mailbox = MailboxService::getMailboxById($id);
        Response::json(['status' => 'ok', 'data' => $mailbox]);
    }

    public static function create(): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $body = self::getJsonBody();
        $mailbox = MailboxService::createMailbox($body, $actor);
        Response::json(['status' => 'ok', 'data' => $mailbox], 201);
    }

    public static function update(string $id): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $body = self::getJsonBody();
        $mailbox = MailboxService::updateMailbox($id, $body, $actor);
        Response::json(['status' => 'ok', 'data' => $mailbox]);
    }

    public static function deactivate(string $id): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $mailbox = MailboxService::removeMailbox($id, $actor);
        Response::json(['status' => 'ok', 'data' => $mailbox]);
    }

    public static function purge(string $id): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $body = self::getJsonBody();
        $result = MailboxService::purgeMailboxPermanently($id, $body, $actor);
        Response::json(['status' => 'ok', 'data' => $result]);
    }

    public static function testConnection(): void
    {
        AuthMiddleware::requireRole('ADMIN');
        $body = self::getJsonBody();
        $result = MailboxService::testConnection($body);

        if ($result['success']) {
            Response::json(['status' => 'ok', 'data' => $result]);
        } else {
            Response::json(['status' => 'error', 'error' => $result['error']], 400);
        }
    }

    public static function sync(string $id): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');

        if (ImapSyncService::isSyncing($id)) {
            Response::json([
                'status' => 'error',
                'message' => 'Sincronizzazione già in corso per questa casella di posta.'
            ], 409);
        }

        // Su PHP, eseguiamo la sincronizzazione direttamente
        $result = ImapSyncService::syncMailbox($id, null, $actor);

        if ($result['success']) {
            Response::json([
                'status' => 'accepted',
                'message' => 'Sincronizzazione completata con successo.',
                'data' => $result
            ], 200);
        } else {
            Response::error($result['error'] ?? 'Errore durante la sincronizzazione', 500);
        }
    }
}
