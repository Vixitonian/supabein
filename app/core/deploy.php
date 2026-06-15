<?php

declare(strict_types=1);

namespace SupaBein;

/**
 * Deploy pipeline — Security Crux #2.
 *
 * Five phases:
 *   1. Receive upload — MIME + size check
 *   2. Validate all zip entries — path traversal + executable extension check
 *   3. Extract to versioned deploy directory
 *   4. Write hardening .htaccess (overwrites anything extracted)
 *   5. Atomic symlink swap + DB record
 */
class Deploy
{
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8',
        'phtml', 'phar', 'phps',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash',
        'asp', 'aspx', 'jsp', 'cfm',
        'exe', 'dll', 'so',
    ];

    private const BLOCKED_MIMES = [
        'application/x-php',
        'text/x-php',
        'application/x-executable',
        'application/x-sharedlib',
    ];

    public static function upload(array $req): void
    {
        $config    = \App::get('config');
        $catalog   = Catalog::getInstance();
        $pdo       = \App::get('db');
        $projectId = (int)$req['params']['project_id'];
        $siteId    = (int)$req['params']['site_id'];

        // Ownership check
        $project = $catalog->getProjectById($projectId, $req['auth']['user_id']);
        if (!$project) {
            abort(404, 'Project not found');
        }
        $site = $catalog->getSiteByProjectId($projectId, $siteId);
        if (!$site) {
            abort(404, 'Site not found');
        }

        // ── Phase 1: Receive ─────────────────────────────────────────────────

        if (!isset($req['files']['zipfile'])) {
            abort(422, 'No zipfile field in upload');
        }
        $file = $req['files']['zipfile'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            abort(422, 'Upload error: ' . self::uploadErrorMessage($file['error']));
        }

        $maxBytes = $config['MAX_DEPLOY_BYTES'] ?? 52428800;
        if ($file['size'] > $maxBytes) {
            abort(422, 'Upload exceeds maximum size of ' . ($maxBytes / 1048576) . ' MB');
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
            abort(422, "Invalid file type '$mimeType'. Only zip archives are accepted.");
        }

        // Move to storage
        $storagePath = $config['STORAGE_PATH'];
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0750, true);
        }
        $zipFilename = uniqid('deploy_', true) . '.zip';
        $zipPath     = $storagePath . '/' . $zipFilename;

        if (!move_uploaded_file($file['tmp_name'], $zipPath)) {
            abort(500, 'Failed to store upload');
        }

        // Create pending deploy record
        $deploy = $catalog->createDeploy($siteId, date('Y-m-d H:i:s'), $file['size']);
        $deploy['id'] = (int)$deploy['id'];
        $catalog->updateDeploy($deploy['id'], 'processing');

        // ── Phase 2: Validate zip entries ────────────────────────────────────

        $sitesPath = $config['SITES_PATH'];
        $deployDir = $sitesPath . '/s' . $siteId . '/deploys/'
                   . date('Ymd_His') . '_' . $deploy['id'];

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $catalog->updateDeploy($deploy['id'], 'failed');
            @unlink($zipPath);
            abort(422, 'Cannot open zip archive');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                // No null bytes
                if (strpos($name, "\0") !== false) {
                    throw new \RuntimeException("Null byte in zip entry: $name");
                }

                // Path traversal guard
                $canonical = self::normalizePath($deployDir . '/' . $name);
                $prefix    = rtrim($deployDir, '/') . '/';
                if ($canonical !== rtrim($deployDir, '/') && !str_starts_with($canonical, $prefix)) {
                    throw new \RuntimeException("Path traversal attempt in zip entry: $name");
                }

                // Extension check (skip directories)
                if (!str_ends_with($name, '/')) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                        throw new \RuntimeException("Blocked file type '$ext' in zip entry: $name");
                    }
                }
            }
        } catch (\RuntimeException $e) {
            $zip->close();
            $catalog->updateDeploy($deploy['id'], 'failed');
            @unlink($zipPath);
            abort(422, $e->getMessage());
        }

        $zip->close();

        // ── Phase 3: Extract ─────────────────────────────────────────────────

        if (!is_dir($deployDir)) {
            mkdir($deployDir, 0755, true);
        }

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($deployDir);
        $zip->close();
        @unlink($zipPath);

        // ── Phase 3b: Strip single top-level wrapper folder ──────────────────
        // If the zip was built as dist/ → index.html, move contents up one level.

        $topEntries = array_values(array_filter(
            scandir($deployDir),
            fn($e) => $e !== '.' && $e !== '..'
        ));
        if (count($topEntries) === 1 && is_dir($deployDir . '/' . $topEntries[0])) {
            $wrapper = $deployDir . '/' . $topEntries[0];
            foreach (scandir($wrapper) as $item) {
                if ($item === '.' || $item === '..') continue;
                rename($wrapper . '/' . $item, $deployDir . '/' . $item);
            }
            rmdir($wrapper);
        }

        // ── Phase 4: Hardening .htaccess (written last, overwrites anything from zip) ──

        $spaMode   = (bool)$site['spa_mode'];
        $htaccess  = self::buildHardeningHtaccess($spaMode);
        file_put_contents($deployDir . '/.htaccess', $htaccess);

        // ── Phase 5: Install to current/ directory ───────────────────────────

        $currentDir = $sitesPath . '/s' . $siteId . '/current';

        if (is_dir($currentDir) && !is_link($currentDir)) {
            self::rrmdir($currentDir);
        } elseif (is_link($currentDir)) {
            unlink($currentDir);
        }

        self::rcopy($deployDir, $currentDir);

        $catalog->updateDeploy($deploy['id'], 'ready', $deployDir);
        $catalog->updateSiteCurrentDeploy($siteId, $deploy['id']);

        json_out($catalog->getDeployById($deploy['id']), 201);

    }

    public static function rollback(array $req): void
    {
        $config    = \App::get('config');
        $catalog   = Catalog::getInstance();
        $projectId = (int)$req['params']['project_id'];
        $siteId    = (int)$req['params']['site_id'];
        $deployId  = (int)$req['params']['deploy_id'];

        $project = $catalog->getProjectById($projectId, $req['auth']['user_id']);
        if (!$project) {
            abort(404, 'Project not found');
        }
        $site = $catalog->getSiteByProjectId($projectId, $siteId);
        if (!$site) {
            abort(404, 'Site not found');
        }
        $deploy = $catalog->getDeployById($deployId);
        if (!$deploy || (int)$deploy['site_id'] !== $siteId || $deploy['status'] !== 'ready') {
            abort(404, 'Deploy not found or not in ready state');
        }

        $deployDir = $deploy['path'];
        $sitesPath = $config['SITES_PATH'];

        // Safety: ensure path stays within SITES_PATH
        $canonical = self::normalizePath($deployDir);
        if (!str_starts_with($canonical, rtrim($sitesPath, '/') . '/')) {
            abort(400, 'Invalid deploy path');
        }

        if (!is_dir($deployDir)) {
            abort(400, 'Deploy directory no longer exists');
        }

        $currentDir = $sitesPath . '/s' . $siteId . '/current';

        if (is_dir($currentDir) && !is_link($currentDir)) {
            self::rrmdir($currentDir);
        } elseif (is_link($currentDir)) {
            unlink($currentDir);
        }

        self::rcopy($deployDir, $currentDir);

        $catalog->updateSiteCurrentDeploy($siteId, $deployId);

        json_out(['rolled_back_to' => $deploy]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Normalize a path by collapsing .. and . segments without touching the filesystem.
     */
    public static function normalizePath(string $path): string
    {
        $path  = str_replace('\\', '/', $path);
        $parts = explode('/', $path);
        $out   = [];

        foreach ($parts as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $p;
        }

        return '/' . implode('/', $out);
    }

    private static function buildHardeningHtaccess(bool $spaMode): string
    {
        $htaccess = <<<'HTACCESS'
Options -Indexes -ExecCGI
RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .cgi .pl .py .rb .sh
AddType text/plain .php .php3 .php4 .php5 .php7 .php8 .phtml .phar
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php5.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
HTACCESS;

        if ($spaMode) {
            $htaccess .= "\n" . <<<'SPA'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.html [L]
</IfModule>
SPA;
        }

        return $htaccess;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private static function rcopy(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        $src    = rtrim($src, '/');
        $srcLen = strlen($src) + 1;
        $it     = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $dst . '/' . substr($item->getPathname(), $srcLen);
            if ($item->isDir()) {
                if (!is_dir($target)) mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'File exceeds php.ini upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in form',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload',
            default               => "Unknown error code $code",
        };
    }
}
