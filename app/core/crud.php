<?php

declare(strict_types=1);

namespace SupaBein;

class Crud
{
    /**
     * Shared setup: resolve physical table, load allowed columns, check policy.
     * Returns [table_row, allowed_col_names, PolicyResult].
     */
    private static function resolve(int $projectId, string $logicalName, ?array $auth, string $operation): array
    {
        $catalog = Catalog::getInstance();
        $pdo     = \App::get('db');

        $table = $catalog->getTable($projectId, $logicalName);
        if (!$table) {
            abort(404, 'Table not found');
        }

        $project = $catalog->getProjectByIdInternal($projectId);
        if (!$project) {
            abort(404, 'Project not found');
        }

        // Project-scoped tokens (pid in JWT) must match this project
        if ($auth !== null && isset($auth['project_id']) && $auth['project_id'] !== null
            && $auth['project_id'] !== $projectId) {
            abort(403, 'Token is not valid for this project');
        }

        // Per-project rate limiting on the data plane
        RateLimit::checkProject($projectId);

        $colRows     = $catalog->listColumns((int)$table['id']);
        $allowedCols = array_column($colRows, 'col_name');
        $colTypes    = array_column($colRows, 'data_type', 'col_name');

        $policy = Policy::check($pdo, (int)$table['id'], $auth, (int)$project['owner_user_id'], $operation);
        if (!$policy->allowed) {
            abort(403, 'Policy denies this operation');
        }

        return [$table, $allowedCols, $policy, $colTypes];
    }

    private static function maskPasswordCols(array $rows, array $colTypes): array
    {
        $passCols = array_keys(array_filter($colTypes, fn($t) => $t === 'PASSWORD'));
        if (!$passCols) return $rows;
        return array_map(function ($r) use ($passCols) {
            foreach ($passCols as $pc) {
                if (array_key_exists($pc, $r)) $r[$pc] = null;
            }
            return $r;
        }, $rows);
    }

    private static function decodeJsonCols(array $rows, array $colTypes): array
    {
        $jsonCols = array_keys(array_filter($colTypes, fn($t) => $t === 'JSON'));
        if (!$jsonCols) return $rows;
        return array_map(function ($r) use ($jsonCols) {
            foreach ($jsonCols as $jc) {
                if (array_key_exists($jc, $r) && is_string($r[$jc])) {
                    $decoded = json_decode($r[$jc], true);
                    if (json_last_error() === JSON_ERROR_NONE) $r[$jc] = $decoded;
                }
            }
            return $r;
        }, $rows);
    }

    /**
     * PDO returns every column as a string on most hosts, so numeric/boolean
     * columns (e.g. user_id, prices, flags) serialize as "1" instead of 1. That
     * breaks strict === / !== comparisons in generated frontends. Cast each
     * column to its real JSON type based on its declared data_type.
     */
    private static function castScalarCols(array $rows, array $colTypes): array
    {
        $intCols = $boolCols = $floatCols = [];
        foreach ($colTypes as $col => $type) {
            $t = strtoupper((string)$type);
            if ($t === 'BOOLEAN' || $t === 'TINYINT(1)')                     $boolCols[]  = $col;
            elseif (preg_match('/^(INT|BIGINT|SMALLINT|TINYINT|MEDIUMINT)\b/', $t)) $intCols[]   = $col;
            elseif (preg_match('/^(DECIMAL|NUMERIC|FLOAT|DOUBLE)\b/', $t))    $floatCols[] = $col;
        }
        if (!$intCols && !$boolCols && !$floatCols) return $rows;
        return array_map(function ($r) use ($intCols, $boolCols, $floatCols) {
            foreach ($intCols as $c)   { if (isset($r[$c]) && $r[$c] !== null) $r[$c] = (int)$r[$c]; }
            foreach ($boolCols as $c)  { if (isset($r[$c]) && $r[$c] !== null) $r[$c] = (bool)(int)$r[$c]; }
            foreach ($floatCols as $c) { if (isset($r[$c]) && $r[$c] !== null) $r[$c] = (float)$r[$c]; }
            return $r;
        }, $rows);
    }

