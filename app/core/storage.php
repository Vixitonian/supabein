<?php

declare(strict_types=1);

namespace SupaBein;

class Storage
{
    private const MAX_FILE_SIZE = 52_428_800; // 50 MB
    private const BLOCKED_EXT   = [
        'php','php3','php4','php5','phtml','phar','cgi','pl','py','rb','sh',
        'exe','bat','cmd','htaccess','htpasswd',
    ];

    private static function root(): string
    {
        return SUPABEIN_ROOT . '/storage/files';
    }

    private static function bucketDir(int $projectId, string $bucket): string
    {
        return self::root() . '/p' . $projectId . '/' . $bucket;
    }

    private static function validBucket(string $name): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $name)) {
            abort(422, 'Bucket name must be 1-63 lowercase alphanumeric characters, hyphens, or underscores');
        }
        return $name;
    }

    private static function validFilename(string $raw): string
    {
        $name = basename(str_replace(['..', "\0"], '', $raw));
        if ($name === '' || $name === '.') {
            abort(422, 'Invalid filename');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, self::BLOCKED_EXT, true)) {
            abort(403, 'File type .' . $ext . ' is not allowed');
        }
        return $name;
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    public static function upload(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $bucket    = self::validBucket($req['params']['bucket']);

        $file = $req['files']['file'] ?? null;
        if (!$file || (int)$file['error'] !== UPLOAD_ERR_OK) {
            $code = (int)($file['error'] ?? -1);
            abort(422, 'Upload error (code ' . $code . '). Send a multipart/form-data POST with field name "file".');
        }
        if ((int)$file['size'] > self::MAX_FILE_SIZE) {
            abort(413, 'File exceeds the 50 MB limit');
        }

        $filename = self::validFilename($file['name']);
        $dir      = self::bucketDir($projectId, $bucket);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            abort(500, 'Could not create storage directory');
        }

        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            abort(500, 'Failed to save file');
        }

        json_out([
            'name'   => $filename,
            'bucket' => $bucket,
            'size'   => (int)$file['size'],
            'url'    => self::publicUrl($projectId, $bucket, $filename),
        ], 201);
    }

    // ── List ──────────────────────────────────────────────────────────────────

    public static function listFiles(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $bucket    = self::validBucket($req['params']['bucket']);
        $dir       = self::bucketDir($projectId, $bucket);

        if (!is_dir($dir)) {
            json_out(['files' => [], 'count' => 0]);
            return;
        }

        $files = [];
        foreach (new \DirectoryIterator($dir) as $f) {
            if ($f->isDot() || !$f->isFile()) {
                continue;
            }
            $name    = $f->getFilename();
            $files[] = [
                'name'         => $name,
                'size'         => (int)$f->getSize(),
                'last_modified'=> date('Y-m-d H:i:s', (int)$f->getMTime()),
                'url'          => self::publicUrl($projectId, $bucket, $name),
            ];
        }

        usort($files, fn($a, $b) => strcmp($b['last_modified'], $a['last_modified']));

        json_out(['files' => $files, 'count' => count($files)]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public static function deleteFile(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $bucket    = self::validBucket($req['params']['bucket']);
        $filename  = self::validFilename(rawurldecode($req['params']['filename']));
        $path      = self::bucketDir($projectId, $bucket) . '/' . $filename;

        if (!file_exists($path) || !is_file($path)) {
            abort(404, 'File not found');
        }

        unlink($path);
        json_out(['deleted' => true]);
    }

    // ── Public serve ──────────────────────────────────────────────────────────

    public static function serve(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $bucket    = self::validBucket($req['params']['bucket']);
        $filename  = self::validFilename(rawurldecode($req['params']['filename']));
        $path      = self::bucketDir($projectId, $bucket) . '/' . $filename;

        if (!file_exists($path) || !is_file($path)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            exit;
        }

        $mime = mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function publicUrl(int $projectId, string $bucket, string $filename): string
    {
        return '/api/v1/storage/' . $projectId . '/' . $bucket . '/' . rawurlencode($filename);
    }
}
