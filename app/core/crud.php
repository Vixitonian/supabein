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

        $colRows     = $catalog->listColumns($table['id']);
        $allowedCols = array_column($colRows, 'col_name');

        $policy = Policy::check($pdo, $table['id'], $auth, (int)$project['owner_user_id'], $operation);
        if (!$policy->allowed) {
            abort(403, 'Policy denies this operation');
        }

        return [$table, $allowedCols, $policy];
    }

    // ─── SELECT (list) ───────────────────────────────────────────────────────

    public static function handleList(array $req): void
    {
        $projectId  = (int)$req['params']['project_id'];
        $tableName  = $req['params']['table_name'];

        [$table, $allowedCols, $policy] = self::resolve($projectId, $tableName, $req['auth'], 'SELECT');

        $limit  = min((int)($req['query']['limit'] ?? 20), 1000);
        $offset = max((int)($req['query']['offset'] ?? 0), 0);

        // Extract filter params (exclude pagination keys)
        $filters = array_diff_key($req['query'], array_flip(['limit', 'offset']));

        [$sql, $params] = QueryBuilder::select(
            $table['physical_name'],
            $allowedCols,
            $filters,
            $policy->constraint,
            $limit,
            $offset
        );

        $pdo  = \App::get('db');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        json_out(['data' => $rows, 'count' => count($rows), 'limit' => $limit, 'offset' => $offset]);
    }

    // ─── SELECT (single) ─────────────────────────────────────────────────────

    public static function handleGet(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];
        $rowId     = (int)$req['params']['id'];

        [$table, $allowedCols, $policy] = self::resolve($projectId, $tableName, $req['auth'], 'SELECT');

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

        json_out($row);
    }

    // ─── INSERT ──────────────────────────────────────────────────────────────

    public static function handleInsert(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];

        [$table, $allowedCols, ] = self::resolve($projectId, $tableName, $req['auth'], 'INSERT');

        try {
            [$sql, $params] = QueryBuilder::insert(
                $table['physical_name'],
                $allowedCols,
                $req['body']
            );
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $pdo = \App::get('db');
        $pdo->prepare($sql)->execute($params);
        $newId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT * FROM `' . $table['physical_name'] . '` WHERE id = ?');
        $stmt->execute([$newId]);
        json_out($stmt->fetch(), 201);
    }

    // ─── UPDATE ──────────────────────────────────────────────────────────────

    public static function handleUpdate(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];
        $rowId     = (int)$req['params']['id'];

        [$table, $allowedCols, $policy] = self::resolve($projectId, $tableName, $req['auth'], 'UPDATE');

        try {
            [$sql, $params] = QueryBuilder::update(
                $table['physical_name'],
                $allowedCols,
                $rowId,
                $req['body'],
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
        json_out($stmt2->fetch());
    }

    // ─── DELETE ──────────────────────────────────────────────────────────────

    public static function handleDelete(array $req): void
    {
        $projectId = (int)$req['params']['project_id'];
        $tableName = $req['params']['table_name'];
        $rowId     = (int)$req['params']['id'];

        [$table, , $policy] = self::resolve($projectId, $tableName, $req['auth'], 'DELETE');

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
