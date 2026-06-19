<?php
/**
 * SupaBein connectivity test
 *
 * Tests admin-shell and Supabein API reachability.
 * Run locally: php test-connection.php
 * Or via web: https://your-domain/test-connection.php
 *
 * DELETE this file after confirming connectivity.
 */

define('BASE_URL',      'https://supabein.dxinnovationhub.com');
define('SHELL_TOKEN',   '7f3k9Xm2pQ8nR4wL6dYv1bT5eJ0hC3sA');
define('SUPABEIN_PAT',  'sb_pat_b1e58be9018dc4e61d41ff5e63e67fc446cbefd1cae6a891b710273ab03400c9');

header('Content-Type: application/json');

function http_post(string $url, array $headers, string $body): array {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw  = @file_get_contents($url, false, $ctx);
    $meta = $http_response_header ?? [];
    $code = 0;
    foreach ($meta as $h) {
        if (preg_match('#HTTP/\S+ (\d{3})#', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    return ['status' => $code, 'body' => $raw ?: ''];
}

function http_get(string $url, array $headers): array {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw  = @file_get_contents($url, false, $ctx);
    $meta = $http_response_header ?? [];
    $code = 0;
    foreach ($meta as $h) {
        if (preg_match('#HTTP/\S+ (\d{3})#', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    return ['status' => $code, 'body' => $raw ?: ''];
}

$results = [];

// 1 — Admin shell
$shell = http_post(
    BASE_URL . '/admin-shell.php',
    ['X-Shell-Token: ' . SHELL_TOKEN, 'Content-Type: application/json'],
    json_encode(['cmd' => 'echo "admin-shell-ok" && php -r "echo PHP_VERSION;"'])
);
$results['admin_shell'] = [
    'url'    => BASE_URL . '/admin-shell.php',
    'status' => $shell['status'],
    'ok'     => $shell['status'] === 200,
    'data'   => json_decode($shell['body'], true) ?? $shell['body'],
];

// 2 — Supabein API health (GET /v1/health or /v1/auth/me)
$api = http_get(
    BASE_URL . '/api/v1/auth/me',
    ['Authorization: Bearer ' . SUPABEIN_PAT]
);
$results['supabein_api'] = [
    'url'    => BASE_URL . '/api/v1/auth/me',
    'status' => $api['status'],
    'ok'     => in_array($api['status'], [200, 401], true), // 401 = server reachable, bad token
    'data'   => json_decode($api['body'], true) ?? $api['body'],
];

$allOk = $results['admin_shell']['ok'] && $results['supabein_api']['ok'];

echo json_encode([
    'connected' => $allOk,
    'timestamp' => date('c'),
    'results'   => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
