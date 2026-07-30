<?php

declare(strict_types=1);



// Force these platform-provided paths to their canonical content, adding them
// if the AI omitted them (all are required whenever frontend files are being
// deployed) and overwriting them if the AI wrote something else (its content
// for these paths is always discarded either way). features/auth/auth.js is
// injected unconditionally, like the other platform files — real
// implementation when $authInfo (from ai_detect_auth) has a table, the inert
// stub above otherwise — rather than being omitted for auth-less apps, so a
// stray reference to `auth` never throws regardless of whether the AI's
// markup or bootstrap code assumed auth exists.
function ai_inject_canonical_frontend_files(array $frontendFiles, ?array $authInfo = null): array
{
    $byPath = [];
    foreach ($frontendFiles as $f) {
        if (!is_array($f) || !isset($f['path'])) continue;
        $byPath[ltrim((string)$f['path'], '/')] = $f;
    }
    $byPath['core/router.js'] = ['path' => 'core/router.js', 'content' => AI_CANONICAL_ROUTER_JS];
    $byPath['core/api.js']    = ['path' => 'core/api.js',    'content' => AI_CANONICAL_API_JS];
    $byPath['core/errors.js'] = ['path' => 'core/errors.js', 'content' => AI_CANONICAL_ERRORS_JS];
    $byPath['core/config.js'] = ['path' => 'core/config.js', 'content' => AI_CANONICAL_CONFIG_JS];
    if (!empty($authInfo['table'])) {
        $authJs = str_replace(
            ['__AUTH_TABLE__', '__AUTH_FIELD__'],
            [$authInfo['table'], $authInfo['field'] ?? 'email'],
            AI_CANONICAL_AUTH_JS
        );
        $byPath['features/auth/auth.js'] = ['path' => 'features/auth/auth.js', 'content' => $authJs];
    } else {
        $byPath['features/auth/auth.js'] = ['path' => 'features/auth/auth.js', 'content' => AI_CANONICAL_AUTH_STUB_JS];
    }
    return array_values($byPath);
}

