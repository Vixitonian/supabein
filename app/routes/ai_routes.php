<?php

declare(strict_types=1);

require_once SUPABEIN_ROOT . '/app/core/gemini_client.php';
require_once SUPABEIN_ROOT . '/app/core/openrouter_client.php';
require_once SUPABEIN_ROOT . '/app/core/deploy.php';

// ─── Gemini system prompts ───────────────────────────────────────────────────

const AI_BUILD_SYSTEM_PROMPT = <<<'PROMPT'
You are a full-stack architect for SupaBein, a self-hosted BaaS platform.
The user will describe an application in plain English.
You must return ONLY a single valid JSON object — no markdown fences, no explanation, no extra text.

The JSON object must conform exactly to this schema:

{
  "project_name": string,
  "subdomain": string,
  "tables": [
    {
      "name": string,
      "columns": [
        {
          "name": string,
          "type": string,
          "nullable": boolean,
          "default": string or null
        }
      ],
      "policies": [
        {
          "api_role": string,
          "operation": string,
          "allowed": boolean,
          "constraint_sql": string or null
        }
      ]
    }
  ],
  "frontend": {
    "files": [
      { "path": string, "content": string }
    ]
  }
}

Field rules:
- project_name: human-readable name, 1-80 characters
- subdomain: 3-30 lowercase alphanumeric + hyphens, no leading/trailing hyphens (e.g. "my-blog")
- table.name: valid SQL identifier matching /^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/
  Do NOT use SQL reserved words (SELECT, INSERT, TABLE, INDEX, KEY, WHERE, FROM, etc.)
- column.name: same rules as table name; do NOT include "id" or "created_at" (auto-generated)
- column.type: MUST be exactly one of:
    INT, BIGINT, SMALLINT, TINYINT,
    VARCHAR(255), VARCHAR(128), VARCHAR(64), VARCHAR(36), VARCHAR(32),
    TEXT, MEDIUMTEXT, LONGTEXT,
    BOOLEAN, TINYINT(1),
    DECIMAL(10,2), DECIMAL(15,4),
    FLOAT, DOUBLE,
    DATETIME, DATE, TIMESTAMP, JSON
- column.default: literal values only (e.g. "0", "1", "active") or null — no SQL functions
- policy.api_role: "anon" or "authenticated"
- policy.operation: "SELECT", "INSERT", "UPDATE", or "DELETE"
- policy.constraint_sql: simple WHERE-style expression or null (no subqueries, no DML)
  IMPORTANT: use ":current_user_id" as the placeholder for the logged-in user's ID
  (e.g. "user_id = :current_user_id"). Do NOT use "auth.uid()" — it is not supported.

Frontend rules:
- Must include "index.html" as the SPA entry point
- Split code across as many files as the app warrants — separate concerns properly:
    css/style.css for all styles, js/api.js for the SupaBein fetch client,
    js/router.js for client-side routing, js/auth.js for auth helpers,
    js/views/home.js, js/views/login.js etc. for individual page components
  Do NOT cram everything into one or two files. Structure it like a production project.
- Use these exact placeholders ONLY in js/auth.js (they are substituted at deploy time):
    const SB_URL = '__SB_URL__';   // e.g. http://example.com/api
    const SB_KEY = '__SB_ANON_KEY__';
    const SB_PID = '__SB_PID__';
  Do NOT redeclare SB_URL/SB_KEY/SB_PID in any other file — they are globals from auth.js.
- SupaBein API URL patterns (build your fetch calls using these — do NOT invent other formats):
    Data list/create:  ${SB_URL}/v1/data/${SB_PID}/${tableName}
    Data get/update/delete by id:  ${SB_URL}/v1/data/${SB_PID}/${tableName}/${id}
    Auth signup:  ${SB_URL}/v1/projects/${SB_PID}/auth/signup  → POST {email,password} → {token}
    Auth login:   ${SB_URL}/v1/projects/${SB_PID}/auth/login   → POST {email,password} → {token}
    Auth me:      ${SB_URL}/v1/projects/${SB_PID}/auth/me      → GET  Authorization: Bearer {token}
    The token returned by login/signup is a JWT — store in localStorage as "sb:token".
    To authenticate data requests: send header  Authorization: Bearer {token}  (use anon key if not logged in).
