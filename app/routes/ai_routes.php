<?php

declare(strict_types=1);

require_once SUPABEIN_ROOT . '/app/core/max_tokens_probe.php';
require_once SUPABEIN_ROOT . '/app/core/gemini_client.php';
require_once SUPABEIN_ROOT . '/app/core/openrouter_client.php';
require_once SUPABEIN_ROOT . '/app/core/nvidia_client.php';
require_once SUPABEIN_ROOT . '/app/core/anthropic_client.php';
require_once SUPABEIN_ROOT . '/app/core/groq_client.php';
require_once SUPABEIN_ROOT . '/app/core/zhipu_client.php';
require_once SUPABEIN_ROOT . '/app/core/zhipu_image_client.php';
require_once SUPABEIN_ROOT . '/app/core/fallback_ai_client.php';
require_once SUPABEIN_ROOT . '/app/core/deploy.php';
require_once SUPABEIN_ROOT . '/app/core/ai_validator.php';
require_once SUPABEIN_ROOT . '/app/core/storage.php';

require_once __DIR__ . '/ai/ai_attachments.php';
require_once __DIR__ . '/ai/ai_deploy.php';
require_once __DIR__ . '/ai/ai_edit_gen.php';
require_once __DIR__ . '/ai/ai_frontend_gen.php';
require_once __DIR__ . '/ai/ai_pipeline.php';
require_once __DIR__ . '/ai/ai_prompts.php';
require_once __DIR__ . '/ai/ai_providers.php';
require_once __DIR__ . '/ai/ai_schema.php';
require_once __DIR__ . '/ai/ai_shared.php';
require_once __DIR__ . '/ai/ai_testing.php';



// ─── Route registration ──────────────────────────────────────────────────────

