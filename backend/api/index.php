<?php
declare(strict_types=1);

/**
 * api/index.php
 * Entrypoint router per MailArchive API REST v1
 */

// 1. Registrazione Autoloader PSR-4 per MailArchive\*
spl_autoload_register(function (string $class) {
    $prefix = 'MailArchive\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    $path = __DIR__ . DIRECTORY_SEPARATOR;

    // Converte namespace in percorsi:
    // MailArchive\Config\Config -> api/config/Config.php
    // MailArchive\Core\Crypto -> api/core/Crypto.php
    // MailArchive\Middleware\AuthMiddleware -> api/middleware/AuthMiddleware.php
    // MailArchive\Services\AuthService -> api/services/AuthService.php
    // MailArchive\Controllers\AuthController -> api/controllers/AuthController.php
    if (count($parts) > 1) {
        $category = strtolower($parts[0]);
        $className = $parts[1];
        $path .= $category . DIRECTORY_SEPARATOR . $className . '.php';
    } else {
        $path .= $parts[0] . '.php';
    }

    if (file_exists($path)) {
        require_once $path;
    }
});

use MailArchive\Config\Config;
use MailArchive\Core\Response;
use MailArchive\Middleware\RateLimitMiddleware;
use MailArchive\Middleware\CsrfMiddleware;

use MailArchive\Controllers\AuthController;
use MailArchive\Controllers\AccountsController;
use MailArchive\Controllers\MailboxesController;
use MailArchive\Controllers\EmailsController;
use MailArchive\Controllers\AttachmentsController;
use MailArchive\Controllers\BackupJobsController;
use MailArchive\Controllers\BackupRunsController;
use MailArchive\Controllers\SettingsController;
use MailArchive\Controllers\AlertsController;
use MailArchive\Controllers\AuditController;

// 2. Gestione Globale delle Eccezioni
set_exception_handler(function (Throwable $e) {
    $code = $e->getCode();
    $status = ($code >= 400 && $code < 600) ? (int) $code : 500;
    if ($status === 500 && str_contains($e->getMessage(), 'non trovata')) {
        $status = 404;
    }
    Response::error($e->getMessage(), $status);
});

// 3. Blocco proattivo bot, crawler e sonde malevole
RateLimitMiddleware::checkCrawler();

// 4. Header CORS e Sicurezza
// La SPA e' same-origin: CORS con credenziali solo per l'URL configurato in APP_URL.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && hash_equals(Config::getAppUrl(), rtrim($origin, '/'))) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    header('Vary: Origin');
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 5. Analisi URI
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?? '/';

// Normalizzazione prefisso
$path = preg_replace('#^/index\.php#', '', $path);
if ($path === '' || $path === false) {
    $path = '/';
}

// Endpoint base /health e /ping
if ($path === '/health') {
    Response::json([
        'status' => 'ok',
        'app' => 'MailArchive',
        'version' => '1.0.0',
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
    ]);
}

if ($path === '/ping') {
    header('Content-Type: text/plain');
    echo 'pong';
    exit;
}

// 6. Protezione CSRF su chiamate mutative API (esclusi login e verifica 2FA pubblici)
$publicAuthPaths = [
    '/api/v1/auth/csrf-token',
    '/api/v1/auth/login',
    '/api/v1/auth/2fa/verify',
    '/api/v1/auth/2fa/send-email'
];

if (str_starts_with($path, '/api/v1') && !in_array($path, $publicAuthPaths, true)) {
    CsrfMiddleware::validate();
}

// 7. Dispatching Route API v1

// --- AUTH ---
if ($path === '/api/v1/auth/csrf-token' && $method === 'GET') {
    AuthController::getCsrfToken();
}
if ($path === '/api/v1/auth/login' && $method === 'POST') {
    AuthController::login();
}
if ($path === '/api/v1/auth/2fa/verify' && $method === 'POST') {
    AuthController::verify2fa();
}
if ($path === '/api/v1/auth/2fa/send-email' && $method === 'POST') {
    AuthController::send2faEmail();
}
if ($path === '/api/v1/auth/2fa/status' && $method === 'GET') {
    AuthController::get2faStatus();
}
if ($path === '/api/v1/auth/2fa/setup' && $method === 'POST') {
    AuthController::setupTotp();
}
if ($path === '/api/v1/auth/2fa/enable' && $method === 'POST') {
    AuthController::enableTotp();
}
if ($path === '/api/v1/auth/2fa/disable' && $method === 'POST') {
    AuthController::disableTotp();
}
if ($path === '/api/v1/auth/logout' && $method === 'POST') {
    AuthController::logout();
}
if ($path === '/api/v1/auth/me' && $method === 'GET') {
    AuthController::getMe();
}

