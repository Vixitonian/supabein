<?php

declare(strict_types=1);

function register_table_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    // ── Helper: verify caller owns the project ───────────────────────────────
    $ownProject = function (int $projectId, int $userId) use ($catalog): array {
        $project = $catalog->getProjectById($projectId, $userId);
        if (!$project) {
            abort(404, 'Project not found');
        }
        $project['id'] = (int)$project['id'];
        return $project;
    };

    // ── Helper: resolve table within project ─────────────────────────────────
    $ownTable = function (int $projectId, string $tableName) use ($catalog): array {
        $table = $catalog->getTable($projectId, $tableName);
        if (!$table) {
            abort(404, 'Table not found');
        }
        $table['id']         = (int)$table['id'];
        $table['project_id'] = (int)$table['project_id'];
        return $table;
    };

    // GET /v1/projects/:id/tables
    $router->get('/v1/projects/:id/tables', function (array $req) use ($catalog, $ownProject): void {
        $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $tables = $catalog->listTables((int)$req['params']['id']);
        foreach ($tables as &$table) {
            $table['row_count'] = $catalog->countTableRows($table['physical_name']);
        }
        unset($table);
        json_out($tables);
    }, ['auth_middleware']);

    // POST /v1/projects/:id/tables
    $router->post('/v1/projects/:id/tables', function (array $req) use ($catalog, $ownProject): void {
        $project   = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $tableName = trim($req['body']['name'] ?? '');

        sb_log('table_create', 'Attempt', ['project_id' => $project['id'], 'name' => $tableName ?: '(empty)']);

        try {
            \SupaBein\Schema::validateIdentifier($tableName);
        } catch (\InvalidArgumentException $e) {
            sb_log('table_create', 'FAIL validation', ['name' => $tableName, 'error' => $e->getMessage()]);
            abort(422, $e->getMessage());
        }

        // Parse initial columns if provided
        // Strip reserved auto-generated columns (id, created_at) so AI-generated schemas
        // that include them don't cause "Duplicate column name" DDL failures.
        $rawCols         = $req['body']['columns'] ?? [];
        $columns         = [];
        $skippedReserved = [];
        $reservedCols    = ['id', 'created_at'];
        foreach ($rawCols as $col) {
            if (in_array(strtolower(trim((string)($col['name'] ?? ''))), $reservedCols, true)) {
                $skippedReserved[] = strtolower(trim((string)$col['name']));
                continue;
            }
            try {
                $raw_default = $col['default'] ?? null;
                $colName     = \SupaBein\Schema::validateIdentifier($col['name'] ?? '');
                $dataType    = \SupaBein\Schema::validateDataType($col['type'] ?? '');

                // Foreign key: explicit `references` (logical table name)
                // wins; otherwise auto-detect from the *_id naming
                // convention against tables that already exist in this
                // project (the table being created here can't be its own
                // FK target). See findForeignKeyTarget() for the heuristic.
                $fkParentPhysical = null;
                $refLogical = $col['references'] ?? null;
                if ($refLogical !== null) {
                    $refTable = $catalog->getTable($project['id'], (string)$refLogical);
                    if (!$refTable) abort(422, "references table '$refLogical' not found in this project");
                    $fkParentPhysical = $refTable['physical_name'];
                    $dataType = 'INT';
                } else {
                    $auto = $catalog->findForeignKeyTarget($project['id'], $colName);
                    if ($auto) { $fkParentPhysical = $auto['physical_name']; $dataType = 'INT'; }
                }

                $columns[] = [
                    'name'       => $colName,
                    'type'       => $dataType,
                    'nullable'   => (bool)($col['nullable'] ?? true),
                    'default'    => is_bool($raw_default) ? ($raw_default ? '1' : '0') : $raw_default,
                    'references' => $fkParentPhysical,
                ];
            } catch (\InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            }
        }

        try {
            $table = $catalog->createTable($project['id'], $tableName);
        } catch (\PDOException $e) {
            sb_log('table_create', 'FAIL catalog insert: ' . $e->getMessage(), ['project_id' => $project['id'], 'name' => $tableName]);
            if (str_contains($e->getMessage(), 'Duplicate')) {
                abort(409, 'A table with this name already exists in the project');
            }
            abort(500, 'Failed to register table: ' . $e->getMessage());
        }

        sb_log('table_create', 'Catalog entry created', ['table_id' => $table['id'], 'physical' => $table['physical_name']]);

        try {
            $pdo = \App::get('db');
            $ddl = \SupaBein\Schema::createTableDDL($table['physical_name'], $columns);
            sb_log('table_create', 'Applying DDL', ['ddl' => $ddl]);
            \SupaBein\Schema::applyDDL($pdo, (int)$project['id'], $ddl);
            sb_log('table_create', 'OK', ['physical' => $table['physical_name']]);
        } catch (\Throwable $e) {
            sb_log('table_create', 'FAIL DDL: ' . $e->getMessage(), ['physical' => $table['physical_name']]);
            // Roll back the catalog entry since DDL failed
            $catalog->deleteTable((int)$project['id'], $tableName);
            abort(500, 'Failed to create table in database: ' . $e->getMessage());
        }

        // Record user-defined columns in catalog
        foreach ($columns as $col) {
            $catalog->addColumn($table['id'], $col['name'], $col['type'], $col['nullable'], $col['default']);
        }

        $out = ['table' => $table, 'ddl' => $ddl];
        if ($skippedReserved) {
            $out['skipped_reserved_columns'] = $skippedReserved;
        }
        json_out($out, 201);
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/tables/:name
    $router->delete('/v1/projects/:id/tables/:name', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable((int)$project['id'], $req['params']['name']);

        $pdo = \App::get('db');
        $ddl = \SupaBein\Schema::dropTableDDL($table['physical_name']);
        \SupaBein\Schema::applyDDL($pdo, $project['id'], $ddl);

        $catalog->deleteTable($project['id'], $table['table_name']);
        json_out(['deleted' => true]);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/tables/:name/columns
    $router->get('/v1/projects/:id/tables/:name/columns', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable((int)$project['id'], $req['params']['name']);
        json_out($catalog->listColumns($table['id']));
    }, ['auth_middleware']);

    // POST /v1/projects/:id/tables/:name/columns
    $router->post('/v1/projects/:id/tables/:name/columns', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable((int)$project['id'], $req['params']['name']);

        $colName     = $req['body']['name'] ?? '';
        $dataType    = $req['body']['type'] ?? '';
        $nullable    = (bool)($req['body']['nullable'] ?? true);
        $raw_default = $req['body']['default'] ?? null;
        $default     = is_bool($raw_default) ? ($raw_default ? '1' : '0') : $raw_default;
        $refLogical  = $req['body']['references'] ?? null;

        if (in_array(strtolower(trim($colName)), ['id', 'created_at'], true)) {
            abort(422, "Column '$colName' is reserved and auto-generated by SupaBein — omit it from your schema.");
        }

        try {
            $colName  = \SupaBein\Schema::validateIdentifier($colName);
            $dataType = \SupaBein\Schema::validateDataType($dataType);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        // Foreign key: an explicit `references` (logical table name) wins;
        // otherwise auto-detect from the *_id naming convention against
        // tables already in this project. Either way forces the column to
        // INT (stored as INT UNSIGNED in the real DDL — see Schema) so it
        // can actually reference the target's id PK.
        $fkParentPhysical = null;
        if ($refLogical !== null) {
            $refTable = $catalog->getTable($project['id'], (string)$refLogical);
            if (!$refTable) abort(422, "references table '$refLogical' not found in this project");
            $fkParentPhysical = $refTable['physical_name'];
            $dataType = 'INT';
        } else {
            $auto = $catalog->findForeignKeyTarget($project['id'], $colName, (int)$table['id']);
            if ($auto) { $fkParentPhysical = $auto['physical_name']; $dataType = 'INT'; }
        }

        $pdo = \App::get('db');
        $ddl = \SupaBein\Schema::addColumnDDL($table['physical_name'], [
            'name' => $colName, 'type' => $dataType, 'nullable' => $nullable, 'default' => $default,
            'references' => $fkParentPhysical,
        ]);
        \SupaBein\Schema::applyDDL($pdo, $project['id'], $ddl);

        try {
            $col = $catalog->addColumn($table['id'], $colName, $dataType, $nullable, $default);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                abort(409, 'Column already exists');
            }
            throw $e;
        }
        $col['references'] = $fkParentPhysical !== null;

        json_out($col, 201);
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/tables/:name/columns/:col
    $router->delete('/v1/projects/:id/tables/:name/columns/:col', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable((int)$project['id'], $req['params']['name']);
        $colName = $req['params']['col'];

        try {
            \SupaBein\Schema::validateIdentifier($colName);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        if (!$catalog->getColumn($table['id'], $colName)) {
            abort(404, 'Column not found');
        }

        $pdo = \App::get('db');
        $ddl = \SupaBein\Schema::dropColumnDDL($table['physical_name'], $colName);
        \SupaBein\Schema::applyDDL($pdo, $project['id'], $ddl);
        $catalog->deleteColumn($table['id'], $colName);

        json_out(['deleted' => true]);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/tables/:name/policies
    $router->get('/v1/projects/:id/tables/:name/policies', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable((int)$project['id'], $req['params']['name']);
        json_out($catalog->listPolicies($table['id']));
    }, ['auth_middleware']);

    // PUT /v1/projects/:id/tables/:name/policies
    // Accepts a single policy object OR an array of policy objects (batch upsert).
    // Shorthand: {"api_role":"anon","allow":["SELECT","INSERT"]} auto-denies omitted operations.
    $router->put('/v1/projects/:id/tables/:name/policies', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable($project['id'], $req['params']['name']);

        $body = $req['body'];
        $raw  = isset($body[0]) ? $body : [$body];

        // Expand shorthand {"api_role","allow":[...]} into one entry per operation
        $policies = [];
        foreach ($raw as $entry) {
            if (isset($entry['allow']) && is_array($entry['allow'])) {
                $apiRole     = $entry['api_role']      ?? '';
                $constraint  = $entry['constraint_sql'] ?? null;
                $allowedOps  = array_map('strtoupper', $entry['allow']);
                foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $op) {
                    $policies[] = [
                        'api_role'       => $apiRole,
                        'operation'      => $op,
                        'allowed'        => in_array($op, $allowedOps, true),
                        'constraint_sql' => $constraint,
                    ];
                }
            } else {
                $policies[] = $entry;
            }
        }

        $results = [];
        foreach ($policies as $i => $policy) {
            $apiRole    = $policy['api_role']      ?? '';
            $operation  = strtoupper($policy['operation'] ?? '');
            $allowed    = (bool)($policy['allowed'] ?? false);
            $constraint = $policy['constraint_sql'] ?? null;

            if (!$apiRole || !in_array($operation, ['SELECT','INSERT','UPDATE','DELETE'], true)) {
                abort(422, "Policy[$i]: api_role and operation (SELECT|INSERT|UPDATE|DELETE) are required. Send a single object {api_role,operation,allowed} or an array of objects.");
            }

            if ($constraint !== null) {
                if (preg_match('/(--)|(;)|(\bDROP\b)|(\bINSERT\b)|(\bUPDATE\b)|(\bDELETE\b)|(\bSELECT\b)/i', $constraint)) {
                    abort(422, "Policy[$i]: constraint_sql contains disallowed keywords or characters");
                }
            }

            $results[] = $catalog->upsertPolicy($table['id'], $apiRole, $operation, $allowed, $constraint);
        }

        json_out(count($results) === 1 ? $results[0] : ['updated' => count($results), 'policies' => $results]);
    }, ['auth_middleware']);
}
