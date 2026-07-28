<?php

declare(strict_types=1);



// ─── Pipeline plan-generation helpers (used by background worker) ────────────

function ai_generate_build_plan(object $client, string $prompt, ?array $approvedIntent, array $history): array
{
    $schemaUserMsg = $approvedIntent
        ? $prompt . "\n\n" . ai_intent_to_context($approvedIntent)
        : $prompt;

    $schemaPlan = $client->generateJsonWithHistory(AI_BUILD_SCHEMA_PROMPT, $history, $schemaUserMsg);
    $schemaPlan['frontend'] = ['files' => []];
    $schemaPlan = ai_sanitize_plan($schemaPlan);

    $validationError = ai_validate_plan($schemaPlan);
    if ($validationError) {
        $retryPrompt = $schemaUserMsg
            . "\n\nYour previous schema was rejected for this reason:\n  " . $validationError
            . "\nReturn a corrected schema that fixes exactly this problem.";
        $schemaPlan = $client->generateJsonWithHistory(AI_BUILD_SCHEMA_PROMPT, $history, $retryPrompt);
        $schemaPlan['frontend'] = ['files' => []];
        $schemaPlan = ai_sanitize_plan($schemaPlan);
        if (ai_validate_plan($schemaPlan) !== null) {
            throw new \RuntimeException('AI returned an invalid schema after retry: ' . ai_validate_plan($schemaPlan));
        }
    }

    // Pass 1.5 — design brief (best-effort; skip silently if it fails)
    $brief    = ai_generate_design_brief($client, $prompt, $schemaPlan);
    $briefCtx = ai_brief_to_context($brief);

    $frontendMsg    = "App description: {$prompt}\n\n"
                    . ($approvedIntent ? ai_intent_to_context($approvedIntent, 'build the frontend to satisfy') . "\n" : '')
                    . ($briefCtx ? "{$briefCtx}\n\n" : '')
                    . "Exact validated schema — use ONLY these column names in JS:\n"
                    . ai_schema_to_context($schemaPlan);
    $frontendResult = $client->generateJson(ai_bind_auth_placeholders(AI_BUILD_FRONTEND_PROMPT, $schemaPlan), $frontendMsg);

    $plan = $schemaPlan;
    $plan['frontend'] = ['files' => $frontendResult['files'] ?? []];
    foreach ($plan['frontend']['files'] as &$file) {
        $file['path'] = ltrim(preg_replace('#^\./+#', '', $file['path'] ?? ''), '/');
    }
    unset($file);
    return $plan;
}

// Stage 3+4 of a build: frontend code generation against an already-confirmed
// schema and design brief, then deterministic validation. Split out so the
// "Review" build flow can run this as its own job, after the user has
// confirmed the schema/design in the previous stage.
/** @param array $refs See ai_generate_intent()'s doc comment for the shape. */
function ai_run_build_frontend(array $schemaPlan, array $designBrief, string $prompt, object $client, array $config, callable $report, bool $validate = true, array $refs = [], ?array $approvedIntent = null): array
{
    // ── Stage 3: frontend — agentic tool-calling loop (search/read/write/
    // syntax-check), same machinery the edit agent uses, instead of a single
    // shot at the whole file set. Lets the model verify its own output (a
    // deterministic syntax check on every write) and read back a file it
    // wrote earlier before extending it, instead of hoping a one-shot
    // multi-file JSON blob comes back internally consistent.
    $report(['stage' => 'frontend', 'status' => 'start', 'label' => 'Generating frontend code…']);
    $frontendResult = ai_run_build_frontend_agentic($schemaPlan, $designBrief, $prompt, $client, $config, $report, $refs, $approvedIntent);
    $aiTrace   = $frontendResult['aiTrace'];
    $feUsage   = $frontendResult['usage'];

    $plan = $schemaPlan;
    $plan['frontend'] = ['files' => $frontendResult['files'] ?? []];
    foreach ($plan['frontend']['files'] as &$file) {
        $file['path'] = ltrim(preg_replace('#^\./+#', '', $file['path'] ?? ''), '/');
    }
    unset($file);
    $report(['stage' => 'frontend', 'status' => 'done', 'label' => 'Frontend generated', 'detail' => count($plan['frontend']['files']) . ' file' . (count($plan['frontend']['files']) === 1 ? '' : 's')]);

    // ── Stage 4: validate (deterministic; AI only explains, never detects) ──
    $validation = [];
    if ($validate) {
        $report(['stage' => 'validate', 'status' => 'start', 'label' => 'Checking for mismatches…']);
        $validation = ai_validator_check_project($plan, $plan['frontend']['files']);
        if (array_filter($validation, fn($f) => $f['severity'] === 'error')) {
            $validation = ai_validator_explain_findings($validation, $client);
        }
        $errCount  = count(array_filter($validation, fn($f) => $f['severity'] === 'error'));
        $warnCount = count(array_filter($validation, fn($f) => $f['severity'] === 'warning'));
        $report(['stage' => 'validate', 'status' => 'done',
                 'label'  => $validation ? 'Validation found issues' : 'No issues found',
                 'detail' => $validation ? "{$errCount} error(s), {$warnCount} warning(s)" : '']);
    }

    $summary = [
        'project_name'   => $plan['project_name'],
        'tables'         => array_map(fn($t) => $t['name'] . ' (' . count($t['columns'] ?? []) . ' cols)', $plan['tables']),
        'frontend_files' => count($plan['frontend']['files'] ?? []),
    ];

    return ['plan' => $plan, 'summary' => $summary, 'usage' => $feUsage, 'aiTrace' => $aiTrace, 'validation' => $validation];
}

