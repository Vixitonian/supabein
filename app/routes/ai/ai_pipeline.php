<?php

declare(strict_types=1);



// ─── Job-backed generation ───────────────────────────────────────────────────
// Shared by the /v1/ai/build/job and /v1/ai/edit/job routes and the background
// worker (app/workers/ai_worker.php) — one implementation so the two never
// drift apart the way the old (dead) worker pipeline did relative to these
// routes. $report(array $event) is called at the same points the old NDJSON
// stream's $emit() was; failures throw instead of emitting an 'error' event
// and returning, since callers now run inside a job, not a live HTTP response.

// Full build pipeline in one call — used by the "watch only" (Review off)
// flow, which runs schema/design/frontend/validate straight through as a
// single job. The "confirm each stage" (Review on) flow instead calls
// ai_run_build_schema_design() and ai_run_build_frontend() as two separate
// jobs, with a user confirmation in between.
/**
 * @param array $refs See ai_generate_intent()'s doc comment for the shape.
 * @param array|null $resumeCheckpoint A prior 'build' job's saved checkpoint
 *   (see ai_run_build_and_deploy()'s doc comment) — when a stage's output is
 *   already present here, that stage is skipped entirely and its saved
 *   output reused, instead of re-running (and re-billing) it.
 * @param callable|null $checkpoint Called as $checkpoint(string $stage, array
 *   $data) right after each stage completes, so the caller can persist it.
 */
