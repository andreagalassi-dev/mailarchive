<?php
declare(strict_types=1);

namespace MailArchive\Controllers;

use MailArchive\Services\AuthService;
use MailArchive\Services\TotpService;
use MailArchive\Middleware\AuthMiddleware;
use MailArchive\Middleware\CsrfMiddleware;
use MailArchive\Middleware\RateLimitMiddleware;
use MailArchive\Config\Config;
use MailArchive\Core\Response;

class AuthController
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

    public static function login(): void
    {
        $body = self::getJsonBody();
        $email = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            Response::error('Email e password sono obbligatorie', 400);
        }

        $clientContext = [
            'ip' => Config::getClientIp(),
            'userAgent' => Config::getUserAgent()
        ];

        RateLimitMiddleware::checkLoginRateLimit($email, $clientContext['ip']);

        $result = AuthService::login($email, $password, $clientContext);

        if (!$result['success']) {
            Response::error($result['message'], 401);
        }

        if (!empty($result['requires2fa'])) {
            Response::json([
                'status' => 'requires_2fa',
                'tempToken' => $result['tempToken'],
                'emailMasked' => $result['emailMasked'],
                'methods' => $result['methods']
            ]);
        }

        // Imposta cookie di sessione HttpOnly, Secure, SameSite=Strict
        $isHttps = Config::isProduction() || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(AuthMiddleware::SESSION_COOKIE_NAME, $result['sessionToken'], [
            'expires' => time() + (24 * 3600),
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        Response::json([
            'status' => 'ok',
            'user' => $result['user']
        ]);
    }

    public static function verify2fa(): void
    {
        $body = self::getJsonBody();
        $tempToken = (string) ($body['tempToken'] ?? '');
        $code = (string) ($body['code'] ?? '');

        if ($tempToken === '' || $code === '') {
            Response::error('tempToken e codice di verifica sono obbligatori', 400);
        }

        $clientContext = [
            'ip' => Config::getClientIp(),
            'userAgent' => Config::getUserAgent()
        ];

        $result = AuthService::verify2fa($tempToken, $code, $clientContext);

        $isHttps = Config::isProduction() || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(AuthMiddleware::SESSION_COOKIE_NAME, $result['sessionToken'], [
            'expires' => time() + (24 * 3600),
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        Response::json([
            'status' => 'ok',
            'user' => $result['user']
        ]);
    }

    public static function send2faEmail(): void
    {
        $body = self::getJsonBody();
        $tempToken = (string) ($body['tempToken'] ?? '');

        if ($tempToken === '') {
            Response::error('tempToken è obbligatorio', 400);
        }

        $result = AuthService::send2faEmail($tempToken);
        Response::json(['status' => 'ok', 'message' => $result['message']]);
    }

    public static function get2faStatus(): void
    {
        $user = AuthMiddleware::requireAuth();
        $masked = AuthService::maskEmail($user['email']);

        Response::json([
            'status' => 'ok',
            'data' => [
                'totpEnabled' => (bool) ($user['totpEnabled'] ?? false),
                'email' => $user['email'],
                'emailMasked' => $masked
            ]
        ]);
    }

    public static function setupTotp(): void
    {
        $user = AuthMiddleware::requireAuth();
        $setupData = AuthService::setupTotp($user['id']);
        Response::json(['status' => 'ok', 'data' => $setupData]);
    }

    public static function enableTotp(): void
    {
        $user = AuthMiddleware::requireAuth();
        $body = self::getJsonBody();
        $secret = (string) ($body['secret'] ?? '');
        $code = (string) ($body['code'] ?? '');

        if ($secret === '' || $code === '') {
            Response::error('Secret e codice di verifica sono obbligatori', 400);
        }

        $context = [
            'ip' => Config::getClientIp(),
            'userAgent' => Config::getUserAgent()
        ];

        AuthService::enableTotp($user['id'], $secret, $code, $context);
        Response::json(['status' => 'ok', 'message' => 'Autenticazione a due fattori attivata con successo.']);
    }

    public static function disableTotp(): void
    {
        $user = AuthMiddleware::requireAuth();
        $body = self::getJsonBody();
        $password = (string) ($body['password'] ?? '');

        if ($password === '') {
            Response::error('La password di conferma è obbligatoria', 400);
        }

        $context = [
            'ip' => Config::getClientIp(),
            'userAgent' => Config::getUserAgent()
        ];

        AuthService::disableTotp($user['id'], $password, $context);
        Response::json(['status' => 'ok', 'message' => 'Autenticazione a due fattori disattivata.']);
    }

    public static function logout(): void
    {
        $session = AuthMiddleware::getSession();
        $user = AuthMiddleware::getUser();

        $context = [
            'ip' => Config::getClientIp(),
            'userAgent' => Config::getUserAgent()
        ];

        if ($session) {
            AuthService::logout($session['id'], $user['id'] ?? null, $user['email'] ?? null, $context);
        }

        $isHttps = Config::isProduction() || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(AuthMiddleware::SESSION_COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        Response::json(['status' => 'ok', 'message' => 'Disconnessione completata con successo']);
    }

    public static function getMe(): void
    {
        $user = AuthMiddleware::requireAuth();
        Response::json(['status' => 'ok', 'user' => $user]);
    }

    public static function getCsrfToken(): void
    {
        $token = CsrfMiddleware::generateToken();
        Response::json(['csrfToken' => $token]);
    }
}
