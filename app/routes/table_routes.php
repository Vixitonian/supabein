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
        return $project;
    };

    // ── Helper: resolve table within project ─────────────────────────────────
    $ownTable = function (int $projectId, string $tableName) use ($catalog): array {
        $table = $catalog->getTable($projectId, $tableName);
        if (!$table) {
            abort(404, 'Table not found');
        }
        return $table;
    };

    // GET /v1/projects/:id/tables
    $router->get('/v1/projects/:id/tables', function (array $req) use ($catalog, $ownProject): void {
        $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $tables = $catalog->listTables((int)$req['params']['id']);
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
        $rawCols = $req['body']['columns'] ?? [];
        $columns = [];
        foreach ($rawCols as $col) {
            try {
                $columns[] = [
                    'name'     => \SupaBein\Schema::validateIdentifier($col['name'] ?? ''),
                    'type'     => \SupaBein\Schema::validateDataType($col['type'] ?? ''),
                    'nullable' => (bool)($col['nullable'] ?? true),
                    'default'  => $col['default'] ?? null,
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
            \SupaBein\Schema::applyDDL($pdo, $project['id'], $ddl);
            sb_log('table_create', 'OK', ['physical' => $table['physical_name']]);
        } catch (\Throwable $e) {
            sb_log('table_create', 'FAIL DDL: ' . $e->getMessage(), ['physical' => $table['physical_name']]);
            // Roll back the catalog entry since DDL failed
            $catalog->deleteTable($project['id'], $tableName);
            abort(500, 'Failed to create table in database: ' . $e->getMessage());
        }

        // Record user-defined columns in catalog
        foreach ($columns as $col) {
            $catalog->addColumn($table['id'], $col['name'], $col['type'], $col['nullable'], $col['default']);
        }

        json_out(['table' => $table, 'ddl' => $ddl], 201);
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/tables/:name
    $router->delete('/v1/projects/:id/tables/:name', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable($project['id'], $req['params']['name']);

        $pdo = App::get('db');
        $ddl = \SupaBein\Schema::dropTableDDL($table['physical_name']);
        \SupaBein\Schema::applyDDL($pdo, $project['id'], $ddl);

        $catalog->deleteTable($project['id'], $table['table_name']);
        json_out(['deleted' => true]);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/tables/:name/columns
    $router->get('/v1/projects/:id/tables/:name/columns', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable($project['id'], $req['params']['name']);
        json_out($catalog->listColumns($table['id']));
    }, ['auth_middleware']);

    // POST /v1/projects/:id/tables/:name/columns
    $router->post('/v1/projects/:id/tables/:name/columns', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable($project['id'], $req['params']['name']);

        $colName  = $req['body']['name'] ?? '';
        $dataType = $req['body']['type'] ?? '';
        $nullable = (bool)($req['body']['nullable'] ?? true);
        $default  = $req['body']['default'] ?? null;

        try {
            $colName  = \SupaBein\Schema::validateIdentifier($colName);
            $dataType = \SupaBein\Schema::validateDataType($dataType);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $pdo = App::get('db');
        $ddl = \SupaBein\Schema::addColumnDDL($table['physical_name'], [
            'name' => $colName, 'type' => $dataType, 'nullable' => $nullable, 'default' => $default,
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

        json_out($col, 201);
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/tables/:name/columns/:col
    $router->delete('/v1/projects/:id/tables/:name/columns/:col', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable($project['id'], $req['params']['name']);
        $colName = $req['params']['col'];

        try {
            \SupaBein\Schema::validateIdentifier($colName);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        if (!$catalog->getColumn($table['id'], $colName)) {
            abort(404, 'Column not found');
        }

        $pdo = App::get('db');
        $ddl = \SupaBein\Schema::dropColumnDDL($table['physical_name'], $colName);
        \SupaBein\Schema::applyDDL($pdo, $project['id'], $ddl);
        $catalog->deleteColumn($table['id'], $colName);

        json_out(['deleted' => true]);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/tables/:name/policies
    $router->get('/v1/projects/:id/tables/:name/policies', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable($project['id'], $req['params']['name']);
        json_out($catalog->listPolicies($table['id']));
    }, ['auth_middleware']);

    // PUT /v1/projects/:id/tables/:name/policies
    $router->put('/v1/projects/:id/tables/:name/policies', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project  = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table    = $ownTable($project['id'], $req['params']['name']);

        $apiRole  = $req['body']['api_role'] ?? '';
        $operation = strtoupper($req['body']['operation'] ?? '');
        $allowed  = (bool)($req['body']['allowed'] ?? false);
        $constraint = $req['body']['constraint_sql'] ?? null;

        if (!$apiRole || !in_array($operation, ['SELECT','INSERT','UPDATE','DELETE'], true)) {
            abort(422, 'api_role and operation (SELECT|INSERT|UPDATE|DELETE) are required');
        }

        // Validate constraint SQL if provided
        if ($constraint !== null) {
            if (preg_match('/(--)|(;)|(\bDROP\b)|(\bINSERT\b)|(\bUPDATE\b)|(\bDELETE\b)|(\bSELECT\b)/i', $constraint)) {
                abort(422, 'Constraint SQL contains disallowed keywords or characters');
            }
        }

        $policy = $catalog->upsertPolicy($table['id'], $apiRole, $operation, $allowed, $constraint);
        json_out($policy);
    }, ['auth_middleware']);
}