function ai_run_build_generation(string $prompt, array $history, ?array $approvedIntent, object $client, array $config, callable $report, bool $validate = true, array $refs = [], ?array $resumeCheckpoint = null, ?callable $checkpoint = null): array
{
    $checkpoint = $checkpoint ?? function (string $stage, array $data): void {};
    $aiTrace = [];

    // Review-off ("watch only") never runs the separate intent-review step,
    // but the actors/stories/journeys it produces are still valuable context
    // for the schema pass — generate it here instead of skipping it outright.
    // Review-on always passes an already-confirmed $approvedIntent, so this
    // never re-runs (and never re-generates) an intent the user already saw.
    $resumedPastIntent = !empty($resumeCheckpoint['intent']) || !empty($resumeCheckpoint['schema']) || !empty($resumeCheckpoint['plan']);
    if ($approvedIntent === null && $resumedPastIntent) {
        // A crashed prior run already got at least as far as schema/design
        // (or beyond) — that only ever happens after requirements were
        // already understood, so there is nothing left for this step to do,
        // whether or not the checkpoint it resumed from happened to still
        // carry the intent object itself (only the earliest 'intent'
        // checkpoint does; later ones don't need to re-carry it).
        $approvedIntent = $resumeCheckpoint['intent'] ?? null;
        $report(['stage' => 'requirements', 'status' => 'done', 'label' => 'Requirements understood (resumed)']);
    } elseif ($approvedIntent === null) {
        $report(['stage' => 'requirements', 'status' => 'start', 'label' => 'Understanding your requirements…']);
        $_t0 = microtime(true);
        $approvedIntent = ai_generate_intent($client, $prompt, $history, $refs,
            ai_agent_retry_reporter($report, 'requirements', 'Understanding your requirements…'));
        $aiTrace[] = ['stage' => 'intent', 'system' => AI_INTENT_PROMPT, 'history' => $history, 'user_msg' => $prompt, 'response' => $approvedIntent, 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];
        $actorNames = array_filter(array_map(fn($a) => is_array($a) ? ($a['name'] ?? '') : (string)$a, $approvedIntent['actors'] ?? []));
        $storyCount = array_sum(array_map(fn($a) => is_array($a) ? count($a['stories'] ?? []) : 0, $approvedIntent['actors'] ?? []));
        $report(['stage' => 'requirements', 'status' => 'done', 'label' => 'Requirements understood',
                 'detail' => count($actorNames) . ' actor(s): ' . implode(', ', $actorNames) . ' — ' . $storyCount . ' user stor' . ($storyCount === 1 ? 'y' : 'ies')]);
        ai_pipeline_debug_log('requirements', "Prompt: {$prompt}", ['intent' => $approvedIntent]);
        $checkpoint('intent', ['intent' => $approvedIntent]);
    }

    // A 'plan' checkpoint (saved once frontend generation finishes, or once
    // deploy finishes — both stages that only ever run AFTER schema/design)
    // already carries the schema inside it, so schema/design never needs to
    // re-run just because this resume's checkpoint happens to have been
    // saved from a later stage than 'schema' itself.
    if (!empty($resumeCheckpoint['schema']) || !empty($resumeCheckpoint['plan'])) {
        $schemaResult = [
            'schema'       => $resumeCheckpoint['schema'] ?? $resumeCheckpoint['plan'],
            'design_brief' => $resumeCheckpoint['design_brief'] ?? [],
            'aiTrace' => [], 'usage' => $client->getLastUsage(),
        ];
        $tableNames = array_map(fn($t) => $t['name'], $schemaResult['schema']['tables'] ?? []);
        $report(['stage' => 'schema', 'status' => 'done', 'label' => 'Database schema ready (resumed)', 'detail' => count($tableNames) . ' table' . (count($tableNames) === 1 ? '' : 's') . ': ' . implode(', ', $tableNames)]);
        $report(['stage' => 'design', 'status' => 'done', 'label' => 'Visual design chosen (resumed)']);
    } else {
        $schemaResult = ai_run_build_schema_design($prompt, $history, $approvedIntent, $client, $report, $refs);
        $checkpoint('schema', ['intent' => $approvedIntent, 'schema' => $schemaResult['schema'], 'design_brief' => $schemaResult['design_brief']]);
    }

    if (!empty($resumeCheckpoint['plan'])) {
        $frontendResult = ['plan' => $resumeCheckpoint['plan'], 'summary' => [
            'project_name'   => $resumeCheckpoint['plan']['project_name'] ?? '',
            'tables'         => array_map(fn($t) => $t['name'] . ' (' . count($t['columns'] ?? []) . ' cols)', $resumeCheckpoint['plan']['tables'] ?? []),
            'frontend_files' => count($resumeCheckpoint['plan']['frontend']['files'] ?? []),
        ], 'usage' => $client->getLastUsage(), 'aiTrace' => [], 'validation' => $resumeCheckpoint['validation'] ?? []];
        $report(['stage' => 'frontend', 'status' => 'done', 'label' => 'Frontend generated (resumed)', 'detail' => count($resumeCheckpoint['plan']['frontend']['files'] ?? []) . ' file(s)']);
        if ($validate) {
            $report(['stage' => 'validate', 'status' => 'done', 'label' => $frontendResult['validation'] ? 'Validation found issues (resumed)' : 'No issues found (resumed)']);
        }
    } else {
        $frontendResult = ai_run_build_frontend($schemaResult['schema'], $schemaResult['design_brief'], $prompt, $client, $config, $report, $validate, $refs, $approvedIntent);
        $checkpoint('frontend', [
            'intent' => $approvedIntent, 'schema' => $schemaResult['schema'], 'design_brief' => $schemaResult['design_brief'],
            'plan' => $frontendResult['plan'], 'validation' => $frontendResult['validation'],
        ]);
    }

    return [
        'plan'       => $frontendResult['plan'],
        'summary'    => $frontendResult['summary'],
        'usage'      => $frontendResult['usage'],
        'aiTrace'    => array_merge($aiTrace, $schemaResult['aiTrace'], $frontendResult['aiTrace']),
        'validation' => $frontendResult['validation'],
        // The final resolved intent -- whether passed in already-approved, just
        // generated fresh above, or pulled from a resume checkpoint -- surfaced
        // here so ai_run_build_and_deploy() can persist it once a project
        // actually exists. Without this, story-driven testing has no way to
        // know what was actually requested and silently falls back to
        // inferring stories from the schema + rendered HTML alone.
        'intent'     => $approvedIntent,
    ];
}

// Full watch-only (Review off) pipeline: generation, deploy, and test as ONE
// job — not three separately-orchestrated frontend steps. That matters for
// more than tidiness: a job's progress/result survive a page reload because
// the client reconnects by jobId and replays persisted progress events; three
// separate client-driven steps chained by JS awaits do NOT survive a reload
// (a backgrounded mobile tab reloading mid-build lost the in-memory chain
// entirely, leaving "Deploying to staging"/"Running tests" stuck pending
// forever while a completely different, older code path took over instead).
// Doing deploy+test inside the same job gives them the same resumability
// as generation for free.
/**
 * @param array $refs See ai_generate_intent()'s doc comment for the shape.
 * @param array|null $resumeCheckpoint A prior 'build' job's checkpoint —
 *   pass the ('mode'==='build') job's own saved result when retrying it via
 *   resume_job_id. Its 'stage' key names the last stage that finished
 *   ('intent'|'schema'|'frontend'|'deploy'); every stage up to and including
 *   that one is skipped and its saved output reused verbatim (never
 *   re-billed, and — critically for 'deploy' — never re-creates the
 *   project). The stage that was RUNNING when the prior job died (most
 *   often 'test', the one with a live browser subprocess) always re-runs.
 * @param callable|null $checkpoint Wired by the worker to persist the
 *   checkpoint to this job's own row after each stage — see
 *   Catalog::saveJobCheckpoint().
 */
