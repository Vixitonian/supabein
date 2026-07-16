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

// Every deployed app calls its own API as `window.location.origin + '/api/v1'`
// -- that only ever worked because every site used to share one domain with
// the real API. Now that a site can be reached at its own subdomain/custom
// domain, that origin is THIS wildcard vhost, which has no API of its own --
// live-caught as every data call silently falling through to the SPA
// fallback and getting index.html's HTML back instead of JSON ("Unexpected
// token '<'"). Proxying /api/ here, transparently, fixes it for every
// existing and future deployed app without editing a single one of them.
$reqPathRaw = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
if (str_starts_with($reqPathRaw, '/api/')) {
    $target = rtrim($config['API_BASE_URL'], '/') . $_SERVER['REQUEST_URI'];

    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_') && $k !== 'HTTP_HOST') {
            $headers[] = str_replace('_', '-', substr($k, 5)) . ': ' . $v;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];

    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $_SERVER['REQUEST_METHOD'],
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Upstream API unreachable']);
        exit;
    }
    $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    http_response_code($status);
    foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
        // Only forward headers the client actually needs -- skip hop-by-hop
        // and framing headers (Transfer-Encoding etc.) that don't apply to
        // this second response, and skip Content-Length since PHP recomputes
        // it correctly for whatever we echo below regardless.
        if (preg_match('/^(Content-Type|X-Refresh-Token|Cache-Control|ETag):/i', $line)) {
            header(trim($line));
        }
    }
    echo substr($raw, $headerSize);
    exit;
}

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
