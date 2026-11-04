<?php
declare(strict_types=1);

/**
 * scripts/dev-router.php
 * Router locale per PHP Built-in Web Server:
 *   php -S 127.0.0.1:8089 scripts/dev-router.php
 * Simula fedelmente le regole di rewrite e sicurezza di Apache .htaccess
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// 1. Protezione cartelle e file sensibili (403 Forbidden come .htaccess)
if (preg_match('#^/(\.env|\.git|storage|config|cron|database|src|node_modules|scripts)(/|$)#', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo '403 Forbidden';
    exit;
}

// 2. Servizio file statici se esistenti (asset JS/CSS, immagini, favicon)
$filePath = dirname(__DIR__) . $uri;
if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    return false; // Il server built-in serve il file statico direttamente
}

// 3. Routing verso le API REST
if (str_starts_with($uri, '/api/') || $uri === '/health' || $uri === '/ping') {
    require dirname(__DIR__) . '/api/index.php';
    exit;
}

// 4. Fallback verso index.html per la SPA
$indexPath = dirname(__DIR__) . '/index.html';
if (file_exists($indexPath)) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($indexPath);
    exit;
}

http_response_code(404);
echo 'Not Found';