function ai_run_build_and_deploy(string $prompt, array $history, ?array $approvedIntent, object $client, callable $report, bool $validate, array $config, \SupaBein\Catalog $catalog, int $userId, array $refs = [], ?array $resumeCheckpoint = null, ?callable $checkpoint = null): array
{
    $checkpoint = $checkpoint ?? function (string $stage, array $data): void {};
    $genResult = ai_run_build_generation($prompt, $history, $approvedIntent, $client, $config, $report, $validate, $refs, $resumeCheckpoint, $checkpoint);

    if (!empty($resumeCheckpoint['apply'])) {
        // The prior run already deployed this exact plan before it died —
        // reuse that project/site/deploy rather than calling
        // ai_execute_build() again, which would unconditionally create a
        // SECOND project with the same name and fail on the duplicate-name
        // constraint (or silently double it, if the name happened to differ).
        $applyResult = $resumeCheckpoint['apply'];
        $report(['stage' => 'deploy', 'status' => 'done', 'label' => 'Deployed (resumed)',
                 'detail' => $applyResult['staging'] ? 'Reusing previously deployed project' : ($applyResult['site'] ? 'Site created — no frontend deployed' : 'No site created')]);
    } else {
        $report(['stage' => 'deploy', 'status' => 'start', 'label' => 'Deploying to staging…']);
        $applyResult = ai_execute_build($genResult['plan'], $userId);
        // Uploaded reference images (e.g. "use this as the logo") were staged as
        // 'pending_assets' during generation, before a project existed to store
        // them under — write the actual bytes now that ai_execute_build() has
        // created one, so the __SB_PID__-placeholder URLs already baked into the
        // generated frontend resolve to real files the moment it's viewed.
        if (!empty($refs['pending_assets']) && !empty($applyResult['project']['id'])) {
            ai_upload_pending_assets($refs['pending_assets'], (int)$applyResult['project']['id']);
        }
        $report(['stage' => 'deploy', 'status' => 'done', 'label' => 'Deployed',
                 'detail' => $applyResult['staging'] ? 'Deployed to staging' : ($applyResult['site'] ? 'Site created — no frontend deployed' : 'No site created')]);
        $checkpoint('deploy', ['plan' => $genResult['plan'], 'validation' => $genResult['validation'] ?? [], 'apply' => $applyResult]);

        // Persist the real approved intent (actors/stories) the moment a
        // project exists, so story-driven testing below reads the actual
        // requested stories via ai_extract_saved_stories() instead of falling
        // back to ai_infer_stories() -- which only ever sees the schema and
        // rendered HTML, and invents plausible-sounding but unrequested
        // features (a login page, a dark-mode toggle, an input field) that
        // then get reported as failures and sent through auto-fix chasing
        // something the user never asked for. Every review-off build hit
        // this fallback before this fix, since nothing ever wrote to
        // project_requirements outside of the one manual PUT endpoint.
        if (!empty($genResult['intent']) && !empty($applyResult['project']['id'])) {
            $catalog->upsertProjectRequirements((int)$applyResult['project']['id'], $userId, $genResult['intent']);
        }
    }

    // A bare 'site' with no 'staging' means the deploy step itself rejected
    // the generated files (its own smoke check failed, or ai_deploy_files()
    // errored) -- there's nothing live to test. Previously this still
    // counted as "hasDeployed", so the pipeline ran the test stage anyway,
    // which then immediately failed with a confusing "No deploy found —
    // build or edit the project first" (job 218) instead of skipping
    // straight to the honest "Nothing to test" branch below.
    $hasDeployed = !empty($applyResult['staging']);
    $testResult  = null;
    if ($hasDeployed && !empty($applyResult['project']['id'])) {
        $report(['stage' => 'test', 'status' => 'start', 'label' => 'Running tests…']);
        // The test job's own sub-stages (script/stories/run/validate) would
        // otherwise collide with this pipeline's own 'validate' stage key —
        // remap them all onto this single outer 'test' stage's detail text
        // instead, so its one row narrates "Preparing test script…" through
        // to a final pass/fail count as it goes.
        $testReport = function (array $ev) use ($report) {
            $report(['stage' => 'test', 'status' => 'active', 'label' => 'Running tests…',
                     'detail' => $ev['label'] . (!empty($ev['detail']) ? ' — ' . $ev['detail'] : '')]);
        };
        try {
            // Review-off ("watch only") is the one flow where nobody's going
            // to look at a plan card and click Apply -- deploy already
            // happened automatically, so a test failure here has no human in
            // the loop to notice and fix it unless auto-fix does that job
            // instead. Review-on builds and every edit still stop at a
            // manual Apply/Run Full Test click, so this is deliberately
            // scoped to just this one pipeline.
            $testResult = ai_run_test_and_autofix((int)$applyResult['project']['id'], $userId, $catalog, $config, $testReport, $client);
            $passed = $testResult['passed'] ?? 0; $failed = $testResult['failed'] ?? 0;
            $autofixNote = '';
            if (!empty($testResult['autofix_attempts'])) {
                $fixedCount  = count($testResult['autofix_attempts']);
                $autofixNote = ' (' . $fixedCount . ' auto-fix attempt' . ($fixedCount === 1 ? '' : 's')
                             . ($testResult['autofix_gave_up'] ? ', still failing after' : ', resolved') . ')';
            }
            $report(['stage' => 'test', 'status' => 'done', 'label' => 'Tests finished', 'detail' => "{$passed} passed, {$failed} failed{$autofixNote}"]);
        } catch (\Throwable $e) {
            $report(['stage' => 'test', 'status' => 'error', 'label' => 'Testing failed', 'detail' => $e->getMessage()]);
        }
    } else {
        $report(['stage' => 'test', 'status' => 'done', 'label' => 'Nothing to test', 'detail' => 'No frontend was deployed']);
    }

    // The unified health gate (see ai_assess_deploy_health()'s doc comment) —
    // every completion path returns this, and the UI is expected to check it
    // FIRST rather than re-deriving "success" from staging/site presence the
    // way it used to.
    $unreachablePolicies = !empty($applyResult['project']['id'])
        ? $catalog->findUnreachablePolicies((int)$applyResult['project']['id'])
        : [];
    $health = ai_assess_deploy_health(
        $genResult['validation'] ?? [],
        $applyResult['deploy_error'] ?? null,
        $applyResult['staging'] ?? null,
        $unreachablePolicies
    );

    return array_merge($genResult, ['apply' => $applyResult, 'test' => $testResult, 'health' => $health]);
}

