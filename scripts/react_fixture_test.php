<?php

declare(strict_types=1);

/**
 * Zero-LLM regression harness for react_build.php / ai_validator.php --
 * exercises the exact same functions the real agent loop calls
 * (ai_react_build_bundle, ai_smoke_test_files, ai_validator_check_project)
 * against a small hand-written fixture instead of running a full AI build
 * job. No schema/design stage, no agent turns, no waiting on an LLM --
 * esbuild + (for `smoke`) one Browserless round trip is the only latency,
 * so a run finishes in seconds instead of the 20-30+ minutes a real job
 * takes.
 *
 * Use build/smoke/validate whenever a change touches react_build.php,
 * ai_validator.php's regex checks, or the canonical React/vanilla modules
 * themselves -- anything that's testing OUR code, not the model's
 * behavior. Confirmed live (2026-07-29): this is exactly how the
 * dev-mode-error-decoding fix and the this/onclick validator rewrite were
 * verified, each in a few seconds instead of a real build job.
 *
 * `probe` mode covers the other half: does the MODEL actually follow a
 * given prompt rule at a specific decision point (write order, whether it
 * self-corrects a flagged RULE 2B violation, etc.). It replays a
 * hand-constructed transcript prefix up to the moment you want to
 * inspect and makes exactly ONE real LLM call -- the same
 * generateJsonWithHistory() call a real turn of ai_run_build_frontend_
 * agentic() makes, against the real system prompt and the real default
 * model (zhipu/glm-4.5-flash unless overridden) -- and prints the raw
 * decision. One LLM call, a few seconds, instead of waiting through
 * however many turns a full job takes to reach (or never reach) the same
 * decision point. It genuinely can't go faster than one real model
 * response, but it never runs more than that one.
 *
 * Must run on a host with the react build runtime installed (esbuild +
 * node_modules under the configured react runtime dir) and, for `smoke`
 * mode, a working Browserless connection -- i.e. production, via
 * admin-shell, the same way every other live-verification script in this
 * project runs. `build` and `validate` modes don't touch Browserless at
 * all (validate doesn't even touch esbuild) so they'll work anywhere the
 * app's own config/bootstrap does; `probe` needs a configured AI provider
 * (same config the real pipeline uses) but nothing else.
 *
 * Usage:
 *   php scripts/react_fixture_test.php list
 *   php scripts/react_fixture_test.php build    <fixture-name-or-path>
 *   php scripts/react_fixture_test.php smoke    <fixture-name-or-path>
 *   php scripts/react_fixture_test.php validate <fixture-name-or-path> [vanilla|react]
 *   php scripts/react_fixture_test.php probe    <fixture-name-or-path> [vanilla|react] [provider] [model]
 *
 * A build/smoke/validate fixture is a directory of files --
 * scripts/fixtures/react/<name>/ or scripts/fixtures/vanilla/<name>/ by
 * default, or any path you pass directly. Every regular file in it
 * (except README.txt) becomes one agent-authored file, path relative to
 * the fixture root. Add a new one by making a new directory with an
 * optional one-line README.txt describing what it's for and what result
 * to expect; nothing else needs registering anywhere.
 *
 * A probe fixture is a directory under scripts/fixtures/probes/<name>/
 * containing:
 *   schema.json  -- {"tables": [...]}, the exact shape ai_schema_to_
 *                   context()/ai_validator_check_project() expect. Reuse
 *                   scripts/fixtures/probes/_schema_tasks.json for a
 *                   ready-made one-table schema unless the probe
 *                   specifically needs different columns.
 *   history.json -- [{"role": "user"|"model", "text": "..."}, ...], the
 *                   simulated transcript BEFORE the turn you're probing.
 *                   [] for "probe the very first decision".
 *   turn.json    -- the final turn's content. If it parses as JSON, it's
 *                   re-encoded compactly (matching how the real loop
 *                   builds turnMsg via json_encode($toolResult) after
 *                   every real tool dispatch) -- e.g.
 *                   {"tool": "smoke_test", "result": {...}}. If it's not
 *                   valid JSON, used as a raw string instead (for probing
 *                   the very first turn, which is plain text, not a tool
 *                   result -- see turnMsg's construction at the top of
 *                   ai_run_build_frontend_agentic()).
 *   README.txt   -- what decision this is probing and what a rule-
 *                   following response should look like.
 *
 * This intentionally does NOT grade fixtures pass/fail against a fixed
 * expectation -- a fixture might exist specifically to prove something
 * IS caught (a bug repro) or specifically to prove nothing false-
 * positives (a known-good baseline), and the harness has no way to know
 * which without you reading its README. It just runs the real function
 * and prints the real result; read the output against what that
 * fixture's README says should happen. Exit code reflects only whether
 * the harness itself ran without an unexpected error (missing fixture,
 * missing runtime, PHP fatal) -- never a verdict on the fixture's app.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php'; // defines SUPABEIN_ROOT itself
require_once SUPABEIN_ROOT . '/app/routes/ai_routes.php';

function harness_log(string $msg): void
{
    echo $msg . "\n";
}

function harness_fixture_dir(string $fixture, string $stack): string
{
    if (is_dir($fixture)) return rtrim($fixture, '/');
    $dir = SUPABEIN_ROOT . "/scripts/fixtures/{$stack}/{$fixture}";
    if (!is_dir($dir)) {
        fwrite(STDERR, "Fixture directory not found: {$dir}\n");
        exit(1);
    }
    return $dir;
}

/** @return array{0: array<int, array{path: string, content: string}>, 1: ?string} [files, readme] */
function harness_load_fixture(string $dir): array
{
    $files  = [];
    $readme = null;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if (!$file->isFile()) continue;
        $rel = ltrim(substr($file->getPathname(), strlen($dir)), '/');
        if ($rel === 'README.txt') {
            $readme = trim((string)file_get_contents($file->getPathname()));
            continue;
        }
        $files[] = ['path' => $rel, 'content' => (string)file_get_contents($file->getPathname())];
    }
    if (!$files) {
        fwrite(STDERR, "Fixture directory has no files: {$dir}\n");
        exit(1);
    }
    return [$files, $readme];
}

