<?php

declare(strict_types=1);

// Runs as a genuinely separate OS process (spawned by Blogic::invoke() via
// proc_open, never `require`'d into the main app) so a bad or hostile
// tenant-authored function can't touch anything outside this process:
// invoked with disable_functions stripping network/process/exec functions
// and open_basedir pointed at a directory nothing meaningful lives in, and
// wall-clock-limited by the parent (see Blogic::invoke()), which kills this
// process outright if it runs too long -- there is no cooperative timeout to
// evade. This file itself never connects to a database, never requires
// bootstrap.php/config, and never sees a secret of any kind -- there is
// nothing sensitive in this process for tenant code to reach even via a
// `global` reference.
//
// Protocol: one JSON object on STDIN, `{"source": "<php statements>",
// "context": {...plain data...}}`. `source` is the *body* of a function --
// never a full PHP file with its own opening/closing tags -- so it only
// ever runs inside a fresh
// function scope with exactly two things in it: $ctx (read-only-by-
// convention input data) and $effects (the only way it can express an
// intent to change anything). One JSON object on STDOUT: either
// `{"effects": [...]}` or `{"error": "..."}`.

// Builder for the fixed, whitelisted vocabulary of things a BLogic function
// is allowed to ask for -- it never touches a database itself, it just
// accumulates a plain list of intended operations for the trusted parent
// process to validate and execute atomically.
final class BlogicEffects
{
    private array $ops = [];

    public function increment(string $table, int|string $id, string $column, int|float $amount): void
    {
        $this->ops[] = ['op' => 'increment', 'table' => $table, 'id' => $id, 'column' => $column, 'amount' => $amount];
    }

    public function decrement(string $table, int|string $id, string $column, int|float $amount): void
    {
        $this->ops[] = ['op' => 'decrement', 'table' => $table, 'id' => $id, 'column' => $column, 'amount' => $amount];
    }

    public function update(string $table, int|string $id, array $values): void
    {
        $this->ops[] = ['op' => 'update', 'table' => $table, 'id' => $id, 'values' => $values];
    }

    public function insert(string $table, array $values): void
    {
        $this->ops[] = ['op' => 'insert', 'table' => $table, 'values' => $values];
    }

    // Aborts the whole transition -- nothing this function queued gets
    // applied -- if $condition is false. This is the only way tenant code
    // enforces an invariant ("balance can't go negative"); it can't do
    // anything else the parent wouldn't also re-validate.
    public function assert(bool $condition, string $message = 'Assertion failed'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public function toArray(): array
    {
        return $this->ops;
    }
}

// The actual result is written to fd 3, not STDOUT -- disable_functions
// applies to this whole process, not just the eval'd tenant code, so
// `fwrite` can't be stripped from tenant code without also breaking this
// file's own output. Keeping the trusted result on a descriptor tenant code
// has no reason to know about means anything it dumps to STDOUT/STDERR is
// just noise the parent ignores, never something that can collide with or
// forge the channel the parent actually parses.
function blogic_runner_write_result(array $result): void
{
    $fd3 = fopen('php://fd/3', 'w');
    fwrite($fd3, json_encode($result));
    fclose($fd3);
}

function blogic_runner_main(): void
{
    $raw = stream_get_contents(STDIN);
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload) || !isset($payload['source']) || !is_string($payload['source'])) {
        blogic_runner_write_result(['error' => 'Malformed invocation payload']);
        return;
    }

    $ctx = is_array($payload['context'] ?? null) ? $payload['context'] : [];
    $effects = new BlogicEffects();

    try {
        // Wrapping in a function via eval() is the whole point: the tenant
        // source can only ever see the two parameters handed to it, never
        // any variable in this file's outer scope (PHP function scoping is
        // real -- a `global $x` inside the eval'd body can only reach a
        // global that actually exists in this process, and this process
        // deliberately never creates one worth reaching).
        $fn = null;
        eval('$fn = function(array $ctx, BlogicEffects $effects): void {' . $payload['source'] . "\n};");
        if (!($fn instanceof \Closure)) {
            throw new \RuntimeException('Function body failed to compile');
        }
        $fn($ctx, $effects);
    } catch (\Throwable $e) {
        blogic_runner_write_result(['error' => $e->getMessage()]);
        return;
    }

    blogic_runner_write_result(['effects' => $effects->toArray()]);
}

blogic_runner_main();