// Forces every deployed index.html to load core/errors.js before any other
// script, regardless of whether the AI's markup included the tag. This is
// what makes error capture a deploy-time guarantee instead of a prompt-
// compliance hope: an app generated before this feature existed gets the
// tag added automatically on its very next deploy (build or edit), with no
// edit request needed to "pick up" the fix. Idempotent — a re-deploy that
// already has the tag (e.g. the AI added it anyway, or a second deploy of
// the same index.html) is left alone rather than duplicating it.
function ai_ensure_error_script_tag(string $html): string
{
    if (str_contains($html, 'core/errors.js')) return $html;
    $tag = '<script src="./core/errors.js"></script>' . "\n    ";
    if (preg_match('/<script\b/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        return substr($html, 0, $pos) . $tag . substr($html, $pos);
    }
    if (stripos($html, '</head>') !== false) {
        return str_ireplace('</head>', "    {$tag}</head>", $html);
    }
    return $tag . $html;
}

// ─── File-level helpers (filesystem) ────────────────────────────────────────

function ai_deploy_files(
    array $config,
    \SupaBein\Catalog $catalog,
    int $siteId,
    array $project,
    array $frontendFiles,
    bool $mergeFromCurrent = false,
    bool $publishLive = true,
    ?array $authInfo = null,
    string $frontendStack = 'vanilla'
): array {
    if ($frontendStack === 'react') {
        // The agent's raw .jsx files are never deployed as-is — bundle them
        // (with the platform's canonical React modules force-injected) into
        // the same {index.html, bundle.js} shape the rest of this function
        // already knows how to write, smoke-check, and publish.
        $build = ai_react_build_bundle($frontendFiles, $config, $authInfo, (string)($project['name'] ?? 'App'));
        if (!$build['ok']) {
            $deploy = $catalog->createDeploy($siteId, 'ai-generated-' . date('Y-m-d'), 0);
            $catalog->updateDeploy((int)$deploy['id'], 'failed');
            return ['error' => 'React build failed: ' . $build['error'], 'deploy' => null];
        }
        $frontendFiles = $build['files'];
    } else {
        $frontendFiles = ai_inject_canonical_frontend_files($frontendFiles, $authInfo);
    }
    $sitesPath = rtrim($config['SITES_PATH'], '/');
    $label     = 'ai-generated-' . date('Y-m-d');

    $deploy   = $catalog->createDeploy($siteId, $label, 0);
    $deployId = (int)$deploy['id'];
    $catalog->updateDeploy($deployId, 'processing');

    $deployDir = $sitesPath . '/s' . $siteId . '/deploys/'
               . date('Ymd_His') . '_' . $deployId;

    if (!is_dir($deployDir) && !mkdir($deployDir, 0755, true)) {
        $catalog->updateDeploy($deployId, 'failed');
        return ['error' => 'Cannot create deploy directory', 'deploy' => null];
    }

    // Seed from the site's actual live deploy so an edit that returns only
    // some files doesn't blank the rest of the site. A project can sit in
    // staging-only for its whole test-and-fix loop (Review-off builds deploy
    // to staging first; "current" only exists after an explicit Publish), so
    // this must prefer staging over current exactly like ai_effective_deploy_target()
    // elsewhere — merging from a nonexistent "current" here silently drops
    // every file the edit didn't re-output, which then fails the smoke check
    // below and the whole edit apply fails with nothing actually deployed.
    if ($mergeFromCurrent) {
        $mergeSite   = $catalog->getSiteById($siteId) ?? [];
        $mergeTarget = ai_effective_deploy_target($mergeSite);
        $mergeDir    = $sitesPath . '/s' . $siteId . '/' . $mergeTarget;
        if (is_dir($mergeDir)) {
            \SupaBein\Deploy::rcopy($mergeDir, $deployDir);
            @unlink($deployDir . '/.htaccess');   // regenerated below
        }
    }

    // Substitution map — replace placeholders with real values.
    // SB_URL is intentionally NOT substituted here; generated code uses
    // window.location.origin + '/api/v1' at runtime for HTTP/HTTPS compatibility.
    $replacements = [
        '__SB_PID__' => (string)$project['id'],
    ];

    $errors = [];
    foreach ($frontendFiles as $fileDef) {
        $relPath = ltrim((string)($fileDef['path'] ?? ''), '/');
        if ($relPath === '') continue;

        $fullPath = \SupaBein\Deploy::normalizePath($deployDir . '/' . $relPath);
        if (!str_starts_with($fullPath, $deployDir . '/')) {
            $errors[] = 'Path traversal attempt: ' . $relPath;
            continue;
        }

        $parentDir = dirname($fullPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        $rawContent = (string)($fileDef['content'] ?? '');
        if ($relPath === 'index.html' && $frontendStack !== 'react') {
            $rawContent = ai_ensure_error_script_tag($rawContent);
        }
        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $rawContent
        );

        if (file_put_contents($fullPath, $content) === false) {
            $errors[] = 'Cannot write file: ' . $relPath;
        }
    }

    if ($errors) {
        \SupaBein\Deploy::rrmdir($deployDir);
        $catalog->updateDeploy($deployId, 'failed');
        return ['error' => implode('; ', $errors), 'deploy' => null];
    }

    // Ensure the error-capture script tag on whatever index.html actually
    // ended up in the deploy dir — not just one freshly written this round.
    // An edit job that doesn't touch index.html merges the file forward
    // unchanged from the previous deploy (see $mergeFromCurrent above), so
    // checking only $frontendFiles here would miss every such deploy. Reading
    // back off disk after both the merge and the write loop is what makes
    // this actually unconditional on every deploy, matching the doc comment
    // on ai_ensure_error_script_tag().
    // Skipped for react: core/errors.js has no standalone deployed file to
    // point a <script src> at — its exact same error-capture behavior is
    // already active via main.jsx's `import './core/errors.js'`, bundled
    // directly into bundle.js. Adding the tag here would just 404.
    if ($frontendStack !== 'react') {
        $indexPath = $deployDir . '/index.html';
        if (is_file($indexPath)) {
            $indexHtml = file_get_contents($indexPath);
            $patched   = ai_ensure_error_script_tag((string)$indexHtml);
            if ($patched !== $indexHtml) {
                file_put_contents($indexPath, $patched);
            }
        }
    }

    // Hardening .htaccess (force-written, cannot be skipped).
    $htaccess = \SupaBein\Deploy::buildHardeningHtaccess(true);
    file_put_contents($deployDir . '/.htaccess', $htaccess);

    // Smoke check the assembled (merged) site before publishing.
    $smoke = ai_smoke_check_dir($deployDir);
    if ($smoke !== null) {
        \SupaBein\Deploy::rrmdir($deployDir);
        $catalog->updateDeploy($deployId, 'failed');
        return ['error' => 'Smoke check failed: ' . $smoke, 'deploy' => null];
    }

    // Calculate total size.
    $totalSize = 0;
    $iterator  = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($deployDir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $f) {
        if ($f->isFile()) $totalSize += $f->getSize();
    }
    \App::get('db')->prepare('UPDATE deploys SET size_bytes = ? WHERE id = ?')
                   ->execute([$totalSize, $deployId]);

    // Copy to staging/.
    $stagingDir = $sitesPath . '/s' . $siteId . '/staging';
    if (is_dir($stagingDir))   \SupaBein\Deploy::rrmdir($stagingDir);
    if (is_link($stagingDir))  unlink($stagingDir);
    \SupaBein\Deploy::rcopy($deployDir, $stagingDir);

    if (!is_dir($stagingDir)) {
        $catalog->updateDeploy($deployId, 'failed', $deployDir);
        return ['error' => 'Staging copy failed', 'deploy' => null];
    }

    $catalog->updateDeploy($deployId, 'ready', $deployDir);
    $catalog->updateSiteStagingDeploy($siteId, $deployId);

    // Staging-only: leave the deploy in staging/ with staging_deploy_id set so the
    // user can preview and explicitly publish it to live later.
    if (!$publishLive) {
        return ['error' => null, 'deploy' => $catalog->getDeployById($deployId), 'published' => false, 'staged' => true];
    }

    // Auto-publish to current/ so the site is live immediately.
    $currentDir = $sitesPath . '/s' . $siteId . '/current';
    if (is_dir($currentDir))  \SupaBein\Deploy::rrmdir($currentDir);
    if (is_link($currentDir)) unlink($currentDir);
    \SupaBein\Deploy::rcopy($stagingDir, $currentDir);
    $catalog->updateSiteCurrentDeploy($siteId, $deployId);
    $catalog->updateSiteStagingDeploy($siteId, null);

    return ['error' => null, 'deploy' => $catalog->getDeployById($deployId), 'published' => true];
}

// ─── Execution helpers ───────────────────────────────────────────────────────

// Record which rows were inserted by AI seeding (build's initial seed_data or
// an on-demand edit-mode seed request) so they — and only they — can later be
// removed by "clear seed data" without touching real user-entered rows.
function ai_track_seed_rows(\PDO $pdo, int $projectId, string $tableName, array $insertedIds): void
{
    if (!$insertedIds) return;
    $stmt = $pdo->prepare(
        'INSERT INTO project_seed_rows (project_id, table_name, row_id) VALUES (?, ?, ?)'
    );
    foreach ($insertedIds as $id) {
        try { $stmt->execute([$projectId, $tableName, (int)$id]); }
        catch (\Throwable $e) { sb_log('ai_seed', 'Seed row tracking failed (non-fatal): ' . $e->getMessage()); }
    }
}

// An LLM asked for an image/photo/avatar URL reliably invents a plausible-
// looking but nonexistent one (live-caught: "https://images.example.com/
// blog.jpg" — images.example.com is the RFC 2606 reserved domain that is
// GUARANTEED to never resolve to anything) rather than admit it can't
// actually know a real photo's address. Anchored on a preceding "_" or
// start-of-string so a real column like "discovery_notes" (contains "cover"
// as a raw substring) or "recover_token" never false-positives.
function ai_is_image_like_column(string $colName): bool
{
    return (bool)preg_match('/(^|_)(image|photo|avatar|thumbnail|banner|logo|picture|cover|img)(_url)?$/i', $colName);
}

// Picsum (picsum.photos) is a real, always-up photo CDN — seeding the URL
// deterministically from the table name + row identity (not randomly) means
// re-seeding the same project doesn't churn every image on each run, and two
// different rows in the same table never collide on the same photo.
function ai_real_seed_image_url(string $table, $rowKey): string
{
    $seed = preg_replace('/[^a-z0-9]+/i', '-', strtolower($table)) . '-' . $rowKey;
    return 'https://picsum.photos/seed/' . rawurlencode($seed) . '/800/600';
}

// Shared seed_data-block inserter used by both build (initial seed) and edit
// (on-demand "seed N fake rows" requests) — inserts rows and tracks their ids.
// $excludeTables skips tables handled separately (e.g. the auth table, which
// the on-demand "Seed App" flow seeds itself via ai_seed_test_accounts() so
// passwords get properly hashed instead of inserted as unusable plaintext).
function ai_insert_seed_data(\PDO $pdo, \SupaBein\Catalog $catalog, int $projectId, array $seedData, array $excludeTables = []): array
{
    $excludeTables = array_map('strtolower', $excludeTables);
    $seeded = [];
    foreach ($seedData as $seedTable => $rows) {
        if (!is_array($rows) || empty($rows)) continue;
        if (in_array(strtolower((string)$seedTable), $excludeTables, true)) continue;
        $tbl = $catalog->getTable($projectId, (string)$seedTable);
        if (!$tbl) continue;

        $physical  = $tbl['physical_name'];
        $imageCols = array_values(array_filter(
            array_column($catalog->listColumns((int)$tbl['id']), 'name'),
            'ai_is_image_like_column'
        ));
        $insertedIds = [];
        $rowIndex = 0;
        foreach (array_slice($rows, 0, 50) as $row) {
            if (!is_array($row) || empty($row)) continue;
            unset($row['id'], $row['created_at']);
            if (empty($row)) continue;
            $rowIndex++;

            // Never trust whatever the model put here (or left null/absent) —
            // always a real, working photo, regardless. This is what makes a
            // broken seeded image structurally impossible rather than merely
            // less likely: the model's own guess for this field is discarded
            // unconditionally, not validated or spot-corrected.
            foreach ($imageCols as $col) {
                $row[$col] = ai_real_seed_image_url((string)$seedTable, $rowIndex);
            }

            $cols         = array_keys($row);
            $colList      = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            try {
                $pdo->prepare("INSERT INTO `{$physical}` ({$colList}) VALUES ({$placeholders})")
                    ->execute(array_values($row));
                $insertedIds[] = (int)$pdo->lastInsertId();
            } catch (\Throwable $e) {
                sb_log('ai_seed', 'Seed insert failed (non-fatal): ' . $e->getMessage(), ['table' => $seedTable]);
            }
        }
        if ($insertedIds) {
            ai_track_seed_rows($pdo, $projectId, (string)$seedTable, $insertedIds);
            $seeded[] = "{$seedTable}: " . count($insertedIds) . ' row' . (count($insertedIds) !== 1 ? 's' : '');
        }
    }
    return $seeded;
}

// Retroactive counterpart to the write-time fix in ai_insert_seed_data() above
// — that fix only stops a NEW broken image URL from being written, it can't
// undo one already sitting in the database from before this shipped. Scoped
// via project_seed_rows (the same table "clear seed data" uses) so this only
// ever touches rows the platform itself seeded, never a real user's own
// uploaded/entered data. Returns the number of values actually replaced.
function ai_heal_seed_image_urls(\PDO $pdo, \SupaBein\Catalog $catalog, int $projectId): int
{
    $healed = 0;
    $tableStmt = $pdo->prepare('SELECT DISTINCT table_name FROM project_seed_rows WHERE project_id = ?');
    $tableStmt->execute([$projectId]);

    foreach ($tableStmt->fetchAll(\PDO::FETCH_COLUMN) as $tableName) {
        $tbl = $catalog->getTable($projectId, (string)$tableName);
        if (!$tbl) continue;
        $imageCols = array_values(array_filter(
            array_column($catalog->listColumns((int)$tbl['id']), 'name'),
            'ai_is_image_like_column'
        ));
        if (!$imageCols) continue;
        $physical = $tbl['physical_name'];

        $rowStmt = $pdo->prepare('SELECT row_id FROM project_seed_rows WHERE project_id = ? AND table_name = ?');
        $rowStmt->execute([$projectId, $tableName]);
        foreach ($rowStmt->fetchAll(\PDO::FETCH_COLUMN) as $rowId) {
            foreach ($imageCols as $col) {
                try {
                    $cur = $pdo->prepare("SELECT `{$col}` FROM `{$physical}` WHERE id = ?");
                    $cur->execute([$rowId]);
                    $value = $cur->fetchColumn();
                    if ($value !== false && $value !== null && str_starts_with((string)$value, 'https://picsum.photos/')) {
                        continue; // already a real, working URL — nothing to heal
                    }
                    $pdo->prepare("UPDATE `{$physical}` SET `{$col}` = ? WHERE id = ?")
                        ->execute([ai_real_seed_image_url((string)$tableName, $rowId), $rowId]);
                    $healed++;
                } catch (\Throwable $e) {
                    sb_log('ai_seed', 'Seed image heal failed (non-fatal): ' . $e->getMessage(), ['table' => $tableName, 'row' => $rowId]);
                }
            }
        }
    }
    return $healed;
}

// A policy's constraint_sql is stored and executed verbatim (QueryBuilder
// just interpolates it into the WHERE clause — see app/core/query_builder.php
// and Policy::resolveConstraint, which only substitutes :current_user_id).
// The AI only ever knows tables by their LOGICAL name, so a completely normal
// row-level-security pattern — "visible to the user who owns the related
// program" — comes back as a subquery like
// "id IN (SELECT project_id FROM project_assignments WHERE ...)", written
// against the logical name. The real MySQL table is project-prefixed
// (p{id}_project_assignments), so that subquery 500s the instant it runs.
// Live-caught: a generated app's /projects, /enrollments, and
// /project_applications pages all 500'd this exact way. Rewriting any
// "FROM <logical>" / "JOIN <logical>" reference in the stored SQL to the real
// physical name, once, at write time, means every future read of this policy
// executes correctly forever — no per-request rewriting needed.
function ai_rewrite_constraint_table_refs(?string $sql, int $projectId, array $logicalNames): ?string
{
    if ($sql === null || $sql === '') return $sql;
    foreach ($logicalNames as $name) {
        $name = (string)$name;
        if ($name === '') continue;
        $physical = 'p' . $projectId . '_' . strtolower($name);
        $sql = preg_replace(
            '/\b(FROM|JOIN)\s+' . preg_quote($name, '/') . '\b/i',
            '$1 `' . $physical . '`',
            $sql
        );
    }
    return $sql;
}

// Applies ai_rewrite_constraint_table_refs() retroactively to every existing
// policy in a project, persisting any change. Closes the gap that fix alone
// can't: it only prevents a *new* broken constraint_sql from being written,
// it can't undo one written before it shipped. A project built earlier than
// this fix stays broken forever unless something goes back and repairs its
// already-stored policies — every edit is a natural, low-cost point to do
// that, since the full table list and every policy are already being loaded
// for the edit anyway. Returns the number of policies actually changed.
function ai_heal_project_policy_refs(int $projectId, \SupaBein\Catalog $catalog, array $tables): int
{
    $logicalNames = array_column($tables, 'name');
    $healed = 0;
    foreach ($tables as $t) {
        $tbl = $catalog->getTable($projectId, $t['name']);
        if (!$tbl) continue;
        foreach ($catalog->listPolicies((int)$tbl['id']) as $p) {
            if (empty($p['constraint_sql'])) continue;
            $fixed = ai_rewrite_constraint_table_refs($p['constraint_sql'], $projectId, $logicalNames);
            if ($fixed !== $p['constraint_sql']) {
                $catalog->upsertPolicy((int)$tbl['id'], $p['api_role'], $p['operation'], (bool)$p['allowed'], $fixed);
                $healed++;
            }
        }
    }
    return $healed;
}

function ai_execute_build(array $plan, int $userId): array
{
    $config  = \App::get('config');
    $catalog = \SupaBein\Catalog::getInstance();
    $pdo     = \App::get('db');

    $projectName   = trim($plan['project_name']);
    $frontendStack = ($plan['frontend_stack'] ?? 'vanilla') === 'react' ? 'react' : 'vanilla';
    $partial       = ['project' => null, 'tables' => [], 'site' => null];

    try {
        $project   = $catalog->createProject($userId, $projectName, '', $frontendStack);
        $projectId = (int)$project['id'];
        $serviceKey = make_service_key($projectId);
        $catalog->setServiceKey($projectId, $serviceKey);
        $project['service_key'] = $serviceKey;
        $partial['project'] = $project;
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            abort(409, "A project named \"$projectName\" already exists");
        }
        abort(500, 'Failed to create project: ' . $e->getMessage());
    }

    // The column an app logs in by (email/username/etc., paired with the
    // PASSWORD column) must be UNIQUE at the DB level -- without it, nothing
    // stops the same identifier from being registered twice, and login-by-
    // identifier becomes ambiguous the moment it happens. Detected once
    // against the whole plan since it's the same table/field for every
    // table in this build.
    $authField = ai_detect_auth($plan);

    // See ai_rewrite_constraint_table_refs() — this build's own table names
    // are the full set any policy in it could plausibly reference.
    $allTableNames = array_column($plan['tables'], 'name');

    foreach ($plan['tables'] as $tableDef) {
        $tableName = $tableDef['name'];

        $columns = [];
        foreach ($tableDef['columns'] ?? [] as $col) {
            $columns[] = [
                'name'     => \SupaBein\Schema::validateIdentifier($col['name']),
                'type'     => \SupaBein\Schema::validateDataType($col['type']),
                'nullable' => (bool)($col['nullable'] ?? true),
                'default'  => (isset($col['default']) && $col['default'] !== null && $col['default'] !== false)
                               ? (string)$col['default'] : null,
                'unique'   => $tableName === $authField['table'] && $col['name'] === $authField['field'],
            ];
        }

        try {
            $table = $catalog->createTable($projectId, $tableName);
        } catch (\PDOException $e) {
            $catalog->deleteProject($projectId, $userId);
            abort(500, "Table creation failed for \"$tableName\": " . $e->getMessage(), [
                'partial' => array_merge($partial, ['failed_at' => $tableName]),
            ]);
        }

        try {
            $ddl = \SupaBein\Schema::createTableDDL($table['physical_name'], $columns);
            \SupaBein\Schema::applyDDL($pdo, $projectId, $ddl);
        } catch (\Throwable $e) {
            $catalog->deleteTable($projectId, $tableName);
            $catalog->deleteProject($projectId, $userId);
            abort(500, "DDL failed for table \"$tableName\": " . $e->getMessage(), [
                'partial' => array_merge($partial, ['failed_at' => $tableName]),
            ]);
        }

        foreach ($columns as $col) {
            $catalog->addColumn($table['id'], $col['name'], $col['type'], $col['nullable'], $col['default'], $col['unique'] ?? false);
        }

        foreach ($tableDef['policies'] ?? [] as $policy) {
            try {
                $catalog->upsertPolicy(
                    $table['id'],
                    $policy['api_role'],
                    strtoupper($policy['operation']),
                    (bool)$policy['allowed'],
                    ai_rewrite_constraint_table_refs($policy['constraint_sql'] ?? null, $projectId, $allTableNames)
                );
            } catch (\Throwable $e) {
                sb_log('ai_build', 'Policy upsert failed (non-fatal): ' . $e->getMessage(), ['table' => $tableName]);
            }
        }
        $catalog->backfillAuthenticatedAccess($table['id']);
        $catalog->reconcileNoAuthAnonAccess($projectId, $table['id']);

        $partial['tables'][] = ['name' => $tableName, 'columns' => count($columns)];
    }

    // ── Seed data insertion ───────────────────────────────────────────────────
    if (!empty($plan['seed_data']) && is_array($plan['seed_data'])) {
        ai_insert_seed_data($pdo, $catalog, $projectId, $plan['seed_data']);
    }

    $subdomain = $plan['subdomain'];
    $site      = null;
    $deploy    = null;

    try {
        $site = $catalog->createSite($projectId, $subdomain, true);
        $catalog->syncSiteRegistry($site, $projectId);
        $partial['site'] = $site;
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            $subdomain = $subdomain . '-' . $projectId;
            try {
                $site = $catalog->createSite($projectId, $subdomain, true);
                $catalog->syncSiteRegistry($site, $projectId);
                $partial['site'] = $site;
            } catch (\PDOException $e2) {
                sb_log('ai_build', 'Site creation failed (non-fatal): ' . $e2->getMessage());
            }
        } else {
            sb_log('ai_build', 'Site creation failed (non-fatal): ' . $e->getMessage());
        }
    }

    $staging = null;
    $deployError = null;
    if ($site !== null && !empty($plan['frontend']['files'])) {
        // Builds deploy to STAGING (preview), same as edits — the user
        // publishes to live explicitly via the Publish button, after
        // reviewing/testing the staged result.
        $deployResult = ai_deploy_files(
            $config,
            $catalog,
            (int)$site['id'],
            $project,
            $plan['frontend']['files'],
            false,
            false,
            ai_detect_auth($plan),
            $frontendStack
        );
        if ($deployResult['error']) {
            // Previously only logged server-side (sb_log) and silently dropped
            // from the returned result — the caller (and the dashboard) had no
            // way to know deploy failed at all, only that 'staging' was absent,
            // which the UI used to misread as success (job 218). Surfaced here
            // the same way ai_execute_edit()'s apply route already exposes
            // 'deploy_error' for the edit path.
            $deployError = $deployResult['error'];
            sb_log('ai_build', 'Deploy failed (non-fatal): ' . $deployResult['error']);
        } else {
            $deploy = $deployResult['deploy'];
            $apiBase = rtrim($config['API_BASE_URL'] ?? '', '/');
            $appBase = preg_replace('#/(api|v\d+)(/.*)?$#i', '', $apiBase);
            $staging = [
                'project_id'    => $projectId,
                'site_id'       => (int)$site['id'],
                'deploy_id'     => (int)$deploy['id'],
                'staging_url'   => $appBase . '/sites/s' . $site['id'] . '/staging/',
                'subdomain'     => $site['subdomain'] ?? null,
                'custom_domain' => $site['custom_domain'] ?? null,
            ];
        }
    }

    sb_log('ai_build', 'Complete', [
        'project_id' => $projectId,
        'tables'     => count($plan['tables']),
        'site_id'    => $site['id'] ?? null,
    ]);

    return [
        'project'      => $project,
        'tables'       => $partial['tables'],
        'site'         => $site,
        'deploy'       => $deploy,
        'staging'      => $staging,
        'deploy_error' => $deployError,
    ];
}