// Seeds a small number of test login accounts when the app has auth, with a
// fixed password properly bcrypt-hashed server-side — never left to the AI,
// which would otherwise have no way to produce a working, verifiable hash.
// Idempotent: re-running "Seed App" reuses the same test1@/test2@ accounts
// instead of erroring on the duplicate email or minting new ones each time.
function ai_seed_test_accounts(\PDO $pdo, \SupaBein\Catalog $catalog, int $projectId, array $schema): array
{
    $auth = ai_detect_auth($schema);
    if (!$auth['table']) return [];

    $tbl = $catalog->getTable($projectId, $auth['table']);
    if (!$tbl) return [];

    $pwCol = null;
    foreach ($schema['tables'] as $t) {
        if ($t['name'] !== $auth['table']) continue;
        foreach ($t['columns'] as $c) {
            if (strtoupper(trim((string)($c['type'] ?? ''))) === 'PASSWORD') { $pwCol = $c['name']; break 2; }
        }
    }
    if (!$pwCol) return [];

    $idField      = $auth['field'] ?? 'email';
    $isEmailField = str_contains(strtolower($idField), 'email');
    $hash         = password_hash(AI_TEST_ACCOUNT_PASSWORD, PASSWORD_BCRYPT);
    $physical     = $tbl['physical_name'];
    $accounts     = [];

    for ($i = 1; $i <= AI_TEST_ACCOUNT_COUNT; $i++) {
        $identifier = $isEmailField ? "test{$i}@example.com" : "test{$i}";
        try {
            $existing = $pdo->prepare("SELECT id FROM `{$physical}` WHERE `{$idField}` = ? LIMIT 1");
            $existing->execute([$identifier]);
            $existingId = $existing->fetchColumn();
            if ($existingId) {
                $accounts[] = ['id' => (int)$existingId, 'identifier' => $identifier, 'password' => AI_TEST_ACCOUNT_PASSWORD];
                continue;
            }
            $pdo->prepare("INSERT INTO `{$physical}` (`{$idField}`, `{$pwCol}`) VALUES (?, ?)")
                ->execute([$identifier, $hash]);
            $rowId = (int)$pdo->lastInsertId();
            ai_track_seed_rows($pdo, $projectId, $auth['table'], [$rowId]);
            $accounts[] = ['id' => $rowId, 'identifier' => $identifier, 'password' => AI_TEST_ACCOUNT_PASSWORD];
        } catch (\Throwable $e) {
            sb_log('ai_seed', 'Test account insert failed (non-fatal): ' . $e->getMessage());
        }
    }
    return $accounts;
}