- index.html must load all JS and CSS files with RELATIVE paths (no leading slash):
    <link rel="stylesheet" href="css/style.css">     ← correct
    <script src="js/auth.js"></script>               ← correct
    NOT <script src="/js/auth.js">                   ← wrong — absolute paths break the site
- Use vanilla JS only — no frameworks, no npm, no build tools
- Use a dark theme with CSS variables: --bg: #0f1117; --surface: #1a1d27; --accent: #3ecf8e; --text: #e2e8f0; --muted: #8892a4; --danger: #ef4444; --border: #2d3045;
- The app must be fully functional — real fetch calls, real CRUD, real auth flows

Access control guidelines:
- anon role: SELECT=true only on genuinely public tables; all else false
- authenticated role: SELECT/INSERT/UPDATE/DELETE=true on tables the user owns or can interact with
- If the app has user-generated content, ensure policies enforce ownership where appropriate
- When a table has a column like "user_id" with constraint_sql "user_id = :current_user_id":
    The INSERT frontend code MUST include that column in the POST body by decoding the JWT:
      const payload = JSON.parse(atob(token.split('.')[1]));
      body.user_id = parseInt(payload.sub, 10);
    (The backend also enforces this via constraint injection, but the frontend must send it
    so the NOT NULL constraint is satisfied even in strict DB modes.)

Always include at least one table. Generate all tables the described app needs.
PROMPT;

const AI_EDIT_SYSTEM_PROMPT = <<<'PROMPT'
You are a backend architect for SupaBein, a self-hosted BaaS platform.
The user wants to MODIFY an existing project. You will be given the current schema and a change request.
Return ONLY a single valid JSON object — no markdown fences, no explanation, no extra text.

Schema:
{
  "add_tables": [
    {
      "name": string,
      "columns": [
        {"name": string, "type": string, "nullable": boolean}
      ],
      "policies": [
        {"api_role": "anon"|"authenticated", "operation": "SELECT"|"INSERT"|"UPDATE"|"DELETE", "allowed": boolean}
      ]
    }
  ],
  "add_columns": [
    {
      "table": string,
      "columns": [
        {"name": string, "type": string, "nullable": boolean}
      ]
    }
  ],
  "update_policies": [
    {"table": string, "api_role": "anon"|"authenticated", "operation": "SELECT"|"INSERT"|"UPDATE"|"DELETE", "allowed": boolean}
  ]
}

Rules:
- Do NOT include tables or columns that already exist in the current schema.
- Do NOT drop or rename anything — only additions and policy changes.
- column.type MUST be exactly one of: INT, BIGINT, SMALLINT, TINYINT, VARCHAR(255), VARCHAR(128), VARCHAR(64), VARCHAR(36), VARCHAR(32), TEXT, MEDIUMTEXT, LONGTEXT, BOOLEAN, TINYINT(1), DECIMAL(10,2), DECIMAL(15,4), FLOAT, DOUBLE, DATETIME, DATE, TIMESTAMP, JSON
- table.name and column.name: valid SQL identifiers /^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/; avoid SQL reserved words; do NOT use "id" or "created_at"
- If no changes of a given type are needed, return an empty array [] for that key.
PROMPT;

// ─── File-level helpers (filesystem) ────────────────────────────────────────

