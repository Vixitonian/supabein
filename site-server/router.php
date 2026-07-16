<?php
/**
 * Standalone router for the wildcard vhost (*.dxinnovationhub.com).
 *
 * Deliberately independent of the main SupaBein app: it never requires
 * app/bootstrap.php (so a broken deploy or a syntax error in some unrelated
 * route file can't take every hosted subdomain down with it) and it never
 * queries SupaBein's own `sites`/`projects` tables directly. Instead it
 * reads only the neutral `site_registry` table, which SupaBein (or any
 * other product) writes into via POST /v1/projects/:id/hostnames -- this
 * file doesn't know or care who wrote a given row.
 *
 * It DOES still read config/secrets.php for DB credentials rather than
 * duplicating them into a second file -- a deliberate middle ground: this
 * still depends on that one file surviving, but no longer on the rest of
 * the app (vendor/, composer autoload, every other route file) being
 * intact. See the "put back site-serve.php" discussion for why full
 * independence (its own copy of the credentials) was left as optional
 * further hardening rather than done up front.
 */

declare(strict_types=1);

$secretsPath = '/home/dxinethn/supabein.dxinnovationhub.com/config/secrets.php';
$config = require $secretsPath;

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
    error_log('[site-server] DB CONNECTION FAILED: ' . $e->getMessage());
    http_response_code(503);
    echo 'Service unavailable';
    exit;
}

$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);

$stmt = $pdo->prepare('SELECT docroot, spa_mode FROM site_registry WHERE hostname = ?');
$stmt->execute([$host]);
$registration = $stmt->fetch();

if (!$registration) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$base    = rtrim($registration['docroot'], '/');
$spaMode = (int)$registration['spa_mode'] === 1;

$reqPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$reqPath = ltrim($reqPath, '/');

// Normalize trailing slash -> index.html (same convention as site-serve.php)
if ($reqPath === '' || str_ends_with($reqPath, '/')) {
    $reqPath = rtrim($reqPath, '/') . '/index.html';
}

// Path traversal guard -- resolve symlinks/.. manually since realpath()
// would fail on a not-yet-existing file (the SPA-fallback case below).
function site_server_normalize_path(string $path): string
{
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    return '/' . implode('/', $parts);
}

$fullPath = site_server_normalize_path($base . '/' . $reqPath);
if ($fullPath !== $base && !str_starts_with($fullPath, $base . '/')) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

// SPA fallback: if the exact file isn't there and this site opted into it,
// serve index.html instead so client-side routing survives a hard refresh.
if (!file_exists($fullPath) || is_dir($fullPath)) {
    if ($spaMode) {
        $fullPath = $base . '/index.html';
    }
    if (!file_exists($fullPath)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

$mimes = [
    'html' => 'text/html; charset=utf-8',
    'htm'  => 'text/html; charset=utf-8',
    'css'  => 'text/css',
    'js'   => 'application/javascript',
    'mjs'  => 'application/javascript',
    'json' => 'application/json',
    'xml'  => 'application/xml',
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'ico'  => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2'=> 'font/woff2',
    'ttf'  => 'font/ttf',
    'otf'  => 'font/otf',
    'pdf'  => 'application/pdf',
    'txt'  => 'text/plain',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mp3'  => 'audio/mpeg',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

// Same freshness policy as site-serve.php -- deployed apps reference their
// own assets with plain relative paths and no cache-busting query strings,
// so every response must revalidate rather than rely on a flat max-age.
$mtime = filemtime($fullPath);
$size  = filesize($fullPath);
$etag  = '"' . dechex($mtime) . '-' . dechex($size) . '"';

header('Content-Type: ' . $mime);
header('Cache-Control: no-cache, must-revalidate');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

$ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
$ifModSince  = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;
$notModified = false;
if ($ifNoneMatch !== '') {
    $notModified = ($ifNoneMatch === $etag);
} elseif ($ifModSince !== false) {
    $notModified = ($ifModSince >= $mtime);
}
if ($notModified) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . $size);
readfile($fullPath);