// Read-only counterpart to ai_seed_test_accounts() — checks whether the
// deterministic test1@/test2@ (or test1/test2) rows already exist, without
// creating anything. Nothing about test accounts is stored anywhere beyond
// the auth table itself; their identifier and password are both fixed and
// derivable, so "do they exist" is answerable on demand instead of needing
// its own tracking table.
function ai_get_test_accounts_status(\PDO $pdo, \SupaBein\Catalog $catalog, int $projectId, array $schema): array
{
    $auth = ai_detect_auth($schema);
    if (!$auth['table']) return [];

    $tbl = $catalog->getTable($projectId, $auth['table']);
    if (!$tbl) return [];

    $idField      = $auth['field'] ?? 'email';
    $isEmailField = str_contains(strtolower($idField), 'email');
    $physical     = $tbl['physical_name'];
    $accounts     = [];

    for ($i = 1; $i <= AI_TEST_ACCOUNT_COUNT; $i++) {
        $identifier = $isEmailField ? "test{$i}@example.com" : "test{$i}";
        try {
            $existing = $pdo->prepare("SELECT id FROM `{$physical}` WHERE `{$idField}` = ? LIMIT 1");
            $existing->execute([$identifier]);
            if ($existing->fetchColumn()) {
                $accounts[] = ['identifier' => $identifier, 'password' => AI_TEST_ACCOUNT_PASSWORD];
            }
        } catch (\Throwable $e) {
            // Table/column mismatch (e.g. schema changed since accounts were
            // seeded) — treat as "no test accounts", not a hard failure.
        }
    }
    return $accounts;
}

// ─── End-user error logs (core/errors.js ingestion + dashboard viewing) ────
// Fingerprint groups repeat occurrences of "the same" error together so a
// tight error loop in one visitor's browser adds one row with a growing
// `occurrences` count instead of one row per occurrence. Deliberately coarse
// (type + message + first stack line only) — differing line numbers deeper
// in the stack, or differing URLs, still count as the same underlying bug.
function ai_error_log_fingerprint(string $type, string $message, ?string $stack): string
{
    $stackTop = '';
    if ($stack) {
        $lines = explode("\n", trim($stack));
        $stackTop = trim($lines[0] ?? '');
    }
    return md5($type . '|' . $message . '|' . $stackTop);
}