function ai_deploy_files(
    array $config,
    \SupaBein\Catalog $catalog,
    int $siteId,
    array $project,
    array $frontendFiles
): array {
    $sitesPath = rtrim($config['SITES_PATH'], '/');
    $label     = 'ai-generated-' . date('Y-m-d');

    $deploy   = $catalog->createDeploy($siteId, $label, 0);
    $deployId = (int)$deploy['id'];
    $catalog->updateDeploy($deployId, 'processing');

    $deployDir = $sitesPath . '/s' . $siteId . '/deploys/'
               . date('Ymd_His') . '_' . $deployId;

    if (!is_dir($deployDir) && !mkdir($deployDir, 0755, true)) {
        $catalog->updateDeploy($deployId, 'failed');
        return ['error' => 'Cannot create deploy directory', 'deploy' => null];
    }

    // Substitution map — replace placeholders with real credentials
    $apiBase = rtrim($config['API_BASE_URL'], '/') . '/v1';
    $replacements = [
        '__SB_URL__'      => $apiBase,
        '__SB_ANON_KEY__' => $project['anon_key'],
        '__SB_PID__'      => (string)$project['id'],
    ];

    $errors = [];
    foreach ($frontendFiles as $fileDef) {
        $relPath = ltrim((string)($fileDef['path'] ?? ''), '/');
        $relPath = str_replace('..', '', $relPath);
        if ($relPath === '') continue;

        $fullPath = \SupaBein\Deploy::normalizePath($deployDir . '/' . $relPath);
        if (!str_starts_with($fullPath, $deployDir . '/')) {
            $errors[] = 'Path traversal attempt: ' . $relPath;
            continue;
        }

        $parentDir = dirname($fullPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            (string)($fileDef['content'] ?? '')
        );

        if (file_put_contents($fullPath, $content) === false) {
            $errors[] = 'Cannot write file: ' . $relPath;
        }
    }

    if ($errors) {
        \SupaBein\Deploy::rrmdir($deployDir);
        $catalog->updateDeploy($deployId, 'failed');
        return ['error' => implode('; ', $errors), 'deploy' => null];
    }

    // Overwrite with hardening .htaccess (cannot be skipped)
    $htaccess = \SupaBein\Deploy::buildHardeningHtaccess(true);
    file_put_contents($deployDir . '/.htaccess', $htaccess);

    // Calculate total size
    $totalSize = 0;
    $iterator  = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($deployDir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $f) {
        if ($f->isFile()) $totalSize += $f->getSize();
    }
    \App::get('db')->prepare('UPDATE deploys SET size_bytes = ? WHERE id = ?')
                   ->execute([$totalSize, $deployId]);

    // Copy to staging/
    $stagingDir = $sitesPath . '/s' . $siteId . '/staging';
    if (is_dir($stagingDir))   \SupaBein\Deploy::rrmdir($stagingDir);
    if (is_link($stagingDir))  unlink($stagingDir);
    \SupaBein\Deploy::rcopy($deployDir, $stagingDir);

    if (!is_dir($stagingDir)) {
        $catalog->updateDeploy($deployId, 'failed', $deployDir);
        return ['error' => 'Staging copy failed', 'deploy' => null];
    }

    $catalog->updateDeploy($deployId, 'ready', $deployDir);
    $catalog->updateSiteStagingDeploy($siteId, $deployId);

    // Auto-publish to current/ for AI builds so the site is live immediately
    $currentDir = $sitesPath . '/s' . $siteId . '/current';
    if (is_dir($currentDir))  \SupaBein\Deploy::rrmdir($currentDir);
    if (is_link($currentDir)) unlink($currentDir);
    \SupaBein\Deploy::rcopy($stagingDir, $currentDir);
    $catalog->updateSiteCurrentDeploy($siteId, $deployId);
    $catalog->updateSiteStagingDeploy($siteId, null);

    return ['error' => null, 'deploy' => $catalog->getDeployById($deployId)];
}

// ─── Validation helpers ──────────────────────────────────────────────────────