// Renders the agent's current in-progress files in a real headless browser
// via a disposable, isolated preview directory — never the project's real
// staging/live site, so a mid-generation smoke test can never clobber what a
// user might currently be looking at. Catches exactly the class of bug
// syntax_check/validate_frontend cannot: a file that parses fine and looks
// correct but THROWS at runtime. Confirmed live: this is precisely what let
// a calculator app ship with a "this.loadState is not a function" crash
// that nobody caught until a human opened a real browser after deploy.
// Uses a sentinel project id (real api.* calls 404 against it) on purpose —
// this checks the app doesn't crash when the backend is unavailable, not
// that seeded data round-trips; a real end-to-end data check is what the
// separate browser-test-agent is for, post-deploy.
function ai_smoke_test_files(array $frontendFiles, array $config, ?array $authInfo = null): array
{
    $token = $config['BROWSERLESS_TOKEN'] ?? '';
    if (!$token) {
        return ['ok' => null, 'error' => 'Browserless not configured on this server — smoke_test is unavailable'];
    }
    $sitesPath = rtrim((string)($config['SITES_PATH'] ?? ''), '/');
    if ($sitesPath === '') {
        return ['ok' => null, 'error' => 'SITES_PATH not configured on this server — smoke_test is unavailable'];
    }

    // Real deployed sites are only servable at all through a narrow path
    // shape: both the web server's rewrite rule and site-serve.php's own
    // regex require exactly sites/s{numeric}/(current|staging)/... —
    // anything else 403s before PHP even runs. A fake numeric ID in a range
    // real auto-increment site IDs will never reach works fine for this:
    // site-serve.php only hits the database at all as an SPA-fallback when
    // the requested file is missing, and since index.html always exists
    // here, that path never triggers — no real site or DB row needed.
    $previewSiteId = 900000000 + random_int(0, 99999999);
    $previewRoot   = $sitesPath . '/s' . $previewSiteId;
    $previewDir    = $previewRoot . '/staging';

    if (!mkdir($previewDir, 0755, true)) {
        return ['ok' => null, 'error' => 'Cannot create preview directory'];
    }

    try {
        $files = ai_inject_canonical_frontend_files($frontendFiles, $authInfo);
        foreach ($files as $fileDef) {
            $relPath = ltrim((string)($fileDef['path'] ?? ''), '/');
            if ($relPath === '') continue;
            $fullPath = \SupaBein\Deploy::normalizePath($previewDir . '/' . $relPath);
            if (!str_starts_with($fullPath, $previewDir . '/')) continue; // unsafe path — same traversal guard as a real deploy, skip silently
            $parentDir = dirname($fullPath);
            if (!is_dir($parentDir)) mkdir($parentDir, 0755, true);
            $rawContent = (string)($fileDef['content'] ?? '');
            if ($relPath === 'index.html') $rawContent = ai_ensure_error_script_tag($rawContent);
            $content = str_replace('__SB_PID__', '0', $rawContent);
            file_put_contents($fullPath, $content);
        }

        $previewUrl = rtrim((string)($config['API_BASE_URL'] ?? ''), '/') . '/sites/s' . $previewSiteId . '/staging/';
        $script = ai_fetch_page_script_generate($previewUrl, $token, '/', false);
        $result = ai_fetch_page_run($script, $config);

        // Fold the platform's own console-error capture into the same
        // pass/fail signal so the model doesn't have to separately notice a
        // non-empty console_errors array on an otherwise "ok": true result.
        if (($result['ok'] ?? false) && !empty($result['console_errors'])) {
            $result['ok'] = false;
        }
        // Live-observed root cause of a build that should take ~3 minutes
        // taking 15+: on a real failure, this result handed back raw
        // console_errors/bodyText and left the model to infer its own next
        // move. That inference didn't happen — it re-ran smoke_test twice
        // more unchanged, then (once the stuck-repeat detector blocked
        // that) gave up investigating entirely and spent the rest of its
        // turn budget calling finish() with a fabricated "all done" claim,
        // never once calling read_file on the file its own error pointed
        // at. Naming the exact next action here — not just the symptom —
        // removes the inference step that was silently failing.
        if (($result['ok'] ?? true) === false) {
            // Split into a raw location (for the loop to mechanically check
            // whether that exact file was actually edited afterward -- see
            // $lastFailedFile below) and a human-readable instruction (for
            // the model). A hint the model can ignore was only half the
            // fix; next_step_file is what makes the other half enforceable.
            $failingLocation = ai_smoke_test_extract_failing_location($result);
            $result['next_step_file'] = $failingLocation[0] ?? null;
            $result['next_step'] = ai_smoke_test_next_step_hint($failingLocation);
        }
        return $result;
    } finally {
        \SupaBein\Deploy::rrmdir($previewRoot);
    }
}