// Called from the public POST /v1/errors/:project_id route. Rate-limiting is
// the caller's responsibility (RateLimit::checkProjectErrors) so it happens
// before any DB work, same convention as the data routes.
function ai_report_error_log(\PDO $pdo, int $projectId, array $body): void
{
    static $validTypes = ['js_error', 'promise_rejection', 'api_error', 'console_error'];

    $type = (string)($body['type'] ?? '');
    if (!in_array($type, $validTypes, true)) abort(422, 'Invalid error type');

    $message = trim((string)($body['message'] ?? ''));
    if ($message === '') abort(422, 'message is required');
    $message = mb_substr($message, 0, 2000);

    $stack = isset($body['stack']) && $body['stack'] !== null ? mb_substr((string)$body['stack'], 0, 4000) : null;
    $url   = isset($body['url']) ? mb_substr((string)$body['url'], 0, 1024) : null;
    $meta  = isset($body['meta']) && $body['meta'] !== null ? json_encode($body['meta']) : null;
    $ua    = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

    $fingerprint = ai_error_log_fingerprint($type, $message, $stack);

    $pdo->prepare(
        'INSERT INTO ai_error_logs
            (project_id, type, message, stack, url, user_agent, meta, fingerprint, occurrences, first_seen_at, last_seen_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_seen_at = NOW(), url = VALUES(url)'
    )->execute([$projectId, $type, $message, $stack, $url, $ua, $meta, $fingerprint]);

    // Hard cap: keep only the most-recently-seen N distinct errors per
    // project so a project that generates many distinct (non-deduping)
    // errors still can't grow this table without bound.
    static $maxRowsPerProject = 500;
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ai_error_logs WHERE project_id = ?');
    $countStmt->execute([$projectId]);
    if ((int)$countStmt->fetchColumn() > $maxRowsPerProject) {
        $pdo->prepare(
            'DELETE FROM ai_error_logs WHERE project_id = ? ORDER BY last_seen_at ASC LIMIT 1'
        )->execute([$projectId]);
    }
}

function ai_list_error_logs(\PDO $pdo, int $projectId, int $limit = 200): array
{
    $stmt = $pdo->prepare(
        'SELECT id, type, message, stack, url, user_agent, meta, occurrences, first_seen_at, last_seen_at
         FROM ai_error_logs WHERE project_id = ? ORDER BY last_seen_at DESC LIMIT ?'
    );
    $stmt->bindValue(1, $projectId, \PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
    $stmt->execute();
    return array_map(function (array $row): array {
        $row['id']          = (int)$row['id'];
        $row['occurrences'] = (int)$row['occurrences'];
        $row['meta']        = $row['meta'] !== null ? json_decode($row['meta'], true) : null;
        return $row;
    }, $stmt->fetchAll());
}

// On-demand seeding for the "Seed App" button — same seed_data shape and
// insertion path as build's initial seed and edit mode's "seed N rows"
// requests (ai_insert_seed_data), just triggered directly instead of via a
// natural-language edit prompt.
function ai_run_project_seed(int $projectId, \SupaBein\Catalog $catalog, \PDO $pdo, object $client, callable $report): array
{
    $report(['stage' => 'schema', 'status' => 'start', 'label' => 'Reading schema…']);
    $schema = ai_schema_from_db($projectId, $catalog);
    $report(['stage' => 'schema', 'status' => 'done', 'label' => 'Schema loaded']);

    $report(['stage' => 'accounts', 'status' => 'start', 'label' => 'Setting up test login accounts…']);
    $testAccounts = ai_seed_test_accounts($pdo, $catalog, $projectId, $schema);
    $report(['stage' => 'accounts', 'status' => 'done',
             'label'  => $testAccounts ? 'Test accounts ready' : 'No auth table found',
             'detail' => $testAccounts ? implode(', ', array_column($testAccounts, 'identifier')) . ' (password: ' . AI_TEST_ACCOUNT_PASSWORD . ')' : '']);

    $report(['stage' => 'generate', 'status' => 'start', 'label' => 'Generating sample data…']);
    $prompt  = $testAccounts ? AI_SEED_PROMPT_WITH_ACCOUNTS : AI_SEED_PROMPT;
    $userMsg = "Exact schema:\n" . ai_schema_to_context($schema);
    if ($testAccounts) {
        $userMsg .= "\n\nTest user IDs available to own seeded rows: " . implode(', ', array_column($testAccounts, 'id'));
    }
    $result = $client->generateJson($prompt, $userMsg, [], true,
        ai_agent_retry_reporter($report, 'generate', 'Generating sample data…'));
    $seedData = is_array($result['seed_data'] ?? null) ? $result['seed_data'] : [];
    $report(['stage' => 'generate', 'status' => 'done', 'label' => 'Sample data generated', 'detail' => count($seedData) . ' table(s)']);

    $report(['stage' => 'insert', 'status' => 'start', 'label' => 'Inserting rows…']);
    $auth   = ai_detect_auth($schema);
    $seeded = ai_insert_seed_data($pdo, $catalog, $projectId, $seedData, $auth['table'] ? [$auth['table']] : []);
    $report(['stage' => 'insert', 'status' => 'done', 'label' => 'Done', 'detail' => $seeded ? implode(', ', $seeded) : 'No eligible tables found']);

    return [
        'seeded'        => $seeded,
        'usage'         => $client->getLastUsage(),
        'test_accounts' => array_map(fn($a) => ['identifier' => $a['identifier'], 'password' => $a['password']], $testAccounts),
    ];
}