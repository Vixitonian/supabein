<?php

declare(strict_types=1);

use Firebase\JWT\JWT;

function make_service_key(int $projectId): string
{
    $config = \App::get('config');
    $now    = time();
    return JWT::encode(
        ['sub' => $projectId, 'pid' => $projectId, 'type' => 'service', 'iat' => $now],
        $config['JWT_SECRET'],
        $config['JWT_ALGO']
    );
}

function register_project_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    // GET /v1/projects — supports ?limit=&offset= for lazy-loading the project
    // list; without those params it returns the full array as before (used by
    // the AI panel's project picker and other callers that want everything).
    $router->get('/v1/projects', function (array $req) use ($catalog): void {
        $projects = $catalog->listProjectsWithStats($req['auth']['user_id']);

        if (!isset($req['query']['limit'])) {
            json_out($projects);
            return;
        }

        $limit  = max(1, min(100, (int)$req['query']['limit']));
        $offset = max(0, (int)($req['query']['offset'] ?? 0));

        json_out([
            'projects' => array_slice($projects, $offset, $limit),
            'total'    => count($projects),
            'has_more' => ($offset + $limit) < count($projects),
        ]);
    }, ['auth_middleware']);

    // GET /v1/overview — aggregated data for the Home dashboard
    $router->get('/v1/overview', function (array $req) use ($catalog): void {
        json_out($catalog->getOverview((int)$req['auth']['user_id']));
    }, ['auth_middleware']);

    // POST /v1/projects
    $router->post('/v1/projects', function (array $req) use ($catalog): void {
        $name = trim($req['body']['name'] ?? '');
        if (!$name || strlen($name) > 128) {
            abort(422, 'Project name is required (max 128 chars)');
        }

        // Reserve an ID by inserting with a placeholder key, then generate the real key with that ID
        try {
            $project = $catalog->createProject($req['auth']['user_id'], $name, '');
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                abort(409, 'A project with this name already exists');
            }
            throw $e;
        }

        $serviceKey = make_service_key((int)$project['id']);
        $catalog->setServiceKey((int)$project['id'], $serviceKey);
        $project['service_key'] = $serviceKey;

        json_out($project, 201);
    }, ['auth_middleware']);

    // GET /v1/projects/:id
    $router->get('/v1/projects/:id', function (array $req) use ($catalog): void {
        $project = $catalog->getProjectById((int)$req['params']['id'], $req['auth']['user_id']);
        if (!$project) {
            abort(404);
        }
        json_out($project);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/overview — stats, site status, and recent activity for one project
    $router->get('/v1/projects/:id/overview', function (array $req) use ($catalog): void {
        $projectId = (int)$req['params']['id'];
        $userId    = $req['auth']['user_id'];

        $project = $catalog->getProjectById($projectId, $userId);
        if (!$project) abort(404);

        json_out($catalog->getProjectOverview($projectId, $userId));
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id
    $router->delete('/v1/projects/:id', function (array $req) use ($catalog): void {
        $projectId = (int)$req['params']['id'];
        $userId    = $req['auth']['user_id'];

        $project = $catalog->getProjectByIdInternal($projectId);
        if (!$project || (int)$project['owner_user_id'] !== $userId) abort(404);

        $pdo    = \App::get('db');
        $config = \App::get('config');

        // Drop all physical MySQL tables for this project
        foreach ($catalog->listTables($projectId) as $tbl) {
            $pdo->exec(\SupaBein\Schema::dropTableDDL($tbl['physical_name']));
        }

        // Delete all site directories from disk
        $sitesPath = $config['SITES_PATH'];
        foreach ($catalog->listSites($projectId) as $site) {
            $siteDir = $sitesPath . '/s' . $site['id'];
            if (is_dir($siteDir)) \SupaBein\Deploy::rrmdir($siteDir);
        }

        // Delete project storage files
        $storagePath = $config['STORAGE_PATH'] . '/files/p' . $projectId;
        if (is_dir($storagePath)) \SupaBein\Deploy::rrmdir($storagePath);

        $catalog->deleteProject($projectId, $userId);
        json_out(['deleted' => true]);
    }, ['auth_middleware']);

    // POST /v1/projects/:id/rotate-service-key
    $router->post('/v1/projects/:id/rotate-service-key', function (array $req) use ($catalog): void {
        $projectId = (int)$req['params']['id'];
        $userId    = $req['auth']['user_id'];

        $project = $catalog->getProjectById($projectId, $userId);
        if (!$project) abort(404);

        $newKey = make_service_key($projectId);
        $catalog->setServiceKey($projectId, $newKey);
        json_out(['service_key' => $newKey]);
    }, ['auth_middleware']);

    // POST /v1/projects/:id/seed/clear — remove only rows that AI seeding inserted
    // (build's initial seed_data or an on-demand edit-mode seed request), leaving
    // any real user-entered rows in the same tables untouched.
    $router->post('/v1/projects/:id/seed/clear', function (array $req) use ($catalog): void {
        $projectId = (int)$req['params']['id'];
        $userId    = $req['auth']['user_id'];

        $project = $catalog->getProjectById($projectId, $userId);
        if (!$project) abort(404);

        $pdo  = \App::get('db');
        $rows = $pdo->prepare('SELECT table_name, row_id FROM project_seed_rows WHERE project_id = ?');
        $rows->execute([$projectId]);

        $byTable = [];
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $byTable[$r['table_name']][] = (int)$r['row_id'];
        }

        $deleted = [];
        foreach ($byTable as $tableName => $ids) {
            $tbl = $catalog->getTable($projectId, $tableName);
            if (!$tbl) continue;

            $physical     = $tbl['physical_name'];
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            try {
                $stmt = $pdo->prepare("DELETE FROM `{$physical}` WHERE id IN ({$placeholders})");
                $stmt->execute($ids);
                $deleted[$tableName] = $stmt->rowCount();
            } catch (\Throwable $e) {
                sb_log('ai_seed', 'Seed clear failed (non-fatal): ' . $e->getMessage(), ['table' => $tableName]);
            }
        }

        $pdo->prepare('DELETE FROM project_seed_rows WHERE project_id = ?')->execute([$projectId]);

        // Every test run signs up a fresh account to genuinely exercise signup
        // (ai_playwright_test_generate's TEST_EMAIL, ai_run_browser_test_agent's
        // own TEST_EMAIL) instead of reusing one that already exists -- these
        // go through the app's own live signup endpoint, not the PHP seed-insert
        // path, so project_seed_rows never learns about them and every test run
        // left a permanent, un-removable row behind. Both use a fixed,
        // recognizable prefix specifically so they can be swept up here safely
        // (a real user's email can never collide with it).
        $schema  = ai_schema_from_db($projectId, $catalog);
        $auth    = ai_detect_auth($schema);
        if ($auth['table']) {
            $tbl = $catalog->getTable($projectId, $auth['table']);
            if ($tbl) {
                $idField = $auth['field'] ?? 'email';
                try {
                    $stmt = $pdo->prepare("DELETE FROM `{$tbl['physical_name']}` WHERE `{$idField}` LIKE 'pw-a-%@testmail.dev' OR `{$idField}` LIKE 'pw-agent-%@testmail.dev'");
                    $stmt->execute();
                    if ($stmt->rowCount() > 0) $deleted[$auth['table']] = ($deleted[$auth['table']] ?? 0) + $stmt->rowCount();
                } catch (\Throwable $e) {
                    sb_log('ai_seed', 'Test-run account cleanup failed (non-fatal): ' . $e->getMessage());
                }
            }
        }

        json_out(['deleted' => $deleted]);
    }, ['auth_middleware']);

    // POST /v1/projects/:id/cleanup — remove ghost catalog entries and orphaned physical resources
    $router->post('/v1/projects/:id/cleanup', function (array $req) use ($catalog): void {
        $projectId = (int)$req['params']['id'];
        $userId    = $req['auth']['user_id'];

        $project = $catalog->getProjectByIdInternal($projectId);
        if (!$project || (int)$project['owner_user_id'] !== $userId) abort(404);

        $pdo    = \App::get('db');
        $config = \App::get('config');

        $tablesDropped       = [];
        $catalogTablesRemoved = 0;
        $deployRowsRemoved   = 0;
        $orphanDirsDeleted   = 0;

        // ── Table sync: catalog ↔ MySQL ──────────────────────────────────────
        $catalogTables = $catalog->listTables($projectId);
        $catalogPhysical = array_column($catalogTables, 'physical_name');

        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?'
        );
        $stmt->execute(['p' . $projectId . '_%']);
        $mysqlTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // Ghost entries: in catalog but not in MySQL → remove catalog row
        foreach ($catalogTables as $tbl) {
            if (!in_array($tbl['physical_name'], $mysqlTables, true)) {
                $pdo->prepare('DELETE FROM project_tables WHERE id = ?')->execute([$tbl['id']]);
                $catalogTablesRemoved++;
            }
        }

        // Orphan tables: in MySQL but not in catalog → DROP
        foreach ($mysqlTables as $mysqlName) {
            if (!in_array($mysqlName, $catalogPhysical, true)) {
                $pdo->exec(\SupaBein\Schema::dropTableDDL($mysqlName));
                $tablesDropped[] = $mysqlName;
            }
        }

        // ── Deploy sync: catalog ↔ disk ──────────────────────────────────────
        $sitesPath = $config['SITES_PATH'];
        foreach ($catalog->listSites($projectId) as $site) {
            $siteId   = (int)$site['id'];
            $deploys  = $catalog->listDeploys($siteId);
            $knownPaths = [];

            // Ghost deploys: catalog path doesn't exist on disk
            foreach ($deploys as $deploy) {
                $path = (string)($deploy['path'] ?? '');
                if ($path !== '') {
                    $knownPaths[] = rtrim($path, '/');
                    if (!is_dir($path)) {
                        $pdo->prepare('DELETE FROM deploys WHERE id = ?')->execute([$deploy['id']]);
                        $deployRowsRemoved++;
                        continue;
                    }
                }
                $knownPaths[] = rtrim($path, '/');
            }

            // Orphan deploy dirs: exist on disk but not in catalog
            $deploysDir = $sitesPath . '/s' . $siteId . '/deploys';
            if (is_dir($deploysDir)) {
                foreach (scandir($deploysDir) as $entry) {
                    if ($entry === '.' || $entry === '..') continue;
                    $full = $deploysDir . '/' . $entry;
                    if (is_dir($full) && !in_array($full, $knownPaths, true)) {
                        \SupaBein\Deploy::rrmdir($full);
                        $orphanDirsDeleted++;
                    }
                }
            }
        }

        json_out([
            'tables_dropped'           => $tablesDropped,
            'catalog_tables_removed'   => $catalogTablesRemoved,
            'deploy_rows_removed'      => $deployRowsRemoved,
            'orphan_deploy_dirs_deleted' => $orphanDirsDeleted,
        ]);
    }, ['auth_middleware']);
}