    // A real UNIQUE column (see Schema::buildColumnDef()) rejects a
    // conflicting INSERT/UPDATE with MySQL error 1062 -- without this, that
    // PDOException would propagate all the way to api/index.php's top-level
    // catch-all, which returns a raw 500 with the internal exception message
    // (physical table name, driver-specific SQL error text). Translate it
    // into the clean, actionable 409 a client can actually branch on.
    private static function execOrConflict(\PDOStatement $stmt, array $params): void
    {
        try {
            $stmt->execute($params);
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                $value = null;
                if (preg_match("/Duplicate entry '(.*)' for key/", $e->getMessage(), $m)) {
                    $value = $m[1];
                }
                abort(409, $value !== null
                    ? "A row with that value already exists (\"$value\") — this column has a unique constraint."
                    : 'A row with that value already exists — this column has a unique constraint.');
            }
            throw $e;
        }
    }

    // ─── SELECT (list) ───────────────────────────────────────────────────────

    public static function handleList(array $req): void
    {
        $projectId  = (int)$req['params']['project_id'];
        $tableName  = $req['params']['table_name'];

        [$table, $allowedCols, $policy, $colTypes] = self::resolve($projectId, $tableName, $req['auth'], 'SELECT');

        $limit  = min((int)($req['query']['limit'] ?? 20), 1000);
        $offset = max((int)($req['query']['offset'] ?? 0), 0);

        // Extract filter params (exclude pagination and ordering keys)
        $order   = $req['query']['order'] ?? null;
        $filters = array_diff_key($req['query'], array_flip(['limit', 'offset', 'order']));

        [$sql, $params] = QueryBuilder::select(
            $table['physical_name'],
            $allowedCols,
            $filters,
            $policy->constraint,
            $limit,
            $offset,
            is_string($order) ? $order : null
        );

        $pdo  = \App::get('db');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $rows = array_map(fn($r) => isset($r['id']) ? array_merge($r, ['id' => (int)$r['id']]) : $r, $rows);
        $rows = self::maskPasswordCols($rows, $colTypes);
        $rows = self::decodeJsonCols($rows, $colTypes);
        $rows = self::castScalarCols($rows, $colTypes);

        json_out(['data' => $rows, 'count' => count($rows), 'limit' => $limit, 'offset' => $offset]);
    }

    // ─── SELECT (single) ─────────────────────────────────────────────────────

    public static function handleGet(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];
        $rowId     = (int)$req['params']['id'];

        [$table, $allowedCols, $policy, $colTypes] = self::resolve($projectId, $tableName, $req['auth'], 'SELECT');

        [$sql, $params] = QueryBuilder::selectOne(
            $table['physical_name'],
            $allowedCols,
            $rowId,
            $policy->constraint
        );

        $pdo  = \App::get('db');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row  = $stmt->fetch();

        if (!$row) {
            abort(404, 'Row not found');
        }

        if (isset($row['id'])) $row['id'] = (int)$row['id'];
        [$row] = self::maskPasswordCols([$row], $colTypes);
        [$row] = self::decodeJsonCols([$row], $colTypes);
        [$row] = self::castScalarCols([$row], $colTypes);
        json_out($row);
    }

    // ─── INSERT ──────────────────────────────────────────────────────────────

    /**
     * Parse simple "col = integer" pairs out of a resolved constraint string
     * so they can be force-injected into INSERT, preventing client spoofing.
     */
    private static function constraintInsertValues(?string $constraint): array
    {
        if ($constraint === null || $constraint === '') {
            return [];
        }
        $values = [];
        foreach (preg_split('/\s+AND\s+/i', $constraint) as $part) {
            $part = trim($part, ' ()');
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(\d+)$/', $part, $m)) {
                $values[$m[1]] = (int)$m[2];
            } elseif (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*\'([^\']*)\'\s*$/', $part, $m)) {
                $values[$m[1]] = $m[2];
            }
        }
        return $values;
    }

    public static function handleInsert(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];

        [$table, $allowedCols, $policy, $colTypes] = self::resolve($projectId, $tableName, $req['auth'], 'INSERT');

        // Constraint values override whatever the client sent — prevents user_id spoofing
        $body = array_merge((array)$req['body'], self::constraintInsertValues($policy->constraint));

        // Auto-hash any PASSWORD columns
        foreach ($allowedCols as $col) {
            if (($colTypes[$col] ?? '') === 'PASSWORD' && isset($body[$col]) && $body[$col] !== '') {
                $body[$col] = password_hash((string)$body[$col], PASSWORD_BCRYPT);
            }
        }

        try {
            [$sql, $params] = QueryBuilder::insert(
                $table['physical_name'],
                $allowedCols,
                $body
            );
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $pdo = \App::get('db');
        self::execOrConflict($pdo->prepare($sql), $params);
        $newId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT * FROM `' . $table['physical_name'] . '` WHERE id = ?');
        $stmt->execute([$newId]);
        $row = $stmt->fetch();
        if ($row && isset($row['id'])) $row['id'] = (int)$row['id'];
        if ($row) [$row] = self::maskPasswordCols([$row], $colTypes);
        if ($row) [$row] = self::decodeJsonCols([$row], $colTypes);
        if ($row) self::fireInsertTriggers($projectId, $tableName, $row);
        if ($row) [$row] = self::castScalarCols([$row], $colTypes);
        json_out($row, 201);
    }

    // Best-effort, never allowed to affect the insert's own response --
    // Catalog::fireTriggers() already catches per-trigger failures
    // internally, this wraps the lookup query itself too. Runs against the
    // already-password-masked, JSON-decoded row so a trigger's own template
    // never sees a bcrypt hash and can address JSON columns by dot-path.
    private static function fireInsertTriggers(int $projectId, string $tableName, array $row): void
    {
        try {
            \SupaBein\Catalog::getInstance()->fireTriggers($projectId, $tableName, 'insert', $row);
        } catch (\Throwable $e) {
            sb_log('trigger', 'fireTriggers lookup failed', ['project_id' => $projectId, 'table' => $tableName, 'error' => $e->getMessage()]);
        }
    }

    // ─── BATCH INSERT ────────────────────────────────────────────────────────

    public static function handleBatchInsert(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];

        $rows = $req['body'];
        if (!is_array($rows) || empty($rows) || !array_is_list($rows)) {
            abort(422, 'Request body must be a non-empty JSON array of objects');
        }
        if (count($rows) > 500) {
            abort(422, 'Batch limited to 500 rows per request');
        }

        [$table, $allowedCols, $policy, $colTypes] = self::resolve($projectId, $tableName, $req['auth'], 'INSERT');

        $pdo      = \App::get('db');
        $inserted = [];

        foreach ($rows as $body) {
            $body = array_merge((array)$body, self::constraintInsertValues($policy->constraint));

            foreach ($allowedCols as $col) {
                if (($colTypes[$col] ?? '') === 'PASSWORD' && isset($body[$col]) && $body[$col] !== '') {
                    $body[$col] = password_hash((string)$body[$col], PASSWORD_BCRYPT);
                }
            }

            try {
                [$sql, $params] = QueryBuilder::insert($table['physical_name'], $allowedCols, $body);
            } catch (\InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            }

            self::execOrConflict($pdo->prepare($sql), $params);
            $newId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare('SELECT * FROM `' . $table['physical_name'] . '` WHERE id = ?');
            $stmt->execute([$newId]);
            $row = $stmt->fetch();
            if ($row && isset($row['id'])) $row['id'] = (int)$row['id'];
            if ($row) [$row] = self::maskPasswordCols([$row], $colTypes);
            if ($row) [$row] = self::decodeJsonCols([$row], $colTypes);
            if ($row) self::fireInsertTriggers($projectId, $tableName, $row);
            $inserted[] = $row;
        }

        json_out(['inserted' => count($inserted), 'rows' => $inserted], 201);
    }

    // ─── UPDATE ──────────────────────────────────────────────────────────────

    public static function handleUpdate(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];
        $rowId     = (int)$req['params']['id'];

        [$table, $allowedCols, $policy, $colTypes] = self::resolve($projectId, $tableName, $req['auth'], 'UPDATE');

        $body = (array)$req['body'];
        // Auto-hash any PASSWORD columns being updated
        foreach ($allowedCols as $col) {
            if (($colTypes[$col] ?? '') === 'PASSWORD' && isset($body[$col]) && $body[$col] !== '') {
                $body[$col] = password_hash((string)$body[$col], PASSWORD_BCRYPT);
            }
        }

        try {
            [$sql, $params] = QueryBuilder::update(
                $table['physical_name'],
                $allowedCols,
                $rowId,
                $body,
                $policy->constraint
            );
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $pdo  = \App::get('db');
        $stmt = $pdo->prepare($sql);
        self::execOrConflict($stmt, $params);

        if ($stmt->rowCount() === 0) {
            abort(404, 'Row not found or policy constraint not satisfied');
        }

        $stmt2 = $pdo->prepare('SELECT * FROM `' . $table['physical_name'] . '` WHERE id = ?');
        $stmt2->execute([$rowId]);
        $row = $stmt2->fetch();
        if ($row && isset($row['id'])) $row['id'] = (int)$row['id'];
        if ($row) [$row] = self::maskPasswordCols([$row], $colTypes);
        if ($row) [$row] = self::decodeJsonCols([$row], $colTypes);
        if ($row) [$row] = self::castScalarCols([$row], $colTypes);
        json_out($row);
    }

    // ─── DELETE ──────────────────────────────────────────────────────────────

    public static function handleDelete(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];
        $rowId     = (int)$req['params']['id'];

        [$table, , $policy] = self::resolve($projectId, $tableName, $req['auth'], 'DELETE'); // $colTypes not needed for DELETE

        [$sql, $params] = QueryBuilder::delete(
            $table['physical_name'],
            $rowId,
            $policy->constraint
        );

        $pdo  = \App::get('db');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            abort(404, 'Row not found or policy constraint not satisfied');
        }

        json_out(['deleted' => true]);
    }
}
