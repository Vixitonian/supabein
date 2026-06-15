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
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

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
function abort(int $status, string $message = ''): never
{
    http_response_code($status);
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
    echo json_encode(['error' => $message ?: ($default[$status] ?? 'Error')]);
    exit;
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
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

function generate_project_key(string $type, int $projectId): string
{
    $config  = App::get('config');
    $payload = ['iss' => 'supabein', 'type' => $type, 'pid' => $projectId];
    return \Firebase\JWT\JWT::encode($payload, $config['JWT_SECRET'], $config['JWT_ALGO']);
}
