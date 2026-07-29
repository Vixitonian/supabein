<?php

declare(strict_types=1);



function ai_generate_edit_plan(object $client, int $projectId, int $userId, string $prompt, array $history, \SupaBein\Catalog $catalog, array $config): array
{
    $project = $catalog->getProjectById($projectId, $userId);
    if (!$project) throw new \RuntimeException('Project not found');

    $existingSchema   = ai_schema_from_db($projectId, $catalog);
    $schemaCtx        = ai_schema_to_context($existingSchema);
    $currentFiles     = ai_read_frontend_files($config, $catalog, $projectId, $prompt);
    $userMessage      = "Exact schema:\n{$schemaCtx}\n\nCurrent frontend:\n{$currentFiles}\n\nRequest: {$prompt}";
    $editSystemPrompt = ai_bind_auth_placeholders(AI_EDIT_SYSTEM_PROMPT, $existingSchema);

    $delta      = $client->generateJsonWithHistory($editSystemPrompt, $history, $userMessage);
    $deltaError = ai_validate_delta($delta, $existingSchema);
    if ($deltaError) {
        $delta      = $client->generateJsonWithHistory($editSystemPrompt, $history,
            $userMessage . "\n\nYour previous response was rejected for this reason:\n  " . $deltaError
            . "\nReturn a corrected JSON delta that fixes exactly this problem and nothing else.");
        $deltaError = ai_validate_delta($delta, $existingSchema);
        if ($deltaError) throw new \RuntimeException('AI returned an invalid edit: ' . $deltaError);
    }

    if (!empty($delta['frontend']['files'])) {
        foreach ($delta['frontend']['files'] as &$file) {
            $file['path'] = ltrim(preg_replace('#^\./+#', '', $file['path'] ?? ''), '/');
        }
        unset($file);
        // Column names are injected as a hard constraint in the edit system prompt via
        // ai_schema_to_context(), so a separate audit round-trip is not needed.
    }

    return array_merge($delta, ['project_id' => $projectId]);
}

function ai_generate_edit_suggestions(object $client, int $projectId, int $userId, string $prompt, \SupaBein\Catalog $catalog, array $config): array
{
    $project = $catalog->getProjectById($projectId, $userId);
    if (!$project) throw new \RuntimeException('Project not found');

    $existingSchema = ai_schema_from_db($projectId, $catalog);
    $schemaCtx      = ai_schema_to_context($existingSchema);
    $currentFiles   = ai_read_frontend_files($config, $catalog, $projectId, $prompt);
    $suggestContext = "Project: " . $project['name']
        . "\n\nExact schema:\n" . $schemaCtx
        . $currentFiles
        . "\n\nUser request: " . $prompt;

    $result = $client->generateJson(<<<'PROMPT'
You are a SupaBein full-stack AI assistant reviewing an edit request.
Analyze the project schema, frontend files, and user request.
Return a list of specific, concrete changes that should be made.

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
PROMPT, $suggestContext);

    return $result['suggestions'] ?? [];
}

// Minimal external "read a doc page" capability — URL-in, text-out, no
// search engine (no search API key available on this server), so the model
// must already have a specific URL rather than searching one up. Guards
// against SSRF: only http/https, resolves the host and rejects anything
// that lands in a private/loopback/reserved range, and never follows
// redirects (a redirect to an internal address would otherwise bypass the
// same check).
function ai_agent_fetch_docs(string $url, int $maxChars = 6000): array
{
    $parsed = parse_url($url);
    $scheme = strtolower((string)($parsed['scheme'] ?? ''));
    $host   = (string)($parsed['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return ['ok' => false, 'error' => 'invalid URL — must be a plain http(s) URL'];
    }
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
        return ['ok' => false, 'error' => 'could not resolve host'];
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['ok' => false, 'error' => 'refusing to fetch an internal/private network address'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT      => 'SupaBein-AI-Agent/1.0 (doc fetch)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => $err ?: 'request failed'];
    }
    if ($status >= 300 && $status < 400) {
        return ['ok' => false, 'error' => "got a redirect (HTTP {$status}) — fetch_docs does not follow redirects; pass the final URL directly"];
    }

    $text = (string)preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $body);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = (string)preg_replace('/[ \t]+/', ' ', $text);
    $text = trim((string)preg_replace('/\n{3,}/', "\n\n", $text));

    return [
        'ok'          => $status >= 200 && $status < 300,
        'http_status' => $status,
        'text'        => mb_substr($text, 0, $maxChars),
        'truncated'   => mb_strlen($text) > $maxChars,
    ];
}

