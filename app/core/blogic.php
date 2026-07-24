<?php

declare(strict_types=1);

namespace SupaBein;

/**
 * Executes tenant-authored BLogic functions in a sandboxed subprocess and
 * applies the effects they request. The subprocess (app/blogic/sandbox_runner.php)
 * never holds a database handle -- Blogic pre-fetches whatever context a
 * function declares it needs (resolveContext()), the sandbox only computes
 * over that in-memory data, and Blogic is the only thing that ever executes
 * a real query, after validating every requested effect against a whitelist
 * of physical tables this specific BLogic entry is actually scoped to.
 */
class Blogic
{
    private const RUNNER_PATH = __DIR__ . '/../blogic/sandbox_runner.php';
    private const TIMEOUT_SECONDS = 5;

    // Process/exec, raw sockets, mail, and dangerous config functions --
    // the things open_basedir *can't* contain by restricting a path.
    // Filesystem functions (fopen, file_get_contents, etc.) are deliberately
    // NOT in this list: open_basedir already fully contains them to the
    // empty sandbox tmp dir, and disable_functions applies to the whole
    // subprocess including sandbox_runner.php's own trusted code -- which
    // needs fopen() itself, to write the actual result to fd 3. Disabling
    // it broke that (a disabled function call returns false rather than
    // throwing, so the runner's own fwrite(false, ...) call raised an
    // uncaught TypeError and crashed silently with display_errors off) --
    // caught by testing the real invoke() path via proc_open specifically,
    // not just by testing sandbox_runner.php directly from a shell.
    private const DISABLED_FUNCTIONS = 'exec,passthru,shell_exec,system,popen,pcntl_exec,pcntl_fork,proc_open,proc_close,proc_get_status,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,socket_create,mail,dl,putenv,ini_set';

    private const ALLOWED_EFFECT_OPS = ['increment', 'decrement', 'update', 'insert', 'assert'];