$mode    = $argv[1] ?? '';
$fixture = $argv[2] ?? '';

if ($mode === 'list' || $mode === '') {
    foreach (['react', 'vanilla', 'probes'] as $stack) {
        $base = SUPABEIN_ROOT . "/scripts/fixtures/{$stack}";
        if (!is_dir($base)) continue;
        harness_log("{$stack}:");
        foreach (scandir($base) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir("{$base}/{$entry}")) continue;
            $readmePath = "{$base}/{$entry}/README.txt";
            $desc = is_file($readmePath) ? trim((string)file_get_contents($readmePath)) : '';
            harness_log("  {$entry}" . ($desc ? " -- {$desc}" : ''));
        }
    }
    if ($mode === '') {
        harness_log('');
        harness_log('Usage: php scripts/react_fixture_test.php <build|smoke|validate|probe|list> <fixture> [vanilla|react]');
    }
    exit(0);
}

if (!in_array($mode, ['build', 'smoke', 'validate', 'probe'], true) || $fixture === '') {
    fwrite(STDERR, "Usage: php scripts/react_fixture_test.php <build|smoke|validate|probe|list> <fixture> [vanilla|react]\n");
    exit(1);
}

$config = \App::get('config');
$t0 = microtime(true);

if ($mode === 'build' || $mode === 'smoke') {
    $dir = harness_fixture_dir($fixture, 'react');
    [$files, $readme] = harness_load_fixture($dir);
    if ($readme) harness_log("README: {$readme}");

    if ($mode === 'build') {
        $build = ai_react_build_bundle($files, $config, null, 'FixtureApp', true);
        $elapsed = round(microtime(true) - $t0, 2);
        if (!$build['ok']) {
            harness_log("BUILD FAILED ({$elapsed}s):");
            harness_log($build['error']);
            exit(0);
        }
        $bundleSize = strlen($build['files'][1]['content'] ?? '');
        harness_log("BUILD OK ({$elapsed}s) -- bundle size {$bundleSize} bytes");
        exit(0);
    }

    $result  = ai_smoke_test_files($files, $config, null, 'react', 'FixtureApp');
    $elapsed = round(microtime(true) - $t0, 2);
    harness_log('SMOKE ' . ($result['ok'] ? 'OK' : 'FAILED (or found console errors)') . " ({$elapsed}s)");
    if (!empty($result['bodyText'])) harness_log('bodyText: ' . $result['bodyText']);
    if (!empty($result['console_errors'])) {
        harness_log('console_errors:');
        foreach ($result['console_errors'] as $e) harness_log('  - ' . $e);
    }
    if (!empty($result['error'])) harness_log('error: ' . $result['error']);
    exit(0);
}