// ── Edit agent: single tool-call execution ───────────────────────────────────
// $byPath is the read-only starting file set; $changedFiles is the in-progress
// staged-writes map, passed by reference so write_file's effects are visible
// to later read_file/search_code/syntax_check calls in the same loop.
// $readPaths tracks which pre-existing paths have actually been read_file'd
// this session — write_file refuses to overwrite a pre-existing path that
// hasn't been read first (see the case below), so a model can't silently
// regenerate an existing file from general knowledge instead of its real
// content: exactly the class of bug that dropped a whole feature's worth of
// working code (note creation/editing) in the file that surfaced this rule.
function ai_run_edit_agent_tool(string $tool, array $args, array $byPath, array &$changedFiles, array &$readPaths, array $config, int $projectId, array $schema = [], string $frontendStack = 'vanilla', string $projectTitle = 'App'): array
{
    $platformPaths = $frontendStack === 'react' ? AI_REACT_CANONICAL_PATHS : AI_PLATFORM_CANONICAL_PATHS;

    // read_file on a platform path used to return content: null, forcing the
    // model to go fetch these files from a live deployed/preview URL instead
    // whenever it needed to see their real implementation (e.g. to check
    // what api.js actually exposes) -- live-observed burning several turns
    // per build spinning up fresh smoke_test previews just to read files
    // that are already known, static, in-process text. Return the real
    // canonical content instead: same content that's force-injected at
    // deploy time regardless of what the model writes here.
    $platformFileContent = function (string $path) use ($schema, $frontendStack): ?string {
        if ($frontendStack === 'react') {
            $authInfo = ai_detect_auth($schema);
            foreach (ai_react_canonical_source_files($authInfo) as $f) {
                if ($f['path'] === $path) return $f['content'];
            }
            return null;
        }
        switch ($path) {
            case 'core/router.js': return AI_CANONICAL_ROUTER_JS;
            case 'core/api.js':    return AI_CANONICAL_API_JS;
            case 'core/errors.js': return AI_CANONICAL_ERRORS_JS;
            case 'features/auth/auth.js':
                $authInfo = ai_detect_auth($schema);
                if (!empty($authInfo['table'])) {
                    return str_replace(['__AUTH_TABLE__', '__AUTH_FIELD__'],
                        [$authInfo['table'], $authInfo['field'] ?? 'email'], AI_CANONICAL_AUTH_JS);
                }
                return AI_CANONICAL_AUTH_STUB_JS;
            default: return null;
        }
    };

    // Reuses the exact normalize-then-prefix-check ai_deploy_files() already
    // uses against real deploy directories, just against a virtual prefix —
    // same traversal protection, no filesystem involved.
    $normalizePath = function (string $path): ?string {
        $rel = ltrim($path, '/');
        if ($rel === '') return null;
        $norm = \SupaBein\Deploy::normalizePath('/virtual/' . $rel);
        if (!str_starts_with($norm, '/virtual/')) return null;
        $out = substr($norm, strlen('/virtual/'));
        return $out === '' ? null : $out;
    };

    switch ($tool) {
        case 'list_files':
            return ['tool' => 'list_files', 'result' => [
                'files' => array_values(array_unique(array_merge(array_keys($byPath), array_keys($changedFiles)))),
            ]];

        case 'search_code':
            $query = trim((string)($args['query'] ?? ''));
            if ($query === '') return ['tool' => 'search_code', 'error' => 'args.query is required'];
            $matches = [];
            foreach (array_unique(array_merge(array_keys($byPath), array_keys($changedFiles))) as $path) {
                $content = $changedFiles[$path] ?? $byPath[$path] ?? '';
                foreach (explode("\n", $content) as $i => $line) {
                    if (stripos($line, $query) !== false) {
                        $matches[] = ['path' => $path, 'line' => $i + 1, 'text' => trim($line)];
                        if (count($matches) >= 20) break 2;
                    }
                }
            }
            return ['tool' => 'search_code', 'result' => ['matches' => $matches, 'truncated' => count($matches) >= 20]];

        case 'read_file':
            $path = $normalizePath((string)($args['path'] ?? ''));
            if ($path === null) return ['tool' => 'read_file', 'error' => 'args.path is missing or unsafe'];
            if (in_array($path, $platformPaths, true)) {
                return ['tool' => 'read_file', 'result' => ['path' => $path, 'content' => $platformFileContent($path),
                    'note' => 'This is the real, current content of this platform-provided file — read-only; ' .
                        'write_file/patch_file on this path is always discarded at deploy time no matter what ' .
                        'you write, so there is never a need to fetch this from a live/preview URL.']];
            }
            $content = $changedFiles[$path] ?? $byPath[$path] ?? null;
            if ($content === null) return ['tool' => 'read_file', 'error' => "no such file: {$path}"];
            $readPaths[$path] = true;
            return ['tool' => 'read_file', 'result' => ['path' => $path, 'content' => $content]];

        // Batch counterpart to read_file, same pattern as write_files below —
        // looking at N files (e.g. every file that touches a shared helper
        // before editing it) used to cost N full round-trips through the
        // model, each paying its own fixed per-call latency overhead for
        // work that has nothing to do with reasoning, just fetching text
        // already known server-side. One call, N results.
        case 'read_files':
            $pathsArg = is_array($args['paths'] ?? null) ? $args['paths'] : null;
            if ($pathsArg === null) return ['tool' => 'read_files', 'error' => 'args.paths must be an array of path strings'];
            $results = [];
            foreach (array_slice($pathsArg, 0, 20) as $p) {
                $results[] = is_string($p)
                    ? ai_run_edit_agent_tool('read_file', ['path' => $p], $byPath, $changedFiles, $readPaths, $config, $projectId, $schema, $frontendStack, $projectTitle)
                    : ['tool' => 'read_file', 'error' => 'each entry must be a path string'];
            }
            return ['tool' => 'read_files', 'result' => ['files' => $results]];

        // Targeted alternative to write_file for a small change to a large
        // file: send only the text to find and its replacement instead of
        // regenerating the whole file. Two direct benefits over always
        // rewriting the full file -- smaller model output (faster to
        // generate, far less likely to hit the truncation path a big
        // write_file response risks) and no chance of silently altering
        // something outside the intended change, since only the exact
        // matched span is touched. args.find must match EXACTLY ONCE in the
        // current content -- ambiguous or missing matches are rejected
        // rather than guessed at, the same "don't regenerate from a guess"
        // principle write_file's own HARD RULE already enforces.
        case 'patch_file':
            $path = $normalizePath((string)($args['path'] ?? ''));
            if ($path === null) return ['tool' => 'patch_file', 'error' => 'args.path is missing or unsafe'];
            if (in_array($path, $platformPaths, true)) {
                return ['tool' => 'patch_file', 'error' => 'platform-provided file — writes to this path are always discarded at deploy time, do not write it'];
            }
            $find = $args['find'] ?? null;
            $replace = $args['replace'] ?? null;
            if (!is_string($find) || $find === '') return ['tool' => 'patch_file', 'error' => 'args.find must be a non-empty string'];
            if (!is_string($replace)) return ['tool' => 'patch_file', 'error' => 'args.replace must be a string'];
            $current = $changedFiles[$path] ?? $byPath[$path] ?? null;
            if ($current === null) return ['tool' => 'patch_file', 'error' => "no such file: {$path} — use write_file to create a new file"];
            if (!isset($readPaths[$path])) {
                return ['tool' => 'patch_file', 'error' =>
                    "\"{$path}\" hasn't been read_file'd yet this session — read it first so your patch is based on its real content, not a guess."];
            }
            $matchCount = substr_count($current, $find);
            if ($matchCount === 0) {
                return ['tool' => 'patch_file', 'error' =>
                    'args.find text was not found in the file — it must match EXACTLY, including whitespace/indentation. ' .
                    're-read the current content and copy the exact text you mean to replace.'];
            }
            if ($matchCount > 1) {
                return ['tool' => 'patch_file', 'error' =>
                    "args.find text appears {$matchCount} times in the file — it must match exactly once. " .
                    'Include more surrounding context in args.find to uniquely identify the one occurrence you mean to change.'];
            }
            $newContent = substr_replace($current, $replace, strpos($current, $find), strlen($find));
            $changedFiles[$path] = $newContent;
            $patchCheck = ai_check_js_syntax($path, $newContent, $config);
            return ['tool' => 'patch_file', 'result' => [
                'path' => $path, 'bytes' => strlen($newContent), 'syntax_ok' => $patchCheck['ok'], 'syntax_error' => $patchCheck['error'],
            ]];

        case 'write_file':
            $path = $normalizePath((string)($args['path'] ?? ''));
            if ($path === null) return ['tool' => 'write_file', 'error' => 'args.path is missing or unsafe'];
            if (in_array($path, $platformPaths, true)) {
                return ['tool' => 'write_file', 'error' => 'platform-provided file — writes to this path are always discarded at deploy time, do not write it'];
            }
            $preExisting = isset($byPath[$path]) && !isset($changedFiles[$path]);
            if ($preExisting && !isset($readPaths[$path])) {
                return ['tool' => 'write_file', 'error' =>
                    "\"{$path}\" already exists and you haven't read_file'd it yet this session — " .
                    'read_file it first so your write is based on its real content, not a guess, ' .
                    'then write_file again with your change merged in.'];
            }
            $content = (string)($args['content'] ?? '');
            $changedFiles[$path] = $content;
            $check = ai_check_js_syntax($path, $content, $config);
            return ['tool' => 'write_file', 'result' => [
                'path' => $path, 'bytes' => strlen($content), 'syntax_ok' => $check['ok'], 'syntax_error' => $check['error'],
            ]];

        // Actually runs a policy's stored constraint_sql against the real
        // database (a harmless, row-count-only dry run — never returns row
        // data) instead of asking the model to eyeball SQL text and guess
        // whether it's valid. A constraint referencing a sibling table is
        // completely normal and, since the platform fix, always resolved to
        // the right physical table — but this tool exists so the agent can
        // verify that for itself (or catch some other, not-yet-known-about
        // constraint bug) instead of taking correctness on faith, the same
        // way the browser-test-agent verifies a page by actually loading it
        // rather than reading its source and guessing.
        case 'check_policy':
            $tableName = trim((string)($args['table'] ?? ''));
            $apiRole   = (string)($args['api_role'] ?? '');
            $operation = strtoupper((string)($args['operation'] ?? ''));
            if ($tableName === '' || !in_array($apiRole, ['anon', 'authenticated'], true)
                || !in_array($operation, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], true)) {
                return ['tool' => 'check_policy', 'error' => 'args must be {table, api_role: "anon"|"authenticated", operation: "SELECT"|"INSERT"|"UPDATE"|"DELETE"}'];
            }
            $catalog = \SupaBein\Catalog::getInstance();
            $tbl = $catalog->getTable($projectId, $tableName);
            if (!$tbl) return ['tool' => 'check_policy', 'error' => "no such table: {$tableName}"];
            $policy = $catalog->getPolicy((int)$tbl['id'], $apiRole, $operation);
            if (!$policy || !$policy['allowed']) {
                return ['tool' => 'check_policy', 'result' => ['allowed' => false, 'note' => 'This role/operation is not allowed at all — nothing to test.']];
            }
            if (empty($policy['constraint_sql'])) {
                return ['tool' => 'check_policy', 'result' => ['allowed' => true, 'has_constraint' => false, 'executes_ok' => true]];
            }
            $constraint = str_replace(':current_user_id', '0', $policy['constraint_sql']);
            try {
                $pdo = \App::get('db');
                $pdo->query('SELECT COUNT(*) FROM `' . $tbl['physical_name'] . '` WHERE (' . $constraint . ')')->fetchColumn();
                return ['tool' => 'check_policy', 'result' => ['allowed' => true, 'has_constraint' => true, 'executes_ok' => true]];
            } catch (\Throwable $e) {
                return ['tool' => 'check_policy', 'result' => [
                    'allowed' => true, 'has_constraint' => true, 'executes_ok' => false,
                    'db_error' => $e->getMessage(),
                    'constraint_sql' => $policy['constraint_sql'],
                ]];
            }

        // Lets the agent check what's ACTUALLY live right now — the currently
        // deployed site's real HTTP response, or a real read against the data
        // API — instead of only reasoning from source text, the same
        // "check, don't guess" idea behind check_policy above. A bug report
        // like "table X won't load" is often a live 500/404/policy-denial
        // that's obvious from one real request and easy to miss just reading
        // code. GET-only by construction (no write/update/delete target) so
        // this can never be the thing that mutates real project data as a
        // side effect of "just checking" — and the URL is always built from
        // this project's OWN known site/deploy info via Catalog::listSites(),
        // never from agent-supplied host input, so it can't become an open
        // SSRF proxy. Only reaches the server: a client-side SPA hash route
        // (e.g. "#/dashboard") never leaves the browser, so this confirms a
        // static file or API response is correct but NOT what a given hash
        // route renders once JS runs — see the tool's own doc string below.
        case 'curl_site':
            if ($projectId <= 0) {
                return ['tool' => 'curl_site', 'error' => 'not available yet — this project has no deployed site until after finish()'];
            }
            $curlTarget = (string)($args['target'] ?? 'site');
            $curlPath   = (string)($args['path'] ?? '/');
            if (!in_array($curlTarget, ['site', 'api'], true)) {
                return ['tool' => 'curl_site', 'error' => 'args.target must be "site" or "api"'];
            }
            if ($curlPath === '' || $curlPath[0] !== '/') $curlPath = '/' . $curlPath;
            $curlCatalog = \SupaBein\Catalog::getInstance();
            $curlSites = $curlCatalog->listSites($projectId);
            if (!$curlSites) return ['tool' => 'curl_site', 'error' => 'no deployed site found for this project yet'];
            $curlSite = $curlSites[0];
            if ($curlSite['staging_deploy_id'] ?? null) {
                $curlVariant = 'staging';
            } elseif ($curlSite['current_deploy_id'] ?? null) {
                $curlVariant = 'current';
            } else {
                return ['tool' => 'curl_site', 'error' => 'no deploy found for this site yet'];
            }
            $curlBase = rtrim($config['API_BASE_URL'] ?? '', '/');
            $curlUrl = $curlTarget === 'api'
                ? $curlBase . '/v1/data/' . $projectId . $curlPath
                : $curlBase . '/sites/s' . (int)$curlSite['id'] . '/' . $curlVariant . $curlPath;
            $ch = curl_init($curlUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);
            $curlBody = curl_exec($ch);
            $curlHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($curlBody === false) {
                return ['tool' => 'curl_site', 'result' => ['url' => $curlUrl, 'error' => $curlErr ?: 'request failed']];
            }
            return ['tool' => 'curl_site', 'result' => [
                'url'         => $curlUrl,
                'http_status' => $curlHttpCode,
                'body'        => mb_substr($curlBody, 0, 3000),
                'truncated'   => strlen($curlBody) > 3000,
                'note'        => $curlTarget === 'site'
                    ? 'Raw HTML/asset response only — a client-side hash route (#/...) never reaches the server, so this confirms the shell/static files load, not what a hash route renders.'
                    : 'This hits the real data API under this project\'s real policies (same as an actual visitor), read-only (GET) — a policy denial or 500 here is real, not a guess.',
            ]];

        // Closes exactly the gap curl_site's own note above admits to: a real,
        // rendered look at a client-side hash route after the app's own JS has
        // run — the thing a report like "Dashboard 404" or "page is blank
        // after login" actually needs, and the thing this session's human
        // diagnoses of those exact bugs used a real browser for. Uses the same
        // Browserless-driven Playwright connection the browser-test-agent
        // already has, just a single one-shot navigate+snapshot instead of a
        // persistent multi-turn click/fill session — read-only, no state
        // mutation beyond whatever the app's own login flow does.
        case 'fetch_page':
            if ($projectId <= 0) {
                return ['tool' => 'fetch_page', 'error' => 'not available yet — this project has no deployed site until after finish()'];
            }
            $fetchToken = $config['BROWSERLESS_TOKEN'] ?? '';
            if (!$fetchToken) {
                return ['tool' => 'fetch_page', 'error' => 'Browserless not configured on this server — this tool is unavailable'];
            }
            $fetchPath = (string)($args['path'] ?? '/');
            $fetchCatalog = \SupaBein\Catalog::getInstance();
            $fetchSites = $fetchCatalog->listSites($projectId);
            if (!$fetchSites) return ['tool' => 'fetch_page', 'error' => 'no deployed site found for this project yet'];
            $fetchSite = $fetchSites[0];
            if ($fetchSite['staging_deploy_id'] ?? null) {
                $fetchVariant = 'staging';
            } elseif ($fetchSite['current_deploy_id'] ?? null) {
                $fetchVariant = 'current';
            } else {
                return ['tool' => 'fetch_page', 'error' => 'no deploy found for this site yet'];
            }
            $fetchAppUrl = rtrim($config['API_BASE_URL'] ?? '', '/') . '/sites/s' . (int)$fetchSite['id'] . '/' . $fetchVariant . '/';
            $fetchAuthInfo = ai_detect_auth($schema);
            $fetchScript = ai_fetch_page_script_generate($fetchAppUrl, $fetchToken, $fetchPath, !empty($fetchAuthInfo['table']));
            $fetchResult = ai_fetch_page_run($fetchScript, $config);
            return ['tool' => 'fetch_page', 'result' => $fetchResult];

        // Runs the SAME deterministic checks ai_validator_check_project() runs
        // after the agent finishes (dead routes, api.* calls against tables
        // that don't exist, auth handlers that don't exist, etc.) but DURING
        // the loop, against whatever's actually been written so far — so a
        // mistake gets caught and fixed before finish(), instead of shipping
        // and only surfacing in a post-hoc report a human has to notice and
        // act on. Only reflects the schema as it exists right now: any
        // add_tables/add_columns this same edit plans to include in finish()
        // aren't real yet, so a file that assumes one of those already exists
        // will still show a false positive here — that's expected, not a bug.
        case 'validate_frontend':
            $merged = $byPath;
            foreach ($changedFiles as $p => $c) $merged[$p] = $c;
            $files = array_map(fn($p) => ['path' => $p, 'content' => $merged[$p]], array_keys($merged));
            $findings = ai_validator_check_project($schema, $files, $frontendStack);
            $errors = array_values(array_filter($findings, fn($f) => $f['severity'] === 'error'));
            return ['tool' => 'validate_frontend', 'result' => [
                'error_count' => count($errors),
                'errors'      => array_slice($errors, 0, 10),
                'note'        => 'Reflects only the schema as it exists right now — any add_tables/add_columns '
                                . 'you plan to include in finish() are not accounted for yet.',
            ]];

        case 'smoke_test':
            $merged = $byPath;
            foreach ($changedFiles as $p => $c) $merged[$p] = $c;
            $files = array_map(fn($p) => ['path' => $p, 'content' => $merged[$p]], array_keys($merged));
            $authInfo = ai_detect_auth($schema);
            return ['tool' => 'smoke_test', 'result' => ai_smoke_test_files($files, $config, $authInfo, $frontendStack, $projectTitle)];

        case 'write_files':
            $filesArg = is_array($args['files'] ?? null) ? $args['files'] : null;
            if ($filesArg === null) return ['tool' => 'write_files', 'error' => 'args.files must be an array of {path, content}'];
            $results = [];
            foreach ($filesArg as $f) {
                $results[] = is_array($f)
                    ? ai_run_edit_agent_tool('write_file', $f, $byPath, $changedFiles, $readPaths, $config, $projectId, $schema, $frontendStack, $projectTitle)
                    : ['tool' => 'write_file', 'error' => 'each entry must be an object with path and content'];
            }
            return ['tool' => 'write_files', 'result' => ['files' => $results]];

        case 'fetch_docs':
            $docUrl = trim((string)($args['url'] ?? ''));
            if ($docUrl === '') return ['tool' => 'fetch_docs', 'error' => 'args.url is required'];
            return ['tool' => 'fetch_docs', 'result' => ai_agent_fetch_docs($docUrl)];

        case 'syntax_check':
            if (isset($args['path'])) {
                $path = $normalizePath((string)$args['path']);
                if ($path === null) return ['tool' => 'syntax_check', 'error' => 'args.path is unsafe'];
                $content = $changedFiles[$path] ?? $byPath[$path] ?? null;
                if ($content === null) return ['tool' => 'syntax_check', 'error' => "no such file: {$path}"];
                $check = ai_check_js_syntax($path, $content, $config);
                return ['tool' => 'syntax_check', 'result' => ['path' => $path, 'ok' => $check['ok'], 'error' => $check['error']]];
            }
            $results = [];
            foreach ($changedFiles as $p => $content) {
                $check = ai_check_js_syntax($p, $content, $config);
                $results[] = ['path' => $p, 'ok' => $check['ok'], 'error' => $check['error']];
            }
            return ['tool' => 'syntax_check', 'result' => ['results' => $results]];

        default:
            return ['error' => "unknown tool \"{$tool}\" — must be one of: list_files, search_code, read_file, read_files, write_file, write_files, patch_file, syntax_check, check_policy, curl_site, fetch_page, smoke_test, fetch_docs, validate_frontend, finish"];
    }
}

