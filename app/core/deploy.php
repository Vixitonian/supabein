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
    public const BLOCKED_EXTENSIONS = [
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
        $label  = trim($req['body']['label'] ?? '') ?: date('Y-m-d H:i:s');
        $deploy = $catalog->createDeploy($siteId, $label, $file['size']);
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

        // ── Phase 3b: Clean macOS artifacts + strip single wrapper folder ────
        // Mac zips include __MACOSX/ and .DS_Store entries alongside the real folder.
        // Delete those first, then check if a single wrapper folder remains.

        $junk = ['__MACOSX', '.DS_Store', '.AppleDouble', '.Spotlight-V100', '.Trashes'];
        foreach ($junk as $j) {
            $jp = $deployDir . '/' . $j;
            if (is_dir($jp)) self::rrmdir($jp);
            elseif (file_exists($jp)) unlink($jp);
        }
        // Also remove any dot-files left at the root
        foreach (scandir($deployDir) as $e) {
            if (str_starts_with($e, '.') && $e !== '.' && $e !== '..') {
                $ep = $deployDir . '/' . $e;
                if (is_file($ep)) unlink($ep);
            }
        }

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

        // ── Phase 5: Copy to staging/ — preview before going live ───────────

        $stagingDir = $sitesPath . '/s' . $siteId . '/staging';

        if (is_dir($stagingDir) && !is_link($stagingDir)) {
            self::rrmdir($stagingDir);
        } elseif (is_link($stagingDir)) {
            unlink($stagingDir);
        }

        self::rcopy($deployDir, $stagingDir);

        // Verify files actually landed
        if (!is_dir($stagingDir)) {
            $catalog->updateDeploy($deploy['id'], 'failed');
            abort(500, 'Deploy copy failed — staging/ directory was not created. Check PHP open_basedir or directory permissions for: ' . $stagingDir);
        }

        $catalog->updateDeploy($deploy['id'], 'ready', $deployDir);
        $catalog->updateSiteStagingDeploy($siteId, $deploy['id']);

        $result = $catalog->getDeployById($deploy['id']);
        $result['staging_dir'] = $stagingDir;
        $result['staging_dir_exists'] = is_dir($stagingDir);
        json_out($result, 201);

    }

    // ─── File-by-file deploy API ─────────────────────────────────────────────

    /**
     * Open a new pending deploy — returns deploy record + staging directory.
     * POST /v1/projects/:project_id/sites/:site_id/deploys/open
     */
    public static function open(array $req): void
    {
        $config    = \App::get('config');
        $catalog   = Catalog::getInstance();
        $projectId = (int)$req['params']['project_id'];
        $siteId    = (int)$req['params']['site_id'];

        $project = $catalog->getProjectById($projectId, $req['auth']['user_id']);
        if (!$project) abort(404, 'Project not found');

        $site = $catalog->getSiteByProjectId($projectId, $siteId);
        if (!$site) abort(404, 'Site not found');

        $label     = trim($req['body']['label'] ?? '') ?: date('Y-m-d H:i:s');
        $deploy    = $catalog->createDeploy($siteId, $label, 0);
        $deployId  = (int)$deploy['id'];

        $sitesPath = $config['SITES_PATH'];
        $stagingDir = $sitesPath . '/s' . $siteId . '/deploys/'
                    . date('Ymd_His') . '_' . $deployId;

        if (!is_dir($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }

        // Write hardening .htaccess up front so any early file access is safe
        $spaMode = (bool)$site['spa_mode'];
        file_put_contents($stagingDir . '/.htaccess', self::buildHardeningHtaccess($spaMode));

        // Store path immediately (status stays 'pending')
        $catalog->updateDeploy($deployId, 'pending', $stagingDir);

        $result = $catalog->getDeployById($deployId);
        $result['staging_dir'] = $stagingDir;
        json_out($result, 201);
    }

    /**
     * Upload a single file into a pending deploy staging directory.
     * PUT /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/files?path=sub/dir/file.html
     */
    public static function putFile(array $req): void
    {
        $config    = \App::get('config');
        $catalog   = Catalog::getInstance();
        $projectId = (int)$req['params']['project_id'];
        $siteId    = (int)$req['params']['site_id'];
        $deployId  = (int)$req['params']['deploy_id'];

        $project = $catalog->getProjectById($projectId, $req['auth']['user_id']);
        if (!$project) abort(404, 'Project not found');

        $site = $catalog->getSiteByProjectId($projectId, $siteId);
        if (!$site) abort(404, 'Site not found');

        $deploy = $catalog->getDeployById($deployId);
        if (!$deploy || (int)$deploy['site_id'] !== $siteId) abort(404, 'Deploy not found');
        if ($deploy['status'] !== 'pending') abort(409, 'Deploy is already finalized');

        $relPath = ltrim($req['query']['path'] ?? '', '/');
        if ($relPath === '') abort(422, 'Query parameter ?path= is required');

        // Security checks
        if (strpos($relPath, "\0") !== false) abort(400, 'Null byte in path');

        $stagingDir = rtrim($deploy['path'], '/');
        $fullPath   = self::normalizePath($stagingDir . '/' . $relPath);
        if (!str_starts_with($fullPath, $stagingDir . '/')) {
            abort(400, 'Path traversal attempt detected');
        }


        // Refuse overwriting the hardening .htaccess
        if (basename($fullPath) === '.htaccess' && dirname($fullPath) === $stagingDir) {
            abort(403, 'The root .htaccess is managed by SupaBein and cannot be overwritten');
        }

        $parentDir = dirname($fullPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        $body = file_get_contents('php://input');
        if ($body === false) abort(500, 'Failed to read request body');

        if (file_put_contents($fullPath, $body) === false) {
            abort(500, 'Failed to write file');
        }

        json_out(['path' => $relPath, 'size' => strlen($body)]);
    }

    /**
     * Remove a staged file from a pending deploy.
     * DELETE /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/files?path=file.html
     */
    public static function deleteFile(array $req): void
    {
        $catalog   = Catalog::getInstance();
        $projectId = (int)$req['params']['project_id'];
        $siteId    = (int)$req['params']['site_id'];
        $deployId  = (int)$req['params']['deploy_id'];

        $project = $catalog->getProjectById($projectId, $req['auth']['user_id']);
        if (!$project) abort(404, 'Project not found');

        $site = $catalog->getSiteByProjectId($projectId, $siteId);
        if (!$site) abort(404, 'Site not found');

        $deploy = $catalog->getDeployById($deployId);
        if (!$deploy || (int)$deploy['site_id'] !== $siteId) abort(404, 'Deploy not found');
        if ($deploy['status'] !== 'pending') abort(409, 'Deploy is already finalized');

        $relPath = ltrim($req['query']['path'] ?? '', '/');
        if ($relPath === '') abort(422, 'Query parameter ?path= is required');

        $stagingDir = rtrim($deploy['path'], '/');
        $fullPath   = self::normalizePath($stagingDir . '/' . $relPath);
        if (!str_starts_with($fullPath, $stagingDir . '/')) {
            abort(400, 'Path traversal attempt detected');
        }

        if (!file_exists($fullPath)) abort(404, 'File not found in staging');
        if (is_dir($fullPath)) abort(400, 'Path is a directory; delete files individually');

        unlink($fullPath);
        json_out(['deleted' => true, 'path' => $relPath]);
    }

    /**
     * List all files currently staged in a pending deploy.
     * GET /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/files
     */
    public static function listFiles(array $req): void
    {
        $catalog   = Catalog::getInstance();
        $projectId = (int)$req['params']['project_id'];
        $siteId    = (int)$req['params']['site_id'];
        $deployId  = (int)$req['params']['deploy_id'];

        $project = $catalog->getProjectById($projectId, $req['auth']['user_id']);
        if (!$project) abort(404, 'Project not found');

        $site = $catalog->getSiteByProjectId($projectId, $siteId);
        if (!$site) abort(404, 'Site not found');

        $deploy = $catalog->getDeployById($deployId);
        if (!$deploy || (int)$deploy['site_id'] !== $siteId) abort(404, 'Deploy not found');

        $stagingDir = rtrim($deploy['path'], '/');
        if (!is_dir($stagingDir)) {
            json_out(['files' => []]);
            return;
        }

        $files = [];
        $base  = $stagingDir . '/';
        $it    = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            if ($item->isFile()) {
                $relPath = substr($item->getPathname(), strlen($base));
                $files[] = ['path' => $relPath, 'size' => $item->getSize()];
            }
        }
        usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));
        json_out(['deploy_id' => $deployId, 'status' => $deploy['status'], 'files' => $files]);
    }

    /**
     * Finalize a pending deploy: harden, copy to current/, mark ready.
     * POST /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/finalize
     */
    public static function finalize(array $req): void
    {
        $config    = \App::get('config');
        $catalog   = Catalog::getInstance();
        $projectId = (int)$req['params']['project_id'];
        $siteId    = (int)$req['params']['site_id'];
        $deployId  = (int)$req['params']['deploy_id'];

        $project = $catalog->getProjectById($projectId, $req['auth']['user_id']);
        if (!$project) abort(404, 'Project not found');

        $site = $catalog->getSiteByProjectId($projectId, $siteId);
        if (!$site) abort(404, 'Site not found');

        $deploy = $catalog->getDeployById($deployId);
        if (!$deploy || (int)$deploy['site_id'] !== $siteId) abort(404, 'Deploy not found');
        if ($deploy['status'] !== 'pending') abort(409, 'Deploy is already finalized');

        $deployDir = rtrim($deploy['path'], '/');
        if (!is_dir($deployDir)) abort(400, 'Staging directory no longer exists');

        // Re-write hardening .htaccess last, ensuring it overwrites anything the caller uploaded
        $spaMode  = (bool)$site['spa_mode'];
        file_put_contents($deployDir . '/.htaccess', self::buildHardeningHtaccess($spaMode));

        // Reject empty deploys (only the auto-written .htaccess present means no real files)
        $uploadedCount = 0;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($deployDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        ) as $f) {
            if ($f->isFile() && $f->getFilename() !== '.htaccess') {
                $uploadedCount++;
            }
        }
        if ($uploadedCount === 0) {
            abort(422, 'Cannot finalize an empty deploy — no files were uploaded');
        }

        // Calculate total size
        $totalSize = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($deployDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) $totalSize += $f->getSize();
        }

        $sitesPath  = $config['SITES_PATH'];
        $stagingDir = $sitesPath . '/s' . $siteId . '/staging';

        if (is_dir($stagingDir) && !is_link($stagingDir)) {
            self::rrmdir($stagingDir);
        } elseif (is_link($stagingDir)) {
            unlink($stagingDir);
        }

        self::rcopy($deployDir, $stagingDir);

        if (!is_dir($stagingDir)) {
            $catalog->updateDeploy($deployId, 'failed', $deployDir);
            abort(500, 'Finalize copy failed — staging/ directory was not created');
        }

        // Update size in DB via a direct query since updateDeploy doesn't expose size
        $pdo = \App::get('db');
        $pdo->prepare('UPDATE deploys SET size_bytes = ? WHERE id = ?')->execute([$totalSize, $deployId]);

        $catalog->updateDeploy($deployId, 'ready', $deployDir);
        $catalog->updateSiteStagingDeploy($siteId, $deployId);

        $apiBase    = rtrim(\App::get('config')['API_BASE_URL'] ?? '', '/');
        $appBase    = preg_replace('#/(api|v\d+)(/.*)?$#i', '', $apiBase);
        $result     = $catalog->getDeployById($deployId);
        $result['staging_url'] = $appBase . '/sites/s' . $siteId . '/staging/';
        json_out($result);
    }

    /**
     * Promote the current staging deploy to live (current/).
     * POST /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/publish
     */
    public static function publish(array $req): void
    {
        $config    = \App::get('config');
        $catalog   = Catalog::getInstance();
        $projectId = (int)$req['params']['project_id'];
        $siteId    = (int)$req['params']['site_id'];
        $deployId  = (int)$req['params']['deploy_id'];

        $project = $catalog->getProjectById($projectId, $req['auth']['user_id']);
        if (!$project) abort(404, 'Project not found');

        $site = $catalog->getSiteByProjectId($projectId, $siteId);
        if (!$site) abort(404, 'Site not found');

        if ((int)$site['staging_deploy_id'] !== $deployId) {
            abort(409, 'Deploy is not the current staging deploy');
        }

        $deploy = $catalog->getDeployById($deployId);
        if (!$deploy || (int)$deploy['site_id'] !== $siteId || $deploy['status'] !== 'ready') {
            abort(404, 'Deploy not found or not in ready state');
        }

        $sitesPath  = $config['SITES_PATH'];
        $stagingDir = $sitesPath . '/s' . $siteId . '/staging';
        $currentDir = $sitesPath . '/s' . $siteId . '/current';

        if (!is_dir($stagingDir)) {
            abort(400, 'Staging directory does not exist');
        }

        if (is_dir($currentDir) && !is_link($currentDir)) {
            self::rrmdir($currentDir);
        } elseif (is_link($currentDir)) {
            unlink($currentDir);
        }

        self::rcopy($stagingDir, $currentDir);

        if (!is_dir($currentDir)) {
            abort(500, 'Publish copy failed — current/ directory was not created');
        }

        $catalog->updateSiteCurrentDeploy($siteId, $deployId);
        $catalog->updateSiteStagingDeploy($siteId, null);

        $apiBase = rtrim(\App::get('config')['API_BASE_URL'] ?? '', '/');
        $appBase = preg_replace('#/(api|v\d+)(/.*)?$#i', '', $apiBase);
        $site    = $catalog->getSiteByProjectId($projectId, $siteId);
        $site['live_url']    = $appBase . '/sites/s' . $site['id'] . '/current/';
        $site['staging_url'] = $appBase . '/sites/s' . $site['id'] . '/staging/';
        json_out($site);
    }

    /**
     * Compare two deploy snapshots.
     * GET /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/diff?vs=:other_id
     */
    public static function diff(array $req): void
    {
        $config    = \App::get('config');
        $catalog   = Catalog::getInstance();
        $projectId = (int)$req['params']['project_id'];
        $siteId    = (int)$req['params']['site_id'];
        $deployId  = (int)$req['params']['deploy_id'];
        $otherId   = (int)($req['query']['vs'] ?? 0);

        if (!$otherId) abort(422, 'Query parameter ?vs= (other deploy id) is required');

        $project = $catalog->getProjectById($projectId, $req['auth']['user_id']);
        if (!$project) abort(404, 'Project not found');

        $site = $catalog->getSiteByProjectId($projectId, $siteId);
        if (!$site) abort(404, 'Site not found');

        $deployA = $catalog->getDeployById($deployId);
        if (!$deployA || (int)$deployA['site_id'] !== $siteId || $deployA['status'] !== 'ready') {
            abort(404, 'Deploy not found or not ready');
        }

        $deployB = $catalog->getDeployById($otherId);
        if (!$deployB || (int)$deployB['site_id'] !== $siteId || $deployB['status'] !== 'ready') {
            abort(404, 'Comparison deploy (vs) not found or not ready');
        }

        $sitesPath = $config['SITES_PATH'];

        // Safety: both paths must stay within SITES_PATH
        $pathA = self::normalizePath($deployA['path']);
        $pathB = self::normalizePath($deployB['path']);
        $prefix = rtrim($sitesPath, '/') . '/';
        if (!str_starts_with($pathA, $prefix) || !str_starts_with($pathB, $prefix)) {
            abort(400, 'Invalid deploy paths');
        }

        if (!is_dir($pathA)) abort(400, 'Deploy directory for ' . $deployId . ' no longer exists');
        if (!is_dir($pathB)) abort(400, 'Deploy directory for ' . $otherId . ' no longer exists');

        $filesA = self::hashTree($pathA);
        $filesB = self::hashTree($pathB);

        $added     = [];
        $removed   = [];
        $modified  = [];
        $unchanged = 0;

        foreach ($filesA as $path => $hash) {
            if (!isset($filesB[$path])) {
                // In A but not B → this file was added relative to B
                $added[] = $path;
            } elseif ($filesB[$path] !== $hash) {
                $modified[] = $path;
            } else {
                $unchanged++;
            }
        }

        foreach ($filesB as $path => $hash) {
            if (!isset($filesA[$path])) {
                $removed[] = $path;
            }
        }

        sort($added);
        sort($removed);
        sort($modified);

        json_out([
            'deploy_id'   => $deployId,
            'vs'          => $otherId,
            'added'       => $added,
            'removed'     => $removed,
            'modified'    => $modified,
            'unchanged'   => $unchanged,
        ]);
    }

    /**
     * Walk a deploy directory and return [relativePath => sha256].
     */
    private static function hashTree(string $dir): array
    {
        $dir    = rtrim($dir, '/');
        $base   = $dir . '/';
        $result = [];
        $it     = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel          = substr($f->getPathname(), strlen($base));
                $result[$rel] = hash_file('sha256', $f->getPathname());
            }
        }
        return $result;
    }

    // ─── End file-by-file deploy API ─────────────────────────────────────────

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

    public static function buildHardeningHtaccess(bool $spaMode): string
    {
        $htaccess = <<<'HTACCESS'
DirectoryIndex index.html index.htm
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
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.html [L]
</IfModule>
SPA;
        }

        return $htaccess;
    }

    public static function rrmdir(string $dir): void
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

    public static function rcopy(string $src, string $dst): void
    {
        if (!is_dir($dst) && !mkdir($dst, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: $dst");
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
                if (!is_dir($target) && !mkdir($target, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: $target");
                }
            } else {
                if (!copy($item->getPathname(), $target)) {
                    throw new \RuntimeException("Failed to copy file to: $target");
                }
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