function ai_execute_edit(array $delta, int $projectId, int $userId): array
{
    $catalog = \SupaBein\Catalog::getInstance();
    $pdo     = \App::get('db');

    $addedTables     = [];
    $addedColumns    = [];
    $updatedPolicies = [];

    // See ai_rewrite_constraint_table_refs() — a policy added or changed by
    // this edit can reference any table already in the project OR one being
    // added in this same delta.
    $allTableNames = array_column($catalog->listTables($projectId), 'table_name');
    foreach ($delta['add_tables'] ?? [] as $t) {
        if (!empty($t['name'])) $allTableNames[] = (string)$t['name'];
    }

    foreach ($delta['add_tables'] ?? [] as $tableDef) {
        try { \SupaBein\Schema::validateIdentifier($tableDef['name'] ?? ''); }
        catch (\InvalidArgumentException $e) { continue; }

        // Same reasoning as ai_execute_build(): if this new table is the one
        // introducing auth (a PASSWORD column), its login identifier column
        // must be UNIQUE. Scoped to just this table's own definition since
        // that's all ai_detect_auth() needs to find it.
        $authField = ai_detect_auth(['tables' => [$tableDef]]);

        $columns = [];
        foreach ($tableDef['columns'] ?? [] as $col) {
            try {
                $colName = \SupaBein\Schema::validateIdentifier($col['name'] ?? '');
                if (in_array(strtolower($colName), ['id','created_at'], true)) continue;
                $columns[] = [
                    'name'     => $colName,
                    'type'     => \SupaBein\Schema::validateDataType($col['type'] ?? 'TEXT'),
                    'nullable' => (bool)($col['nullable'] ?? true),
                    'default'  => null,
                    'unique'   => $colName === $authField['field'],
                ];
            } catch (\InvalidArgumentException $e) { continue; }
        }

        try {
            $table = $catalog->createTable($projectId, $tableDef['name']);
            $ddl   = \SupaBein\Schema::createTableDDL($table['physical_name'], $columns);
            \SupaBein\Schema::applyDDL($pdo, $projectId, $ddl);
            foreach ($columns as $col) {
                $catalog->addColumn($table['id'], $col['name'], $col['type'], $col['nullable'], $col['default'], $col['unique'] ?? false);
            }
            foreach ($tableDef['policies'] ?? [] as $p) {
                try {
                    $catalog->upsertPolicy(
                        $table['id'],
                        $p['api_role'],
                        strtoupper($p['operation']),
                        (bool)$p['allowed'],
                        ai_rewrite_constraint_table_refs($p['constraint_sql'] ?? null, $projectId, $allTableNames)
                    );
                } catch (\Throwable $e) {}
            }
            $catalog->backfillAuthenticatedAccess($table['id']);
            $catalog->reconcileNoAuthAnonAccess($projectId, $table['id']);
            $addedTables[] = $tableDef['name'];
        } catch (\Throwable $e) {
            sb_log('ai_edit', 'add_table failed: ' . $e->getMessage(), ['table' => $tableDef['name']]);
        }
    }

    foreach ($delta['add_columns'] ?? [] as $entry) {
        $tblName = $entry['table'] ?? '';
        $tbl = $catalog->getTable($projectId, $tblName);
        if (!$tbl) continue;

        foreach ($entry['columns'] ?? [] as $col) {
            try {
                $colName = \SupaBein\Schema::validateIdentifier($col['name'] ?? '');
                if (in_array(strtolower($colName), ['id','created_at'], true)) continue;
                $colType  = \SupaBein\Schema::validateDataType($col['type'] ?? 'TEXT');
                $nullable = (bool)($col['nullable'] ?? true);
                $unique   = (bool)($col['unique'] ?? false);

                $physicalTable = $tbl['physical_name'];
                $ddl = \SupaBein\Schema::addColumnDDL($physicalTable, [
                    'name' => $colName, 'type' => $colType, 'nullable' => $nullable, 'unique' => $unique,
                ]);
                \SupaBein\Schema::applyDDL($pdo, $projectId, $ddl);
                $catalog->addColumn($tbl['id'], $colName, $colType, $nullable, null, $unique);
                $addedColumns[] = $tblName . '.' . $colName;
            } catch (\Throwable $e) {
                sb_log('ai_edit', 'add_column failed: ' . $e->getMessage());
            }
        }
    }

    $policyTouchedTableIds = [];
    foreach ($delta['update_policies'] ?? [] as $p) {
        $tblName = $p['table'] ?? '';
        $tbl = $catalog->getTable($projectId, $tblName);
        if (!$tbl) continue;
        try {
            $catalog->upsertPolicy(
                $tbl['id'],
                $p['api_role'],
                strtoupper($p['operation']),
                (bool)$p['allowed'],
                ai_rewrite_constraint_table_refs($p['constraint_sql'] ?? null, $projectId, $allTableNames)
            );
            $updatedPolicies[] = $tblName . '.' . $p['api_role'] . '.' . $p['operation'];
            $policyTouchedTableIds[$tbl['id']] = true;
        } catch (\Throwable $e) {
            sb_log('ai_edit', 'policy update failed: ' . $e->getMessage());
        }
    }
    foreach (array_keys($policyTouchedTableIds) as $touchedTableId) {
        $catalog->backfillAuthenticatedAccess((int)$touchedTableId);
        $catalog->reconcileNoAuthAnonAccess($projectId, (int)$touchedTableId);
    }

    $seeded = [];
    if (!empty($delta['seed_data']) && is_array($delta['seed_data'])) {
        $seeded = ai_insert_seed_data($pdo, $catalog, $projectId, $delta['seed_data']);
    }

    sb_log('ai_edit', 'Complete', ['project_id' => $projectId, 'added_tables' => count($addedTables)]);

    return [
        'added_tables'     => $addedTables,
        'added_columns'    => $addedColumns,
        'updated_policies' => $updatedPolicies,
        'seeded'           => $seeded,
    ];
}