function register_ai_routes(\SupaBein\Router $router): void
{
    $router->post('/v1/ai/build', function (array $req): void {
        set_time_limit(420);

        $config  = \App::get('config');
        $userId  = (int)$req['auth']['user_id'];

        // ── 1. Validate inputs ────────────────────────────────────────────────
        $prompt = ai_validate_prompt($req['body']);

        // Reference files (logo to match, sample document/screenshot to build
        // from, etc.) — see ai_prepare_attachments_for_ai()'s doc comment.
        $refs = ai_prepare_attachments_for_ai(ai_validate_attachments($req['body']['attachments'] ?? null));
        $hasRefs = !empty($refs['attachments']) || $refs['context'] !== '';
        $attachmentNote = $hasRefs ? ai_attachment_instruction_note() : '';

        // Optional human-review controls (both default off → current behaviour unchanged):
        //   review:true  → generate + cap the intent, return it, build NOTHING (caller edits it)
        //   intent:{...}  → an approved/edited intent; lock it into the schema pass and build
        $review         = !empty($req['body']['review']);
        $approvedIntent = (isset($req['body']['intent']) && is_array($req['body']['intent']))
                        ? $req['body']['intent'] : null;

        // ── 2. Call AI (two passes) ──────────────────────────────────────────
        $provider = $req['body']['provider'] ?? null;
        $model    = $req['body']['model']    ?? null;
        $gemini = make_ai_client($config, $provider, $model);

        // ── Review gate: return capped intent for the caller to edit, build nothing ──
        if ($review && !$approvedIntent) {
            sb_log('ai_build', 'Review requested: generating intent only', ['user_id' => $userId]);
            try {
                $intent = ai_generate_intent($gemini, $prompt, [], $refs);
            } catch (\RuntimeException $e) {
                ai_abort_error('intent', $e->getMessage());
            }
            json_out(['mode' => 'intent', 'intent' => $intent, 'usage' => $gemini->getLastUsage()]);
            return;
        }

        // If an approved intent was supplied, lock it into the schema prompt as fixed scope.
        $schemaUserMsg = $prompt;
        if ($approvedIntent) {
            $schemaUserMsg = $prompt . "\n\n" . ai_intent_to_context($approvedIntent);
        }
        if ($refs['context'] !== '') $schemaUserMsg .= "\n\n" . $refs['context'];

        sb_log('ai_build', 'Calling AI (pass 1: schema)', ['user_id' => $userId, 'provider' => $provider, 'model' => $model, 'locked_intent' => (bool)$approvedIntent]);

        // Pass 1 — schema only (one self-correcting retry on validation failure)
        try {
            $schemaPlan = $gemini->generateJson(AI_BUILD_SCHEMA_PROMPT . $attachmentNote, $schemaUserMsg, $refs['attachments']);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            sb_log('ai_build', 'AI error (pass 1): ' . $msg, ['user_id' => $userId]);
            ai_abort_error('schema', $msg);
        }

        // ── 3. Validate schema (sanitize → validate → retry once with the error) ──
        $schemaPlan['frontend'] = ['files' => []];
        $schemaPlan = ai_sanitize_plan($schemaPlan);
        $validationError = ai_validate_plan($schemaPlan);
        if ($validationError) {
            sb_log('ai_build', 'Schema invalid, retrying with feedback: ' . $validationError, ['user_id' => $userId]);
            try {
                $retryPrompt = $schemaUserMsg
                    . "\n\nYour previous schema was rejected for this reason:\n  " . $validationError
                    . "\nReturn a corrected schema that fixes exactly this problem.";
                $schemaPlan = $gemini->generateJson(AI_BUILD_SCHEMA_PROMPT . $attachmentNote, $retryPrompt, $refs['attachments']);
                $schemaPlan['frontend'] = ['files' => []];
                $schemaPlan = ai_sanitize_plan($schemaPlan);
                $validationError = ai_validate_plan($schemaPlan);
            } catch (\RuntimeException $e) {
                ai_abort_error('schema_retry', $e->getMessage());
            }
        }
        if ($validationError) {
            sb_log('ai_build', 'Schema validation failed after retry: ' . $validationError, ['plan_keys' => array_keys($schemaPlan)]);
            abort(422, 'AI returned an invalid schema: ' . $validationError);
        }

        // Pass 1.5 — design brief (best-effort)
        sb_log('ai_build', 'Calling AI (pass 1.5: design brief)', ['user_id' => $userId]);
        $brief    = ai_generate_design_brief($gemini, $prompt, $schemaPlan, $refs);
        $briefCtx = ai_brief_to_context($brief);

        // Pass 2 — frontend with exact (post-sanitize) column names + bound auth.js
        sb_log('ai_build', 'Calling AI (pass 2: frontend)', ['user_id' => $userId]);
        $frontendMsg = "App description: {$prompt}\n\n"
                     . ($briefCtx ? "{$briefCtx}\n\n" : '')
                     . "Exact validated schema — use ONLY these column names in JS:\n"
                     . ai_schema_to_context($schemaPlan)
                     . ($refs['context'] !== '' ? "\n\n{$refs['context']}" : '');
        try {
            $frontendResult = $gemini->generateJson(ai_bind_auth_placeholders(AI_BUILD_FRONTEND_PROMPT, $schemaPlan) . $attachmentNote, $frontendMsg, $refs['attachments']);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            sb_log('ai_build', 'AI error (pass 2): ' . $msg, ['user_id' => $userId]);
            ai_abort_error('frontend', $msg);
        }

        $plan = $schemaPlan;
        $plan['frontend'] = ['files' => $frontendResult['files'] ?? []];
        foreach ($plan['frontend']['files'] as &$file) {
            $file['path'] = ltrim(preg_replace('#^\./+#', '', $file['path'] ?? ''), '/');
        }
        unset($file);

        // ── 4-7. Execute build ────────────────────────────────────────────────
        $result = ai_execute_build($plan, $userId);
        if (!empty($refs['pending_assets']) && !empty($result['project']['id'])) {
            ai_upload_pending_assets($refs['pending_assets'], (int)$result['project']['id']);
        }
        json_out($result, 201);

    }, ['auth_middleware']);

    // ── AI Intent: return capped actors + user stories for review (builds nothing) ──
    $router->post('/v1/ai/intent', function (array $req): void {
        set_time_limit(420);

        $config = \App::get('config');
        $userId = (int)$req['auth']['user_id'];

        $prompt = ai_validate_prompt($req['body']);

        // Optional prior turns for multi-turn context (capped at 20)
        $history = [];
        foreach (array_slice((array)($req['body']['history'] ?? []), 0, 20) as $turn) {
            if (!is_array($turn)) continue;
            $role = $turn['role'] ?? '';
            $text = trim($turn['text'] ?? '');
            if (!in_array($role, ['user', 'model'], true) || $text === '') continue;
            $history[] = ['role' => $role, 'text' => $text];
        }

        $client = make_ai_client($config, $req['body']['provider'] ?? null, $req['body']['model'] ?? null);
        try {
            $intent = ai_generate_intent($client, $prompt, $history);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            sb_log('ai_intent', 'AI error: ' . $msg, ['user_id' => $userId]);
            if (str_contains($msg, 'credits') || str_contains($msg, 'quota')) abort(402, $msg);
            abort(502, 'AI generation failed: ' . $msg);
        }

        // Caller edits the returned intent, then POSTs it back to /v1/ai/build as body.intent
        json_out(['mode' => 'intent', 'intent' => $intent, 'usage' => $client->getLastUsage()]);

    }, ['auth_middleware']);

    // ── AI Edit: modify an existing project ────────────────────────────────────
    $router->post('/v1/ai/edit', function (array $req): void {
        set_time_limit(420);

        $config  = \App::get('config');
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];

        $projectId = (int)($req['body']['project_id'] ?? 0);
        if (!$projectId) abort(422, 'project_id is required');
        $prompt = ai_validate_prompt($req['body']);

        $project = $catalog->getProjectById($projectId, $userId);
        if (!$project) abort(404, 'Project not found');

        // Build context snapshot of existing schema
        $existingTables = $catalog->listTables($projectId);
        $schemaLines = [];
        foreach ($existingTables as $tbl) {
            $cols = array_map(fn($c) => $c['name'] . ' ' . $c['type'], $catalog->listColumns($tbl['id']));
            $schemaLines[] = '  Table "' . $tbl['logical_name'] . '": id (INT auto), ' . implode(', ', $cols) . ', created_at (DATETIME auto)';
        }
        $schemaContext = $schemaLines ? implode("\n", $schemaLines) : '  (no tables yet)';

        // Reference files (a screenshot of the change wanted, a document to
        // pull new copy from, etc.) — see ai_prepare_attachments_for_ai().
        // $projectId already exists here, so an uploaded image is uploaded
        // to real storage immediately and the AI gets its real URL.
        $refs = ai_prepare_attachments_for_ai(ai_validate_attachments($req['body']['attachments'] ?? null), $projectId);
        $hasRefs = !empty($refs['attachments']) || $refs['context'] !== '';

        $userMessage = "Current schema:\n" . $schemaContext . "\n\nRequested change: " . $prompt;
        if ($refs['context'] !== '') $userMessage .= "\n\n" . $refs['context'];

        $gemini = make_ai_client($config, $req['body']['provider'] ?? null, $req['body']['model'] ?? null);
        $existingSchema   = ai_schema_from_db($projectId, $catalog);
        $editSystemPrompt = ai_bind_auth_placeholders(AI_EDIT_SYSTEM_PROMPT, $existingSchema)
                          . ($hasRefs ? ai_attachment_instruction_note() : '');
        try {
            $delta = $gemini->generateJson($editSystemPrompt, $userMessage, $refs['attachments']);

            // Validate the delta; one self-correcting retry with the reason fed back.
            $deltaError = ai_validate_delta($delta, $existingSchema);
            if ($deltaError) {
                sb_log('ai_edit', 'Delta invalid, retrying with feedback: ' . $deltaError, ['project_id' => $projectId]);
                $retryMsg = $userMessage
                    . "\n\nYour previous response was rejected for this reason:\n  " . $deltaError
                    . "\nReturn a corrected JSON delta that fixes exactly this problem and nothing else.";
                $delta = $gemini->generateJson($editSystemPrompt, $retryMsg, $refs['attachments']);
                $deltaError = ai_validate_delta($delta, $existingSchema);
                if ($deltaError) abort(422, 'AI returned an invalid edit: ' . $deltaError);
            }
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'credits') || str_contains($msg, 'quota')) abort(402, $msg);
            abort(502, 'AI generation failed: ' . $msg);
        }

        $result = ai_execute_edit($delta, $projectId, $userId);
        json_out($result);

    }, ['auth_middleware']);

    // ── AI Plan: generate a plan without executing ─────────────────────────────
    $router->post('/v1/ai/plan', function (array $req): void {
        set_time_limit(420);

        $config  = \App::get('config');
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];

        // ── Recovery mode: fast-path before normal plan flow ──────────────────
        if (($req['body']['mode'] ?? '') === 'recover') {
            $error    = trim($req['body']['error']   ?? '');
            $origPlan = $req['body']['plan']          ?? [];
            $partial  = $req['body']['partial']       ?? [];

            if (!$error || !is_array($origPlan) || empty($origPlan)) {
                abort(422, 'error and plan are required for recover mode');
            }

            $gemini          = make_ai_client($config, $req['body']['provider'] ?? null, $req['body']['model'] ?? null);
            $tablesCompleted = array_column($partial['tables'] ?? [], 'name');
            $failedAt        = $partial['failed_at'] ?? 'unknown';
            $planJson        = json_encode($origPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $context         = "Original build plan:\n{$planJson}"
                             . "\n\nBuild error: {$error}"
                             . "\n\nState before rollback (project was fully rolled back, nothing persists):"
                             . "\n  Project name: " . ($partial['project']['name'] ?? 'not created')
                             . "\n  Tables completed before error: " . (implode(', ', $tablesCompleted) ?: 'none')
                             . "\n  Failed at table: {$failedAt}";

            $recoverPrompt = <<<'PROMPT'
You are a SupaBein AI error recovery assistant. A database build failed. Analyze the error and propose 2–4 concrete, immediately applicable fixes.

Return ONLY valid JSON:
{
  "diagnosis": "One or two sentences explaining the root cause in plain language",
  "options": [
    {
      "id": "opt_1",
      "label": "Short label (5 words max)",
      "description": "One sentence: exactly what this option changes",
      "plan": { ...complete corrected build plan... }
    }
  ]
}

Rules:
- Each plan must be COMPLETE: project_name, subdomain, tables (with all columns+policies), frontend — same as the original
- Include ALL original tables in every plan; the project was rolled back so everything must be rebuilt from scratch
- Only change what is necessary to fix the error; everything else stays identical
- Common fixes for "Unsafe default value": (1) set default to null, (2) use a safe string literal like "pending", (3) remove the column if non-essential
- Common fixes for "already exists" or 409: change project_name/subdomain
- Do NOT propose vague options like "review manually" — every option must be auto-applicable
PROMPT;

            try {
                $result = $gemini->generateJson($recoverPrompt, $context);
            } catch (\RuntimeException $e) {
                ai_abort_error('recover', $e->getMessage());
            }

            json_out([
                'mode'      => 'recover',
                'diagnosis' => $result['diagnosis'] ?? 'An error occurred during the build.',
                'options'   => $result['options']   ?? [],
                'usage'     => $gemini->getLastUsage(),
            ]);
        }

        // ── Suggest mode: return proposed changes for user review ─────────────
        if (($req['body']['mode'] ?? '') === 'suggest') {
            $prompt    = trim($req['body']['prompt']     ?? '');
            $projectId = isset($req['body']['project_id']) ? (int)$req['body']['project_id'] : null;
            if (!$prompt)    abort(422, 'prompt is required');
            if (!$projectId) abort(422, 'project_id is required for suggest mode');

            $project = $catalog->getProjectById($projectId, $userId);
            if (!$project) abort(404, 'Project not found');

            $gemini         = make_ai_client($config, $req['body']['provider'] ?? null, $req['body']['model'] ?? null);
            $existingSchema = ai_schema_from_db($projectId, $catalog);
            $schemaCtx      = ai_schema_to_context($existingSchema);
            $currentFiles   = ai_read_frontend_files($config, $catalog, $projectId, $prompt);

            // This call has no tools — it's one shot, no way to query the
            // database itself — so without this it has to take a failing
            // test's own narrative at face value. Live-caught: a test agent
            // (which also can't see the database, only the rendered page)
            // reported "the store has no products loaded" for a page that
            // showed zero items — an inference from what it saw, not
            // something it verified — and Resolve, with no way to check
            // either, built a whole plan around re-seeding a table that
            // actually already had 10 real rows in it. The real bug (a
            // frontend filter comparing a boolean to the number 1) never got
            // a chance to be found, because nothing in this call's context
            // ever contradicted the false "no data" premise it was handed.
            // A cheap, deterministic COUNT(*) per table closes that gap.
            $rowCounts = [];
            foreach ($catalog->listTables($projectId) as $t) {
                $rowCounts[$t['table_name']] = $catalog->countTableRows($t['physical_name']);
            }
            $rowCountCtx = "\n\nActual current row counts (query results, not a guess — trust this over any "
                . "narrative in the user request about a table being \"empty\" or having \"no data\"):\n"
                . implode("\n", array_map(fn($k, $v) => "- {$k}: {$v} row(s)", array_keys($rowCounts), $rowCounts));

            $suggestContext = "Project: " . $project['name']
                . "\n\nExact schema:\n" . $schemaCtx
                . $rowCountCtx
                . $currentFiles
                . "\n\nUser request: " . $prompt;

            $suggestPrompt = <<<'PROMPT'
You are a SupaBein full-stack AI assistant reviewing an edit request.
Analyze the project schema, actual row counts, frontend files, and user request.
Return a list of specific, concrete changes that should be made.
Each suggestion should be a distinct, independently useful change.

Return ONLY valid JSON:
{
  "suggestions": [
    {
      "id": "s1",
      "label": "Short action title (max 60 chars)",
      "description": "What exactly will change and why (1-2 sentences)"
    }
  ]
}

Rules:
- 2-8 suggestions maximum
- Each suggestion must reference actual column names from the schema
- Be specific: "Add price column display to product cards" not "Update frontend"
- Include both schema and frontend changes if applicable
- The row counts given are ground truth, queried directly from the database —
  a test result or user message saying a table is "empty"/"has no data" is
  someone's (or something's) INFERENCE from what a page rendered, not a fact.
  If the row count for a table is already greater than 0, do NOT suggest
  seeding, inserting, or creating data for it — that will not fix anything
  and will just add more rows the same bug keeps hiding. Instead, read the
  frontend file(s) that fetch and render that table's data, and suggest the
  SPECIFIC code fix — e.g. a comparison that can never match the type the API
  actually returns (a strict `=== 1` check against a column the API
  serializes as a JSON boolean is a common one), a filter excluding every row,
  a route never registered, or an API call using the wrong table name.
PROMPT;

            try {
                $result = $gemini->generateJson($suggestPrompt, $suggestContext);
            } catch (\RuntimeException $e) {
                ai_abort_error('suggest', $e->getMessage());
            }

            json_out([
                'mode'        => 'suggest',
                'suggestions' => $result['suggestions'] ?? [],
                'usage'       => $gemini->getLastUsage(),
            ]);
        }

        $prompt    = ai_validate_prompt($req['body']);
        $projectId = isset($req['body']['project_id']) ? (int)$req['body']['project_id'] : null;

        // Prior conversation turns for multi-turn context (capped at 20 turns)
        $rawHistory = $req['body']['history'] ?? [];
        $history = [];
        foreach (array_slice((array)$rawHistory, 0, 20) as $turn) {
            if (!is_array($turn)) continue;
            $role = $turn['role'] ?? '';
            $text = trim($turn['text'] ?? '');
            if (!in_array($role, ['user', 'model'], true) || $text === '') continue;
            $history[] = ['role' => $role, 'text' => $text];
        }

        // ── If the frontend is in chat mode, skip build-intent detection ────────
        // This prevents "build a notepad" from triggering an actual build when the
        // user is just chatting and didn't flip the Build toggle.
        if ($req['body']['chatMode'] ?? false) {
            $isChat = true;
        } else {

        // ── Detect explicit build/create requests ────────────────────────────
        $isBuildRequest = (bool)preg_match(
            '/\b(build|create|make)\b.{0,40}\b(app|application|website|site|system|tool|platform|dashboard|blog|store|shop|api)\b/i',
            $prompt
        ) || (bool)preg_match('/\bi (want|need) (a |an |to build|to create|to make)/i', $prompt);

        // ── Detect conversational / info-seeking messages ─────────────────────
        $isChat = !$isBuildRequest && (
            // Pure greeting or acknowledgement
            preg_match('/^(hi|hello|hey|yo|sup|howdy|hiya|thanks|thank\s+you|ok|okay|sure|cool|great|nice|perfect|lol|haha)[\s!.,?]*$/i', $prompt)
            // Starts with an info-seeking opener
            || preg_match('/^(what|how (many|do|does|can|should|is)|why|can you|could you|tell me|explain|describe|give me|show me|list (my|the|all)?|do i|does (this|my|the)|is there|are there|which|when|where)\b/i', $prompt)
            // Any question mark
            || str_ends_with(rtrim($prompt), '?')
            // Short message with no build-intent keywords
            || (mb_strlen($prompt) < 30 && !preg_match('/\b(app|application|website|site|build|create|make|blog|store|shop|todo|task|system|tool|dashboard|tracker|manager|platform|api|database)\b/i', $prompt))
        );

        } // end else (chatMode check)

        // Auto-detect mode
        $diagnoseKeywords = ['why', 'error', 'failing', 'broken', 'wrong', 'issue', 'problem', 'debug', 'not working', 'failed'];
        if ($projectId === null) {
            $mode = 'build';
        } else {
            $lowerPrompt = strtolower($prompt);
            $isDiagnose = false;
            foreach ($diagnoseKeywords as $kw) {
                if (str_contains($lowerPrompt, $kw)) { $isDiagnose = true; break; }
            }
            $mode = $isDiagnose ? 'diagnose' : 'edit';
        }

        $gemini  = make_ai_client($config, $req['body']['provider'] ?? null, $req['body']['model'] ?? null);
        $aiTrace = [];   // AI-internal call log returned alongside the plan response

        // ── Handle chat / info mode ───────────────────────────────────────────
        if ($isChat) {
            // Always include the user's full project list
            $allProjects    = $catalog->listProjects($userId);
            $projectListStr = implode("\n", array_map(fn($p) => '  - ' . $p['name'] . ' (id:' . $p['id'] . ')', $allProjects));
            $globalContext  = 'Projects (' . count($allProjects) . " total):\n"
                . ($projectListStr ?: '  (none yet)');

            // Add schema detail for the currently selected project
            $projectContext = '';
            if ($projectId) {
                $proj = $catalog->getProjectById($projectId, $userId);
                if ($proj) {
                    $schemaLines = [];
                    foreach ($catalog->listTables($projectId) as $tbl) {
                        $cols = array_map(
                            fn($c) => $c['name'] . ' (' . $c['type'] . ')',
                            $catalog->listColumns($tbl['id'])
                        );
                        $policies    = $catalog->listPolicies($tbl['id']);
                        $policyStrs  = array_map(
                            fn($p) => $p['api_role'] . '.' . strtolower($p['operation']) . '=' . ($p['allowed'] ? 'allow' : 'deny'),
                            $policies
                        );
                        $schemaLines[] = '  ' . $tbl['logical_name']
                            . ': id, ' . implode(', ', $cols) . ', created_at'
                            . ($policyStrs ? ' [policies: ' . implode(', ', $policyStrs) . ']' : '');
                    }
                    $tableCount     = count($schemaLines);
                    $frontendFiles  = ai_read_frontend_files($config, $catalog, $projectId, $prompt);
                    $projectContext = "\n\nSelected project: \"" . $proj['name'] . "\"\nTables (" . $tableCount . "):\n"
                        . ($schemaLines ? implode("\n", $schemaLines) : '  (no tables yet)')
                        . $frontendFiles;
                }
            }

            $chatSystemPrompt = <<<'CHAT'
You are SupaBein AI, a knowledgeable assistant for SupaBein — a self-hosted PHP+MySQL BaaS platform.

Platform capabilities:
- Auto-generates REST CRUD APIs from table schemas at /v1/data/{table}
- JWT authentication: /v1/auth/signup, /v1/auth/login, /v1/auth/me, /v1/auth/logout
- Row-level access policies per table and role (user/anon) with optional constraint_sql (use :current_user_id, NOT auth.uid())
- Static frontend hosting with per-project subdomain routing
- AI panel: natural language → schema + frontend code generation
- Tables always have auto-managed `id` (INT auto-increment) and `created_at` (DATETIME) columns

If asked about the current project (tables, columns, policies, counts, or frontend code), use the provided project context to answer accurately.
If frontend files are provided, you can read, explain, and suggest edits to them.
Reply concisely and helpfully. Return ONLY valid JSON: {"message": "your reply"}
CHAT;

            $userQuestion = $globalContext . $projectContext . "\n\nQuestion: " . $prompt;

            try {
                $_t0 = microtime(true);
                $res = $gemini->generateJsonWithHistory($chatSystemPrompt, $history, $userQuestion);
                $aiTrace[] = ['stage' => 'chat', 'system' => $chatSystemPrompt, 'history' => $history, 'user_msg' => $userQuestion, 'response' => $res, 'tokens' => $gemini->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];
            } catch (\RuntimeException $e) {
                // Some models ignore the "reply as JSON" instruction for conversational
                // questions and just answer in plain text. A chat reply doesn't need the
                // JSON envelope to be useful, so fall back to the raw text rather than
                // erroring — only genuine failures (network/HTTP/empty response) abort.
                $rawText = method_exists($gemini, 'getLastRawText') ? $gemini->getLastRawText() : '';
                if ($rawText !== '') {
                    $res = ['message' => $rawText];
                    $aiTrace[] = ['stage' => 'chat', 'system' => $chatSystemPrompt, 'history' => $history, 'user_msg' => $userQuestion, 'response' => $res, 'tokens' => $gemini->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false, 'note' => 'raw-text fallback (model did not wrap reply in JSON)'];
                } else {
                    ai_abort_error('chat', $e->getMessage());
                }
            }
            json_out([
                'mode'    => 'chat',
                'message' => $res['message'] ?? 'Hi! How can I help you?',
                'usage'   => $gemini->getLastUsage(),
                'aiTrace' => $aiTrace,
            ]);
        }

        // Read confirmed intent (sent by frontend after user reviews it)
        $approvedIntent = (isset($req['body']['intent']) && is_array($req['body']['intent']))
            ? $req['body']['intent'] : null;

        try {
            if ($mode === 'build') {
                // Pass 1 — schema only; lock in confirmed intent scope when available
                $schemaUserMsg = $approvedIntent
                    ? $prompt . "\n\n" . ai_intent_to_context($approvedIntent)
                    : $prompt;
                $_t0 = microtime(true);
                try { $schemaPlan = $gemini->generateJsonWithHistory(AI_BUILD_SCHEMA_PROMPT, $history, $schemaUserMsg); }
                catch (\RuntimeException $e) { ai_abort_error('schema', $e->getMessage()); }
                $aiTrace[] = ['stage' => 'schema_pass_1', 'system' => AI_BUILD_SCHEMA_PROMPT, 'history' => $history, 'user_msg' => $schemaUserMsg, 'response' => $schemaPlan, 'tokens' => $gemini->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];
                $schemaPlan['frontend'] = ['files' => []];
                $schemaPlan = ai_sanitize_plan($schemaPlan);

                $validationError = ai_validate_plan($schemaPlan);
                if ($validationError) {
                    // One self-correcting retry with the rejection reason fed back.
                    $retryPrompt = $schemaUserMsg
                        . "\n\nYour previous schema was rejected for this reason:\n  " . $validationError
                        . "\nReturn a corrected schema that fixes exactly this problem.";
                    $_t0 = microtime(true);
                    try { $schemaPlan = $gemini->generateJsonWithHistory(AI_BUILD_SCHEMA_PROMPT, $history, $retryPrompt); }
                    catch (\RuntimeException $e) { ai_abort_error('schema_retry', $e->getMessage()); }
                    $aiTrace[] = ['stage' => 'schema_retry', 'system' => AI_BUILD_SCHEMA_PROMPT, 'history' => $history, 'user_msg' => $retryPrompt, 'response' => $schemaPlan, 'tokens' => $gemini->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => true, 'error' => $validationError];
                    $schemaPlan['frontend'] = ['files' => []];
                    $schemaPlan = ai_sanitize_plan($schemaPlan);
                    $validationError = ai_validate_plan($schemaPlan);
                    if ($validationError) {
                        abort(422, 'AI returned an invalid schema: ' . $validationError);
                    }
                }

                // Pass 1.5 — design brief (best-effort; skip silently on failure)
                $_t0   = microtime(true);
                $brief = ai_generate_design_brief($gemini, $prompt, $schemaPlan);
                if (!empty($brief)) {
                    $aiTrace[] = ['stage' => 'design_brief', 'system' => AI_DESIGN_BRIEF_PROMPT, 'history' => [], 'user_msg' => "App description: {$prompt}\n\nSchema:\n" . ai_schema_to_context($schemaPlan), 'response' => $brief, 'tokens' => $gemini->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];
                }
                $briefCtx = ai_brief_to_context($brief);

                // Pass 2 — frontend with exact (post-sanitize) column names + bound auth.js
                $frontendMsg          = "App description: {$prompt}\n\n"
                                      . ($briefCtx ? "{$briefCtx}\n\n" : '')
                                      . "Exact validated schema — use ONLY these column names in JS:\n"
                                      . ai_schema_to_context($schemaPlan);
                $_frontendSysPrompt   = ai_bind_auth_placeholders(AI_BUILD_FRONTEND_PROMPT, $schemaPlan);
                $_t0 = microtime(true);
                try { $frontendResult = $gemini->generateJson($_frontendSysPrompt, $frontendMsg); }
                catch (\RuntimeException $e) { ai_abort_error('frontend', $e->getMessage()); }
                $aiTrace[] = ['stage' => 'frontend_pass_2', 'system' => $_frontendSysPrompt, 'history' => [], 'user_msg' => $frontendMsg, 'response' => ['files' => array_map(fn($f) => ['path' => $f['path'], 'bytes' => mb_strlen($f['content'] ?? '')], $frontendResult['files'] ?? [])], 'tokens' => $gemini->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];

                $plan = $schemaPlan;
                $plan['frontend'] = ['files' => $frontendResult['files'] ?? []];
                foreach ($plan['frontend']['files'] as &$file) {
                    $file['path'] = ltrim(preg_replace('#^\./+#', '', $file['path'] ?? ''), '/');
                }
                unset($file);

                $summary = [
                    'project_name'   => $plan['project_name'],
                    'tables'         => array_map(fn($t) => $t['name'] . ' (' . count($t['columns'] ?? []) . ' cols)', $plan['tables']),
                    'frontend_files' => count($plan['frontend']['files'] ?? []),
                ];

                json_out(['mode' => 'build', 'plan' => $plan, 'summary' => $summary, 'usage' => $gemini->getLastUsage(), 'aiTrace' => $aiTrace]);

            } elseif ($mode === 'edit') {
                $project = $catalog->getProjectById($projectId, $userId);
                if (!$project) abort(404, 'Project not found');

                $existingSchema   = ai_schema_from_db($projectId, $catalog);
                $schemaCtx        = ai_schema_to_context($existingSchema);
                $currentFiles     = ai_read_frontend_files($config, $catalog, $projectId, $prompt);
                $userMessage      = "Exact schema:\n{$schemaCtx}\n\nCurrent frontend:\n{$currentFiles}\n\nRequest: {$prompt}";
                $editSystemPrompt = ai_bind_auth_placeholders(AI_EDIT_SYSTEM_PROMPT, $existingSchema);
                $_t0 = microtime(true);
                try { $delta = $gemini->generateJsonWithHistory($editSystemPrompt, $history, $userMessage); }
                catch (\RuntimeException $e) { ai_abort_error('edit', $e->getMessage()); }
                $aiTrace[] = ['stage' => 'edit_pass', 'system' => $editSystemPrompt, 'history' => $history, 'user_msg' => mb_strlen($userMessage) > 5000 ? mb_substr($userMessage, 0, 5000) : $userMessage, 'user_msg_len' => mb_strlen($userMessage), 'user_msg_truncated' => mb_strlen($userMessage) > 5000, 'response' => array_merge(array_intersect_key($delta, array_flip(['add_tables', 'add_columns', 'update_policies'])), ['frontend_files' => count($delta['frontend']['files'] ?? [])]), 'tokens' => $gemini->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];

                // Validate the delta; one self-correcting retry with the reason fed back.
                $deltaError = ai_validate_delta($delta, $existingSchema);
                if ($deltaError) {
                    $retryMsg = $userMessage
                        . "\n\nYour previous response was rejected for this reason:\n  " . $deltaError
                        . "\nReturn a corrected JSON delta that fixes exactly this problem and nothing else.";
                    $_t0 = microtime(true);
                    try { $delta = $gemini->generateJsonWithHistory($editSystemPrompt, $history, $retryMsg); }
                    catch (\RuntimeException $e) { ai_abort_error('edit_retry', $e->getMessage()); }
                    $aiTrace[] = ['stage' => 'edit_retry', 'system' => $editSystemPrompt, 'history' => $history, 'user_msg' => mb_strlen($retryMsg) > 5000 ? mb_substr($retryMsg, 0, 5000) : $retryMsg, 'user_msg_len' => mb_strlen($retryMsg), 'user_msg_truncated' => mb_strlen($retryMsg) > 5000, 'response' => array_merge(array_intersect_key($delta, array_flip(['add_tables', 'add_columns', 'update_policies'])), ['frontend_files' => count($delta['frontend']['files'] ?? [])]), 'tokens' => $gemini->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => true, 'error' => $deltaError];
                    $deltaError = ai_validate_delta($delta, $existingSchema);
                    if ($deltaError) abort(422, 'AI returned an invalid edit: ' . $deltaError);
                }

                // Normalise file paths if the AI returned frontend files
                if (!empty($delta['frontend']['files'])) {
                    foreach ($delta['frontend']['files'] as &$file) {
                        $file['path'] = ltrim(preg_replace('#^\./+#', '', $file['path'] ?? ''), '/');
                    }
                    unset($file);
                }

                // Column names are injected as a hard constraint in the edit system prompt via
                // ai_schema_to_context(), so a separate audit round-trip is not needed.

                $summary = [
                    'add_tables'      => array_column($delta['add_tables'] ?? [], 'name'),
                    'add_columns'     => array_merge(...array_map(fn($e) => array_map(fn($c) => $e['table'] . '.' . $c['name'], $e['columns'] ?? []), $delta['add_columns'] ?? [])),
                    'update_policies' => array_map(fn($p) => $p['table'] . ' ' . $p['api_role'] . ' ' . $p['operation'], $delta['update_policies'] ?? []),
                ];
                if (!empty($delta['frontend']['files'])) {
                    $summary['frontend_files'] = count($delta['frontend']['files']);
                }

                $editPlan = array_merge($delta, ['project_id' => $projectId]);
                json_out(['mode' => 'edit', 'plan' => $editPlan, 'summary' => $summary, 'usage' => $gemini->getLastUsage(), 'aiTrace' => $aiTrace]);

            } else { // diagnose
                $project = $catalog->getProjectById($projectId, $userId);
                if (!$project) abort(404, 'Project not found');

                $existingTables = $catalog->listTables($projectId);
                $schemaLines = [];
                foreach ($existingTables as $tbl) {
                    $cols = array_map(fn($c) => $c['name'] . ' ' . $c['type'], $catalog->listColumns($tbl['id']));
                    $policies = $catalog->listPolicies($tbl['id']);
                    $policyStrs = array_map(fn($p) => $p['api_role'] . '.' . $p['operation'] . '=' . ($p['allowed'] ? 'allow' : 'deny'), $policies);
                    $schemaLines[] = '  Table "' . $tbl['logical_name'] . '": cols=[' . implode(', ', $cols) . '], policies=[' . implode(', ', $policyStrs) . ']';
                }
                $schemaContext = $schemaLines ? implode("\n", $schemaLines) : '  (no tables yet)';

                $apiBase       = rtrim($config['API_BASE_URL'], '/') . '/api/v1';
                $frontendFiles = ai_read_frontend_files($config, $catalog, $projectId, $prompt);
                $context = "Project: " . $project['name'] . "\nAPI base: " . $apiBase . "\nSchema:\n" . $schemaContext . $frontendFiles . "\n\nIssue: " . $prompt;

                $diagnosePrompt = <<<'PROMPT'
You are a debugging assistant for SupaBein, a self-hosted PHP+MySQL BaaS.
Analyze the project context and issue. Return ONLY valid JSON:
{ "diagnosis": "clear explanation", "suggestions": ["step 1", ...] }
PROMPT;

                $_t0 = microtime(true);
                try { $result = $gemini->generateJsonWithHistory($diagnosePrompt, $history, $context); }
                catch (\RuntimeException $e) { ai_abort_error('diagnose', $e->getMessage()); }
                $aiTrace[] = ['stage' => 'diagnose', 'system' => $diagnosePrompt, 'history' => $history, 'user_msg' => mb_strlen($context) > 5000 ? mb_substr($context, 0, 5000) : $context, 'user_msg_len' => mb_strlen($context), 'user_msg_truncated' => mb_strlen($context) > 5000, 'response' => $result, 'tokens' => $gemini->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];

                json_out([
                    'mode'        => 'diagnose',
                    'diagnosis'   => $result['diagnosis'] ?? '',
                    'suggestions' => $result['suggestions'] ?? [],
                    'usage'       => $gemini->getLastUsage(),
                    'aiTrace'     => $aiTrace,
                ]);
            }
        } catch (\RuntimeException $e) {
            ai_abort_error('unknown', $e->getMessage());
        }

    }, ['auth_middleware']);

    // ── AI Build (job): creates a background job that runs the full
    //    schema → design → frontend generation server-side, independent of the
    //    client's connection — reload, rotate, close the tab, the job keeps
    //    going and a reopened panel just reconnects to it. Each job spawns its
    //    own OS process (see ai_spawn_job_worker), so multiple users' builds
    //    run fully in parallel — there's no shared queue/consumer to wait behind.
    $router->post('/v1/ai/build/job', function (array $req): void {
        $config  = \App::get('config');
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];

        $prompt = ai_validate_prompt($req['body']);

        $history = [];
        foreach (array_slice((array)($req['body']['history'] ?? []), 0, 20) as $turn) {
            if (!is_array($turn)) continue;
            $role = $turn['role'] ?? '';
            $text = trim($turn['text'] ?? '');
            if (!in_array($role, ['user', 'model'], true) || $text === '') continue;
            $history[] = ['role' => $role, 'text' => $text];
        }

        $intent    = (isset($req['body']['intent']) && is_array($req['body']['intent'])) ? $req['body']['intent'] : null;
        $sessionId = isset($req['body']['session_id']) ? (int)$req['body']['session_id'] : null;

        // Continuing a previous build job that died partway through — the
        // worker resolves this to that job's own saved checkpoint (scoped to
        // this same user), so a retry can skip straight past whatever
        // already finished instead of redoing the whole pipeline.
        $resumeJobId = isset($req['body']['resume_job_id']) ? (int)$req['body']['resume_job_id'] : 0;

        $payload = [
            'prompt'        => $prompt,
            'history'       => $history,
            'intent'        => $intent,
            'provider'      => $req['body']['provider'] ?? null,
            'model'         => $req['body']['model'] ?? null,
            'validate'      => !isset($req['body']['validate']) || (bool)$req['body']['validate'],
            'resume_job_id' => $resumeJobId ?: null,
            'attachments'   => ai_validate_attachments_for_job($req['body']['attachments'] ?? null),
        ];
        $job = $catalog->createJob($userId, $sessionId, 'build', $payload);
        ai_spawn_job_worker($config, (int)$job['id']);
        json_out(['job_id' => (int)$job['id']], 202);
    }, ['auth_middleware']);

    // ── AI Build, Review-on stage 1 (job): schema + design brief only. The
    //    frontend shows a confirm card after this and only fires the stage-2
    //    job below once the user explicitly confirms.
    $router->post('/v1/ai/build-schema/job', function (array $req): void {
        $config  = \App::get('config');
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];

        $prompt = ai_validate_prompt($req['body']);

        $history = [];
        foreach (array_slice((array)($req['body']['history'] ?? []), 0, 20) as $turn) {
            if (!is_array($turn)) continue;
            $role = $turn['role'] ?? '';
            $text = trim($turn['text'] ?? '');
            if (!in_array($role, ['user', 'model'], true) || $text === '') continue;
            $history[] = ['role' => $role, 'text' => $text];
        }

        $intent    = (isset($req['body']['intent']) && is_array($req['body']['intent'])) ? $req['body']['intent'] : null;
        $sessionId = isset($req['body']['session_id']) ? (int)$req['body']['session_id'] : null;

        $payload = [
            'prompt'      => $prompt,
            'history'     => $history,
            'intent'      => $intent,
            'provider'    => $req['body']['provider'] ?? null,
            'model'       => $req['body']['model'] ?? null,
            'attachments' => ai_validate_attachments_for_job($req['body']['attachments'] ?? null),
        ];
        $job = $catalog->createJob($userId, $sessionId, 'build_schema', $payload);
        ai_spawn_job_worker($config, (int)$job['id']);
        json_out(['job_id' => (int)$job['id']], 202);
    }, ['auth_middleware']);

    // ── AI Build, Review-on stage 2 (job): frontend + validate, against the
    //    schema/design brief the user already confirmed in stage 1.
    $router->post('/v1/ai/build-frontend/job', function (array $req): void {
        $config  = \App::get('config');
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];

        $prompt = ai_validate_prompt($req['body']);
        $schema = $req['body']['schema'] ?? null;
        if (!is_array($schema) || empty($schema['tables'])) abort(422, 'schema is required');
        $designBrief = (isset($req['body']['design_brief']) && is_array($req['body']['design_brief'])) ? $req['body']['design_brief'] : [];

        $sessionId = isset($req['body']['session_id']) ? (int)$req['body']['session_id'] : null;

        $payload = [
            'prompt'       => $prompt,
            'schema'       => $schema,
            'design_brief' => $designBrief,
            'provider'     => $req['body']['provider'] ?? null,
            'model'        => $req['body']['model'] ?? null,
            'validate'     => !isset($req['body']['validate']) || (bool)$req['body']['validate'],
            // Review-on's stage 1 (build-schema/job) already saw these — the
            // caller resends them here (a fresh HTTP request, no server-side
            // memory of stage 1) if it wants stage 2's frontend generation to
            // see them too. 'intent' is optional and forward-compatible —
            // frontend generation falls back to just prompt+schema without
            // it, same as before this field existed.
            'intent'       => (isset($req['body']['intent']) && is_array($req['body']['intent'])) ? $req['body']['intent'] : null,
            'attachments'  => ai_validate_attachments_for_job($req['body']['attachments'] ?? null),
        ];
        $job = $catalog->createJob($userId, $sessionId, 'build_frontend', $payload);
        ai_spawn_job_worker($config, (int)$job['id']);
        json_out(['job_id' => (int)$job['id']], 202);
    }, ['auth_middleware']);

    // ── AI Edit (job): same job-backed pattern, for editing an existing project.
    $router->post('/v1/ai/edit/job', function (array $req): void {
        $config  = \App::get('config');
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];

        $prompt    = ai_validate_prompt($req['body']);
        $projectId = isset($req['body']['project_id']) ? (int)$req['body']['project_id'] : 0;
        if (!$projectId) abort(422, 'project_id is required');

        $project = $catalog->getProjectById($projectId, $userId);
        if (!$project) abort(404, 'Project not found');

        $history = [];
        foreach (array_slice((array)($req['body']['history'] ?? []), 0, 20) as $turn) {
            if (!is_array($turn)) continue;
            $role = $turn['role'] ?? '';
            $text = trim($turn['text'] ?? '');
            if (!in_array($role, ['user', 'model'], true) || $text === '') continue;
            $history[] = ['role' => $role, 'text' => $text];
        }

        $sessionId = isset($req['body']['session_id']) ? (int)$req['body']['session_id'] : null;

        // Continuing a previous edit that ran out of turns before finishing —
        // the worker resolves this to that job's own saved resume_state
        // (scoped to this same user, same as any other job lookup), never
        // trusting anything about the prior run's content from the request
        // body itself.
        $resumeJobId = isset($req['body']['resume_job_id']) ? (int)$req['body']['resume_job_id'] : 0;

        $payload = [
            'prompt'        => $prompt,
            'project_id'    => $projectId,
            'history'       => $history,
            'provider'      => $req['body']['provider'] ?? null,
            'model'         => $req['body']['model'] ?? null,
            'validate'      => !isset($req['body']['validate']) || (bool)$req['body']['validate'],
            'resume_job_id' => $resumeJobId ?: null,
            'attachments'   => ai_validate_attachments_for_job($req['body']['attachments'] ?? null),
        ];
        $job = $catalog->createJob($userId, $sessionId, 'edit', $payload);
        ai_spawn_job_worker($config, (int)$job['id']);
        json_out(['job_id' => (int)$job['id']], 202);
    }, ['auth_middleware']);

    // ── AI Jobs: poll progress/result, list active jobs, or cancel one ────────
    $router->get('/v1/ai/jobs/:id', function (array $req): void {
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];
        $jobId   = (int)$req['params']['id'];
        $job = $catalog->getJobById($jobId, $userId);
        if (!$job) abort(404, 'Job not found');

        // A worker can die without ever reaching the try/catch that would mark
        // it failed (killed by the host's process/resource limits, OOM, etc.) —
        // that used to leave the job (and the panel polling it) "running"
        // forever with no error and no way out. If it's been quiet for 5+
        // minutes AND its recorded OS process no longer exists, it's dead.
        if (ai_job_is_orphaned($job)) {
            $catalog->markJobFailed($jobId, 'Worker process stopped unexpectedly — please retry.');
            $job = $catalog->getJobById($jobId, $userId);
        }

        $since  = max(0, (int)($req['query']['since'] ?? 0));
        $events = array_slice($job['progress'], $since);

        json_out([
            'status'      => $job['status'],
            'events'      => $events,
            'event_count' => count($job['progress']),
            'result'      => $job['status'] === 'done' ? $job['result'] : null,
            'error'       => $job['status'] === 'failed' ? $job['error'] : null,
        ]);
    }, ['auth_middleware']);

    // Same status payload as the poll endpoint above, pushed over a single
    // connection as soon as it changes instead of the client re-asking every
    // few seconds. Each connection is capped at a modest duration and then
    // closed cleanly (not as an error, and never mid-payload) so the client
    // reconnects with an updated `since` -- this keeps a PHP-FPM worker from
    // being tied up for a build's full 20-30 minute lifetime, and stays
    // comfortably under this host's own reverse-proxy read timeout (already
    // hit once elsewhere -- see the site-server 30s proxy timeout fix).
    $router->get('/v1/ai/jobs/:id/stream', function (array $req): void {
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];
        $jobId   = (int)$req['params']['id'];
        $since   = max(0, (int)($req['query']['since'] ?? 0));

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // nginx: don't buffer this response
        while (ob_get_level() > 0) ob_end_flush();
        ob_implicit_flush(true);
        set_time_limit(0);
        ignore_user_abort(false);

        $deadline      = time() + 20; // stay well under the 30s proxy read timeout
        $lastHeartbeat = time();

        while (true) {
            $job = $catalog->getJobById($jobId, $userId);
            if (!$job) {
                echo 'data: ' . json_encode(['status' => 'not_found']) . "\n\n";
                flush();
                return;
            }
            if (ai_job_is_orphaned($job)) {
                $catalog->markJobFailed($jobId, 'Worker process stopped unexpectedly — please retry.');
                $job = $catalog->getJobById($jobId, $userId);
            }

            $events = array_slice($job['progress'], $since);
            if ($events || $job['status'] !== 'running') {
                $since = count($job['progress']);
                echo 'data: ' . json_encode([
                    'status'      => $job['status'],
                    'events'      => $events,
                    'event_count' => $since,
                    'result'      => $job['status'] === 'done' ? $job['result'] : null,
                    'error'       => $job['status'] === 'failed' ? $job['error'] : null,
                ]) . "\n\n";
                flush();
                $lastHeartbeat = time();

                if ($job['status'] !== 'running') return; // terminal -- client won't reconnect
            }

            if (connection_aborted()) return; // client closed the panel/tab

            if (time() - $lastHeartbeat >= 10) {
                echo ": heartbeat\n\n";
                flush();
                $lastHeartbeat = time();
            }

            if (time() >= $deadline) return; // bounded cutoff -- client reconnects with updated `since`

            usleep(700000);
        }
    }, ['auth_middleware']);

    $router->get('/v1/ai/jobs', function (array $req): void {
        $catalog = \SupaBein\Catalog::getInstance();
        $jobs = $catalog->listActiveJobs((int)$req['auth']['user_id']);
        // Same orphan sweep GET /v1/ai/jobs/:id already applies to a single
        // job -- without it, a job whose worker died (OOM, host restart, any
        // crash that never reaches the try/catch that marks it failed) sits
        // 'running' here forever. That's what silently broke the dashboard's
        // active-jobs watchdog: it asks "is my stuck job still in the active
        // list?" specifically so a "no" means safe-to-reconnect -- but a
        // truly-dead job answered "yes" on every single poll, indefinitely,
        // since nothing here ever looked past its status column to check
        // whether the process behind it actually still existed.
        $jobs = array_values(array_filter(array_map(function ($job) use ($catalog) {
            if (ai_job_is_orphaned($job)) {
                $catalog->markJobFailed((int)$job['id'], 'Worker process stopped unexpectedly — please retry.');
                return null;
            }
            return $job;
        }, $jobs)));
        json_out($jobs);
    }, ['auth_middleware']);

    $router->post('/v1/ai/jobs/:id/cancel', function (array $req): void {
        $catalog = \SupaBein\Catalog::getInstance();
        $pid = $catalog->cancelJob((int)$req['params']['id'], (int)$req['auth']['user_id']);
        if ($pid && function_exists('posix_kill')) {
            @posix_kill($pid, 15); // SIGTERM — matches today's behavior where aborting the fetch also kills the server-side process
        }
        json_out(['cancelled' => true]);
    }, ['auth_middleware']);

    // ── AI Apply: execute a previously generated plan ──────────────────────────
    $router->post('/v1/ai/apply', function (array $req): void {
        set_time_limit(420);

        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];

        $mode = $req['body']['mode'] ?? '';
        $plan = $req['body']['plan'] ?? [];

        if (!is_array($plan)) abort(422, 'plan must be an array');

        if ($mode === 'build') {
            $plan = ai_sanitize_plan($plan);
            $validationError = ai_validate_plan($plan);
            if ($validationError) abort(422, 'Invalid plan: ' . $validationError);

            $result = ai_execute_build($plan, $userId);
            // Review-on's stage 1/2 jobs ran before any project existed, so
            // the generated frontend (if it references an uploaded logo/
            // image at all) uses the __SB_PID__-placeholder path — the
            // project now exists, so upload straight to it with the SAME
            // deterministic filename ai_dedupe_asset_filename() would have
            // produced during generation (same attachments, same order in
            // → same name out). The caller resends the same `attachments`
            // it sent to build-schema/job for this to have anything to upload.
            if (!empty($result['project']['id'])) {
                ai_prepare_attachments_for_ai(ai_validate_attachments($req['body']['attachments'] ?? null), (int)$result['project']['id']);
            }
            // Review-on's intent-review step confirms the real actors/stories
            // before schema/frontend generation ever runs, but review-on's
            // job-backed stage 1/2 never had a project to persist it against
            // until now -- without this, story-driven testing after THIS
            // apply would fall back to inferring stories from the schema +
            // rendered HTML alone, same gap review-off had (see
            // ai_run_build_and_deploy()'s own persistence for that path).
            if (!empty($req['body']['intent']) && is_array($req['body']['intent']) && !empty($result['project']['id'])) {
                $catalog->upsertProjectRequirements((int)$result['project']['id'], $userId, $req['body']['intent']);
            }

            // Review-off's job-backed path already validates before deploy (see
            // ai_run_build_frontend()'s retry loop); this apply-then-deploy
            // path never ran the validator at all before now — recompute it
            // here (cheap, deterministic, no AI call) so the unified health
            // gate below has real evidence instead of assuming success.
            $result['validation'] = ai_validator_check_project($plan, $plan['frontend']['files'] ?? []);
            $unreachablePolicies  = !empty($result['project']['id'])
                ? $catalog->findUnreachablePolicies((int)$result['project']['id'])
                : [];
            $result['health'] = ai_assess_deploy_health(
                $result['validation'],
                $result['deploy_error'] ?? null,
                $result['staging'] ?? null,
                $unreachablePolicies
            );
            json_out($result, 201);

        } elseif ($mode === 'edit') {
            $projectId = (int)($plan['project_id'] ?? 0);
            if (!$projectId) abort(422, 'plan.project_id is required for edit mode');
            $project = $catalog->getProjectById($projectId, $userId);
            if (!$project) abort(404, 'Project not found');
            $applySchema = ai_schema_from_db($projectId, $catalog);
            // A retry of this same call (see "Retry apply" in the dashboard)
            // must not be blocked by whatever the FIRST attempt already got
            // through before failing on something later in the delta.
            $plan = ai_reconcile_delta_for_apply($plan, $applySchema, $projectId);
            $deltaError = ai_validate_delta($plan, $applySchema);
            if ($deltaError) abort(422, 'Invalid edit plan: ' . $deltaError);

            $result = ai_execute_edit($plan, $projectId, $userId);
            if (!empty($plan['frontend']['files'])) {
                $editConfig = \App::get('config');
                $editSites  = $catalog->listSites($projectId);
                if ($editSites) {
                    $editSiteId = (int)$editSites[0]['id'];
                    // Re-fetch schema post-edit so a users table added in THIS
                    // same edit is picked up for auth.js injection immediately.
                    $updatedSchema = ai_schema_from_db($projectId, $catalog);
                    // Edits deploy to STAGING (preview) — the user publishes to live explicitly.
                    $deployResult = ai_deploy_files($editConfig, $catalog, $editSiteId,
                                                    $project, $plan['frontend']['files'],
                                                    true, false, ai_detect_auth($updatedSchema));
                    if (!empty($deployResult['deploy'])) {
                        $result['deploy'] = $deployResult['deploy'];
                        $apiBase = rtrim($editConfig['API_BASE_URL'] ?? '', '/');
                        $appBase = preg_replace('#/(api|v\d+)(/.*)?$#i', '', $apiBase);
                        $result['staging'] = [
                            'project_id'    => $projectId,
                            'site_id'       => $editSiteId,
                            'deploy_id'     => (int)$deployResult['deploy']['id'],
                            'staging_url'   => $appBase . '/sites/s' . $editSiteId . '/staging/',
                            'subdomain'     => $editSites[0]['subdomain'] ?? null,
                            'custom_domain' => $editSites[0]['custom_domain'] ?? null,
                        ];
                    } elseif (!empty($deployResult['error'])) {
                        $result['deploy_error'] = $deployResult['error'];
                    }
                }
            }

            // Same unified health gate as build mode. Skips a full validator
            // re-check here — this delta only carries the files it actually
            // changed, not the project's complete current file set, so
            // re-running ai_validator_check_project against just that subset
            // would misfire on anything the OTHER, already-deployed files
            // already satisfy (a route another feature file defines, etc.).
            // deploy_error/staging and policy reachability don't have that
            // problem — both reflect real, whole-project state either way.
            $unreachablePolicies = $catalog->findUnreachablePolicies($projectId);
            $result['health'] = ai_assess_deploy_health(
                $result['validation'] ?? [],
                $result['deploy_error'] ?? null,
                $result['staging'] ?? null,
                $unreachablePolicies
            );
            json_out($result);

        } else {
            abort(422, 'mode must be build or edit');
        }

    }, ['auth_middleware']);

    // ── AI Sessions (DB-backed) ────────────────────────────────────────────────

    // History is project-scoped: pass ?project_id=<id> for a specific project's
    // sessions, ?project_id=none for the "Build with AI" bucket (no project yet),
    // or omit entirely for the full unscoped list.
    // Backs the dashboard's model picker -- see AI_MODEL_CATALOG's own
    // comment for why this replaced a hardcoded frontend array. Filters out
    // (a) any provider with no configured API key and (b) any entry whose
    // model isn't actually present in AI_ALLOWED_MODELS for that provider,
    // so a stale or typo'd catalog entry never offers a choice that would
    // silently get substituted for a different model server-side.
    $router->get('/v1/ai/models', function (array $req): void {
        $config = \App::get('config');
        $models = array_values(array_filter(AI_MODEL_CATALOG, function (array $entry) use ($config): bool {
            if (!ai_provider_configured($config, $entry['provider'])) return false;
            $allowed = AI_ALLOWED_MODELS[$entry['provider']] ?? [];
            return in_array($entry['model'], $allowed, true);
        }));
        json_out(['models' => $models]);
    }, ['auth_middleware']);
    $router->get('/v1/ai/sessions', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        $projectId = $req['query']['project_id'] ?? null;

        if ($projectId === 'none') {
            json_out($catalog->listAiSessionsUnassigned($userId));
        } elseif ($projectId !== null && $projectId !== '') {
            json_out($catalog->listAiSessionsForProject((int)$projectId, $userId));
        } else {
            json_out($catalog->listAiSessions($userId));
        }
    }, ['auth_middleware']);

    // Generate a short, descriptive title for a chat session from its first message.
    // Best-effort: the client falls back to a truncated prompt if this fails.
    $router->post('/v1/ai/session-title', function (array $req): void {
        $prompt = trim($req['body']['prompt'] ?? '');
        if ($prompt === '') abort(422, 'prompt is required');
        $config = \App::get('config');
        try {
            $client = make_ai_client($config, null, null, 60); // default fast model — keep it cheap
            $sys = 'You title chat sessions. Given the user\'s first message, return ONLY JSON {"title": "..."} '
                 . 'with a concise, specific 1-5 word Title Case label (max 40 chars, no trailing punctuation, no quotes).';
            $res   = $client->generateJson($sys, mb_substr($prompt, 0, 500));
            $title = is_array($res) ? trim((string)($res['title'] ?? '')) : '';
            $title = trim($title, " \t\n\r\0\x0B\"'.");
            if ($title === '') abort(502, 'empty title');
            // Deterministic guarantee, not prompt-compliance hope — clamp to at
            // most 5 words server-side regardless of what the model returned.
            $words = preg_split('/\s+/', $title, -1, PREG_SPLIT_NO_EMPTY);
            $title = implode(' ', array_slice($words, 0, 5));
            if ($title === '') abort(502, 'empty title');
            json_out(['title' => mb_substr($title, 0, 60)]);
        } catch (\Throwable $e) {
            abort(502, 'title generation unavailable');
        }
    }, ['auth_middleware']);

    $router->post('/v1/ai/sessions', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        $name      = trim($req['body']['name'] ?? 'New session');
        $projectId = isset($req['body']['project_id']) ? (int)$req['body']['project_id'] : null;
        json_out($catalog->createAiSession($userId, $name ?: 'New session', $projectId), 201);
    }, ['auth_middleware']);

    $router->get('/v1/ai/sessions/:id', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $sessionId = (int)$req['params']['id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        // Lazy loading: default to only the most recent page of messages
        // (large sessions can carry hundreds of KB of trace/progress data) —
        // ?before=<messageId> pages further back as the client scrolls up.
        // ?full=1 is kept for any caller that genuinely needs everything.
        if (!empty($req['query']['full'])) {
            $sess = $catalog->getAiSession($sessionId, $userId);
            if (!$sess) abort(404, 'Session not found');
            json_out($sess);
            return;
        }
        $limit  = isset($req['query']['limit']) ? max(1, min(200, (int)$req['query']['limit'])) : 40;
        $before = isset($req['query']['before']) && $req['query']['before'] !== ''
            ? (string)$req['query']['before'] : null;
        $sess = $catalog->getAiSessionPage($sessionId, $userId, $limit, $before);
        if (!$sess) abort(404, 'Session not found');
        json_out($sess);
    }, ['auth_middleware']);

    $router->patch('/v1/ai/sessions/:id', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $sessionId = (int)$req['params']['id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        $name      = trim($req['body']['name'] ?? '');
        $messages  = $req['body']['messages'] ?? null;
        $sess = $catalog->getAiSession($sessionId, $userId);
        if (!$sess) abort(404, 'Session not found');
        $newName = $name ?: $sess['name'];
        if (is_array($messages)) {
            // Upsert by message id rather than a blind "whichever array is
            // longer wins" — a lazy-loading client only ever holds a partial
            // window of the full history, so a length check would treat that
            // window as poorer than the server's full copy and silently
            // discard every new message on it. Merging by id preserves the
            // original guarantee this replaced (a save can never erase a
            // message the server already has — the bug that produced this
            // code in the first place: an edit's deploy and a 20-minute
            // auto-test both completed, but the session's chat history never
            // recorded any of it past the apply call) while staying correct
            // for a client that only has the last N messages loaded.
            $catalog->upsertAiSessionMessages($sessionId, $userId, $newName, $messages);
        } else {
            $catalog->updateAiSession($sessionId, $userId, $newName, $sess['messages']);
        }
        // Attach the session to the project a completed build just created —
        // only ever moves a session FROM unassigned TO a project, never away.
        if (isset($req['body']['project_id']) && $sess['project_id'] === null) {
            $catalog->setAiSessionProject($sessionId, $userId, (int)$req['body']['project_id']);
        }
        // The client only needs to know the save landed, not the full/paged
        // history back — returning it here would defeat the point of lazy
        // loading by re-downloading everything after every single save.
        json_out(['id' => $sessionId, 'saved' => true]);
    }, ['auth_middleware']);

    $router->delete('/v1/ai/sessions/:id', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $sessionId = (int)$req['params']['id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        if (!$catalog->deleteAiSession($sessionId, $userId)) abort(404, 'Session not found');
        json_out(['deleted' => true]);
    }, ['auth_middleware']);

    // ── Product requirements: get + upsert ────────────────────────────────────
    $router->get('/v1/projects/:id/requirements', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $projectId = (int)$req['params']['id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        if (!$catalog->getProjectById($projectId, $userId)) abort(404, 'Project not found');
        $reqs = $catalog->getProjectRequirements($projectId);
        json_out($reqs ?? (object)[]);
    }, ['auth_middleware']);

    $router->put('/v1/projects/:id/requirements', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $projectId = (int)$req['params']['id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        if (!$catalog->getProjectById($projectId, $userId)) abort(404, 'Project not found');
        $data = $req['body'] ?? [];
        if (empty($data)) abort(422, 'requirements body required');
        $catalog->upsertProjectRequirements($projectId, $userId, $data);
        json_out(['ok' => true]);
    }, ['auth_middleware']);

    // ── Latest test verdict for a project — used by the dashboard to warn
    //    before publishing a staging deploy that has failing tests.
    $router->get('/v1/projects/:id/test-status', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $projectId = (int)$req['params']['id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        if (!$catalog->getProjectById($projectId, $userId)) abort(404, 'Project not found');
        json_out($catalog->getLatestTestStatus($projectId, $userId) ?? ['tested' => false]);
    }, ['auth_middleware']);

    // ── Test login accounts seeded for this project (if any) — read-only,
    //    checks for the deterministic test1@/test2@ rows rather than storing
    //    anything separately; see ai_get_test_accounts_status().
    $router->get('/v1/projects/:id/test-accounts', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $projectId = (int)$req['params']['id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        if (!$catalog->getProjectById($projectId, $userId)) abort(404, 'Project not found');
        $schema = ai_schema_from_db($projectId, $catalog);
        $pdo    = \App::get('db');
        json_out(['accounts' => ai_get_test_accounts_status($pdo, $catalog, $projectId, $schema)]);
    }, ['auth_middleware']);

    // ── End-user error logs — reported by the platform-injected core/errors.js
    //    running in the deployed app's visitors' browsers (see the public
    //    POST /v1/errors/:project_id route in data_routes.php). Most-recent
    //    first, deduped server-side by fingerprint.
    $router->get('/v1/projects/:id/errors', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $projectId = (int)$req['params']['id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        if (!$catalog->getProjectById($projectId, $userId)) abort(404, 'Project not found');
        $pdo = \App::get('db');
        json_out(['errors' => ai_list_error_logs($pdo, $projectId)]);
    }, ['auth_middleware']);

    // ── Same data as above, as a downloadable JSON file (Content-Disposition)
    //    rather than an inline API response — for offline triage / sharing.
    $router->get('/v1/projects/:id/errors/download', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $projectId = (int)$req['params']['id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        if (!$catalog->getProjectById($projectId, $userId)) abort(404, 'Project not found');
        $pdo  = \App::get('db');
        $rows = ai_list_error_logs($pdo, $projectId, 5000);
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="project-' . $projectId . '-errors-' . date('Ymd_His') . '.json"');
        echo json_encode(['project_id' => $projectId, 'exported_at' => date('c'), 'errors' => $rows], JSON_PRETTY_PRINT);
        exit;
    }, ['auth_middleware']);

    // ── Clear all logged errors for a project (housekeeping once triaged).
    $router->delete('/v1/projects/:id/errors', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $projectId = (int)$req['params']['id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        if (!$catalog->getProjectById($projectId, $userId)) abort(404, 'Project not found');
        \App::get('db')->prepare('DELETE FROM ai_error_logs WHERE project_id = ?')->execute([$projectId]);
        json_out(['ok' => true]);
    }, ['auth_middleware']);

    // ── Run Playwright user-story tests as a background job — same pattern as
    //    build/edit jobs, so a test run survives the panel closing or the page
    //    reloading (a run takes 30-60s+; the old synchronous route quietly lost
    //    the result if the user navigated away while it ran).
    $router->post('/v1/ai/test/job', function (array $req): void {
        $config    = \App::get('config');
        $catalog   = \SupaBein\Catalog::getInstance();
        $userId    = (int)$req['auth']['user_id'];
        $projectId = (int)($req['body']['project_id'] ?? 0);

        if (!$projectId) abort(422, 'project_id is required');

        $project = $catalog->getProjectById($projectId, $userId);
        if (!$project) abort(404, 'Project not found');

        // Pre-flight the obvious failure modes so they come back as an
        // immediate 4xx instead of a job that fails a few seconds later.
        $sites = $catalog->listSites($projectId);
        if (!$sites) abort(422, 'No deployed site found — build the project first');
        $site = $sites[0];
        if (!($site['staging_deploy_id'] ?? null) && !($site['current_deploy_id'] ?? null)) {
            abort(422, 'No deploy found — build or edit the project first');
        }
        if (empty($config['BROWSERLESS_TOKEN'])) {
            abort(500, 'Browserless token not configured (add BROWSERLESS_TOKEN to config/secrets.php)');
        }

        $sessionId = isset($req['body']['session_id']) ? (int)$req['body']['session_id'] : null;
        $job = $catalog->createJob($userId, $sessionId, 'test', [
            'project_id' => $projectId,
            // Story-test generation uses the same model the user picked in the panel.
            'provider'   => $req['body']['provider'] ?? null,
            'model'      => $req['body']['model'] ?? null,
            // Opt-in: on a failure, feed it back as an edit and re-test, up
            // to AI_TEST_AUTOFIX_MAX_ATTEMPTS times — see
            // ai_run_test_and_autofix(). Off by default so a plain "run
            // tests" request never silently starts editing the project.
            'auto_fix'   => !empty($req['body']['auto_fix']),
        ]);
        ai_spawn_job_worker($config, (int)$job['id']);
        json_out(['job_id' => (int)$job['id']], 202);
    }, ['auth_middleware']);

    // ── Generate + insert sample data for the "Seed App" button — same
    //    background-job pattern as build/edit/test so it survives the panel
    //    closing while the AI call is in flight.
    $router->post('/v1/ai/seed/job', function (array $req): void {
        $config    = \App::get('config');
        $catalog   = \SupaBein\Catalog::getInstance();
        $userId    = (int)$req['auth']['user_id'];
        $projectId = (int)($req['body']['project_id'] ?? 0);

        if (!$projectId) abort(422, 'project_id is required');

        $project = $catalog->getProjectById($projectId, $userId);
        if (!$project) abort(404, 'Project not found');

        if (!$catalog->listTables($projectId)) abort(422, 'This project has no tables to seed yet');

        $job = $catalog->createJob($userId, null, 'seed', [
            'project_id' => $projectId,
            'provider'   => $req['body']['provider'] ?? null,
            'model'      => $req['body']['model'] ?? null,
        ]);
        ai_spawn_job_worker($config, (int)$job['id']);
        json_out(['job_id' => (int)$job['id']], 202);
    }, ['auth_middleware']);

}