// A stack frame in a REAL deployed error is a full URL through this
// preview's own /staging/ path (see ai_smoke_test_files() above) --
// anchoring on that marker pulls out just the project-relative path (e.g.
// "core/api.js"), not the whole domain+id+path blob a bare ".js" match
// would grab. Returns [file, line] or null if nothing matched.
function ai_smoke_test_extract_failing_location(array $result): ?array
{
    $errors = $result['console_errors'] ?? [];
    if (!is_array($errors)) return null;
    foreach ($errors as $err) {
        if (!is_string($err)) continue;
        if (preg_match('#/(?:staging|current)/([\w./-]+\.js):(\d+)#', $err, $m)
            || preg_match('#(?:^|[\s(])([\w./-]*[\w-]+\.js):(\d+)#', $err, $m)) {
            return [$m[1], (int)$m[2]];
        }
    }
    return null;
}

// A failing smoke_test carries console_errors/bodyText but that raw data
// alone isn't a next action — the model has to independently infer "there's
// a 404 in core/api.js:39, I should read_file that". Live-observed: it
// doesn't reliably make that jump; the file:line is usually already sitting
// right there in the error text (browsers report it), so extracting it and
// stating the next tool call directly turns an inference into an instruction.
function ai_smoke_test_next_step_hint(?array $failingLocation): string
{
    if ($failingLocation !== null) {
        [$file, $line] = $failingLocation;
        return "A console error points at {$file} line {$line}. Call read_file on {$file} next, find "
             . 'and fix the specific problem it reports, then re-run smoke_test to confirm it is '
             . 'actually clean before doing anything else. Do not re-run smoke_test unchanged again, '
             . "and do not call finish, until you've made a real code change.";
    }
    return 'smoke_test found a real problem — read the file most likely responsible (based on the bodyText/'
         . 'console_errors above) and fix it before doing anything else. Do not re-run smoke_test unchanged '
         . "and do not call finish until you've made an actual code change.";
}

