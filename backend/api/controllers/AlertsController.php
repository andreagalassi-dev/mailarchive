<?php
declare(strict_types=1);

namespace MailArchive\Controllers;

use MailArchive\Services\AlertService;
use MailArchive\Middleware\AuthMiddleware;
use MailArchive\Config\Config;
use MailArchive\Core\Response;

class AlertsController
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
        AuthMiddleware::requireRole('VIEWER');
        $result = AlertService::listAlerts($_GET);
        Response::json([
            'status' => 'ok',
            'data' => $result['data'],
            'pagination' => $result['pagination']
        ]);
    }

    public static function create(): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $body = self::getJsonBody();

        $severity = (string) ($body['severity'] ?? 'INFO');
        $category = (string) ($body['category'] ?? 'SYSTEM');
        $title = (string) ($body['title'] ?? '');
        $message = (string) ($body['message'] ?? '');

        if ($title === '' || $message === '') {
            Response::error('Titolo e messaggio dell’allarme sono obbligatori', 400);
        }

        $alert = AlertService::createAlert($severity, $category, $title, $message, $actor['id']);
        Response::json(['status' => 'ok', 'data' => $alert], 201);
    }

    public static function resolve(string $id): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $alert = AlertService::resolveAlert($id, $actor['id']);
        Response::json(['status' => 'ok', 'data' => $alert]);
    }

    public static function test(): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $recipient = (string) Config::get('ALERT_RECIPIENT_EMAIL', Config::get('INITIAL_ADMIN_EMAIL', ''));

        $sent = AlertService::sendEmailNotification(
            $recipient,
            '[MailArchive Test] Notifica di Collaudo Allarmi',
            '<p>Questo è un messaggio di test inviato dalla console MailArchive per verificare la configurazione SMTP.</p>'
        );

        Response::json([
            'status' => 'ok',
            'data' => [
                'success' => $sent,
                'recipient' => $recipient,
                'message' => $sent ? 'Notifica email di prova inviata con successo' : 'Impossibile inviare email di prova (verifica configurazione SMTP)'
            ]
        ]);
    }
}
