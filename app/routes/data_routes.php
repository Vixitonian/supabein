<?php

declare(strict_types=1);

use Firebase\JWT\JWT;

function register_data_routes(\SupaBein\Router $router): void
{
    // All data routes use optional auth — policy engine decides access.
    // Rate limiting is applied per project before the handler runs.

    // POST /v1/data/:project_id/:table_name/login — password verification for project tables
    $router->post('/v1/data/:project_id/:table_name/login', function (array $req): void {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];
        $catalog   = \SupaBein\Catalog::getInstance();

        $table   = $catalog->getTable($projectId, $tableName);
        if (!$table) abort(404, 'Table not found');

        $project = $catalog->getProjectByIdInternal($projectId);
        if (!$project) abort(404, 'Project not found');

        $colRows  = $catalog->listColumns((int)$table['id']);
        $colTypes = array_column($colRows, 'data_type', 'col_name');

        // Find the PASSWORD column
        $passwordCol = null;
        foreach ($colTypes as $col => $type) {
            if ($type === 'PASSWORD') { $passwordCol = $col; break; }
        }
        if ($passwordCol === null) abort(400, 'This table has no PASSWORD column');

        // Detect identifier column: first non-password allowed column with a value in the body
        $allowedCols   = array_column($colRows, 'col_name');
        $identifierCol = null;
        foreach ($allowedCols as $col) {
            if ($col !== $passwordCol && isset($req['body'][$col])) {
                $identifierCol = $col;
                break;
            }
        }
        if ($identifierCol === null) abort(422, 'No identifier column found in request body');

        $identVal = (string)($req['body'][$identifierCol] ?? '');
        $password = (string)($req['body'][$passwordCol] ?? $req['body']['password'] ?? '');

        if ($identVal === '' || $password === '') {
            abort(422, 'Identifier and password are required');
        }

        \SupaBein\RateLimit::checkProject($projectId);

        $pdo  = \App::get('db');
        $stmt = $pdo->prepare(
            'SELECT * FROM `' . $table['physical_name'] . '` WHERE `' . $identifierCol . '` = ? LIMIT 1'
        );
        $stmt->execute([$identVal]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, (string)($row[$passwordCol] ?? ''))) {
            abort(401, 'Invalid credentials');
        }

        // Null out the hash before returning
        $row[$passwordCol] = null;
        if (isset($row['id'])) $row['id'] = (int)$row['id'];

        $config  = \App::get('config');
        $now     = time();
        // End-user app sessions should last for days, not the 1-hour platform TTL —
        // otherwise owner-scoped reads start failing mid-use once the token expires.
        $projectUserTtl = (int)($config['PROJECT_USER_JWT_TTL'] ?? 2592000); // 30 days
        $payload = [
            'sub'   => (int)$row['id'],
            'pid'   => $projectId,
            'table' => $tableName,
            'type'  => 'project_user',
            'iat'   => $now,
            'exp'   => $now + $projectUserTtl,
        ];
        $token = JWT::encode($payload, $config['JWT_SECRET'], $config['JWT_ALGO']);

        json_out(['token' => $token, 'user' => $row]);
    });

    $router->get(
        '/v1/data/:project_id/:table_name',
        [\SupaBein\Crud::class, 'handleList'],
        ['optional_auth_middleware']
    );

    $router->post(
        '/v1/data/:project_id/:table_name',
        [\SupaBein\Crud::class, 'handleInsert'],
        ['optional_auth_middleware']
    );

    // POST /v1/data/:project_id/:table_name/batch — bulk insert (up to 500 rows)
    $router->post(
        '/v1/data/:project_id/:table_name/batch',
        [\SupaBein\Crud::class, 'handleBatchInsert'],
        ['optional_auth_middleware']
    );

    $router->get(
        '/v1/data/:project_id/:table_name/:id',
        [\SupaBein\Crud::class, 'handleGet'],
        ['optional_auth_middleware']
    );

    $router->patch(
        '/v1/data/:project_id/:table_name/:id',
        [\SupaBein\Crud::class, 'handleUpdate'],
        ['optional_auth_middleware']
    );

    $router->delete(
        '/v1/data/:project_id/:table_name/:id',
        [\SupaBein\Crud::class, 'handleDelete'],
        ['optional_auth_middleware']
    );
}