// ── Build frontend agent: the loop itself ────────────────────────────────────
// Same ReAct-style loop and tool executor (ai_run_edit_agent_tool) as the edit
// agent above, starting from zero files. Returns ['files'=>[...], 'aiTrace'=>[...],
// 'usage'=>[...]] — the exact shape ai_run_build_frontend()'s old single-shot
// $frontendResult had, so the surgical swap there needs no other changes.
/**
 * @param array $refs See ai_generate_intent()'s doc comment for the shape.
 * @param array|null $approvedIntent The structured actors/stories from
 *   ai_generate_intent() (or the user's own confirmed review card), when
 *   available. Without this, the agent only ever sees the original one-line
 *   prompt and has no way to know what it's actually being tested against
 *   later (e.g. a "custom step value" story the requirements stage inferred
 *   but the free-text prompt never mentioned) -- it can't aim for acceptance
 *   criteria it was never shown. Live-observed: a trivial counter app failed
 *   6/6 post-deploy story tests on its first pass for exactly this reason.
 */
function ai_run_build_frontend_agentic(
    array $schemaPlan, array $designBrief, string $prompt, object $client, array $config, callable $report, array $refs = [], ?array $approvedIntent = null
): array {
    $briefCtx    = ai_brief_to_context($designBrief);
    $hasRefs     = !empty($refs['attachments']) || !empty($refs['context']);
    $agentPrompt = ai_bind_auth_placeholders(AI_BUILD_FRONTEND_AGENT_SYSTEM_PROMPT, $schemaPlan)
                 . ($hasRefs ? ai_attachment_instruction_note() : '');
    $schemaCtx   = ai_schema_to_context($schemaPlan);
    $intentCtx   = $approvedIntent ? ai_intent_to_context($approvedIntent, 'build the frontend to satisfy') : '';

    $byPath       = []; // a fresh build starts with nothing on disk
    $changedFiles = [];
    $readPaths    = [];
    $aiTrace      = [];
    $finished     = false;
    $totalUsage   = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

    $turnMsg = "App description: {$prompt}\n\n"
             . ($intentCtx ? "{$intentCtx}\n" : '')
             . ($briefCtx ? "{$briefCtx}\n\n" : '')
             . "Exact validated schema — use ONLY these column names in JS:\n{$schemaCtx}\n\n"
             . (!empty($refs['context']) ? "{$refs['context']}\n\n" : '')
             . 'Respond with your first tool action.';
    $loopHistory = [];
    $recentCalls = [];
    $consecutiveParseFailures = 0;
    $lastSmokeTestOk = null; // null = never called this session; true/false = its last result
    $lastSmokeTestWasConnectionError = false; // true if the last failure was Browserless itself, not the app
    $hasPlanned = false; // must submit a "plan" action before any other tool -- see gate below
    $consecutiveFinishRejections = 0; // escalates a repeatedly-rejected finish() -- see gate below
    $lastFailedFile = null; // the file a failing smoke_test's console_errors pointed at, if any
    $lastFailedFileSnapshot = null; // that file's content at the moment of the failure -- see finish() gate below

    for ($turn = 1; $turn <= AI_BUILD_FRONTEND_AGENT_MAX_TURNS; $turn++) {
        $_t0 = microtime(true);
        try {
            // Reference images/PDFs only need to be seen once — attaching
            // them to every turn of a loop that can run dozens of tool calls
            // would multiply the token cost of the request for no benefit,
            // since the model already has whatever it extracted from them in
            // its own running context after turn 1.
            $turnAttachments = $turn === 1 ? ($refs['attachments'] ?? []) : [];
            $action = $client->generateJsonWithHistory($agentPrompt, $loopHistory, $turnMsg, $turnAttachments);
        } catch (\Throwable $e) {
            if (ai_is_unrecoverable_provider_error($e->getMessage())) {
                throw new \RuntimeException('AI provider error during frontend generation: ' . $e->getMessage());
            }
            // Same recoverable-turn treatment as the edit agent — a truncated
            // write_file response cutting off mid-JSON shouldn't fail the
            // whole build and discard every file already staged.
            $ms = (int)((microtime(true) - $_t0) * 1000);
            $aiTrace[] = ['stage' => 'frontend_agent', 'system' => $agentPrompt, 'history' => [],
                'user_msg' => mb_strlen($turnMsg) > 3000 ? mb_substr($turnMsg, 0, 3000) : $turnMsg,
                'response' => ['error' => $e->getMessage()], 'tokens' => $client->getLastUsage(), 'ms' => $ms, 'retry' => true, 'error' => $e->getMessage()];
            if (ai_agent_is_rate_limited($e->getMessage())) {
                $report(['stage' => 'frontend', 'status' => 'active', 'label' => 'Generating frontend code…',
                    'detail' => 'Rate limited by the AI provider — waiting…']);
            } else {
                $report(['stage' => 'frontend', 'status' => 'active', 'label' => 'Generating frontend code…',
                    'detail' => 'Response was invalid, retrying…']);
                $turnMsg .= ai_agent_note_parse_failure($consecutiveParseFailures, $e->getMessage());
            }
            continue;
        }
        $consecutiveParseFailures = 0;
        $ms    = (int)((microtime(true) - $_t0) * 1000);
        $usage = $client->getLastUsage();
        foreach ($totalUsage as $k => $v) $totalUsage[$k] = $v + (int)($usage[$k] ?? 0);

        $tool = is_array($action) ? (string)($action['tool'] ?? '') : '';
        $args = is_array($action) && is_array($action['args'] ?? null) ? $action['args'] : [];

        $aiTrace[] = ['stage' => 'frontend_agent', 'system' => $agentPrompt, 'history' => [],
            'user_msg' => mb_strlen($turnMsg) > 3000 ? mb_substr($turnMsg, 0, 3000) : $turnMsg,
            'response' => $action, 'tokens' => $usage, 'ms' => $ms, 'retry' => false];

        // The 'detail' string here is rendered directly into the real
        // dashboard's AI panel (dashboard/assets/app.js's progress-detail
        // div) -- a live user watching their app get built, not a
        // diagnostics surface. Keep it to the short label; the actual write
        // content goes to the server log instead, via
        // ai_log_agent_write_content() below, a channel with zero UI exposure.
        $report(['stage' => 'frontend', 'status' => 'active', 'label' => 'Generating frontend code…',
            'detail' => ai_edit_agent_step_label($tool, $args)]);
        ai_log_agent_write_content('frontend', $tool, $args);

        $loopHistory[] = ['role' => 'user', 'text' => $turnMsg];
        $loopHistory[] = ['role' => 'model', 'text' => ai_agent_history_action_json($action)];
        $loopHistory   = ai_agent_trim_history($loopHistory);

        // Force a plan before any exploratory or file-writing action. Cheap
        // forced-planning step aimed at exactly the pattern seen live: turns
        // spent reacting incrementally (list_files, read_file, a write, more
        // reads...) instead of committing once to which files serve which
        // stories and then executing that. "plan" itself touches no files —
        // it's a pure commitment step, not dispatched through the real tool
        // executor below.
        if ($tool === 'plan') {
            $hasPlanned = true;
            $turnMsg = json_encode(['tool' => 'plan', 'result' => ['ok' => true,
                'note' => 'Plan received. Proceed to write these files now.']]);
            continue;
        }
        if (!$hasPlanned) {
            $turnMsg = json_encode(['tool' => $tool, 'error' =>
                'Your first action must be "plan" — list every file you intend to write and which user ' .
                'story each one serves. Call plan now, then proceed with this action afterward if it\'s ' .
                'still needed.']);
            continue;
        }

        if ($tool === 'finish') {
            if (empty($changedFiles)) {
                // Nothing written at all — reject and force at least one file
                // before letting it stop, the same "must actually do
                // something" guarantee ai_validate_delta gives the edit
                // agent's finish.
                $turnMsg = json_encode(['tool' => 'finish', 'error' => ai_agent_note_finish_rejected(
                    $consecutiveFinishRejections,
                    "No files have been written yet — write_file the app's frontend before calling finish."
                )]);
                continue;
            }
            // A smoke_test that came back broken and was never fixed (or
            // re-checked) must not be allowed to silently ship — this is
            // exactly the gap that let the calculator app's "this.loadState
            // is not a function" crash reach a real deploy: the tool to
            // catch it existed by the time this check was added, but
            // nothing stopped finish() from being called anyway. But a
            // Browserless CONNECTION failure (quota, network) is not
            // evidence of an app bug -- live-observed: the model correctly
            // diagnosed this exact case every single time (its own "thought"
            // said so) yet had no way to satisfy this gate since re-running
            // smoke_test hit the same persistent quota exhaustion again, and
            // was rejected 20 turns in a row until the turn limit forced a
            // finish anyway. Only a REAL smoke_test failure blocks finish().
            //
            // A rejection here used to just repeat the same static message
            // forever -- live-observed: one build called finish() 14 times
            // straight against an unresolved failure, each time with an
            // identical fabricated "all done" justification, never once
            // re-running smoke_test to check. ai_agent_note_finish_rejected()
            // escalates to a forceful, specific instruction once that
            // pattern repeats instead of leaving it to grind to the turn
            // limit.
            if ($lastSmokeTestOk === false && !$lastSmokeTestWasConnectionError) {
                // Live-observed: the message alone wasn't enough either -- a
                // model can read "fix the actual problem" and still call
                // finish() again with nothing changed, believing it already
                // fixed it. Checking the implicated file's actual content
                // against its state at failure time makes this a fact, not
                // an instruction the model has to choose to follow.
                $fileUnaddressed = $lastFailedFile !== null
                    && ($changedFiles[$lastFailedFile] ?? null) === $lastFailedFileSnapshot;
                $reason = $fileUnaddressed
                    ? "smoke_test's last failure pointed at {$lastFailedFile}, and that file is unchanged since " .
                      "then. Call read_file on {$lastFailedFile}, make an actual fix, then smoke_test again " .
                      'before calling finish.'
                    : 'Your last smoke_test came back with errors and you called finish() without fixing them or ' .
                      're-running smoke_test clean. Fix the actual problem it reported, then call smoke_test again ' .
                      'to confirm it is clean before calling finish.';
                $turnMsg = json_encode(['tool' => 'finish', 'error' =>
                    ai_agent_note_finish_rejected($consecutiveFinishRejections, $reason)]);
                continue;
            }
            $finished = true;
            break;
        }

        if (ai_agent_detect_stuck_repeat($recentCalls, $tool, $args)) {
            $turnMsg = json_encode(['tool' => $tool, 'error' =>
                'You have called this exact action with these exact arguments several times in a row with no ' .
                'different result to show for it. Repeating it again will not work either — try a genuinely ' .
                'different action (a different file, a different search, or finish with whatever is actually ' .
                'ready) instead of this one.']);
            continue;
        }

        // Reached only for a real, non-finish tool call (finish() always
        // continues or breaks above) — this is exactly the "did something
        // else" signal ai_agent_note_finish_rejected()'s escalation should
        // reset on, so a model that DOES follow the smoke_test/read_file
        // guidance in between isn't still treated as stuck.
        $consecutiveFinishRejections = 0;

        // No real project exists yet at this stage of a build — 0 is a safe
        // placeholder; this agent's own system prompt never advertises
        // check_policy, so it has no route to actually call it. validate_frontend
        // works fine here though — it only needs the schema plan, not a live DB.
        $toolResult = ai_run_edit_agent_tool($tool, $args, $byPath, $changedFiles, $readPaths, $config, 0, $schemaPlan);
        if ($tool === 'smoke_test') {
            $lastSmokeTestOk = $toolResult['result']['ok'] ?? null;
            $lastSmokeTestWasConnectionError = !empty($toolResult['result']['connection_error']);
            if ($lastSmokeTestOk === false) {
                $lastFailedFile = $toolResult['result']['next_step_file'] ?? null;
                $lastFailedFileSnapshot = $lastFailedFile !== null ? ($changedFiles[$lastFailedFile] ?? null) : null;
                // Report the REAL outcome, not just the static pre-dispatch
                // label — this is the only channel available while the job
                // is still running (see ai_smoke_test_failure_progress_detail()).
                $report(['stage' => 'frontend', 'status' => 'active', 'label' => 'Generating frontend code…',
                    'detail' => ai_smoke_test_failure_progress_detail($toolResult['result'] ?? [])]);
            } else {
                $lastFailedFile = null;
                $lastFailedFileSnapshot = null;
            }
        }
        $turnMsg = json_encode($toolResult);
    }

    if (!$finished) {
        // Turn budget exhausted without a finish() — force-finish with
        // whatever's staged rather than hang or hard-fail the whole build.
        $report(['stage' => 'frontend', 'status' => 'active', 'label' => 'Generating frontend code…',
            'detail' => 'Turn limit reached — finishing with what was staged']);
    }

    $files = array_map(fn($p) => ['path' => $p, 'content' => $changedFiles[$p]], array_keys($changedFiles));
    return ['files' => $files, 'aiTrace' => $aiTrace, 'usage' => $totalUsage];
}