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
        $pdo->prepare($sql)->execute($params);
        $newId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT * FROM `' . $table['physical_name'] . '` WHERE id = ?');
        $stmt->execute([$newId]);
        $row = $stmt->fetch();
        if ($row && isset($row['id'])) $row['id'] = (int)$row['id'];
        if ($row) [$row] = self::maskPasswordCols([$row], $colTypes);
        json_out($row, 201);
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
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            abort(404, 'Row not found or policy constraint not satisfied');
        }

        $stmt2 = $pdo->prepare('SELECT * FROM `' . $table['physical_name'] . '` WHERE id = ?');
        $stmt2->execute([$rowId]);
        $row = $stmt2->fetch();
        if ($row && isset($row['id'])) $row['id'] = (int)$row['id'];
        if ($row) [$row] = self::maskPasswordCols([$row], $colTypes);
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
