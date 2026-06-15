<?php
/**
 * PHP proxy for serving deployed user sites.
 * Handles /sites/s{id}/current/{path} → SITES_PATH/s{id}/current/{path}
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$config    = App::get('config');
$sitesPath = rtrim($config['SITES_PATH'], '/');

// Parse: /sites/s{siteId}/current/{path}
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = ltrim($uri, '/');

if (!preg_match('#^sites/s(\d+)/current(?:/(.*))?$#', $uri, $m)) {
    http_response_code(404); echo 'Not found'; exit;
}

$siteId  = (int)$m[1];
$reqPath = $m[2] ?? '';

// Normalize trailing slash → index.html
if ($reqPath === '' || str_ends_with($reqPath, '/')) {
    $reqPath = rtrim($reqPath, '/') . '/index.html';
}

$base     = $sitesPath . '/s' . $siteId . '/current';
$fullPath = \SupaBein\Deploy::normalizePath($base . '/' . $reqPath);

// Path traversal guard
if ($fullPath !== $base && !str_starts_with($fullPath, $base . '/')) {
    http_response_code(400); echo 'Bad request'; exit;
}

// Block executable extensions (belt-and-suspenders)
$ext     = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$blocked = ['php','php3','php4','php5','php7','php8','phtml','phar','cgi','pl','py','rb','sh','bash'];
if (in_array($ext, $blocked, true)) {
    http_response_code(403); echo 'Forbidden'; exit;
}

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

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=3600');
readfile($fullPath);