// --- ACCOUNTS ---
if ($path === '/api/v1/accounts' && $method === 'GET') {
    AccountsController::list();
}
if ($path === '/api/v1/accounts' && $method === 'POST') {
    AccountsController::create();
}
if (preg_match('#^/api/v1/accounts/([^/]+)/reset-password$#', $path, $m) && $method === 'POST') {
    AccountsController::resetPassword($m[1]);
}
if (preg_match('#^/api/v1/accounts/([^/]+)$#', $path, $m)) {
    if ($method === 'GET') AccountsController::getById($m[1]);
    if ($method === 'PUT') AccountsController::update($m[1]);
    if ($method === 'DELETE') AccountsController::deactivate($m[1]);
}

// --- MAILBOXES ---
if ($path === '/api/v1/mailboxes' && $method === 'GET') {
    MailboxesController::list();
}
if ($path === '/api/v1/mailboxes' && $method === 'POST') {
    MailboxesController::create();
}
if ($path === '/api/v1/mailboxes/test-connection' && $method === 'POST') {
    MailboxesController::testConnection();
}
if (preg_match('#^/api/v1/mailboxes/([^/]+)/purge$#', $path, $m) && $method === 'POST') {
    MailboxesController::purge($m[1]);
}
if (preg_match('#^/api/v1/mailboxes/([^/]+)/sync$#', $path, $m) && $method === 'POST') {
    MailboxesController::sync($m[1]);
}
if (preg_match('#^/api/v1/mailboxes/([^/]+)$#', $path, $m)) {
    if ($method === 'GET') MailboxesController::getById($m[1]);
    if ($method === 'PUT') MailboxesController::update($m[1]);
    if ($method === 'DELETE') MailboxesController::deactivate($m[1]);
}

// --- EMAILS ---
if ($path === '/api/v1/emails/stats' && $method === 'GET') {
    EmailsController::stats();
}
if ($path === '/api/v1/emails/export' && $method === 'GET') {
    EmailsController::export();
}
if ($path === '/api/v1/emails' && $method === 'GET') {
    EmailsController::list();
}
if (preg_match('#^/api/v1/emails/([^/]+)/attachments$#', $path, $m) && $method === 'GET') {
    AttachmentsController::listByEmail($m[1]);
}
if (preg_match('#^/api/v1/emails/([^/]+)/download-eml$#', $path, $m) && $method === 'GET') {
    EmailsController::downloadEml($m[1]);
}
if (preg_match('#^/api/v1/emails/([^/]+)$#', $path, $m) && $method === 'GET') {
    EmailsController::getById($m[1]);
}

// --- ATTACHMENTS ---
if (preg_match('#^/api/v1/attachments/([^/]+)/download$#', $path, $m) && $method === 'GET') {
    AttachmentsController::download($m[1]);
}
if (preg_match('#^/api/v1/attachments/([^/]+)$#', $path, $m) && $method === 'GET') {
    AttachmentsController::getById($m[1]);
}

// --- BACKUP JOBS ---
if ($path === '/api/v1/backup-jobs' && $method === 'GET') {
    BackupJobsController::list();
}
if ($path === '/api/v1/backup-jobs' && $method === 'POST') {
    BackupJobsController::create();
}
if (preg_match('#^/api/v1/backup-jobs/([^/]+)$#', $path, $m)) {
    if ($method === 'GET') BackupJobsController::getById($m[1]);
    if ($method === 'PUT') BackupJobsController::update($m[1]);
    if ($method === 'DELETE') BackupJobsController::delete($m[1]);
}

// --- BACKUP RUNS ---
if ($path === '/api/v1/backup-runs' && $method === 'GET') {
    BackupRunsController::list();
}
if (preg_match('#^/api/v1/backup-runs/([^/]+)$#', $path, $m) && $method === 'GET') {
    BackupRunsController::getById($m[1]);
}

// --- SETTINGS ---
if ($path === '/api/v1/settings' && $method === 'GET') {
    SettingsController::list();
}
if (preg_match('#^/api/v1/settings/([^/]+)$#', $path, $m)) {
    if ($method === 'GET') SettingsController::getByKey($m[1]);
    if ($method === 'PUT') SettingsController::set($m[1]);
    if ($method === 'DELETE') SettingsController::delete($m[1]);
}

// --- ALERTS ---
if ($path === '/api/v1/alerts' && $method === 'GET') {
    AlertsController::list();
}
if ($path === '/api/v1/alerts' && $method === 'POST') {
    AlertsController::create();
}
if ($path === '/api/v1/alerts/test' && $method === 'POST') {
    AlertsController::test();
}
if (preg_match('#^/api/v1/alerts/([^/]+)/resolve$#', $path, $m) && $method === 'PUT') {
    AlertsController::resolve($m[1]);
}

// --- AUDIT LOGS ---
if ($path === '/api/v1/audit-logs' && $method === 'GET') {
    AuditController::listAuditLogs();
}
if ($path === '/api/v1/audit-logs/login-attempts' && $method === 'GET') {
    AuditController::listLoginAttempts();
}

// Risorsa non trovata
Response::error('Risorsa non trovata', 404);