function ai_validate_plan(array $plan): ?string
{
    if (empty($plan['project_name']) || strlen($plan['project_name']) > 80) {
        return 'project_name missing or too long';
    }
    if (!isset($plan['subdomain']) || !preg_match('/^[a-z0-9][a-z0-9\-]{1,28}[a-z0-9]$/', $plan['subdomain'])) {
        return 'subdomain must be 3-30 lowercase alphanumeric + hyphens';
    }
    if (empty($plan['tables']) || !is_array($plan['tables'])) {
        return 'tables array is required';
    }
    foreach ($plan['tables'] as $i => $t) {
        try {
            \SupaBein\Schema::validateIdentifier($t['name'] ?? '');
        } catch (\InvalidArgumentException $e) {
            return "tables[$i].name: " . $e->getMessage();
        }
        foreach ($t['columns'] ?? [] as $j => $col) {
            try {
                $colName = \SupaBein\Schema::validateIdentifier($col['name'] ?? '');
            } catch (\InvalidArgumentException $e) {
                return "tables[$i].columns[$j].name: " . $e->getMessage();
            }
            if (in_array(strtolower($colName), ['id', 'created_at'], true)) {
                return "tables[$i].columns[$j].name: 'id' and 'created_at' are reserved";
            }
            try {
                \SupaBein\Schema::validateDataType($col['type'] ?? '');
            } catch (\InvalidArgumentException $e) {
                return "tables[$i].columns[$j].type: " . $e->getMessage();
            }
        }
        foreach ($t['policies'] ?? [] as $k => $p) {
            if (!in_array($p['api_role'] ?? '', ['anon', 'authenticated'], true)) {
                return "tables[$i].policies[$k].api_role must be 'anon' or 'authenticated'";
            }
            if (!in_array(strtoupper($p['operation'] ?? ''), ['SELECT','INSERT','UPDATE','DELETE'], true)) {
                return "tables[$i].policies[$k].operation must be SELECT, INSERT, UPDATE, or DELETE";
            }
        }
    }
    return null;
}

// ─── Execution helpers ───────────────────────────────────────────────────────

function ai_execute_build(array $plan, int $userId): array
{
    $config  = \App::get('config');
    $catalog = \SupaBein\Catalog::getInstance();
    $pdo     = \App::get('db');

    $projectName = trim($plan['project_name']);
    $partial     = ['project' => null, 'tables' => [], 'site' => null];

    try {
        $project = $catalog->createProject($userId, $projectName);
        $partial['project'] = $project;
        $projectId = (int)$project['id'];
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            abort(409, "A project named \"$projectName\" already exists");
        }
        abort(500, 'Failed to create project: ' . $e->getMessage());
    }

    foreach ($plan['tables'] as $tableDef) {
        $tableName = $tableDef['name'];

        $columns = [];
        foreach ($tableDef['columns'] ?? [] as $col) {
            $columns[] = [
                'name'     => \SupaBein\Schema::validateIdentifier($col['name']),
                'type'     => \SupaBein\Schema::validateDataType($col['type']),
                'nullable' => (bool)($col['nullable'] ?? true),
                'default'  => isset($col['default']) ? (string)$col['default'] : null,
            ];
        }

        try {
            $table = $catalog->createTable($projectId, $tableName);
        } catch (\PDOException $e) {
            $catalog->deleteProject($projectId, $userId);
            abort(500, "Table creation failed for \"$tableName\": " . $e->getMessage());
        }

        try {
            $ddl = \SupaBein\Schema::createTableDDL($table['physical_name'], $columns);
            \SupaBein\Schema::applyDDL($pdo, $projectId, $ddl);
        } catch (\Throwable $e) {
            $catalog->deleteTable($projectId, $tableName);
            $catalog->deleteProject($projectId, $userId);
            abort(500, "DDL failed for table \"$tableName\": " . $e->getMessage());
        }

        foreach ($columns as $col) {
            $catalog->addColumn($table['id'], $col['name'], $col['type'], $col['nullable'], $col['default']);
        }

        foreach ($tableDef['policies'] ?? [] as $policy) {
            try {
                $catalog->upsertPolicy(
                    $table['id'],
                    $policy['api_role'],
                    strtoupper($policy['operation']),
                    (bool)$policy['allowed'],
                    $policy['constraint_sql'] ?? null
                );
            } catch (\Throwable $e) {
                sb_log('ai_build', 'Policy upsert failed (non-fatal): ' . $e->getMessage(), ['table' => $tableName]);
            }
        }

        $partial['tables'][] = ['name' => $tableName, 'columns' => count($columns)];
    }

    $subdomain = $plan['subdomain'];
    $site      = null;
    $deploy    = null;

    try {
        $site = $catalog->createSite($projectId, $subdomain, true);
        $partial['site'] = $site;
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            $subdomain = $subdomain . '-' . $projectId;
            try {
                $site = $catalog->createSite($projectId, $subdomain, true);
                $partial['site'] = $site;
            } catch (\PDOException $e2) {
                sb_log('ai_build', 'Site creation failed (non-fatal): ' . $e2->getMessage());
            }
        } else {
            sb_log('ai_build', 'Site creation failed (non-fatal): ' . $e->getMessage());
        }
    }

    if ($site !== null && !empty($plan['frontend']['files'])) {
        $deployResult = ai_deploy_files(
            $config,
            $catalog,
            (int)$site['id'],
            $project,
            $plan['frontend']['files']
        );
        if ($deployResult['error']) {
            sb_log('ai_build', 'Deploy failed (non-fatal): ' . $deployResult['error']);
        } else {
            $deploy = $deployResult['deploy'];
        }
    }

    sb_log('ai_build', 'Complete', [
        'project_id' => $projectId,
        'tables'     => count($plan['tables']),
        'site_id'    => $site['id'] ?? null,
    ]);

    return [
        'project' => $project,
        'tables'  => $partial['tables'],
        'site'    => $site,
        'deploy'  => $deploy,
    ];
}

