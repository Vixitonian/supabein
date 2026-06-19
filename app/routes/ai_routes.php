<?php

declare(strict_types=1);

require_once SUPABEIN_ROOT . '/app/core/gemini_client.php';
require_once SUPABEIN_ROOT . '/app/core/deploy.php';

// ─── Gemini system prompt ────────────────────────────────────────────────────

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

Frontend rules:
- Must include "index.html" as the SPA entry point
- Split code across as many files as the app warrants — separate concerns properly:
    css/style.css for all styles, js/api.js for the SupaBein fetch client,
    js/router.js for client-side routing, js/auth.js for auth helpers,
    js/views/home.js, js/views/login.js etc. for individual page components
  Do NOT cram everything into one or two files. Structure it like a production project.
- Use these exact placeholders in JS files (substituted by the server at deploy time):
    const SB_URL = '__SB_URL__';
    const SB_KEY = '__SB_ANON_KEY__';
    const SB_PID = '__SB_PID__';
- index.html must load all JS and CSS files via <script src="..."> and <link rel="stylesheet">
- Use vanilla JS only — no frameworks, no npm, no build tools
- Use a dark theme with CSS variables: --bg: #0f1117; --surface: #1a1d27; --accent: #3ecf8e; --text: #e2e8f0; --muted: #8892a4; --danger: #ef4444; --border: #2d3045;
- The app must be fully functional — real fetch calls, real CRUD, real auth flows

Access control guidelines:
- anon role: SELECT=true only on genuinely public tables; all else false
- authenticated role: SELECT/INSERT/UPDATE/DELETE=true on tables the user owns or can interact with
- If the app has user-generated content, ensure policies enforce ownership where appropriate

Always include at least one table. Generate all tables the described app needs.
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

    $blockedExtensions = \SupaBein\Deploy::BLOCKED_EXTENSIONS;

    $errors = [];
    foreach ($frontendFiles as $fileDef) {
        $relPath = ltrim((string)($fileDef['path'] ?? ''), '/');
        $relPath = str_replace('..', '', $relPath);
        if ($relPath === '') continue;

        $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
        if (in_array($ext, $blockedExtensions, true)) {
            $errors[] = 'Blocked extension in AI output: .' . $ext;
            continue;
        }

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

// ─── Route registration ──────────────────────────────────────────────────────

function register_ai_routes(\SupaBein\Router $router): void
{
    $router->post('/v1/ai/build', function (array $req): void {
        set_time_limit(120);

        $config  = \App::get('config');
        $catalog = \SupaBein\Catalog::getInstance();
        $pdo     = \App::get('db');
        $userId  = (int)$req['auth']['user_id'];

        // ── 1. Validate inputs ────────────────────────────────────────────────
        $prompt = trim($req['body']['prompt'] ?? '');
        if (!$prompt || strlen($prompt) > 2000) {
            abort(422, 'prompt is required and must be under 2000 characters');
        }

        $apiKey = $config['GEMINI_API_KEY'] ?? '';
        if (!$apiKey) {
            abort(503, 'AI build is not configured on this server (missing GEMINI_API_KEY)');
        }

        // ── 2. Call Gemini ────────────────────────────────────────────────────
        sb_log('ai_build', 'Calling Gemini', ['user_id' => $userId]);
        $gemini = new \SupaBein\GeminiClient($apiKey);

        try {
            $plan = $gemini->generateJson(AI_BUILD_SYSTEM_PROMPT, $prompt);
        } catch (\RuntimeException $e) {
            sb_log('ai_build', 'Gemini error: ' . $e->getMessage(), ['user_id' => $userId]);
            abort(502, 'AI generation failed: ' . $e->getMessage());
        }

        // ── 3. Validate plan ──────────────────────────────────────────────────
        $validationError = ai_validate_plan($plan);
        if ($validationError) {
            sb_log('ai_build', 'Plan validation failed: ' . $validationError, ['plan_keys' => array_keys($plan)]);
            abort(422, 'AI returned an invalid plan: ' . $validationError);
        }

        // ── 4. Create project ─────────────────────────────────────────────────
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

        // ── 5. Create tables, columns, DDL, policies ──────────────────────────
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

        // ── 6. Create site ────────────────────────────────────────────────────
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

        // ── 7. Deploy frontend ────────────────────────────────────────────────
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

        json_out([
            'project' => $project,
            'tables'  => $partial['tables'],
            'site'    => $site,
            'deploy'  => $deploy,
        ], 201);

    }, ['auth_middleware']);
}
