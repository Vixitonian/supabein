<?php

declare(strict_types=1);

namespace SupaBein;

/**
 * Parameterized query builder for generic CRUD operations.
 *
 * - Table and column names come exclusively from the catalog (already validated).
 * - Values always travel via PDO bound parameters.
 * - Identifiers are backtick-quoted even though they are catalog-sourced.
 */
class QueryBuilder
{
    private static function q(string $ident): string
    {
        return '`' . $ident . '`';
    }

    /** Operator map for filter values prefixed with "op." */
    private const FILTER_OPS = [
        'eq'   => '=',
        'neq'  => '!=',
        'gt'   => '>',
        'gte'  => '>=',
        'lt'   => '<',
        'lte'  => '<=',
        'like' => 'LIKE',
    ];

    /**
     * Build a SELECT query.
     *
     * Filters: pass [col => value] for exact match, or [col => "op.value"] for
     * operators (eq, neq, gt, gte, lt, lte, like). E.g. ['age' => 'gte.18'].
     *
     * Order: "col.asc" or "col.desc", comma-separated for multiple.
     *
     * @param string   $physTable      Physical table name (catalog-sourced)
     * @param string[] $allowedCols    Column names (catalog-sourced)
     * @param array    $filters        [col => value|"op.value"] from query params
     * @param ?string  $constraint     Policy constraint fragment (already interpolated)
     * @param int      $limit
     * @param int      $offset
     * @param ?string  $order          e.g. "created_at.desc" or "name.asc,age.desc"
     * @return array{0: string, 1: array}  [sql, params]
     */
    public static function select(
        string $physTable,
        array $allowedCols,
        array $filters,
        ?string $constraint,
        int $limit = 20,
        int $offset = 0,
        ?string $order = null
    ): array {
        $allCols = array_merge(['id', 'created_at'], $allowedCols);
        $colList = implode(', ', array_map(self::q(...), $allCols));

        $where  = [];
        $params = [];

        foreach ($filters as $col => $val) {
            if (!in_array($col, $allowedCols, true)) {
                continue; // silently ignore unknown columns
            }

            $op = '=';
            if (is_string($val) && preg_match('/^(eq|neq|gt|gte|lt|lte|like)\.(.*)$/s', $val, $m)) {
                $op  = self::FILTER_OPS[$m[1]];
                $val = $m[2];
            }

            $where[]  = self::q($col) . ' ' . $op . ' ?';
            $params[] = $val;
        }

        if ($constraint !== null && $constraint !== '') {
            $where[] = '(' . $constraint . ')';
        }

        // Build ORDER BY
        $orderClauses = [];
        if ($order !== null && $order !== '') {
            foreach (explode(',', $order) as $part) {
                if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\.(asc|desc)$/i', trim($part), $m)) {
                    $col = $m[1];
                    $dir = strtoupper($m[2]);
                    if (in_array($col, $allCols, true)) {
                        $orderClauses[] = self::q($col) . ' ' . $dir;
                    }
                }
            }
        }
        $orderSql = $orderClauses ? implode(', ', $orderClauses) : 'id DESC';

        $sql = "SELECT $colList FROM " . self::q($physTable);
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY ' . $orderSql;

        $limit  = max(1, min($limit, 1000));
        $offset = max(0, $offset);
        $sql .= " LIMIT $limit OFFSET $offset";

        return [$sql, $params];
    }

    /**
     * Build a SELECT single row by id query.
     *
     * @return array{0: string, 1: array}
     */
    public static function selectOne(
        string $physTable,
        array $allowedCols,
        int $id,
        ?string $constraint
    ): array {
        $allCols = array_merge(['id', 'created_at'], $allowedCols);
        $colList = implode(', ', array_map(self::q(...), $allCols));

        $where  = ['`id` = ?'];
        $params = [$id];

        if ($constraint !== null && $constraint !== '') {
            $where[] = '(' . $constraint . ')';
        }

        $sql = "SELECT $colList FROM " . self::q($physTable)
             . ' WHERE ' . implode(' AND ', $where)
             . ' LIMIT 1';

        return [$sql, $params];
    }

    /**
     * Build an INSERT query.
     *
     * @param string[] $allowedCols  Catalog-sourced column whitelist
     * @param array    $data         Key-value pairs from request body (keys stripped to allowedCols)
     * @return array{0: string, 1: array}
     */
    public static function insert(string $physTable, array $allowedCols, array $data): array
    {
        $cols   = [];
        $params = [];

        foreach ($data as $col => $val) {
            if (!in_array($col, $allowedCols, true)) {
                continue;
            }
            $cols[]   = self::q($col);
            $params[] = $val;
        }

        if (empty($cols)) {
            throw new \InvalidArgumentException('No valid columns to insert');
        }

        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO ' . self::q($physTable)
             . ' (' . implode(', ', $cols) . ')'
             . " VALUES ($placeholders)";

        return [$sql, $params];
    }

    /**
     * Build an UPDATE query.
     *
     * @return array{0: string, 1: array}
     */
    public static function update(
        string $physTable,
        array $allowedCols,
        int $id,
        array $data,
        ?string $constraint
    ): array {
        $sets   = [];
        $params = [];

        foreach ($data as $col => $val) {
            if (!in_array($col, $allowedCols, true)) {
                continue;
            }
            $sets[]   = self::q($col) . ' = ?';
            $params[] = $val;
        }

        if (empty($sets)) {
            throw new \InvalidArgumentException('No valid columns to update');
        }

        $where    = ['`id` = ?'];
        $params[] = $id;

        if ($constraint !== null && $constraint !== '') {
            $where[] = '(' . $constraint . ')';
        }

        $sql = 'UPDATE ' . self::q($physTable)
             . ' SET ' . implode(', ', $sets)
             . ' WHERE ' . implode(' AND ', $where);

        return [$sql, $params];
    }

    /**
     * Build a DELETE query.
     *
     * @return array{0: string, 1: array}
     */
    public static function delete(string $physTable, int $id, ?string $constraint): array
    {
        $where    = ['`id` = ?'];
        $params   = [$id];

        if ($constraint !== null && $constraint !== '') {
            $where[] = '(' . $constraint . ')';
        }

        $sql = 'DELETE FROM ' . self::q($physTable)
             . ' WHERE ' . implode(' AND ', $where);

        return [$sql, $params];
    }
}
