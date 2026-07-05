<?php
/**
 * PHP proxy for serving deployed user sites.
 * Handles /sites/s{id}/current/{path} and /sites/s{id}/staging/{path}
 * → SITES_PATH/s{id}/{current|staging}/{path}
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$config    = App::get('config');
$sitesPath = rtrim($config['SITES_PATH'], '/');

// Parse: /sites/s{siteId}/{current|staging}/{path}
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = ltrim($uri, '/');

if (!preg_match('#^sites/s(\d+)/(current|staging)(?:/(.*))?$#', $uri, $m)) {
    http_response_code(404); echo 'Not found'; exit;
}

$siteId  = (int)$m[1];
$variant = $m[2];
$reqPath = $m[3] ?? '';

// Normalize trailing slash → index.html
if ($reqPath === '' || str_ends_with($reqPath, '/')) {
    $reqPath = rtrim($reqPath, '/') . '/index.html';
}

$base     = $sitesPath . '/s' . $siteId . '/' . $variant;
$fullPath = \SupaBein\Deploy::normalizePath($base . '/' . $reqPath);

// Path traversal guard
if ($fullPath !== $base && !str_starts_with($fullPath, $base . '/')) {
    http_response_code(400); echo 'Bad request'; exit;
}


$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

// If file doesn't exist, try SPA fallback to index.html
if (!file_exists($fullPath) || is_dir($fullPath)) {
    $pdo  = App::get('db');
    $stmt = $pdo->prepare('SELECT spa_mode FROM sites WHERE id = ?');
    $stmt->execute([$siteId]);
    $row  = $stmt->fetch();
    if ($row && (int)$row['spa_mode'] === 1) {
        $fullPath = $base . '/index.html';
    }
    if (!file_exists($fullPath)) {
        http_response_code(404); echo 'Not found'; exit;
    }
}

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

// Freshness policy for deployed user apps: a redeploy or edit MUST reach
// visitors (and the owner re-testing) immediately — a flat max-age let a
// changed app keep serving its old HTML/JS for up to an hour, which silently
// broke the build→deploy→verify loop. These apps reference their own assets
// (core/api.js, features/*.js) with plain relative paths and no ?v= cache-bust,
// so correctness can't rely on versioned URLs — instead every response is
// revalidated. "no-cache" means the browser may store it but must check with
// the server before reusing; paired with a strong validator (mtime+size) that
// check is a cheap 304 when nothing changed and a full fresh body the instant
// the file changes. Stale deployed apps become impossible, without giving up
// conditional-request efficiency.
$mtime = filemtime($fullPath);
$size  = filesize($fullPath);
$etag  = '"' . dechex($mtime) . '-' . dechex($size) . '"';

header('Content-Type: ' . $mime);
header('Cache-Control: no-cache, must-revalidate');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

// Honor conditional requests → 304 Not Modified (no body) when unchanged.
// If-None-Match (ETag) takes precedence over If-Modified-Since per spec.
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
