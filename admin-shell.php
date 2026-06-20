<?php
/**
 * SupaBein Admin Shell API
 *
 * Allows running arbitrary shell commands on the server via HTTP.
 * KEEP THIS FILE PRIVATE. DELETE when no longer needed.
 *
 * Usage:
 *   curl -X POST https://your-domain/admin-shell.php \
 *     -H "X-Shell-Token: YOUR_SECRET_TOKEN" \
 *     -H "Content-Type: application/json" \
 *     -d '{"cmd": "git pull"}'
 *
 * Response: { "output": "...", "exit_code": 0, "duration_ms": 123 }
 *
 * SECURITY CHECKLIST:
 *   [ ] Set a long random token below (min 32 chars)
 *   [ ] Only access over HTTPS
 *   [ ] Delete this file when done
 *   [ ] Review the log file: admin-shell.log
 */

// ── Config ───────────────────────────────────────────────────────────────────

define('SECRET_TOKEN',   'CHANGE_ME_USE_SOMETHING_LONG_AND_RANDOM');
define('WORKING_DIR',    __DIR__);
define('LOG_FILE',       __DIR__ . '/admin-shell.log');
define('TIMEOUT_SECONDS', 30);
define('MAX_OUTPUT_BYTES', 65536); // 64 KB cap on response

// Optional: restrict to specific IPs. Leave empty to allow all.
// Example: ['1.2.3.4', '5.6.7.8']
define('ALLOWED_IPS', []);

// ── Bootstrap ────────────────────────────────────────────────────────────────

header('Content-Type: application/json');

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function log_request(string $ip, string $cmd, int $exit_code): void {
    $line = sprintf(
        "[%s] IP=%s EXIT=%d CMD=%s\n",
        date('Y-m-d H:i:s'),
        $ip,
        $exit_code,
        json_encode($cmd)
    );
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// ── Security checks ───────────────────────────────────────────────────────────

// HTTPS only — uncomment to enforce
// if (empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
//     respond(['error' => 'HTTPS required'], 403);
// }

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'POST required'], 405);
}

// IP allowlist
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$clientIp = trim(explode(',', $clientIp)[0]); // handle proxies
if (!empty(ALLOWED_IPS) && !in_array($clientIp, ALLOWED_IPS, true)) {
    log_request($clientIp, '[blocked - IP not allowed]', 403);
    respond(['error' => 'Forbidden'], 403);
}

// Token — must be in X-Shell-Token header
$token = $_SERVER['HTTP_X_SHELL_TOKEN'] ?? '';
if (!hash_equals(SECRET_TOKEN, $token)) {
    log_request($clientIp, '[blocked - bad token]', 401);
    respond(['error' => 'Unauthorized'], 401);
}

// Reminder to change the default token (non-blocking)
if (SECRET_TOKEN === 'CHANGE_ME_USE_SOMETHING_LONG_AND_RANDOM') {
    // still works but please change this
}

// ── Parse request ─────────────────────────────────────────────────────────────

$body = file_get_contents('php://input');
$payload = json_decode($body, true);

if (!is_array($payload) || empty($payload['cmd'])) {
    respond(['error' => 'Request body must be JSON with a "cmd" key'], 422);
}

$cmd = trim((string)$payload['cmd']);
if ($cmd === '') {
    respond(['error' => '"cmd" must not be empty'], 422);
}

// ── Execute ───────────────────────────────────────────────────────────────────

$startMs = round(microtime(true) * 1000);

// Change to the SupaBein directory before running
$fullCmd = sprintf(
    'cd %s && timeout %d bash -c %s 2>&1',
    escapeshellarg(WORKING_DIR),
    TIMEOUT_SECONDS,
    escapeshellarg($cmd)
);

$output   = [];
$exitCode = 0;
exec($fullCmd, $output, $exitCode);

$durationMs = round(microtime(true) * 1000) - $startMs;

$outputStr = implode("\n", $output);

// Truncate if output is huge
$truncated = false;
if (strlen($outputStr) > MAX_OUTPUT_BYTES) {
    $outputStr = substr($outputStr, 0, MAX_OUTPUT_BYTES);
    $truncated = true;
}

// Always log
log_request($clientIp, $cmd, $exitCode);

// ── Respond ───────────────────────────────────────────────────────────────────

respond([
    'cmd'         => $cmd,
    'output'      => $outputStr,
    'exit_code'   => $exitCode,
    'duration_ms' => $durationMs,
    'truncated'   => $truncated,
]);