function ai_edit_agent_step_label(string $tool, array $args): string
{
    return match ($tool) {
        'list_files'   => 'Listing files…',
        'search_code'  => 'Searching for "' . ($args['query'] ?? '') . '"…',
        'read_file'    => 'Reading ' . ($args['path'] ?? '?') . '…',
        'write_file'   => 'Writing ' . ($args['path'] ?? '?') . '…',
        'write_files'  => 'Writing ' . count($args['files'] ?? []) . ' files…',
        'syntax_check' => 'Checking syntax' . (isset($args['path']) ? ' of ' . $args['path'] : '') . '…',
        'check_policy' => 'Testing ' . ($args['table'] ?? '?') . ' ' . ($args['api_role'] ?? '?') . ' ' . ($args['operation'] ?? '?') . '…',
        'smoke_test'   => 'Loading in a real browser to check for errors…',
        'fetch_docs'   => 'Reading ' . ($args['url'] ?? 'a doc page') . '…',
        'finish'       => 'Finishing up…',
        default        => 'Working…',
    };
}

// ── Edit agent: the loop itself ──────────────────────────────────────────────
// Drives AI_EDIT_AGENT_SYSTEM_PROMPT's ReAct-style loop to completion and
// returns the same delta shape ai_run_edit_generation()'s old single-shot call
// produced: ['add_tables','add_columns','update_policies','seed_data',
// 'frontend'=>['files'=>[...]], 'aiTrace'=>[...]] — so the caller's downstream
// validate/deploy logic needs no changes at all. Also always includes
// 'incomplete' (bool), and when true, 'resume_state'/'turns_used' — the
// turn-budget-exhausted case a follow-up request can pass back in via
// $resumeState to continue this exact session instead of starting over.
/**
 * @param array $refs See ai_generate_intent()'s doc comment for the shape.
 * @param callable|null $checkpoint Called after every turn (and once more
 *   right after finish() validates) with the same shape 'resume_state' below
 *   would hold — lets the caller persist progress DURING the loop, not just
 *   when it gracefully runs out of turns, so a hard worker crash mid-loop
 *   still leaves a checkpoint a retry can resume from.
 */
