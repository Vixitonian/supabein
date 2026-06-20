<?php

declare(strict_types=1);

namespace SupaBein;

/**
 * Safe DDL generator — Security Crux #1.
 *
 * All identifiers are validated and backtick-quoted before being interpolated
 * into DDL strings. Prepared statements cannot protect identifiers, so the
 * entire safety model lives in this class.
 */
class Schema
{
    private const IDENT_REGEX = '/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/';

    private const ALLOWED_TYPES = [
        'INT', 'BIGINT', 'SMALLINT', 'TINYINT',
        'VARCHAR(255)', 'VARCHAR(128)', 'VARCHAR(64)', 'VARCHAR(36)', 'VARCHAR(32)',
        'TEXT', 'MEDIUMTEXT', 'LONGTEXT',
        'BOOLEAN', 'TINYINT(1)',
        'DECIMAL(10,2)', 'DECIMAL(15,4)',
        'FLOAT', 'DOUBLE',
        'DATETIME', 'DATE', 'TIMESTAMP',
        'JSON',
        'PASSWORD',
    ];

    // Common SQL reserved words to reject as identifiers
    private const SQL_RESERVED = [
        'ADD','ALL','ALTER','ANALYZE','AND','AS','ASC','AUTO_INCREMENT',
        'BETWEEN','BY','CALL','CASCADE','CASE','CHANGE','CHARACTER','CHECK',
        'COLUMN','CONSTRAINT','CREATE','CROSS','CURRENT_DATE','CURRENT_TIME',
        'CURRENT_TIMESTAMP','DATABASE','DATABASES','DAY_HOUR','DAY_MICROSECOND',
        'DAY_MINUTE','DAY_SECOND','DEC','DECIMAL','DECLARE','DEFAULT','DELAYED',
        'DELETE','DESC','DESCRIBE','DISTINCT','DIV','DOUBLE','DROP','DUAL',
        'ELSE','ELSEIF','ENCLOSED','EXISTS','EXPLAIN','FALSE','FETCH','FLOAT',
        'FOR','FORCE','FOREIGN','FROM','FULLTEXT','GRANT','GROUP','HAVING',
        'HIGH_PRIORITY','HOUR_MICROSECOND','HOUR_MINUTE','HOUR_SECOND','IF',
        'IGNORE','IN','INDEX','INFILE','INNER','INSERT','INT','INTEGER',
        'INTERVAL','INTO','IS','JOIN','KEY','KEYS','KILL','LEADING','LEAVE',
        'LEFT','LIKE','LIMIT','LINES','LOAD','LOCALTIME','LOCALTIMESTAMP',
        'LOCK','LONG','MATCH','MAXVALUE','MEDIUMINT','MINUTE_MICROSECOND',
        'MINUTE_SECOND','MOD','NATURAL','NOT','NULL','ON','OPTIMIZE','OPTION',
        'OPTIONALLY','OR','ORDER','OUTER','OUTFILE','PRECISION','PRIMARY',
        'PROCEDURE','RANGE','READ','REFERENCES','REGEXP','RELEASE','RENAME',
        'REPEAT','REPLACE','REQUIRE','RESTRICT','RETURN','REVOKE','RIGHT',
        'RLIKE','SCHEMA','SCHEMAS','SECOND_MICROSECOND','SELECT','SENSITIVE',
        'SEPARATOR','SET','SHOW','SMALLINT','SPATIAL','SPECIFIC','SQL','SQLEXCEPTION',
        'SQLSTATE','SQLWARNING','SQL_BIG_RESULT','SQL_CALC_FOUND_ROWS',
        'SQL_SMALL_RESULT','SSL','STARTING','TABLE','TERMINATED','THEN','TINYINT',
        'TO','TRAILING','TRIGGER','TRUE','UNDO','UNION','UNIQUE','UNLOCK',
        'UNSIGNED','UPDATE','USAGE','USE','USING','VALUES','VARBINARY','VARCHAR',
        'VARYING','WHEN','WHERE','WHILE','WITH','WRITE','XOR','ZEROFILL',
    ];

    // ─── Validation ──────────────────────────────────────────────────────────

    public static function validateIdentifier(string $name): string
    {
        if (!preg_match(self::IDENT_REGEX, $name)) {
            throw new \InvalidArgumentException(
                "Invalid identifier '$name'. Use letters, digits, underscores; start with letter or underscore."
            );
        }
        if (in_array(strtoupper($name), self::SQL_RESERVED, true)) {
            throw new \InvalidArgumentException("'$name' is a reserved SQL word and cannot be used as an identifier.");
        }
        return $name;
    }