function ai_execute_edit(array $delta, int $projectId, int $userId): array
{
    $catalog = \SupaBein\Catalog::getInstance();
    $pdo     = \App::get('db');

    $addedTables     = [];
    $addedColumns    = [];
    $updatedPolicies = [];

    foreach ($delta['add_tables'] ?? [] as $tableDef) {
        try { \SupaBein\Schema::validateIdentifier($tableDef['name'] ?? ''); }
        catch (\InvalidArgumentException $e) { continue; }

        $columns = [];
        foreach ($tableDef['columns'] ?? [] as $col) {
            try {
                $colName = \SupaBein\Schema::validateIdentifier($col['name'] ?? '');
                if (in_array(strtolower($colName), ['id','created_at'], true)) continue;
                $columns[] = [
                    'name'     => $colName,
                    'type'     => \SupaBein\Schema::validateDataType($col['type'] ?? 'TEXT'),
                    'nullable' => (bool)($col['nullable'] ?? true),
                    'default'  => null,
                ];
            } catch (\InvalidArgumentException $e) { continue; }
        }

        try {
            $table = $catalog->createTable($projectId, $tableDef['name']);
            $ddl   = \SupaBein\Schema::createTableDDL($table['physical_name'], $columns);
            \SupaBein\Schema::applyDDL($pdo, $projectId, $ddl);
            foreach ($columns as $col) {
                $catalog->addColumn($table['id'], $col['name'], $col['type'], $col['nullable'], $col['default']);
            }
            foreach ($tableDef['policies'] ?? [] as $p) {
                try {
                    $catalog->upsertPolicy($table['id'], $p['api_role'], strtoupper($p['operation']), (bool)$p['allowed'], null);
                } catch (\Throwable $e) {}
            }
            $addedTables[] = $tableDef['name'];
        } catch (\Throwable $e) {
            sb_log('ai_edit', 'add_table failed: ' . $e->getMessage(), ['table' => $tableDef['name']]);
        }
    }

    foreach ($delta['add_columns'] ?? [] as $entry) {
        $tblName = $entry['table'] ?? '';
        $tbl = $catalog->getTable($projectId, $tblName);
        if (!$tbl) continue;

        foreach ($entry['columns'] ?? [] as $col) {
            try {
                $colName = \SupaBein\Schema::validateIdentifier($col['name'] ?? '');
                if (in_array(strtolower($colName), ['id','created_at'], true)) continue;
                $colType  = \SupaBein\Schema::validateDataType($col['type'] ?? 'TEXT');
                $nullable = (bool)($col['nullable'] ?? true);

                $physicalTable = $tbl['physical_name'];
                $nullSql = $nullable ? 'NULL' : 'NOT NULL';
                \SupaBein\Schema::applyDDL($pdo, $projectId,
                    "ALTER TABLE `{$physicalTable}` ADD COLUMN `{$colName}` {$colType} {$nullSql}"
                );
                $catalog->addColumn($tbl['id'], $colName, $colType, $nullable, null);
                $addedColumns[] = $tblName . '.' . $colName;
            } catch (\Throwable $e) {
                sb_log('ai_edit', 'add_column failed: ' . $e->getMessage());
            }
        }
    }

    foreach ($delta['update_policies'] ?? [] as $p) {
        $tblName = $p['table'] ?? '';
        $tbl = $catalog->getTable($projectId, $tblName);
        if (!$tbl) continue;
        try {
            $catalog->upsertPolicy($tbl['id'], $p['api_role'], strtoupper($p['operation']), (bool)$p['allowed'], null);
            $updatedPolicies[] = $tblName . '.' . $p['api_role'] . '.' . $p['operation'];
        } catch (\Throwable $e) {
            sb_log('ai_edit', 'policy update failed: ' . $e->getMessage());
        }
    }

    sb_log('ai_edit', 'Complete', ['project_id' => $projectId, 'added_tables' => count($addedTables)]);

    return [
        'added_tables'     => $addedTables,
        'added_columns'    => $addedColumns,
        'updated_policies' => $updatedPolicies,
    ];
}