function ai_run_edit_generation_agentic(
    string $prompt, array $history, array $existingSchema, array $currentFiles,
    object $client, array $config, callable $report, int $projectId, ?array $resumeState = null, array $refs = [], ?callable $checkpoint = null
): array {
    $checkpoint = $checkpoint ?? function (array $state): void {};
    $hasRefs         = !empty($refs['attachments']) || !empty($refs['context']);
    $editAgentPrompt = ai_bind_auth_placeholders(AI_EDIT_AGENT_SYSTEM_PROMPT, $existingSchema)
                     . ($hasRefs ? ai_attachment_instruction_note() : '');
    $schemaCtx       = ai_schema_to_context($existingSchema);

    $byPath = [];
    foreach ($currentFiles as $f) {
        if (isset($f['path'])) $byPath[$f['path']] = (string)($f['content'] ?? '');
    }

    // A crashed prior run's agentic loop had already validated finish() (all
    // that was left was this function returning and the caller's own
    // validate stage) — nothing left to do here, skip the loop entirely and
    // resolve straight to the same delta it was about to produce.
    if (!empty($resumeState['delta_ready'])) {
        $changedFiles = $resumeState['changed_files'] ?? [];
        $delta = $resumeState['finish_args'];
        $delta['frontend'] = ['files' => array_map(
            fn($p) => ['path' => $p, 'content' => $changedFiles[$p]],
            array_keys($changedFiles)
        )];
        $delta['aiTrace']     = [];
        $delta['usage']       = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $delta['incomplete']  = false;
        $report(['stage' => 'changes', 'status' => 'done', 'label' => 'Changes ready (resumed)']);
        return $delta;
    }

    // A resumed run's staged writes were already deployed to staging by the
    // /v1/ai/apply call that followed the PREVIOUS (turn-budget-exhausted)
    // run — see the incomplete/resume_state block below — so $byPath (re-read
    // from disk by the caller) already reflects them too. Seeding
    // $changedFiles/$readPaths from the saved state rather than leaving them
    // empty is what makes this a genuine continuation: those paths are
    // correctly treated as "already changed/read this session", not files a
    // fresh write_file would be blocked on re-reading first.
    $changedFiles = $resumeState['changed_files'] ?? [];
    $readPaths    = $resumeState['read_paths'] ?? [];
    $aiTrace      = [];
    $finishArgs   = null;
    // Aggregated across every turn — a loop of up to AI_EDIT_AGENT_MAX_TURNS
    // calls makes $client->getLastUsage() alone (the old single-shot behavior)
    // wildly undercount the real cost of the edit.
    $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

    if ($resumeState) {
        // Continuing a run that hit its turn budget without ever calling
        // finish() — reuse its full tool-call trace (not just the chat
        // history) and the exact tool result it was about to act on, so the
        // model picks up where it left off instead of re-discovering
        // everything it already read/searched/wrote from turn 1 again.
        $loopHistory = $resumeState['loop_history'] ?? $history;
        $turnMsg = (string)($resumeState['next_turn_msg'] ?? '')
                 . "\n\n(You previously ran out of turns partway through this exact request. You now have a "
                 . 'fresh ' . AI_EDIT_AGENT_MAX_TURNS . ' turns — do NOT restart or redo anything shown above, '
                 . 'pick up exactly where you left off and call finish() once the original request is fully done.)';
    } else {
        $turnMsg = "Exact schema:\n{$schemaCtx}\n\nFile listing (" . count($byPath) . " files): "
                 . implode(', ', array_keys($byPath)) . "\n\nRequest: {$prompt}\n\n"
                 . (!empty($refs['context']) ? "{$refs['context']}\n\n" : '')
                 . 'Respond with your first tool action.';
        $loopHistory = $history;
    }
    $recentCalls = [];
    $consecutiveParseFailures = 0;
    $lastSmokeTestOk = null; // null = never called this session; true/false = its last result
    $lastSmokeTestWasConnectionError = false; // true if the last failure was Browserless itself, not the app
    $consecutiveFinishRejections = 0; // escalates a repeatedly-rejected finish() -- see gate below
    $lastFailedFile = null; // the file a failing smoke_test's console_errors pointed at, if any
    $lastFailedFileSnapshot = null; // that file's content at the moment of the failure -- see finish() gate below
    $lastFailedFileErrorSignature = null; // hash of the last failing smoke_test's console_errors -- see rewrite guard below
    $consecutiveIdenticalSmokeTestFailures = 0; // see ai_agent_check_canonical_wrapper_abandonment() gate below
    $writesSinceLastCheck = 0; // consecutive write_file/write_files/patch_file calls with no intervening smoke_test

    for ($turn = 1; $turn <= AI_EDIT_AGENT_MAX_TURNS; $turn++) {
        $_t0 = microtime(true);
        try {
            // Same one-time-only attach as the frontend build agent — see its
            // matching comment in ai_run_build_frontend_agentic().
            $turnAttachments = $turn === 1 ? ($refs['attachments'] ?? []) : [];
            $action = $client->generateJsonWithHistory($editAgentPrompt, $loopHistory, $turnMsg, $turnAttachments, true,
                ai_agent_retry_reporter($report, 'changes', 'Generating changes…'));
        } catch (\Throwable $e) {
            if (ai_is_unrecoverable_provider_error($e->getMessage())) {
                throw new \RuntimeException('AI provider error during edit generation: ' . $e->getMessage());
            }
            // A raw parse/HTTP failure from the client (e.g. a truncated
            // write_file response that cuts off mid-JSON) would otherwise
            // throw straight out of this loop and fail the whole job,
            // discarding every turn already staged. Treat it as one more
            // recoverable turn instead — same budget, no special-casing
            // needed downstream.
            $ms = (int)((microtime(true) - $_t0) * 1000);
            $aiTrace[] = ['stage' => 'edit_agent', 'system' => $editAgentPrompt, 'history' => [],
                'user_msg' => mb_strlen($turnMsg) > 3000 ? mb_substr($turnMsg, 0, 3000) : $turnMsg,
                'response' => ['error' => $e->getMessage()], 'tokens' => $client->getLastUsage(), 'ms' => $ms, 'retry' => true, 'error' => $e->getMessage()];
            // A rate limit isn't the model's fault -- telling it "your JSON
            // was malformed, write something shorter" is actively wrong
            // feedback for a request that was rejected before generation
            // even started, and would count toward the escalating "you keep
            // failing" nudge for something the model never did.
            if (ai_agent_is_rate_limited($e->getMessage())) {
                $report(['stage' => 'changes', 'status' => 'active', 'label' => 'Generating changes…',
                    'detail' => 'Rate limited by the AI provider — waiting…']);
            } else {
                $report(['stage' => 'changes', 'status' => 'active', 'label' => 'Generating changes…',
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

        $aiTrace[] = ['stage' => 'edit_agent', 'system' => $editAgentPrompt, 'history' => [],
            'user_msg' => mb_strlen($turnMsg) > 3000 ? mb_substr($turnMsg, 0, 3000) : $turnMsg,
            'response' => $action, 'tokens' => $usage, 'ms' => $ms, 'retry' => false];

        // The 'detail' string here is rendered directly into the real
        // dashboard's AI panel (dashboard/assets/app.js's progress-detail
        // div) -- a live user watching their app get built, not a
        // diagnostics surface. Keep it to the short label; the actual write
        // content goes to the server log instead, via
        // ai_log_agent_write_content() below, a channel with zero UI exposure.
        $report(['stage' => 'changes', 'status' => 'active', 'label' => 'Generating changes…',
            'detail' => ai_edit_agent_step_label($tool, $args)]);
        ai_log_agent_write_content('edit', $tool, $args, $projectId);

        $loopHistory[] = ['role' => 'user', 'text' => $turnMsg];
        $loopHistory[] = ['role' => 'model', 'text' => ai_agent_history_action_json($action)];
        $loopHistory   = ai_agent_trim_history($loopHistory);

        if ($tool === 'finish') {
            // A smoke_test that came back broken and was never fixed (or
            // re-checked) must not be allowed to silently ship — this is
            // exactly the gap that let the calculator app's "this.loadState
            // is not a function" crash reach a real deploy: the tool to
            // catch it existed by the time this check was added, but
            // nothing stopped finish() from being called anyway. But a
            // Browserless CONNECTION failure (quota, network) is not
            // evidence of an app bug -- live-observed in the frontend build
            // agent: the model correctly diagnosed this exact case every
            // time, yet had no way to satisfy this gate since re-running
            // smoke_test hit the same persistent quota exhaustion again,
            // rejected 20 turns straight until the turn limit forced a
            // finish anyway. Only a REAL smoke_test failure blocks finish().
            // A rejection here used to just repeat the same static message
            // forever -- live-observed in the frontend build agent: one run
            // called finish() 14 times straight against an unresolved
            // failure, each time with an identical fabricated "all done"
            // justification, never once re-running smoke_test to check.
            // ai_agent_note_finish_rejected() escalates to a forceful,
            // specific instruction once that pattern repeats instead of
            // leaving it to grind to the turn limit.
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
            $candidateDelta = [
                'add_tables'      => $args['add_tables'] ?? [],
                'add_columns'     => $args['add_columns'] ?? [],
                'update_policies' => $args['update_policies'] ?? [],
                'seed_data'       => $args['seed_data'] ?? [],
            ];
            $deltaError = ai_validate_delta($candidateDelta, $existingSchema);
            if ($deltaError === null) {
                $finishArgs = $candidateDelta;
                $checkpoint(['delta_ready' => true, 'finish_args' => $finishArgs, 'changed_files' => $changedFiles]);
                break;
            }
            $turnMsg = json_encode(['tool' => 'finish', 'error' => ai_agent_note_finish_rejected(
                $consecutiveFinishRejections,
                "Your finish() was rejected: {$deltaError}. Continue working and call finish() again once it's fixed."
            )]);
            continue;
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
        // continues or breaks above) — the "did something else" signal
        // ai_agent_note_finish_rejected()'s escalation should reset on.
        $consecutiveFinishRejections = 0;

        // Mechanical cadence gate (job 210, task #192): reject the write
        // itself, before it's even dispatched, once too many have piled up
        // unchecked. See AI_AGENT_MAX_UNVERIFIED_WRITES for why.
        if (in_array($tool, ['write_file', 'write_files', 'patch_file'], true)
            && $writesSinceLastCheck >= AI_AGENT_MAX_UNVERIFIED_WRITES) {
            $turnMsg = json_encode(['tool' => $tool, 'error' =>
                ai_agent_note_unverified_writes_blocked($writesSinceLastCheck)]);
            continue;
        }

        // Repeated-failure rewrite guard (job 210, task #193): only fires
        // once the same error has repeated enough times to make "rewrite it"
        // a live temptation, and only when the write actually targets the
        // implicated file.
        if (in_array($tool, ['write_file', 'patch_file'], true) && $lastFailedFile !== null
            && $consecutiveIdenticalSmokeTestFailures >= AI_AGENT_MAX_IDENTICAL_SMOKE_FAILURES
            && ($args['path'] ?? null) === $lastFailedFile) {
            $oldContent = $changedFiles[$lastFailedFile] ?? $byPath[$lastFailedFile] ?? '';
            if ($tool === 'write_file') {
                $newContent = (string)($args['content'] ?? '');
            } else {
                $find = (string)($args['find'] ?? '');
                $replace = (string)($args['replace'] ?? '');
                $newContent = ($find !== '' && str_contains($oldContent, $find))
                    ? substr_replace($oldContent, $replace, strpos($oldContent, $find), strlen($find))
                    : $oldContent;
            }
            if (ai_agent_check_canonical_wrapper_abandonment($oldContent, $newContent)) {
                $turnMsg = json_encode(['tool' => $tool, 'error' =>
                    ai_agent_note_repeated_failure_rewrite_blocked($lastFailedFile)]);
                continue;
            }
        }

        if (in_array($tool, ['write_file', 'write_files', 'patch_file'], true)) {
            $writesSinceLastCheck++;
        }

        $toolResult = ai_run_edit_agent_tool($tool, $args, $byPath, $changedFiles, $readPaths, $config, $projectId, $existingSchema);
        if ($tool === 'smoke_test') {
            $writesSinceLastCheck = 0;
            $lastSmokeTestOk = $toolResult['result']['ok'] ?? null;
            $lastSmokeTestWasConnectionError = !empty($toolResult['result']['connection_error']);
            ai_pipeline_debug_log('edit', 'smoke_test result (ok=' . json_encode($lastSmokeTestOk) . ')', ['project_id' => $projectId, 'result' => $toolResult['result'] ?? []]);
            if ($lastSmokeTestOk === false) {
                $lastFailedFile = $toolResult['result']['next_step_file'] ?? null;
                $lastFailedFileSnapshot = $lastFailedFile !== null ? ($changedFiles[$lastFailedFile] ?? null) : null;
                $errorSignature = md5(json_encode($toolResult['result']['console_errors'] ?? []));
                $consecutiveIdenticalSmokeTestFailures = ($errorSignature === $lastFailedFileErrorSignature)
                    ? $consecutiveIdenticalSmokeTestFailures + 1 : 0;
                $lastFailedFileErrorSignature = $errorSignature;
                // Report the REAL outcome, not just the static pre-dispatch
                // label — this is the only channel available while the job
                // is still running (see ai_smoke_test_failure_progress_detail()).
                $report(['stage' => 'changes', 'status' => 'active', 'label' => 'Generating changes…',
                    'detail' => ai_smoke_test_failure_progress_detail($toolResult['result'] ?? [])]);
            } else {
                $lastFailedFile = null;
                $lastFailedFileSnapshot = null;
                $lastFailedFileErrorSignature = null;
                $consecutiveIdenticalSmokeTestFailures = 0;
            }
        }
        $turnMsg = json_encode($toolResult);

        // Persisted every turn (not just when the turn budget runs out below)
        // so a worker killed mid-loop by the host — not just one that
        // gracefully exhausts its turns — still leaves a resumable
        // checkpoint. Same shape as the turn-budget-exhausted resume_state.
        $checkpoint(['loop_history' => $loopHistory, 'changed_files' => $changedFiles, 'read_paths' => $readPaths, 'next_turn_msg' => $turnMsg]);
    }

    $incomplete = false;
    if ($finishArgs === null) {
        // Turn budget exhausted without a validated finish — force-finish with
        // whatever's staged rather than hang or hard-fail the whole job; empty
        // schema-change arrays always pass validation. Also capture enough of
        // the in-progress agent state (its own tool-call trace and the tool
        // result it hadn't acted on yet — not just the files it happened to
        // have written) that a follow-up request can genuinely CONTINUE this
        // same session with a fresh turn budget, instead of the only other
        // option being to discard all of it and start over from turn 1 with
        // no memory of what was already read, searched, or decided.
        $incomplete = true;
        $report(['stage' => 'changes', 'status' => 'active', 'label' => 'Generating changes…',
            'detail' => 'Turn limit reached — finishing with what was staged']);
        $finishArgs = ['add_tables' => [], 'add_columns' => [], 'update_policies' => [], 'seed_data' => []];
    }

    $delta = $finishArgs;
    $delta['frontend'] = ['files' => array_map(
        fn($p) => ['path' => $p, 'content' => $changedFiles[$p]],
        array_keys($changedFiles)
    )];
    $delta['aiTrace'] = $aiTrace;
    $delta['usage']   = $totalUsage;
    $delta['incomplete'] = $incomplete;
    if ($incomplete) {
        $delta['resume_state'] = [
            'loop_history'  => $loopHistory,
            'changed_files' => $changedFiles,
            'read_paths'    => $readPaths,
            'next_turn_msg' => $turnMsg,
        ];
        $delta['turns_used'] = AI_EDIT_AGENT_MAX_TURNS;
    }
    return $delta;
}

/**
 * @param array $refs See ai_generate_intent()'s doc comment for the shape.
 * @param callable|null $checkpoint See ai_run_edit_generation_agentic()'s doc
 *   comment — wired by the worker to persist progress DURING the agentic
 *   loop via Catalog::saveJobCheckpoint(), not just when it returns.
 */
function ai_run_edit_generation(int $projectId, string $prompt, array $history, object $client, \SupaBein\Catalog $catalog, array $config, callable $report, bool $validate = true, ?array $resumeState = null, array $refs = [], ?callable $checkpoint = null): array
{
    $aiTrace = [];

    // AI-driven editing of a react-stack project isn't supported yet — the
    // edit agent's system prompt and tool executor (ai_run_edit_agent_tool)
    // still assume the vanilla <script src>/router.defineRoute contract.
    // Running it against a project's real JSX files would silently corrupt
    // them (wrong platform-path list, wrong canonical file content injected
    // on read_file, wrong validator rules) rather than fail loudly, so this
    // is refused up front instead. Building from scratch (ai_run_build_frontend)
    // already fully supports frontend_stack === 'react'; only the edit path
    // is scoped out of this first iteration.
    $projectRow = $catalog->getProjectByIdInternal($projectId);
    if (($projectRow['frontend_stack'] ?? 'vanilla') === 'react') {
        throw new \RuntimeException('AI-assisted editing of React-stack projects is not supported yet — this is on the roadmap.');
    }

    $report(['stage' => 'read', 'status' => 'start', 'label' => 'Reading current schema & files…']);
    $existingSchema = ai_schema_from_db($projectId, $catalog);

    // Repair any policy written before ai_rewrite_constraint_table_refs()
    // existed — that fix only stops a NEW broken cross-table reference from
    // being written, it can't undo one already stored. Every edit is a
    // natural, low-cost point to self-heal this, so a project doesn't stay
    // broken forever just because nobody happened to touch this exact policy
    // since the platform fix shipped.
    $healedCount = ai_heal_project_policy_refs($projectId, $catalog, $existingSchema['tables'] ?? []);
    if ($healedCount > 0) {
        $existingSchema = ai_schema_from_db($projectId, $catalog);
        $report(['stage' => 'read', 'status' => 'active', 'label' => 'Reading current schema & files…',
                 'detail' => "Repaired {$healedCount} pre-existing polic" . ($healedCount === 1 ? 'y' : 'ies') . " with broken cross-table references"]);
    }

    // Same self-heal shape as the policy repair above, for seeded image URLs
    // that predate the write-time fix in ai_insert_seed_data() — a project
    // seeded before that shipped would otherwise stay stuck with broken
    // image_url values forever.
    $healedImages = ai_heal_seed_image_urls(\App::get('db'), $catalog, $projectId);
    if ($healedImages > 0) {
        $report(['stage' => 'read', 'status' => 'active', 'label' => 'Reading current schema & files…',
                 'detail' => "Replaced {$healedImages} broken seeded image URL" . ($healedImages === 1 ? '' : 's') . " with real, working ones"]);
    }

    // Full file set (not the old pre-filtered text blob) — the agent loop
    // below decides for itself what it needs to read via search_code/read_file
    // instead of being handed either everything or a bare listing up front.
    $currentFiles = ai_read_full_frontend_files($config, $catalog, $projectId);
    $report(['stage' => 'read', 'status' => 'done', 'label' => 'Loaded current project']);

    $report(['stage' => 'changes', 'status' => 'start', 'label' => 'Generating changes…']);
    $delta = ai_run_edit_generation_agentic($prompt, $history, $existingSchema, $currentFiles, $client, $config, $report, $projectId, $resumeState, $refs, $checkpoint);
    $aiTrace     = array_merge($aiTrace, $delta['aiTrace']);
    $editUsage   = $delta['usage'];
    $incomplete  = $delta['incomplete'] ?? false;
    $resumeOut   = $delta['resume_state'] ?? null;
    $turnsUsed   = $delta['turns_used'] ?? null;
    // Hoisted to the top level of this function's return (like 'validation'
    // below) rather than left inside $editPlan — this is agent-loop metadata
    // about the run itself, not part of the schema/frontend delta that
    // ai_execute_edit()/ai_deploy_files() actually apply.
    unset($delta['aiTrace'], $delta['usage'], $delta['incomplete'], $delta['resume_state'], $delta['turns_used']);

    $report(['stage' => 'changes', 'status' => 'done', 'label' => 'Changes ready', 'detail' => (count($delta['add_tables'] ?? []) + count($delta['add_columns'] ?? []) + count($delta['update_policies'] ?? [])) . ' schema change(s), ' . count($delta['frontend']['files'] ?? []) . ' file(s)']);

    // ── Validate (deterministic; AI only explains, never detects) ─────────
    // An edit's delta only contains CHANGED files, so validate against the
    // full current file set with the delta merged on top — the same
    // mergeFromCurrent view ai_deploy_files() will actually publish — and
    // the schema as it will look once add_tables/add_columns are applied.
    $validation = [];
    if ($validate) {
        $report(['stage' => 'validate', 'status' => 'start', 'label' => 'Checking for mismatches…']);

        $mergedSchema = $existingSchema;
        foreach ($delta['add_tables'] ?? [] as $t) $mergedSchema['tables'][] = $t;
        foreach ($delta['add_columns'] ?? [] as $entry) {
            foreach ($mergedSchema['tables'] as &$t) {
                if ($t['name'] === $entry['table']) $t['columns'] = array_merge($t['columns'] ?? [], $entry['columns'] ?? []);
            }
            unset($t);
        }

        $byPath = [];
        foreach (ai_read_full_frontend_files($config, $catalog, $projectId) as $f) $byPath[$f['path']] = $f;
        foreach ($delta['frontend']['files'] ?? [] as $f) $byPath[$f['path']] = $f;

        $validation = ai_validator_check_project($mergedSchema, array_values($byPath));
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
        'add_tables'      => array_column($delta['add_tables'] ?? [], 'name'),
        'add_columns'     => array_merge(...array_map(fn($e) => array_map(fn($c) => $e['table'] . '.' . $c['name'], $e['columns'] ?? []), $delta['add_columns'] ?? []) ?: [[]]),
        'update_policies' => array_map(fn($p) => $p['table'] . ' ' . $p['api_role'] . ' ' . $p['operation'], $delta['update_policies'] ?? []),
    ];
    if (!empty($delta['frontend']['files'])) $summary['frontend_files'] = count($delta['frontend']['files']);

    $editPlan = array_merge($delta, ['project_id' => $projectId]);

    return [
        'plan'         => $editPlan,
        'summary'      => $summary,
        'usage'        => $editUsage,
        'aiTrace'      => $aiTrace,
        'validation'   => $validation,
        'incomplete'   => $incomplete,
        'resume_state' => $resumeOut,
        'turns_used'   => $turnsUsed,
    ];
}