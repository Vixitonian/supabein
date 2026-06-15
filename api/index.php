<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

// Global headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$config = App::get('config');
$origin = $config['CORS_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Build request object
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Strip the /api prefix — REQUEST_URI is /api/v1/... but routes are registered as /v1/...
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); // e.g. '/api'
if ($scriptDir && str_starts_with($uri, $scriptDir)) {
    $uri = substr($uri, strlen($scriptDir)) ?: '/';
}

$rawHeaders = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $name = str_replace('_', '-', substr($k, 5));
        $rawHeaders[ucwords(strtolower($name), '-')] = $v;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $rawHeaders['Content-Type'] = $_SERVER['CONTENT_TYPE'];
}

$body = [];
$rawBody = file_get_contents('php://input');
if ($rawBody && in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) {
        $body = json_decode($rawBody, true) ?? [];
    } elseif (str_contains($ct, 'multipart/form-data') || str_contains($ct, 'application/x-www-form-urlencoded')) {
        $body = $_POST;
    }
}

$request = [
    'method'  => $method,
    'uri'     => $uri,
    'query'   => $_GET,
    'body'    => $body,
    'files'   => $_FILES,
    'headers' => $rawHeaders,
    'auth'    => null,
];

// Load router and routes
require_once SUPABEIN_ROOT . '/app/router.php';

$router = new SupaBein\Router();

require_once SUPABEIN_ROOT . '/app/routes/auth_routes.php';
require_once SUPABEIN_ROOT . '/app/routes/project_routes.php';
require_once SUPABEIN_ROOT . '/app/routes/table_routes.php';
require_once SUPABEIN_ROOT . '/app/routes/data_routes.php';
require_once SUPABEIN_ROOT . '/app/routes/deploy_routes.php';
require_once SUPABEIN_ROOT . '/app/routes/project_auth_routes.php';

register_auth_routes($router);
register_project_routes($router);
register_table_routes($router);
register_data_routes($router);
register_deploy_routes($router);
register_project_auth_routes($router);

try {
    $router->dispatch($request);
} catch (Throwable $e) {
    error_log('[SupaBein] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    abort(500, $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
}