if ($mode === 'validate') {
    $stack = in_array($argv[3] ?? '', ['vanilla', 'react'], true) ? $argv[3] : 'vanilla';
    $dir = harness_fixture_dir($fixture, $stack);
    [$files, $readme] = harness_load_fixture($dir);
    if ($readme) harness_log("README: {$readme}");

    $schema   = ['tables' => []]; // fixtures should be self-contained enough not to need real tables
    $findings = ai_validator_check_project($schema, $files, $stack);
    $elapsed  = round(microtime(true) - $t0, 2);
    harness_log('VALIDATE found ' . count($findings) . " finding(s) ({$elapsed}s)");
    foreach ($findings as $f) {
        harness_log("  [{$f['severity']}/{$f['category']}] {$f['message']}");
        if ($f['detail']) harness_log('    ' . $f['detail']);
    }
    exit(0);
}

// probe -- exactly ONE real generateJsonWithHistory() call, replaying a
// hand-built transcript prefix up to the decision point being inspected.
$dir = is_dir($fixture) ? rtrim($fixture, '/') : SUPABEIN_ROOT . "/scripts/fixtures/probes/{$fixture}";
if (!is_dir($dir)) {
    fwrite(STDERR, "Probe fixture directory not found: {$dir}\n");
    exit(1);
}
$readmePath = "{$dir}/README.txt";
if (is_file($readmePath)) harness_log('README: ' . trim((string)file_get_contents($readmePath)));

$schemaPath = "{$dir}/schema.json";
if (!is_file($schemaPath)) {
    fwrite(STDERR, "Probe fixture missing schema.json: {$schemaPath}\n");
    exit(1);
}
$schema = json_decode((string)file_get_contents($schemaPath), true);
if (!is_array($schema)) {
    fwrite(STDERR, "Probe fixture's schema.json is not valid JSON: {$schemaPath}\n");
    exit(1);
}

$historyPath = "{$dir}/history.json";
$history = [];
if (is_file($historyPath)) {
    $history = json_decode((string)file_get_contents($historyPath), true);
    if (!is_array($history)) {
        fwrite(STDERR, "Probe fixture's history.json is not valid JSON: {$historyPath}\n");
        exit(1);
    }
}

$turnPath = "{$dir}/turn.json";
if (!is_file($turnPath)) {
    fwrite(STDERR, "Probe fixture missing turn.json: {$turnPath}\n");
    exit(1);
}
$turnRaw     = trim((string)file_get_contents($turnPath));
$turnDecoded = json_decode($turnRaw, true);
$turnMsg     = (json_last_error() === JSON_ERROR_NONE) ? json_encode($turnDecoded) : $turnRaw;

$stack    = in_array($argv[3] ?? '', ['vanilla', 'react'], true) ? $argv[3] : 'vanilla';
$provider = $argv[4] ?? null;
$model    = $argv[5] ?? null;

$basePrompt  = $stack === 'react' ? AI_BUILD_FRONTEND_AGENT_SYSTEM_PROMPT_REACT : AI_BUILD_FRONTEND_AGENT_SYSTEM_PROMPT;
$agentPrompt = ai_bind_auth_placeholders($basePrompt, $schema);

$client = make_ai_client($config, $provider, $model);
harness_log("Calling generateJsonWithHistory() ({$stack} stack" . ($provider ? ", {$provider}/{$model}" : ', default provider/model') . ")...");

// The real agentic loop treats a truncated/invalid-JSON response as a
// routine, recoverable event -- the model just gets asked again next turn
// (see ai_run_build_frontend_agentic()'s catch block for generateJsonWithHistory).
// A probe is a stand-in for "the next turn", so a bounded retry here mirrors
// that instead of surfacing a raw parse-failure stack trace as if the
// PLATFORM were broken -- it isn't, this is the same shape of hiccup a real
// job absorbs silently. Capped at 3 attempts so a probe still can't run away
// to job-length latency.
$action = null;
$usage  = [];
$lastError = null;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    try {
        $action = $client->generateJsonWithHistory($agentPrompt, $history, $turnMsg, [], true, null);
        $usage  = $client->getLastUsage();
        $lastError = null;
        break;
    } catch (\Throwable $e) {
        $lastError = $e->getMessage();
        harness_log("  attempt {$attempt} failed (invalid/truncated JSON, retrying): "
            . (mb_strlen($lastError) > 200 ? mb_substr($lastError, 0, 200) . '…' : $lastError));
    }
}
$elapsed = round(microtime(true) - $t0, 2);

if ($lastError !== null) {
    harness_log("PROBE FAILED after 3 attempts ({$elapsed}s): {$lastError}");
    exit(0);
}

harness_log("PROBE RESPONSE ({$elapsed}s, " . ($usage['total_tokens'] ?? '?') . " tokens):");
harness_log(json_encode($action, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
exit(0);