    /**
     * Pre-fetches the triggering row and any declared related lookups, per
     * $blogic['context_spec']. Runs entirely with the caller's own trusted
     * PDO connection -- this is the ONLY place reads happen; the sandbox
     * never queries anything itself.
     *
     * context_spec shape: [{"as": "name", "table": "logical_table_name",
     * "where": {"col": "literal" | "$row.col"}, "order_by": "col ASC",
     * "one": true}, ...]
     *
     * $ctx['table'] and $ctx['tables'][$as] carry the resolved *physical*
     * table names alongside the data -- source never has to know or
     * hardcode SupaBein's internal naming scheme (p{projectId}_{name}) to
     * pass the right value to $effects->increment()/insert()/etc, and
     * whatever it passes is exactly what applyEffects()'s whitelist expects
     * regardless.
     */
    public function resolveContext(\PDO $pdo, int $projectId, array $triggeringRow, string $triggeringTablePhysical, ?array $contextSpec): array
    {
        $catalog = Catalog::getInstance();
        $ctx = ['row' => $triggeringRow, 'table' => $triggeringTablePhysical, 'related' => [], 'tables' => []];

        foreach ($contextSpec ?? [] as $lookup) {
            if (!is_array($lookup) || empty($lookup['as']) || empty($lookup['table'])) continue;
            $table = $catalog->getTable($projectId, (string)$lookup['table']);
            if (!$table) continue;
            $ctx['tables'][$lookup['as']] = $table['physical_name'];

            $where = [];
            $params = [];
            foreach ((array)($lookup['where'] ?? []) as $col => $val) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$col)) continue;
                if (is_string($val) && str_starts_with($val, '$row.')) {
                    $val = $triggeringRow[substr($val, 5)] ?? null;
                }
                $where[] = "`$col` = ?";
                $params[] = $val;
            }
            $sql = 'SELECT * FROM `' . $table['physical_name'] . '`';
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            if (!empty($lookup['order_by']) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s+(ASC|DESC)$/i', (string)$lookup['order_by'])) {
                $sql .= ' ORDER BY ' . $lookup['order_by'];
            }
            $limit = !empty($lookup['one']) ? 1 : (int)($lookup['limit'] ?? 200);
            $sql .= ' LIMIT ' . max(1, min($limit, 500));

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $ctx['related'][$lookup['as']] = !empty($lookup['one']) ? ($rows[0] ?? null) : $rows;
        }

        return $ctx;
    }

    /**
     * Runs $source in the sandbox with $context as input, wall-clock-bounded
     * so a hung or hostile function can't hold a request (or a worker) open
     * indefinitely -- proc_terminate() kills the process outright at the
     * deadline, there is nothing cooperative for tenant code to evade.
     *
     * Returns the raw effect list the sandbox requested. Throws
     * BlogicExecutionException if the function asserted false or threw, and
     * RuntimeException for infrastructure failures (couldn't start the
     * process, timed out, malformed output).
     */
    public function invoke(string $source, array $context): array
    {
        $tmpDir = sys_get_temp_dir() . '/sb_blogic_sandbox';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0700, true);

        // PHP_BINARY is wrong here under LiteSpeed: for a request served by
        // the LSAPI SAPI it resolves to /usr/local/bin/lsphp, which only
        // understands LSAPI-server or basic CLI-interpreter mode, not "run
        // this script with these -d flags" -- it just prints its own usage
        // text and exits. PHP_BIN is the same config key ai_spawn_job_worker()
        // already uses for exactly this (spawning a genuine CLI-capable PHP
        // process from a web request), so reuse it here.
        $phpBin = (\App::get('config')['PHP_BIN'] ?? null) ?: '/usr/local/bin/php';
        $cmd = [
            $phpBin,
            '-d', 'disable_functions=' . self::DISABLED_FUNCTIONS,
            '-d', 'open_basedir=' . $tmpDir,
            '-d', 'display_errors=0',
            '-d', 'log_errors=0',
            self::RUNNER_PATH,
        ];
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptorSpec, $pipes, null, [], ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start BLogic sandbox');
        }

        fwrite($pipes[0], json_encode(['source' => $source, 'context' => $context]) ?: '{}');
        fclose($pipes[0]);
        stream_set_blocking($pipes[3], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $resultOutput = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        do {
            $resultOutput .= stream_get_contents($pipes[3]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(20000);
        } while (microtime(true) < $deadline);

        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
            foreach ([1, 2, 3] as $fd) @fclose($pipes[$fd]);
            proc_close($process);
            throw new \RuntimeException('BLogic function timed out');
        }

        $resultOutput .= stream_get_contents($pipes[3]);
        foreach ([1, 2, 3] as $fd) @fclose($pipes[$fd]);
        proc_close($process);

        $result = json_decode($resultOutput, true);
        if (!is_array($result)) {
            throw new \RuntimeException('BLogic sandbox returned invalid output');
        }
        if (isset($result['error'])) {
            throw new BlogicExecutionException((string)$result['error']);
        }
        return is_array($result['effects'] ?? null) ? $result['effects'] : [];
    }

    /**
     * Validates every requested effect against $allowedTables (physical
     * names this specific BLogic entry is scoped to -- the triggering
     * table plus whatever context_spec declared, nothing the sandboxed
     * code could have expanded at runtime) and the fixed op whitelist, then
     * executes them all inside one transaction. Any invalid effect, or any
     * failure mid-transaction, rolls back everything -- partial application
     * of a BLogic function's effects is never a valid outcome.
     */
    public function applyEffects(\PDO $pdo, array $effects, array $allowedTables): void
    {
        foreach ($effects as $e) {
            if (!is_array($e) || !in_array($e['op'] ?? null, self::ALLOWED_EFFECT_OPS, true)) {
                throw new BlogicExecutionException('BLogic function requested an unrecognized operation');
            }
            if ($e['op'] === 'assert') continue;
            if (!in_array($e['table'] ?? null, $allowedTables, true)) {
                throw new BlogicExecutionException('BLogic function referenced a table outside its declared scope: ' . ($e['table'] ?? '?'));
            }
        }

        $pdo->beginTransaction();
        try {
            foreach ($effects as $e) {
                match ($e['op']) {
                    'increment' => $pdo->prepare('UPDATE `' . $e['table'] . '` SET `' . self::col($e['column']) . '` = `' . self::col($e['column']) . '` + ? WHERE id = ?')->execute([$e['amount'], $e['id']]),
                    'decrement' => $pdo->prepare('UPDATE `' . $e['table'] . '` SET `' . self::col($e['column']) . '` = `' . self::col($e['column']) . '` - ? WHERE id = ?')->execute([$e['amount'], $e['id']]),
                    'update'    => self::execUpdate($pdo, $e),
                    'insert'    => self::execInsert($pdo, $e),
                    'assert'    => null, // enforced inside the sandbox itself (BlogicEffects::assert) -- a false assert never reaches here as a queued op
                    default     => throw new BlogicExecutionException('Unreachable: unrecognized op passed validation'),
                };
            }
            $pdo->commit();
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $ex;
        }
    }

    private static function col(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $name)) {
            throw new BlogicExecutionException('Invalid column name in effect: ' . $name);
        }
        return $name;
    }

    private static function execUpdate(\PDO $pdo, array $e): void
    {
        $sets = [];
        $params = [];
        foreach ((array)$e['values'] as $col => $val) {
            $sets[] = '`' . self::col((string)$col) . '` = ?';
            $params[] = $val;
        }
        if (!$sets) return;
        $params[] = $e['id'];
        $pdo->prepare('UPDATE `' . $e['table'] . '` SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    private static function execInsert(\PDO $pdo, array $e): void
    {
        $cols = [];
        $params = [];
        foreach ((array)$e['values'] as $col => $val) {
            $cols[] = self::col((string)$col);
            $params[] = $val;
        }
        if (!$cols) return;
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $pdo->prepare('INSERT INTO `' . $e['table'] . '` (`' . implode('`, `', $cols) . '`) VALUES (' . $placeholders . ')')->execute($params);
    }
}

class BlogicExecutionException extends \RuntimeException
{
}
