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

    /**
     * Build a SELECT query.
     *
     * @param string   $physTable      Physical table name (catalog-sourced)
     * @param string[] $allowedCols    Column names (catalog-sourced)
     * @param array    $filters        [col => value] from ?query params (keys checked against allowedCols)
     * @param ?string  $constraint     Policy constraint fragment (already interpolated)
     * @param int      $limit
     * @param int      $offset
     * @return array{0: string, 1: array}  [sql, params]
     */
    public static function select(
        string $physTable,
        array $allowedCols,
        array $filters,
        ?string $constraint,
        int $limit = 20,
        int $offset = 0
    ): array {
        $allCols = array_merge(['id', 'created_at'], $allowedCols);
        $colList = implode(', ', array_map(self::q(...), $allCols));

        $where  = [];
        $params = [];

        foreach ($filters as $col => $val) {
            if (!in_array($col, $allowedCols, true)) {
                continue; // silently ignore unknown columns
            }
            $where[]  = self::q($col) . ' = ?';
            $params[] = $val;
        }

        if ($constraint !== null && $constraint !== '') {
            $where[] = '(' . $constraint . ')';
        }

        $sql = "SELECT $colList FROM " . self::q($physTable);
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';

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