// Full, unfiltered file dump for the validator (an edit's delta only contains
// CHANGED files, so validating it alone would false-positive on every route/
// nav check that depends on a file the edit didn't touch — this reads the
// complete current deploy so the delta can be merged onto it before validating,
// mirroring exactly what ai_deploy_files($mergeFromCurrent=true) does on disk).
function ai_read_full_frontend_files(array $config, \SupaBein\Catalog $catalog, int $projectId, ?string $target = null): array
{
    $sites = $catalog->listSites($projectId);
    if (empty($sites)) return [];

    $site   = $sites[0];
    $target = $target ?? ai_effective_deploy_target($site);
    $deployIdKey = $target === 'staging' ? 'staging_deploy_id' : 'current_deploy_id';
    if (!($site[$deployIdKey] ?? null)) return [];

    $sitesPath  = rtrim($config['SITES_PATH'], '/');
    $currentDir = $sitesPath . '/s' . $site['id'] . '/' . $target;
    if (!is_dir($currentDir)) return [];

    $textExts = ['html', 'css', 'js', 'json'];
    $files    = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($currentDir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if (!in_array(strtolower($file->getExtension()), $textExts, true)) continue;
        $rel = ltrim(substr($file->getPathname(), strlen($currentDir)), '/');
        $files[] = ['path' => $rel, 'content' => (string)file_get_contents($file->getPathname())];
    }
    return $files;
}

