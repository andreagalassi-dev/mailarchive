<?php
declare(strict_types=1);

namespace MailArchive\Controllers;

use MailArchive\Services\SettingService;
use MailArchive\Middleware\AuthMiddleware;
use MailArchive\Core\Response;

class SettingsController
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
        $cat = isset($_GET['category']) ? (string) $_GET['category'] : null;
        $settings = SettingService::listSettings($cat);
        Response::json(['status' => 'ok', 'data' => $settings]);
    }

    public static function getByKey(string $key): void
    {
        AuthMiddleware::requireRole('ADMIN');
        $setting = SettingService::getSetting($key);
        Response::json(['status' => 'ok', 'data' => $setting]);
    }

    public static function set(string $key): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $body = self::getJsonBody();
        $setting = SettingService::setSetting($key, $body, $actor);
        Response::json(['status' => 'ok', 'data' => $setting]);
    }

    public static function delete(string $key): void
    {
        $actor = AuthMiddleware::requireRole('ADMIN');
        $setting = SettingService::deleteSetting($key, $actor);
        Response::json(['status' => 'ok', 'data' => $setting]);
    }
}