// ─── AI provider factory ─────────────────────────────────────────────────────

const AI_ALLOWED_PROVIDERS = ['gemini', 'openrouter'];
const AI_ALLOWED_MODELS = [
    'gemini' => [
        'gemini-2.5-flash',
        'gemini-2.5-pro',
        'gemini-2.0-flash',
    ],
    'openrouter' => [
        'openai/gpt-4o',
        'anthropic/claude-sonnet-4-5',
        'mistralai/mistral-small-3.2-24b-instruct',
        'qwen/qwen3-coder:free',
        'meta-llama/llama-3.3-70b-instruct:free',
        'google/gemma-4-31b-it:free',
        'google/gemma-4-26b-a4b-it:free',
        'openai/gpt-oss-120b:free',
        'openai/gpt-oss-20b:free',
        'nvidia/nemotron-3-ultra-550b-a55b:free',
        'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
        'openrouter/owl-alpha',
        'nex-agi/nex-n2-pro:free',
        'poolside/laguna-m.1:free',
        'poolside/laguna-xs.2:free',
    ],
];

function make_ai_client(array $config, ?string $provider, ?string $model): object
{
    $provider = in_array($provider, AI_ALLOWED_PROVIDERS, true)
        ? $provider
        : ($config['AI_PROVIDER'] ?? 'gemini');

    if ($provider === 'openrouter') {
        $key = $config['OPENROUTER_API_KEY'] ?? '';
        if (!$key) abort(503, 'OpenRouter API key not configured on this server');
        $allowed = AI_ALLOWED_MODELS['openrouter'];
        $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
        return new \SupaBein\OpenRouterClient($key, $model);
    }

    // Default: Gemini
    $key = $config['GEMINI_API_KEY'] ?? '';
    if (!$key) abort(503, 'AI build is not configured on this server (missing GEMINI_API_KEY)');
    $allowed = AI_ALLOWED_MODELS['gemini'];
    $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
    return new \SupaBein\GeminiClient($key, $model);
}

// ─── Route registration ──────────────────────────────────────────────────────