function ai_read_frontend_files(array $config, \SupaBein\Catalog $catalog, int $projectId, string $prompt = ''): string
{
    $sites = $catalog->listSites($projectId);
    if (empty($sites)) return '';

    $site        = $sites[0];
    $target      = ai_effective_deploy_target($site);
    $deployIdKey = $target === 'staging' ? 'staging_deploy_id' : 'current_deploy_id';
    if (!($site[$deployIdKey] ?? null)) return '';

    $sitesPath  = rtrim($config['SITES_PATH'], '/');
    $currentDir = $sitesPath . '/s' . $site['id'] . '/' . $target;
    if (!is_dir($currentDir)) return '';

    // Index all text files
    $textExts = ['html', 'css', 'js', 'json'];
    $allFiles = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($currentDir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if (!in_array(strtolower($file->getExtension()), $textExts, true)) continue;
        $rel = ltrim(substr($file->getPathname(), strlen($currentDir)), '/');
        $allFiles[$rel] = $file->getPathname();
    }
    if (empty($allFiles)) return '';

    $listing = 'Frontend files: ' . implode(', ', array_keys($allFiles));

    // Tier 1 — debug/fix signals: send everything (up to cap)
    $lowerPrompt = strtolower($prompt);
    $isDebug = (bool)preg_match('/\b(why|fix|broken|error|bug|issue|problem|debug|not working|failed|wrong|crash)\b/', $lowerPrompt);

    // Tier 2 — extract meaningful prompt words (4+ chars) to match against file paths
    $stopWords = ['that', 'this', 'with', 'have', 'from', 'they', 'will', 'what', 'when', 'where', 'which', 'there', 'their', 'your', 'about', 'does', 'just', 'like', 'make', 'show', 'give', 'tell'];
    $promptWords = array_unique(array_filter(
        preg_split('/\W+/', $lowerPrompt) ?: [],
        fn($w) => strlen($w) >= 4 && !in_array($w, $stopWords, true)
    ));

    $targeted = [];
    if (!$isDebug && !empty($promptWords)) {
        foreach ($allFiles as $rel => $fullPath) {
            $lowerRel = strtolower($rel);
            foreach ($promptWords as $word) {
                if (str_contains($lowerRel, $word)) {
                    $targeted[] = $rel;
                    break;
                }
            }
        }
    }

    // Tier 3 — no file signals at all: return listing only
    if (!$isDebug && empty($targeted)) {
        return "\n\n" . $listing;
    }

    // Read the relevant files up to 40KB
    $sources    = $isDebug ? array_keys($allFiles) : $targeted;
    $maxTotal   = 40000;
    $totalBytes = 0;
    $fileLines  = [$listing];

    foreach ($sources as $rel) {
        $fullPath = $allFiles[$rel];
        $size     = filesize($fullPath);
        if ($totalBytes + $size > $maxTotal) {
            $fileLines[] = "--- $rel (too large, skipped) ---";
            continue;
        }
        $fileLines[]  = "--- $rel ---\n" . file_get_contents($fullPath);
        $totalBytes  += $size;
    }

    return "\n\nFrontend files (current deploy):\n" . implode("\n\n", $fileLines);
}