    public static function normalizeDataType(string $type): string
    {
        // Map any VARCHAR(N) to the smallest allowed size that fits
        if (preg_match('/^VARCHAR\((\d+)\)$/i', $type, $m)) {
            $n = (int)$m[1];
            if ($n <= 32)  return 'VARCHAR(32)';
            if ($n <= 36)  return 'VARCHAR(36)';
            if ($n <= 64)  return 'VARCHAR(64)';
            if ($n <= 128) return 'VARCHAR(128)';
            if ($n <= 255) return 'VARCHAR(255)';
            return 'TEXT';
        }
        // Map any DECIMAL(p,s) to the closest allowed precision
        if (preg_match('/^DECIMAL\((\d+),(\d+)\)$/i', $type, $m)) {
            $p = (int)$m[1]; $s = (int)$m[2];
            if ($p <= 10 && $s <= 2) return 'DECIMAL(10,2)';
            return 'DECIMAL(15,4)';
        }
        return $type;
    }

    public static function validateDataType(string $type): string
    {
        $type = self::normalizeDataType($type);
        if (!in_array(strtoupper($type), array_map('strtoupper', self::ALLOWED_TYPES), true)) {
            throw new \InvalidArgumentException(
                "Unsupported data type '$type'. Allowed: " . implode(', ', self::ALLOWED_TYPES)
            );
        }
        // Return the canonical casing from the allowed list
        foreach (self::ALLOWED_TYPES as $allowed) {
            if (strtoupper($allowed) === strtoupper($type)) {
                return $allowed;
            }
        }
        return $type;
    }

    public static function getAllowedTypes(): array
    {
        return self::ALLOWED_TYPES;
    }

    private static function q(string $ident): string
    {
        return '`' . $ident . '`';
    }

    // ─── DDL Builders ────────────────────────────────────────────────────────

    /**
     * Build CREATE TABLE DDL from validated physical name and column definitions.
     * Always prepends fixed system columns: id (PK) and created_at.
     *
     * @param array $columns  [['name'=>'...', 'type'=>'...', 'nullable'=>bool, 'default'=>?string], ...]
     */
    public static function createTableDDL(string $physicalName, array $columns = []): string
    {
        self::validateIdentifier($physicalName);

        $defs = [
            '`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            '`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ];

        foreach ($columns as $col) {
            $defs[] = self::buildColumnDef($col);
        }

        return sprintf(
            "CREATE TABLE IF NOT EXISTS %s (\n  %s\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            self::q($physicalName),
            implode(",\n  ", $defs)
        );
    }

    /**
     * Build ALTER TABLE ... ADD COLUMN DDL.
     */
    public static function addColumnDDL(string $physicalName, array $col): string
    {
        self::validateIdentifier($physicalName);
        $def = self::buildColumnDef($col);
        return sprintf('ALTER TABLE %s ADD COLUMN %s', self::q($physicalName), $def);
    }

    /**
     * Build ALTER TABLE ... DROP COLUMN DDL.
     */
    public static function dropColumnDDL(string $physicalName, string $colName): string
    {
        self::validateIdentifier($physicalName);
        self::validateIdentifier($colName);
        return sprintf('ALTER TABLE %s DROP COLUMN %s', self::q($physicalName), self::q($colName));
    }

    /**
     * Build DROP TABLE DDL.
     */
    public static function dropTableDDL(string $physicalName): string
    {
        self::validateIdentifier($physicalName);
        return sprintf('DROP TABLE IF EXISTS %s', self::q($physicalName));
    }

    // ─── Execution ───────────────────────────────────────────────────────────

    /**
     * Execute DDL and log it to migrations. This is the only place raw SQL
     * reaches the database — always preceded by full validation.
     */
    public static function applyDDL(\PDO $pdo, int $projectId, string $ddl): void
    {
        $pdo->exec($ddl);
        Catalog::getInstance()->recordMigration($projectId, $ddl);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private static function buildColumnDef(array $col): string
    {
        $name     = self::validateIdentifier($col['name']);
        $type     = self::validateDataType($col['type']);
        $nullable = (bool)($col['nullable'] ?? true);
        $default  = $col['default'] ?? null;

        // PASSWORD is stored as a bcrypt hash in VARCHAR(255)
        $ddlType = ($type === 'PASSWORD') ? 'VARCHAR(255)' : $type;
        $def = self::q($name) . ' ' . $ddlType;
        $def .= $nullable ? ' NULL' : ' NOT NULL';

        if ($default !== null) {
            $default = (string)$default;
            // Only allow safe literal defaults (no function calls or special chars)
            if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $default)) {
                throw new \InvalidArgumentException("Unsafe default value for column '$name'");
            }
            // Quote string defaults; leave bare numerics and SQL keywords unquoted
            $sqlKeywords = ['CURRENT_TIMESTAMP', 'NULL', 'TRUE', 'FALSE'];
            if (is_numeric($default) || in_array(strtoupper($default), $sqlKeywords, true)) {
                $def .= ' DEFAULT ' . $default;
            } else {
                $def .= " DEFAULT '" . $default . "'";
            }
        }

        return $def;
    }
}
