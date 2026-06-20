<?php

declare(strict_types=1);

function register_deploy_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    $ownProject = function (int $projectId, int $userId) use ($catalog): array {
        $project = $catalog->getProjectById($projectId, $userId);
        if (!$project) {
            abort(404, 'Project not found');
        }
        $project['id'] = (int)$project['id'];
        return $project;
    };

    // GET /v1/projects/:id/sites
    $router->get('/v1/projects/:id/sites', function (array $req) use ($catalog, $ownProject): void {
        $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        json_out($catalog->listSites((int)$req['params']['id']));
    }, ['auth_middleware']);

    // POST /v1/projects/:id/sites
    $router->post('/v1/projects/:id/sites', function (array $req) use ($catalog, $ownProject): void {
        $project   = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $subdomain = trim($req['body']['subdomain'] ?? '');
        $spaMode   = (bool)($req['body']['spa_mode'] ?? false);

        if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,61}[a-z0-9]$/', $subdomain)) {
            abort(422, 'Invalid subdomain. Use 2-63 lowercase alphanumeric or hyphen characters.');
        }

        $existing = $catalog->listSites($project['id']);
        if (!empty($existing)) {
            abort(409, 'This project already has a site. Each project supports one site.');
        }

        try {
            $site = $catalog->createSite($project['id'], $subdomain, $spaMode);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                abort(409, 'Subdomain already in use');
            }
            throw $e;
        }

        json_out($site, 201);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/sites/:site_id
    $router->get('/v1/projects/:id/sites/:site_id', function (array $req) use ($catalog, $ownProject): void {
        $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $site = $catalog->getSiteByProjectId((int)$req['params']['id'], (int)$req['params']['site_id']);
        if (!$site) {
            abort(404, 'Site not found');
        }
        json_out($site);
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/sites/:site_id
    $router->delete('/v1/projects/:id/sites/:site_id', function (array $req) use ($catalog, $ownProject): void {
        $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $deleted = $catalog->deleteSite((int)$req['params']['id'], (int)$req['params']['site_id']);
        if (!$deleted) {
            abort(404, 'Site not found');
        }
        json_out(['deleted' => true]);
    }, ['auth_middleware']);

    // ── File-by-file deploy API ──────────────────────────────────────────────

    // POST /v1/projects/:project_id/sites/:site_id/deploys/open
    $router->post(
        '/v1/projects/:project_id/sites/:site_id/deploys/open',
        [\SupaBein\Deploy::class, 'open'],
        ['auth_middleware']
    );

    // GET /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/files
    $router->get(
        '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/files',
        [\SupaBein\Deploy::class, 'listFiles'],
        ['auth_middleware']
    );

    // POST /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/files?path=
    // (POST instead of PUT — some servers block PUT at the proxy/web-server level)
    $router->post(
        '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/files',
        [\SupaBein\Deploy::class, 'putFile'],
        ['auth_middleware']
    );

    // DELETE /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/files?path=
    $router->delete(
        '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/files',
        [\SupaBein\Deploy::class, 'deleteFile'],
        ['auth_middleware']
    );

    // POST /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/finalize
    $router->post(
        '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/finalize',
        [\SupaBein\Deploy::class, 'finalize'],
        ['auth_middleware']
    );

    // GET /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/diff?vs=
    $router->get(
        '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/diff',
        [\SupaBein\Deploy::class, 'diff'],
        ['auth_middleware']
    );

    // POST /v1/projects/:project_id/sites/:site_id/deploys  (zip file upload)
    $router->post('/v1/projects/:project_id/sites/:site_id/deploys', [\SupaBein\Deploy::class, 'upload'], ['auth_middleware']);

    // GET /v1/projects/:project_id/sites/:site_id/deploys
    $router->get('/v1/projects/:project_id/sites/:site_id/deploys', function (array $req) use ($catalog, $ownProject): void {
        $ownProject((int)$req['params']['project_id'], $req['auth']['user_id']);
        $site = $catalog->getSiteByProjectId((int)$req['params']['project_id'], (int)$req['params']['site_id']);
        if (!$site) {
            abort(404, 'Site not found');
        }
        json_out($catalog->listDeploys((int)$site['id']));
    }, ['auth_middleware']);

    // POST /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/publish
    $router->post(
        '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/publish',
        [\SupaBein\Deploy::class, 'publish'],
        ['auth_middleware']
    );

    // POST /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/rollback
    $router->post(
        '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/rollback',
        [\SupaBein\Deploy::class, 'rollback'],
        ['auth_middleware']
    );

    // GET /v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/download
    $router->get(
        '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/download',
        function (array $req) use ($catalog, $ownProject): void {
            $ownProject((int)$req['params']['project_id'], $req['auth']['user_id']);
            $site = $catalog->getSiteByProjectId((int)$req['params']['project_id'], (int)$req['params']['site_id']);
            if (!$site) abort(404, 'Site not found');

            $deploy = $catalog->getDeployById((int)$req['params']['deploy_id']);
            if (!$deploy || (int)$deploy['site_id'] !== (int)$site['id']) abort(404, 'Deploy not found');

            $deployDir = rtrim($deploy['path'], '/');
            if (!is_dir($deployDir)) abort(404, 'Deploy files not found on disk');

            $zipFile = sys_get_temp_dir() . '/deploy_' . $deploy['id'] . '_' . time() . '.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                abort(500, 'Could not create zip archive');
            }

            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($deployDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $file) {
                $realPath = $file->getRealPath();
                $relPath  = ltrim(str_replace($deployDir, '', $realPath), '/\\');
                $zip->addFile($realPath, $relPath);
            }
            $zip->close();

            $label    = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $deploy['version_label'] ?: 'deploy-' . $deploy['id']);
            $filename = 'site-' . $site['subdomain'] . '-' . $label . '.zip';

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($zipFile));
            header('Cache-Control: no-cache');
            readfile($zipFile);
            @unlink($zipFile);
            exit;
        },
        ['auth_middleware']
    );

    // GET /v1/projects/:project_id/sites/:site_id/debug  — filesystem diagnostics
    $router->get('/v1/projects/:project_id/sites/:site_id/debug', function (array $req) use ($catalog, $ownProject): void {
        $ownProject((int)$req['params']['project_id'], $req['auth']['user_id']);
        $site = $catalog->getSiteByProjectId((int)$req['params']['project_id'], (int)$req['params']['site_id']);
        if (!$site) abort(404, 'Site not found');

        $config    = \App::get('config');
        $sitesPath = $config['SITES_PATH'];
        $siteId    = (int)$req['params']['site_id'];
        $currentDir = $sitesPath . '/s' . $siteId . '/current';

        $files = [];
        if (is_dir($currentDir)) {
            foreach (scandir($currentDir) as $f) {
                if ($f === '.' || $f === '..') continue;
                $files[] = $f . (is_dir($currentDir . '/' . $f) ? '/' : '');
            }
        }

        json_out([
            'document_root'      => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
            'script_filename'    => $_SERVER['SCRIPT_FILENAME'] ?? 'unknown',
            'sites_path_config'  => $sitesPath,
            'current_dir'        => $currentDir,
            'current_dir_exists' => is_dir($currentDir),
            'files_in_current'   => $files,
            'expected_url_path'  => '/sites/s' . $siteId . '/current/',
            'sites_dir_exists'   => is_dir($sitesPath),
        ]);
    }, ['auth_middleware']);

    // GET /v1/projects/:project_id/sites/:site_id/browse?path=
    $router->get('/v1/projects/:project_id/sites/:site_id/browse', function (array $req) use ($catalog, $ownProject): void {
        $ownProject((int)$req['params']['project_id'], $req['auth']['user_id']);
        $site = $catalog->getSiteByProjectId((int)$req['params']['project_id'], (int)$req['params']['site_id']);
        if (!$site) {
            abort(404, 'Site not found');
        }
        if (!$site['current_deploy_id']) {
            abort(404, 'No deploy yet');
        }

        $config    = \App::get('config');
        $siteId    = (int)$req['params']['site_id'];
        $base      = rtrim($config['SITES_PATH'], '/') . '/s' . $siteId . '/current';
        $reqPath   = ltrim($req['query']['path'] ?? '', '/');
        $fullPath  = \SupaBein\Deploy::normalizePath($base . '/' . $reqPath);

        // Path traversal guard
        if ($fullPath !== $base && !str_starts_with($fullPath, $base . '/')) {
            abort(400, 'Invalid path');
        }
        if (!file_exists($fullPath)) {
            abort(404, 'Path not found');
        }

        if (is_dir($fullPath)) {
            $items = [];
            foreach (scandir($fullPath) as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $ep = $fullPath . '/' . $entry;
                $items[] = [
                    'name' => $entry,
                    'type' => is_dir($ep) ? 'dir' : 'file',
                    'size' => is_file($ep) ? filesize($ep) : null,
                ];
            }
            usort($items, fn($a, $b) =>
                $a['type'] !== $b['type'] ? ($a['type'] === 'dir' ? -1 : 1) : strcmp($a['name'], $b['name'])
            );
            json_out(['path' => $reqPath, 'type' => 'dir', 'items' => $items]);
        } else {
            $size = filesize($fullPath);
            if ($size > 524288) { // 512 KB cap
                json_out(['path' => $reqPath, 'type' => 'file', 'size' => $size, 'content' => null, 'truncated' => true]);
            } else {
                json_out(['path' => $reqPath, 'type' => 'file', 'size' => $size, 'content' => file_get_contents($fullPath), 'truncated' => false]);
            }
        }
    }, ['auth_middleware']);
}
