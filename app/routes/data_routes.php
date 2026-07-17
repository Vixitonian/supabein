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

    // POST /v1/data/:project_id/:table_name/forgot — generate a reset token
    // for a project's own end-user table (the project-scoped counterpart to
    // the platform's own /v1/auth/forgot). Same identifier auto-detection as
    // /login: whichever non-password column the caller sends becomes both
    // the lookup key and, if an auth email provider is registered, the send-
    // to address. Always the same generic response regardless of whether the
    // identifier matched a row -- same enumeration protection as the
    // platform's own /auth/forgot, deliberately not changed by whether an
    // email provider is registered or whether dispatch succeeds.
    $router->post('/v1/data/:project_id/:table_name/forgot', function (array $req): void {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];
        $catalog   = \SupaBein\Catalog::getInstance();

        $table = $catalog->getTable($projectId, $tableName);
        if (!$table) abort(404, 'Table not found');

        $colRows  = $catalog->listColumns((int)$table['id']);
        $colTypes = array_column($colRows, 'data_type', 'col_name');

        $passwordCol = null;
        foreach ($colTypes as $col => $type) {
            if ($type === 'PASSWORD') { $passwordCol = $col; break; }
        }
        if ($passwordCol === null) abort(400, 'This table has no PASSWORD column');

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
        if ($identVal === '') abort(422, 'Identifier value is required');

        \SupaBein\RateLimit::checkProject($projectId);

        $generic = ['message' => 'If that account exists, a password reset link has been sent to it.'];

        $stmt = \App::get('db')->prepare('SELECT id FROM `' . $table['physical_name'] . '` WHERE `' . $identifierCol . '` = ? LIMIT 1');
        $stmt->execute([$identVal]);
        $row = $stmt->fetch();
        if (!$row) {
            json_out($generic);
            return;
        }

        $token = $catalog->createProjectPasswordResetToken($projectId, $tableName, (int)$row['id']);
        try {
            $catalog->dispatchForgotPasswordEmail($projectId, $identVal, $token);
        } catch (\Throwable $e) {
            sb_log('auth-email', 'forgot-password dispatch failed', ['project_id' => $projectId, 'error' => $e->getMessage()]);
        }

        json_out($generic);
    });

    // POST /v1/data/:project_id/:table_name/reset — exchange a raw token
    // (from the emailed link) for a new password, and log the user straight
    // in with a fresh project_user token, mirroring /login's response shape.
    $router->post('/v1/data/:project_id/:table_name/reset', function (array $req): void {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];
        $catalog   = \SupaBein\Catalog::getInstance();

        $token    = (string)($req['body']['token'] ?? '');
        $password = (string)($req['body']['password'] ?? '');
        if ($token === '' || $password === '') abort(422, 'token and password are required');
        if (strlen($password) < 8) abort(422, 'Password must be at least 8 characters');

        $table = $catalog->getTable($projectId, $tableName);
        if (!$table) abort(404, 'Table not found');

        $colRows  = $catalog->listColumns((int)$table['id']);
        $colTypes = array_column($colRows, 'data_type', 'col_name');
        $passwordCol = null;
        foreach ($colTypes as $col => $type) {
            if ($type === 'PASSWORD') { $passwordCol = $col; break; }
        }
        if ($passwordCol === null) abort(400, 'This table has no PASSWORD column');

        $rowId = $catalog->consumeProjectPasswordResetToken($projectId, $tableName, $token);
        if ($rowId === null) abort(401, 'Invalid or expired reset token');

        $pdo = \App::get('db');
        $pdo->prepare('UPDATE `' . $table['physical_name'] . '` SET `' . $passwordCol . '` = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $rowId]);

        $config = \App::get('config');
        $now    = time();
        $projectUserTtl = (int)($config['PROJECT_USER_JWT_TTL'] ?? 2592000);
        $jwt = JWT::encode([
            'sub' => $rowId, 'pid' => $projectId, 'table' => $tableName, 'type' => 'project_user',
            'iat' => $now, 'exp' => $now + $projectUserTtl,
        ], $config['JWT_SECRET'], $config['JWT_ALGO']);

        json_out(['message' => 'Password updated successfully.', 'token' => $jwt]);
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

    // POST /v1/errors/:project_id — public, unauthenticated ingestion endpoint
    // for the platform-injected core/errors.js script running in a deployed
    // app's visitors' browsers. No auth is possible here by construction (a
    // logged-out visitor's own JS errors are exactly what this needs to
    // capture), so abuse protection is rate limiting + dedup + a hard row cap
    // instead — see RateLimit::checkProjectErrors() and ai_report_error_log().
    $router->post('/v1/errors/:project_id', function (array $req): void {
        $projectId = (int)$req['params']['project_id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        if (!$catalog->getProjectByIdInternal($projectId)) abort(404, 'Project not found');

        \SupaBein\RateLimit::checkProjectErrors($projectId);

        $pdo = \App::get('db');
        ai_report_error_log($pdo, $projectId, is_array($req['body']) ? $req['body'] : []);
        json_out(['ok' => true]);
    });
}