function register_ai_routes(\SupaBein\Router $router): void
{
    $router->post('/v1/ai/build', function (array $req): void {
        set_time_limit(120);

        $config  = \App::get('config');
        $userId  = (int)$req['auth']['user_id'];

        // ── 1. Validate inputs ────────────────────────────────────────────────
        $prompt = trim($req['body']['prompt'] ?? '');
        if (!$prompt || strlen($prompt) > 2000) {
            abort(422, 'prompt is required and must be under 2000 characters');
        }

        // ── 2. Call AI ───────────────────────────────────────────────────────
        $provider = $req['body']['provider'] ?? null;
        $model    = $req['body']['model']    ?? null;
        sb_log('ai_build', 'Calling AI', ['user_id' => $userId, 'provider' => $provider, 'model' => $model]);
        $gemini = make_ai_client($config, $provider, $model);

        try {
            $plan = $gemini->generateJson(AI_BUILD_SYSTEM_PROMPT, $prompt);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            sb_log('ai_build', 'AI error: ' . $msg, ['user_id' => $userId]);
            if (str_contains($msg, 'credits') || str_contains($msg, 'quota')) abort(402, $msg);
            abort(502, 'AI generation failed: ' . $msg);
        }

        // ── 3. Validate plan ──────────────────────────────────────────────────
        $validationError = ai_validate_plan($plan);
        if ($validationError) {
            sb_log('ai_build', 'Plan validation failed: ' . $validationError, ['plan_keys' => array_keys($plan)]);
            abort(422, 'AI returned an invalid plan: ' . $validationError);
        }

        // ── 4-7. Execute build ────────────────────────────────────────────────
        $result = ai_execute_build($plan, $userId);
        json_out($result, 201);

    }, ['auth_middleware']);

    // ── AI Edit: modify an existing project ────────────────────────────────────
    $router->post('/v1/ai/edit', function (array $req): void {
        set_time_limit(120);

        $config  = \App::get('config');
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];

        $projectId = (int)($req['body']['project_id'] ?? 0);
        $prompt    = trim($req['body']['prompt'] ?? '');

        if (!$projectId) abort(422, 'project_id is required');
        if (!$prompt || strlen($prompt) > 2000) abort(422, 'prompt is required and must be under 2000 characters');

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

        $userMessage = "Current schema:\n" . $schemaContext . "\n\nRequested change: " . $prompt;

        $gemini = make_ai_client($config, $req['body']['provider'] ?? null, $req['body']['model'] ?? null);
        try {
            $delta = $gemini->generateJson(AI_EDIT_SYSTEM_PROMPT, $userMessage);
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
        set_time_limit(120);

        $config  = \App::get('config');
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];

        $prompt    = trim($req['body']['prompt'] ?? '');
        $projectId = isset($req['body']['project_id']) ? (int)$req['body']['project_id'] : null;

        if (!$prompt || strlen($prompt) > 2000) {
            abort(422, 'prompt is required and must be under 2000 characters');
        }

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

        $gemini = make_ai_client($config, $req['body']['provider'] ?? null, $req['body']['model'] ?? null);

        try {
            if ($mode === 'build') {
                $plan = $gemini->generateJsonWithHistory(AI_BUILD_SYSTEM_PROMPT, $history, $prompt);

                $validationError = ai_validate_plan($plan);
                if ($validationError) {
                    abort(422, 'AI returned an invalid plan: ' . $validationError);
                }

                $summary = [
                    'project_name'   => $plan['project_name'],
                    'tables'         => array_map(fn($t) => $t['name'] . ' (' . count($t['columns'] ?? []) . ' cols)', $plan['tables']),
                    'frontend_files' => count($plan['frontend']['files'] ?? []),
                ];

                json_out(['mode' => 'build', 'plan' => $plan, 'summary' => $summary, 'usage' => $gemini->getLastUsage()]);

            } elseif ($mode === 'edit') {
                $project = $catalog->getProjectById($projectId, $userId);
                if (!$project) abort(404, 'Project not found');

                $existingTables = $catalog->listTables($projectId);
                $schemaLines = [];
                foreach ($existingTables as $tbl) {
                    $cols = array_map(fn($c) => $c['name'] . ' ' . $c['type'], $catalog->listColumns($tbl['id']));
                    $schemaLines[] = '  Table "' . $tbl['logical_name'] . '": id (INT auto), ' . implode(', ', $cols) . ', created_at (DATETIME auto)';
                }
                $schemaContext = $schemaLines ? implode("\n", $schemaLines) : '  (no tables yet)';

                $userMessage = "Current schema:\n" . $schemaContext . "\n\nRequested change: " . $prompt;
                $delta = $gemini->generateJsonWithHistory(AI_EDIT_SYSTEM_PROMPT, $history, $userMessage);

                $summary = [
                    'add_tables'      => array_column($delta['add_tables'] ?? [], 'name'),
                    'add_columns'     => array_map(fn($e) => implode(', ', array_map(fn($c) => $e['table'] . '.' . $c['name'], $e['columns'] ?? [])), $delta['add_columns'] ?? []),
                    'update_policies' => array_map(fn($p) => $p['table'] . ' ' . $p['api_role'] . ' ' . $p['operation'], $delta['update_policies'] ?? []),
                ];
                // flatten add_columns
                $summary['add_columns'] = array_merge(...array_map(fn($e) => array_map(fn($c) => $e['table'] . '.' . $c['name'], $e['columns'] ?? []), $delta['add_columns'] ?? []));

                json_out(['mode' => 'edit', 'plan' => array_merge($delta, ['project_id' => $projectId]), 'summary' => $summary, 'usage' => $gemini->getLastUsage()]);

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

                $apiBase = rtrim($config['API_BASE_URL'], '/') . '/v1';
                $context = "Project: " . $project['name'] . "\nAPI base: " . $apiBase . "\nSchema:\n" . $schemaContext . "\n\nIssue: " . $prompt;

                $diagnosePrompt = <<<'PROMPT'
You are a debugging assistant for SupaBein, a self-hosted PHP+MySQL BaaS.
Analyze the project context and issue. Return ONLY valid JSON:
{ "diagnosis": "clear explanation", "suggestions": ["step 1", ...] }
PROMPT;

                $result = $gemini->generateJsonWithHistory($diagnosePrompt, $history, $context);

                json_out([
                    'mode'        => 'diagnose',
                    'diagnosis'   => $result['diagnosis'] ?? '',
                    'suggestions' => $result['suggestions'] ?? [],
                    'usage'       => $gemini->getLastUsage(),
                ]);
            }
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'credits') || str_contains($msg, 'quota')) abort(402, $msg);
            abort(502, 'AI generation failed: ' . $msg);
        }

    }, ['auth_middleware']);

    // ── AI Apply: execute a previously generated plan ──────────────────────────
    $router->post('/v1/ai/apply', function (array $req): void {
        set_time_limit(120);

        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];

        $mode = $req['body']['mode'] ?? '';
        $plan = $req['body']['plan'] ?? [];

        if (!is_array($plan)) abort(422, 'plan must be an array');

        if ($mode === 'build') {
            $validationError = ai_validate_plan($plan);
            if ($validationError) abort(422, 'Invalid plan: ' . $validationError);
            $result = ai_execute_build($plan, $userId);
            json_out($result, 201);

        } elseif ($mode === 'edit') {
            $projectId = (int)($plan['project_id'] ?? 0);
            if (!$projectId) abort(422, 'plan.project_id is required for edit mode');
            $project = $catalog->getProjectById($projectId, $userId);
            if (!$project) abort(404, 'Project not found');
            $result = ai_execute_edit($plan, $projectId, $userId);
            json_out($result);

        } else {
            abort(422, 'mode must be build or edit');
        }

    }, ['auth_middleware']);

    // ── AI Sessions (DB-backed) ────────────────────────────────────────────────

    $router->get('/v1/ai/sessions', function (array $req): void {
        $userId  = (int)$req['auth']['user_id'];
        $catalog = \SupaBein\Catalog::getInstance();
        json_out($catalog->listAiSessions($userId));
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
        $sess = $catalog->getAiSession($sessionId, $userId);
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
        $newName     = $name ?: $sess['name'];
        $newMessages = is_array($messages) ? $messages : $sess['messages'];
        $catalog->updateAiSession($sessionId, $userId, $newName, $newMessages);
        json_out($catalog->getAiSession($sessionId, $userId));
    }, ['auth_middleware']);

    $router->delete('/v1/ai/sessions/:id', function (array $req): void {
        $userId    = (int)$req['auth']['user_id'];
        $sessionId = (int)$req['params']['id'];
        $catalog   = \SupaBein\Catalog::getInstance();
        if (!$catalog->deleteAiSession($sessionId, $userId)) abort(404, 'Session not found');
        json_out(['deleted' => true]);
    }, ['auth_middleware']);
}
