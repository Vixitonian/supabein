<?php

declare(strict_types=1);

define('SUPABEIN_ROOT', dirname(__DIR__));

require_once SUPABEIN_ROOT . '/vendor/autoload.php';

$config = require SUPABEIN_ROOT . '/config/secrets.php';

// PDO singleton
try {
    $pdo = new PDO(
        $config['DB_DSN'],
        $config['DB_USER'],
        $config['DB_PASS'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('[SUPABEIN] [bootstrap] DB CONNECTION FAILED: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

// The session's time_zone defaults to the MySQL server's SYSTEM setting, which
// is NOT guaranteed to be UTC (on this host it isn't) — while PHP's own
// date.timezone is UTC. Without this, every CURRENT_TIMESTAMP/NOW() written to
// the DB is silently offset from the UTC the rest of the app assumes, which is
// exactly what made "just happened" activity show up as several hours old.
$pdo->exec("SET time_zone = '+00:00'");

// Simple container
final class App
{
    private static array $registry = [];

    public static function set(string $key, mixed $value): void
    {
        self::$registry[$key] = $value;
    }

    public static function get(string $key): mixed
    {
        return self::$registry[$key] ?? null;
    }
}

App::set('config', $config);
App::set('db', $pdo);

// Helpers available globally
function abort(int $status, string $message = '', array $data = []): never
{
    http_response_code($status);
    // Same no-store guarantee as json_out — error responses are live data too
    // (a cached 401/404/409 is just as wrong to replay as a cached 200).
    if (!headers_sent()) {
        header('Cache-Control: no-store');
    }
    $default = [
        400 => 'Bad request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not found',
        405 => 'Method not allowed',
        409 => 'Conflict',
        422 => 'Unprocessable entity',
        500 => 'Internal server error',
    ];
    $body = array_merge(['error' => $message ?: ($default[$status] ?? 'Error')], $data);
    echo json_encode($body);
    exit;
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    // API responses are live data and must never be served from a cache — a
    // stale cached GET (from the browser, a proxy, or an over-eager CDN) would
    // show out-of-date records with no way for the user to tell. Every JSON
    // response flows through here, so one no-store guarantees it everywhere.
    // (Binary/file responses set their own cache headers and do not use this.)
    if (!headers_sent()) {
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Write a structured log line to PHP's error log.
 * Format: [SUPABEIN] [context] message {key:val, ...}
 */
function sb_log(string $context, string $message, array $data = []): void
{
    $extra = $data ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    error_log('[SUPABEIN] [' . $context . '] ' . $message . $extra);
}

// The platform's own base domain (e.g. "dxinnovationhub.com"), derived from
// the configured API_BASE_URL rather than hardcoded -- this API always runs
// on a fixed subdomain of it (e.g. supabein.dxinnovationhub.com), so
// stripping the leftmost label is reliable and keeps this portable across
// different self-hosted installs. Deliberately NOT derived from
// $_SERVER['HTTP_HOST']: AI builds run in a detached CLI worker process
// (ai_spawn_job_worker -> app/workers/ai_worker.php) with no HTTP request at
// all, and that worker is exactly where a newly-built project's site first
// needs this to register its subdomain -- a Host-header-based version would
// silently break there.
function platform_base_domain(): string
{
    $config = App::get('config');
    $host   = strtolower(parse_url($config['API_BASE_URL'] ?? '', PHP_URL_HOST) ?? '');
    $parts  = explode('.', $host);
    return count($parts) > 1 ? implode('.', array_slice($parts, 1)) : $host;
}

function generate_project_key(string $type, int $projectId): string
{
    $config  = App::get('config');
    $payload = ['iss' => 'supabein', 'type' => $type, 'pid' => $projectId];
    return \Firebase\JWT\JWT::encode($payload, $config['JWT_SECRET'], $config['JWT_ALGO']);
}
