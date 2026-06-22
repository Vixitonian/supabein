<?php

declare(strict_types=1);

require_once SUPABEIN_ROOT . '/app/core/gemini_client.php';
require_once SUPABEIN_ROOT . '/app/core/openrouter_client.php';
require_once SUPABEIN_ROOT . '/app/core/nvidia_client.php';
require_once SUPABEIN_ROOT . '/app/core/deploy.php';

// ─── Gemini system prompts ───────────────────────────────────────────────────

// ── Pass 1: schema only ──────────────────────────────────────────────────────
const AI_BUILD_SCHEMA_PROMPT = <<<'PROMPT'
You are a backend architect for SupaBein, a self-hosted BaaS platform.
The user will describe an application. Return ONLY a single valid JSON object — no markdown fences, no explanation.

{
  "project_name": string,
  "subdomain": string,
  "tables": [
    {
      "name": string,
      "columns": [
        {"name": string, "type": string, "nullable": boolean, "default": string or null}
      ],
      "policies": [
        {"api_role": string, "operation": string, "allowed": boolean, "constraint_sql": string or null}
      ]
    }
  ]
}

Rules:
- project_name: human-readable, 1-80 chars
- subdomain: 3-30 lowercase alphanumeric + hyphens (e.g. "my-blog")
- table.name: valid SQL identifier /^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/; avoid ALL SQL reserved words
  (SELECT, INSERT, TABLE, INDEX, KEY, WHERE, FROM, NAME, DATE, TYPE, STATUS, RANK, ROLE, etc.)
- column.name: same rules; NEVER include "id" or "created_at" — SupaBein adds these automatically to
  every table (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, created_at TIMESTAMP DEFAULT NOW()).
  Including them causes a MySQL "Duplicate column name" DDL failure. Omit them entirely.
  Also avoid reserved words: name, type, status, rank, role, date, time, year, month, value, key, index, order, group
- column.type: exactly one of: INT, BIGINT, SMALLINT, TINYINT, VARCHAR(255), VARCHAR(128), VARCHAR(64),
  VARCHAR(36), VARCHAR(32), TEXT, MEDIUMTEXT, LONGTEXT, BOOLEAN, TINYINT(1), DECIMAL(10,2),
  DECIMAL(15,4), FLOAT, DOUBLE, DATETIME, DATE, TIMESTAMP, JSON, PASSWORD
- column.default: literal values only (e.g. "0", "active") or null — no SQL functions
- policy.api_role: "anon" or "authenticated"
- policy.operation: "SELECT", "INSERT", "UPDATE", or "DELETE"
- policy.constraint_sql: WHERE-style expression or null; use ":current_user_id" for logged-in user ID
  Do NOT use "auth.uid()" — it is not supported.
- Always include at least one table.

AUTHENTICATION (read carefully — this is the most common design failure):
- If the app stores or scopes data per user — anything involving "my", accounts, profiles,
  carts, orders, posts-by-author, ownership, roles, or login — you MUST include exactly ONE
  users table that has a column of type PASSWORD (e.g. {"name":"email","type":"VARCHAR(255)"}
  plus {"name":"password","type":"PASSWORD"}). Without a PASSWORD column the platform cannot
  issue a login token, so :current_user_id is always empty and every owner-scoped table is
  permanently inaccessible.
- Reference the logged-in user from other tables with a column named EXACTLY "user_id" of type
  INT. Do NOT invent owner column names like "customer_ref", "user_ref", "owner_id", or "author".
  Owner-scoped policies must read "user_id = :current_user_id".
- NEVER use ":current_user_id" in any policy unless a PASSWORD column exists somewhere in the
  schema. If the app is genuinely single-user / public-only (no accounts), use anon policies and
  no :current_user_id at all.

LOCKED PRODUCT INTENT:
- If the user message contains a "Locked product intent" block (a fixed list of actors and user
  stories), you MUST design the schema for EXACTLY those actors and stories. Do not add tables,
  features, or roles that no listed story requires, and do not drop any. The locked list is the
  complete scope — treat anything outside it as out of scope.
- Derive auth from the actors: if the actors are more than one, or any actor is not a single
  anonymous "owner", include a users table with a PASSWORD column and user_id ownership.
PROMPT;

// ── Intent pass (pass 0): minimal actors + user stories, deliberately capped ──
const AI_INTENT_PROMPT = <<<'PROMPT'
You extract the MINIMAL set of actors and user stories for a web app from a short description.
Return ONLY JSON, no prose and no markdown fences: {"actors": [...], "stories": [...]}

Rules:
- Include ONLY what the description asks for or strictly requires. Invent nothing.
- "actors": the distinct kinds of human user. If the app is for one person with no sharing,
  accounts, or roles, return exactly ["owner"] — a single anonymous user needing no login.
  Add another actor ONLY if the description implies sharing, assignment, roles, or many users.
- "stories": short "as a <actor> I can <do one thing>" lines, one capability each, core actions only.
- Do NOT add admin panels, notifications, comments, tags, search, analytics, profiles, or sharing
  unless the description explicitly mentions them.
- HARD LIMITS: at most 5 actors and at most 7 stories. Fewer is better. For a trivial app,
  return 1 actor and 2–3 stories.
PROMPT;

// ── Shared frontend rules (single source of truth for build AND edit) ────────
const AI_FRONTEND_RULES = <<<'RULES'
═══════════════════════════════════════════════════════
RULE 1 — COLUMN NAME CONSISTENCY (most common bug)
═══════════════════════════════════════════════════════
The schema lists every table's EXACT column names after validation and reserved-word renaming.
Use these exact names everywhere in JS: fetch bodies, response field access, template literals,
form inputs. Do NOT guess, shorten, or rename. If the schema says "skill_title", use "skill_title".

═══════════════════════════════════════════════════════
RULE 2 — ONE DEFINITION PER NAME (app-killing bug if broken)
═══════════════════════════════════════════════════════
All <script> tags share ONE global scope. Declaring the same top-level `const`/`let` twice
throws "Identifier 'X' has already been declared" — a fatal SyntaxError that BLANKS THE WHOLE PAGE.

Therefore:
- Each module (api, router, auth, every feature) is defined EXACTLY ONCE, in its own file.
- The inline <script> at the bottom of index.html is a BOOTSTRAP ONLY. It MUST NOT contain
  `const`/`let`/`var` declarations of api, router, auth, or any feature module, and MUST NOT
  re-implement them. If a name is defined in a loaded <script src> file, NEVER write `const NAME`
  again anywhere — not inline, not in a second file.
- Do NOT dump the whole app into index.html. The files are the app; index.html only wires them.

The inline bootstrap contains ONLY:
  <script>
    /* define updateNav() here ONCE (function declaration is fine) */
    function updateNav() { /* toggle nav links based on auth.getCurrentUser() */ }

    router.defineRoute('/', featureA.renderView);
    router.defineRoute('/login', auth.renderAuthForms);
    /* ... all other routes ... */

    document.getElementById('nav-toggle').addEventListener('click', () => {
      document.getElementById('nav-menu').classList.toggle('hidden');
    });
    document.addEventListener('auth_status_change', updateNav);

    auth.ready.then(() => {
      updateNav();
      router.onHashChange();
      window.addEventListener('hashchange', router.onHashChange);
    });
  </script>

core/router.js must NEVER call defineRoute() itself — it only exports the router API.

═══════════════════════════════════════════════════════
RULE 3 — AUTH INITIALISATION RACE
═══════════════════════════════════════════════════════
features/auth/auth.js must expose a `ready` promise resolved after loadUser() completes.
loadUser() is async; if the router fires first, getCurrentUser() returns null and protected
pages show "Access Denied" even for logged-in users.

Do NOT hand-write this module. Copy the verbatim auth.js given in the PLACEHOLDERS + AUTH
section below (it already implements `ready`, loadUser, login, signup, logout, getCurrentUser,
and renderAuthForms). Define it ONCE, in features/auth/auth.js only.

═══════════════════════════════════════════════════════
RULE 4 — ROUTER NAVIGATION PATHS
═══════════════════════════════════════════════════════
router.navigate(path) sets window.location.hash = path. Paths must NOT include a leading '#'
(that produces '##/' and 404s).
  ✓ router.navigate('/')      ✗ router.navigate('#/')
Anchor hrefs still use href="#/" — only programmatic navigate() omits the #.

═══════════════════════════════════════════════════════
RULE 5 — NULL SAFETY ON NULLABLE FIELDS
═══════════════════════════════════════════════════════
The schema marks some columns nullable. Never call string/array methods on a field without guarding:
  ✗ item.description.substring(0, 100)         — crashes if null
  ✓ (item.description ?? '').substring(0, 100)
Also guard interpolation: use ${item.description ?? ''} so a null never renders the word "null".

═══════════════════════════════════════════════════════
RULE 6 — DATA ACCESS: USE THE api CLIENT ONLY
═══════════════════════════════════════════════════════
NEVER call fetch() for data yourself and NEVER build data URLs by hand in feature code.
Use the api client exclusively. core/api.js MUST be EXACTLY this file (copy verbatim,
do not change the signatures — this is what keeps every build consistent):

  const api = (() => {
    const authHeader = () => {
      const t = localStorage.getItem('sb:token');
      return t ? { 'Authorization': 'Bearer ' + t } : {};
    };
    const base = (table) => `${SB_URL}/data/${SB_PID}/${table}`;
    // Tolerate either a bare array OR a wrapped envelope from the data API.
    const unwrap = (j) => Array.isArray(j) ? j : (j && (j.data ?? j.rows ?? j.records)) ?? j;
    const req = async (url, opts = {}) => {
      const res = await fetch(url, {
        ...opts,
        headers: { 'Content-Type': 'application/json', ...authHeader(), ...(opts.headers || {}) }
      });
      if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
      return res.status === 204 ? null : res.json();
    };
    const list   = async (table)         => unwrap(await req(base(table)));
    const get    = async (table, id)     => req(`${base(table)}/${id}`);
    const create = async (table, data)   => req(base(table), { method: 'POST',   body: JSON.stringify(data) });
    const update = async (table, id, d)  => req(`${base(table)}/${id}`, { method: 'PUT', body: JSON.stringify(d) });
    const remove = async (table, id)     => req(`${base(table)}/${id}`, { method: 'DELETE' });
    return { list, get, create, update, remove };
  })();

Feature code uses ONLY: api.list('table'), api.get('table', id), api.create('table', {...}),
api.update('table', id, {...}), api.remove('table', id). api.list() always returns an array.

FILTERING: the data API does NOT support PostgREST-style query strings. NEVER write
api.list('table?col=eq.value') or append ?foo=bar to a table name — the filter is ignored and
you get every row (or a 404). To get related/owned rows, fetch the table and filter in JS:
  const rows = (await api.list('order_line_items')).filter(r => r.order_id === orderId);
Keep these client-side filters on small tables only; this is fine for the app sizes here.

═══════════════════════════════════════════════════════
RULE 7 — LOADING STATES + RESPONSIVE NAV
═══════════════════════════════════════════════════════
Every async render shows a loading indicator before the fetch, then real content or an error:
  el.innerHTML = '<p class="text-gray-400 animate-pulse">Loading...</p>';
  try { const rows = await api.list('table'); el.innerHTML = /* content */; }
  catch (e) { el.innerHTML = `<p class="text-red-400">Failed to load: ${e.message}</p>`; }

Always include a mobile hamburger:
  <button id="nav-toggle" class="md:hidden p-2 rounded text-gray-300 hover:text-white">☰</button>
  <nav id="nav-menu" class="hidden md:flex items-center gap-4">...links...</nav>

═══════════════════════════════════════════════════════
STRUCTURE (define each name once, in its own file)
═══════════════════════════════════════════════════════
    index.html                         ← SPA entry + bootstrap ONLY (no module re-declarations)
    core/config.js                     ← SB_URL / SB_KEY / SB_PID globals (declared once, here)
    core/api.js                        ← the exact api client from RULE 6
    core/router.js                     ← router API only (no defineRoute calls)
    features/auth/auth.js              ← login, signup, logout, ready promise
                                       (ONLY include when schema has a PASSWORD column)
    features/<feature>/<feature>.js    ← one subfolder per feature
Load with RELATIVE paths in dependency order (config → api → router → auth → features → bootstrap).
Absolute paths like /core/config.js break the site. No frameworks, no npm, no build tools.

═══════════════════════════════════════════════════════
STYLING
═══════════════════════════════════════════════════════
- Tailwind via CDN in <head>:
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
  Add class="dark" to <html>. No separate CSS file.
- Colours: bg-gray-950 (page), bg-gray-900 (cards), text-emerald-400 (accent),
  text-gray-100 (primary), text-gray-400 (muted), text-red-400 (danger).
- Buttons: rounded-lg px-4 py-2 font-medium transition.
  Primary = bg-emerald-500 hover:bg-emerald-600 text-white.
- Inputs: bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-gray-100 w-full
  focus:outline-none focus:ring-2 focus:ring-emerald-500.
- Never hardcode the year (e.g. a "© 2023" footer). Use new Date().getFullYear().

═══════════════════════════════════════════════════════
PLACEHOLDERS + OWNERSHIP
═══════════════════════════════════════════════════════
- Placeholders ONLY in core/config.js (substituted at deploy time):
    const SB_URL = '__SB_URL__';
    const SB_PID = '__SB_PID__';
  Declared once. Never redeclare anywhere. No SB_KEY — public requests need no auth token.
- Auth (include features/auth/auth.js ONLY when the schema has a table with a PASSWORD column;
  if no PASSWORD column exists, omit auth.js and any /login route entirely).
  Copy this file VERBATIM. The placeholders __AUTH_TABLE__ and __AUTH_FIELD__ are already filled
  in for you with the real users-table name and its identifier column — do not change them.

  features/auth/auth.js:
    const auth = (() => {
      const TABLE = '__AUTH_TABLE__';   // users table from the schema
      const FIELD = '__AUTH_FIELD__';   // its non-password identifier column (email/username)
      let currentUser = null;
      let _resolveReady;
      const ready = new Promise(res => { _resolveReady = res; });

      const loadUser = async () => {
        const t = localStorage.getItem('sb:token');
        if (!t) { _resolveReady(null); document.dispatchEvent(new CustomEvent('auth_status_change')); return; }
        try {
          const payload = JSON.parse(atob(t.split('.')[1]));
          currentUser = { id: parseInt(payload.sub, 10) };
        } catch { localStorage.removeItem('sb:token'); }
        _resolveReady(currentUser);
        document.dispatchEvent(new CustomEvent('auth_status_change'));
      };

      const getCurrentUser = () => currentUser;

      const login = async (identifier, password) => {
        const res = await fetch(`${SB_URL}/data/${SB_PID}/${TABLE}/login`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ [FIELD]: identifier, password })
        });
        if (!res.ok) throw new Error('Invalid credentials');
        const { token, user } = await res.json();
        localStorage.setItem('sb:token', token);
        currentUser = { id: user.id };
        document.dispatchEvent(new CustomEvent('auth_status_change'));
        return currentUser;
      };

      const signup = async (identifier, password) => {
        await api.create(TABLE, { [FIELD]: identifier, password });
        return login(identifier, password);   // auto-login after signup
      };

      const logout = () => {
        localStorage.removeItem('sb:token');
        currentUser = null;
        document.dispatchEvent(new CustomEvent('auth_status_change'));
        router.navigate('/login');
      };

      const renderAuthForms = () => {
        // MUST render BOTH a login form and a signup form (two panels or tabs) into #app.
        // Each submit calls auth.login(...) / auth.signup(...) then router.navigate('/').
        // Show errors inline (text-red-400). Use FIELD as the first input's name.
      };

      loadUser();
      return { ready, getCurrentUser, login, logout, signup, renderAuthForms };
    })();

  Auth wiring requirements (all three are mandatory when auth exists):
  1. renderAuthForms MUST render BOTH login AND signup on the /login screen — never login only.
  2. updateNav() MUST show a "Login" link (href="#/login") when auth.getCurrentUser() is null,
     and a "Logout" button calling auth.logout() when a user is set. There must always be a
     visible way to reach /login.
  3. Protected routes MUST redirect, not dead-end: if a gated render finds no current user, call
     router.navigate('/login') instead of printing "Access Denied" — otherwise the form is
     unreachable.
  - Signup/login server contract (already handled by the verbatim file above):
      signup → POST ${SB_URL}/data/${SB_PID}/{TABLE}            {FIELD: "...", password: "..."}
               server auto-hashes the password; returns the new user row (password is null)
      login  → POST ${SB_URL}/data/${SB_PID}/{TABLE}/login      {FIELD: "...", password: "..."}
               returns {token, user} where user.password is null
    Store the JWT as "sb:token" in localStorage (the file does this).
- When a table has user_id for ownership, set it on INSERT from the stored token:
    const payload = JSON.parse(atob(localStorage.getItem('sb:token').split('.')[1]));
    data.user_id = parseInt(payload.sub, 10);

The app must be fully functional — real api calls, real CRUD, real auth flows where auth exists.
RULES;

// ── Pass 2: frontend given exact validated schema ────────────────────────────
const AI_BUILD_FRONTEND_HEADER = <<<'PROMPT'
You are a frontend developer for SupaBein, a self-hosted BaaS platform.
You will receive the app description and the exact validated database schema.
Return ONLY a single valid JSON object — no markdown fences, no explanation.

{"files": [{"path": string, "content": string}]}
PROMPT;

const AI_BUILD_FRONTEND_PROMPT = AI_BUILD_FRONTEND_HEADER . "\n\n" . AI_FRONTEND_RULES;

// ── Edit: full-stack delta ───────────────────────────────────────────────────
const AI_EDIT_SYSTEM_HEADER = <<<'PROMPT'
You are a full-stack developer for SupaBein, a self-hosted BaaS platform.
The user wants to MODIFY an existing project. You will be given the current schema,
current frontend files (or a file listing), and a change request.
Return ONLY a single valid JSON object — no markdown fences, no explanation, no extra text.

{
  "add_tables": [
    {
      "name": string,
      "columns": [ {"name": string, "type": string, "nullable": boolean} ],
      "policies": [ {"api_role": "anon"|"authenticated", "operation": "SELECT"|"INSERT"|"UPDATE"|"DELETE", "allowed": boolean} ]
    }
  ],
  "add_columns": [
    { "table": string, "columns": [ {"name": string, "type": string, "nullable": boolean} ] }
  ],
  "update_policies": [
    {"table": string, "api_role": "anon"|"authenticated", "operation": "SELECT"|"INSERT"|"UPDATE"|"DELETE", "allowed": boolean}
  ],
  "frontend": { "files": [ {"path": string, "content": string} ] }
}

The "frontend" key is OPTIONAL.
- OMIT "frontend" for a pure schema change (add column, change policy).
- INCLUDE "frontend" for any UI / visual / navigation / "broken" / "blank page" request.
- When included, output EVERY file the site needs to run standalone — index.html, core/*, and
  all feature files — even unchanged ones. Returned files are MERGED over the existing deploy,
  but any file you DO return fully replaces its old version, so a half-written file breaks the site.
- Use the exact column names from the "Exact schema" context — do NOT invent or rename them.

Schema rules:
- Do NOT include tables/columns that already exist. Do NOT drop or rename — additions and policy
  changes only.
- column.type MUST be exactly one of: INT, BIGINT, SMALLINT, TINYINT, VARCHAR(255), VARCHAR(128),
  VARCHAR(64), VARCHAR(36), VARCHAR(32), TEXT, MEDIUMTEXT, LONGTEXT, BOOLEAN, TINYINT(1),
  DECIMAL(10,2), DECIMAL(15,4), FLOAT, DOUBLE, DATETIME, DATE, TIMESTAMP, JSON, PASSWORD
- table.name / column.name: valid SQL identifiers /^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/; avoid reserved words
- NEVER include "id" or "created_at" as columns — they are auto-added to every table by SupaBein and will cause a DDL failure if duplicated
- If no changes of a given type are needed, return an empty array [] for that key.

The FRONTEND RULES below apply whenever you include frontend.files:
PROMPT;

const AI_EDIT_SYSTEM_PROMPT = AI_EDIT_SYSTEM_HEADER . "\n\n" . AI_FRONTEND_RULES;

// ─── File-level helpers (filesystem) ────────────────────────────────────────

function ai_deploy_files(
    array $config,
    \SupaBein\Catalog $catalog,
    int $siteId,
    array $project,
    array $frontendFiles,
    bool $mergeFromCurrent = false
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

    // Seed from the live deploy so an edit that returns only some files
    // doesn't blank the rest of the site.
    if ($mergeFromCurrent) {
        $currentDir = $sitesPath . '/s' . $siteId . '/current';
        if (is_dir($currentDir)) {
            \SupaBein\Deploy::rcopy($currentDir, $deployDir);
            @unlink($deployDir . '/.htaccess');   // regenerated below
        }
    }

    // Substitution map — replace placeholders with real credentials.
    // Already-substituted files copied from current/ contain no placeholders.
    $apiBase = rtrim($config['API_BASE_URL'], '/') . '/v1';
    $replacements = [
        '__SB_URL__'      => $apiBase,
        '__SB_PID__'      => (string)$project['id'],
    ];

    $errors = [];
    foreach ($frontendFiles as $fileDef) {
        $relPath = ltrim((string)($fileDef['path'] ?? ''), '/');
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

    // Hardening .htaccess (force-written, cannot be skipped).
    $htaccess = \SupaBein\Deploy::buildHardeningHtaccess(true);
    file_put_contents($deployDir . '/.htaccess', $htaccess);

    // Smoke check the assembled (merged) site before publishing.
    $smoke = ai_smoke_check_dir($deployDir);
    if ($smoke !== null) {
        \SupaBein\Deploy::rrmdir($deployDir);
        $catalog->updateDeploy($deployId, 'failed');
        return ['error' => 'Smoke check failed: ' . $smoke, 'deploy' => null];
    }

    // Calculate total size.
    $totalSize = 0;
    $iterator  = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($deployDir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $f) {
        if ($f->isFile()) $totalSize += $f->getSize();
    }
    \App::get('db')->prepare('UPDATE deploys SET size_bytes = ? WHERE id = ?')
                   ->execute([$totalSize, $deployId]);

    // Copy to staging/.
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

    // Auto-publish to current/ so the site is live immediately.
    $currentDir = $sitesPath . '/s' . $siteId . '/current';
    if (is_dir($currentDir))  \SupaBein\Deploy::rrmdir($currentDir);
    if (is_link($currentDir)) unlink($currentDir);
    \SupaBein\Deploy::rcopy($stagingDir, $currentDir);
    $catalog->updateSiteCurrentDeploy($siteId, $deployId);
    $catalog->updateSiteStagingDeploy($siteId, null);

    return ['error' => null, 'deploy' => $catalog->getDeployById($deployId)];
}

function ai_sanitize_plan(array $plan): array
{
    // ── Column type normalization ─────────────────────────────────────────────
    static $TYPE_MAP = [
        'string'             => 'VARCHAR(255)',
        'varchar'            => 'VARCHAR(255)',
        'character varying'  => 'VARCHAR(255)',
        'char'               => 'VARCHAR(255)',
        'integer'            => 'INT',
        'int unsigned'       => 'INT',
        'int(11)'            => 'INT',
        'serial'             => 'INT',
        'bigserial'          => 'BIGINT',
        'bool'               => 'BOOLEAN',
        'number'             => 'DECIMAL(10,2)',
        'numeric'            => 'DECIMAL(10,2)',
        'decimal'            => 'DECIMAL(10,2)',
        'money'              => 'DECIMAL(10,2)',
        'uuid'               => 'VARCHAR(36)',
        'real'               => 'FLOAT',
        'long text'          => 'LONGTEXT',
        'medium text'        => 'MEDIUMTEXT',
    ];

    // ── SQL reserved words that models use as table/column names ─────────────
    static $SQL_RESERVED = [
        'order','group','key','index','select','insert','update','delete',
        'table','from','where','join','left','right','inner','outer','on',
        'by','as','in','is','not','null','and','or','like','limit','offset',
        'having','union','all','distinct','case','when','then','else','end',
        'create','drop','alter','add','column','primary','foreign','references',
        'default','check','unique','constraint','auto_increment','values','set',
        'into','exists','between','any','some','user','value','read','write',
        'status','rank','role','type','name','date','time','year','month',
    ];

    // ── api_role normalization ────────────────────────────────────────────────
    static $ROLE_MAP = [
        'user'       => 'authenticated',
        'users'      => 'authenticated',
        'public'     => 'anon',
        'guest'      => 'anon',
        'admin'      => 'authenticated',
        'private'    => 'authenticated',
        'logged_in'  => 'authenticated',
        'loggedin'   => 'authenticated',
        'member'     => 'authenticated',
    ];

    // ── operation normalization ───────────────────────────────────────────────
    static $OP_MAP = [
        'read'    => 'SELECT',
        'get'     => 'SELECT',
        'list'    => 'SELECT',
        'fetch'   => 'SELECT',
        'write'   => 'INSERT',
        'create'  => 'INSERT',
        'add'     => 'INSERT',
        'post'    => 'INSERT',
        'edit'    => 'UPDATE',
        'modify'  => 'UPDATE',
        'patch'   => 'UPDATE',
        'put'     => 'UPDATE',
        'change'  => 'UPDATE',
        'remove'  => 'DELETE',
        'destroy' => 'DELETE',
        'erase'   => 'DELETE',
    ];

    // ── SQL function defaults that must be nulled ─────────────────────────────
    static $FN_DEFAULTS = [
        'now()', 'current_timestamp', 'current_date', 'current_time',
        'sysdate()', 'getdate()', 'uuid_generate_v4()', 'gen_random_uuid()',
        'newid()', 'uuid()',
    ];

    // ── 1. Subdomain ──────────────────────────────────────────────────────────
    if (isset($plan['subdomain'])) {
        $sub = strtolower((string)$plan['subdomain']);
        $sub = preg_replace('/[^a-z0-9]+/', '-', $sub);   // non-alphanum → hyphen
        $sub = preg_replace('/-+/', '-', $sub);             // collapse hyphens
        $sub = trim($sub, '-');
        $sub = substr($sub, 0, 30);
        $sub = trim($sub, '-');
        if (strlen($sub) < 3) $sub = 'app-' . $sub;
        $plan['subdomain'] = $sub;
    }

    // ── 2. Tables ─────────────────────────────────────────────────────────────
    foreach (($plan['tables'] ?? []) as &$table) {

        // Rename reserved table names
        if (in_array(strtolower($table['name'] ?? ''), $SQL_RESERVED, true)) {
            $table['name'] = $table['name'] . '_data';
        }

        // Strip auto-generated columns
        $table['columns'] = array_values(array_filter(
            $table['columns'] ?? [],
            fn($col) => !in_array(strtolower($col['name'] ?? ''), ['id', 'created_at'], true)
        ));

        foreach ($table['columns'] as &$col) {
            // Rename reserved column names
            if (in_array(strtolower($col['name'] ?? ''), $SQL_RESERVED, true)) {
                $col['name'] = $col['name'] . '_value';
            }

            // Normalize type
            $typeLower = strtolower(trim($col['type'] ?? ''));
            if (isset($TYPE_MAP[$typeLower])) {
                $col['type'] = $TYPE_MAP[$typeLower];
            }

            // Null out SQL function defaults
            if (isset($col['default'])) {
                $defLower = strtolower(trim((string)$col['default']));
                if (in_array($defLower, $FN_DEFAULTS, true)) {
                    $col['default'] = null;
                }
            }
        }
        unset($col);

        foreach ($table['policies'] ?? [] as &$policy) {
            // Normalize api_role
            $role = strtolower(trim($policy['api_role'] ?? ''));
            $policy['api_role'] = $ROLE_MAP[$role] ?? $policy['api_role'];

            // Normalize operation
            $op = strtolower(trim($policy['operation'] ?? ''));
            $policy['operation'] = strtoupper($OP_MAP[$op] ?? $policy['operation']);

            // Replace auth.uid() / uid() with :current_user_id in constraint_sql
            if (isset($policy['constraint_sql']) && is_string($policy['constraint_sql'])) {
                $policy['constraint_sql'] = preg_replace(
                    '/\bauth\.uid\s*\(\s*\)|\buid\s*\(\s*\)/i',
                    ':current_user_id',
                    $policy['constraint_sql']
                );
            }
        }
        unset($policy);
    }
    unset($table);

    // ── 3. Frontend file paths — strip leading slashes / ./ ──────────────────
    foreach ($plan['frontend']['files'] ?? [] as &$file) {
        $file['path'] = ltrim(preg_replace('#^\./+#', '', $file['path'] ?? ''), '/');
    }
    unset($file);

    return $plan;
}

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

    // ── Cross-pass auth coherence: :current_user_id requires a PASSWORD column ──
    // Without a PASSWORD column the platform cannot issue a login token, so any
    // owner-scoped policy is permanently unsatisfiable and the app is dead on arrival.
    $hasPassword     = false;
    $usesCurrentUser = false;
    foreach ($plan['tables'] as $t) {
        foreach ($t['columns'] ?? [] as $c) {
            if (strtoupper(trim((string)($c['type'] ?? ''))) === 'PASSWORD') $hasPassword = true;
        }
        foreach ($t['policies'] ?? [] as $p) {
            if (!empty($p['constraint_sql']) && str_contains((string)$p['constraint_sql'], ':current_user_id')) {
                $usesCurrentUser = true;
            }
        }
    }
    if ($usesCurrentUser && !$hasPassword) {
        return 'policies use :current_user_id but no table has a PASSWORD column, so login is '
             . 'impossible and every owner-scoped table is unreachable. Add a users table with a '
             . 'PASSWORD column (e.g. email VARCHAR(255), password PASSWORD) and reference it via user_id.';
    }

    return null;
}

/**
 * Validate an EDIT delta against the existing schema so a one-shot retry has something
 * concrete to feed back. Catches the silent edit-killers: invalid column types, re-adding
 * a table/column that already exists (DDL duplicate → non-fatal skip → the user's change
 * just never appears), and targeting a table that doesn't exist. Pure-frontend deltas
 * (empty add_* arrays) are valid. Returns an error string or null.
 */
function ai_validate_delta(array $delta, array $existingSchema): ?string
{
    // Index existing tables/columns (lowercased) for collision checks.
    $existing = [];
    foreach ($existingSchema['tables'] ?? [] as $t) {
        $tn = strtolower((string)($t['name'] ?? ''));
        if ($tn === '') continue;
        $existing[$tn] = [];
        foreach ($t['columns'] ?? [] as $c) {
            $existing[$tn][strtolower((string)($c['name'] ?? ''))] = true;
        }
    }

    // Tables being added in THIS delta (so add_columns may target them).
    $newTables = [];
    foreach ($delta['add_tables'] ?? [] as $i => $t) {
        $name = (string)($t['name'] ?? '');
        try { \SupaBein\Schema::validateIdentifier($name); }
        catch (\InvalidArgumentException $e) { return "add_tables[$i].name: " . $e->getMessage(); }
        if (isset($existing[strtolower($name)])) {
            return "add_tables[$i] \"$name\" already exists — do not re-add existing tables; "
                 . "use add_columns or update_policies for changes to it.";
        }
        $newTables[strtolower($name)] = true;
        foreach ($t['columns'] ?? [] as $j => $c) {
            try { \SupaBein\Schema::validateDataType((string)($c['type'] ?? '')); }
            catch (\InvalidArgumentException $e) { return "add_tables[$i].columns[$j].type: " . $e->getMessage(); }
        }
    }

    foreach ($delta['add_columns'] ?? [] as $i => $entry) {
        $tn = strtolower((string)($entry['table'] ?? ''));
        if ($tn === '') return "add_columns[$i].table is required";
        $tableIsNew = isset($newTables[$tn]);
        if (!$tableIsNew && !isset($existing[$tn])) {
            return "add_columns[$i] targets unknown table \"{$entry['table']}\" — it is neither in "
                 . "the current schema nor in add_tables.";
        }
        foreach ($entry['columns'] ?? [] as $j => $c) {
            $cn = strtolower((string)($c['name'] ?? ''));
            try { \SupaBein\Schema::validateIdentifier((string)($c['name'] ?? '')); }
            catch (\InvalidArgumentException $e) { return "add_columns[$i].columns[$j].name: " . $e->getMessage(); }
            if (in_array($cn, ['id', 'created_at'], true)) {
                return "add_columns[$i] column \"{$entry['table']}.{$c['name']}\" is auto-managed by "
                     . "SupaBein — never add id or created_at.";
            }
            try { \SupaBein\Schema::validateDataType((string)($c['type'] ?? '')); }
            catch (\InvalidArgumentException $e) { return "add_columns[$i].columns[$j].type: " . $e->getMessage(); }
            if (!$tableIsNew && isset($existing[$tn][$cn])) {
                return "add_columns[$i] column \"{$entry['table']}.{$c['name']}\" already exists — "
                     . "remove it from the delta (additions only, no duplicates).";
            }
        }
    }

    foreach ($delta['update_policies'] ?? [] as $i => $p) {
        $tn = strtolower((string)($p['table'] ?? ''));
        if ($tn === '') return "update_policies[$i].table is required";
        if (!isset($existing[$tn]) && !isset($newTables[$tn])) {
            return "update_policies[$i] targets unknown table \"{$p['table']}\".";
        }
    }

    return null;
}

// ─── Schema serializer for two-pass generation ───────────────────────────────

/**
 * Deterministically cap an intent so a weak model can never explode scope, with or without
 * human review: dedupe, trim, drop empties, hard-limit to 5 actors and 7 stories. This runs
 * on every intent — it protects the review screen as much as the automatic path.
 */
function ai_cap_intent(array $intent): array
{
    $clean = static function ($list, int $max): array {
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $item) {
            if (!is_string($item)) continue;
            $item = trim($item);
            if ($item === '') continue;
            if (!in_array($item, $out, true)) $out[] = $item;
            if (count($out) >= $max) break;
        }
        return $out;
    };
    return [
        'actors'  => $clean($intent['actors']  ?? [], 5),
        'stories' => $clean($intent['stories'] ?? [], 7),
    ];
}

/**
 * Structural check on an intent so the retry loop has something concrete to feed back.
 */
function ai_validate_intent(array $intent): ?string
{
    if (empty($intent['actors'])  || !is_array($intent['actors']))  return 'intent.actors must be a non-empty array of strings';
    if (empty($intent['stories']) || !is_array($intent['stories'])) return 'intent.stories must be a non-empty array of strings';
    return null;
}

/**
 * Run the intent pass (pass 0) with one self-correcting retry, then cap deterministically.
 * Returns ['actors'=>[...], 'stories'=>[...]]. Throws \RuntimeException on model/transport error.
 */
function ai_generate_intent(object $client, string $prompt, array $history = []): array
{
    $call = static function (string $user) use ($client, $history) {
        return $history
            ? $client->generateJsonWithHistory(AI_INTENT_PROMPT, $history, $user)
            : $client->generateJson(AI_INTENT_PROMPT, $user);
    };

    $intent = $call($prompt);
    $err = ai_validate_intent($intent);
    if ($err) {
        $intent = $call($prompt . "\n\nYour previous response was rejected: " . $err
            . "\nReturn ONLY {\"actors\":[...],\"stories\":[...]} obeying the limits.");
        $err = ai_validate_intent($intent);
        if ($err) {
            // Last-resort fallback so a flaky intent pass never blocks a build.
            $intent = ['actors' => ['owner'], 'stories' => [$prompt]];
        }
    }
    return ai_cap_intent($intent);
}

/**
 * Serialize an approved intent into a locked context block for the schema pass. The wording
 * matches the "Locked product intent" rule in AI_BUILD_SCHEMA_PROMPT so the model treats it
 * as the complete, fixed scope rather than a suggestion to expand on.
 */
function ai_intent_to_context(array $intent): string
{
    $intent = ai_cap_intent($intent);
    $lines = "Locked product intent — design the schema for EXACTLY these, add nothing and drop nothing.\nActors:\n";
    foreach ($intent['actors'] as $a)  $lines .= "- {$a}\n";
    $lines .= "User stories:\n";
    foreach ($intent['stories'] as $s) $lines .= "- {$s}\n";
    return $lines;
}

/**
 * Detect the auth/users table in a plan (or DB-derived schema): the table holding a
 * PASSWORD column, plus its identifier field (email/username preferred). These fill the
 * __AUTH_TABLE__ / __AUTH_FIELD__ placeholders in the verbatim auth.js so weak models
 * never have to guess them. Returns ['table' => null, 'field' => null] when no auth.
 */
function ai_detect_auth(array $plan): array
{
    foreach ($plan['tables'] ?? [] as $t) {
        $hasPw = false;
        $field = null;
        foreach ($t['columns'] ?? [] as $c) {
            if (strtoupper(trim((string)($c['type'] ?? ''))) === 'PASSWORD') { $hasPw = true; continue; }
            if ($field === null) {
                $n = strtolower((string)($c['name'] ?? ''));
                if (in_array($n, ['email','username','user_name','login','handle','phone'], true)) {
                    $field = $c['name'];
                }
            }
        }
        if ($hasPw) {
            if ($field === null) {
                foreach ($t['columns'] ?? [] as $c) {
                    if (strtoupper(trim((string)($c['type'] ?? ''))) !== 'PASSWORD') { $field = $c['name']; break; }
                }
            }
            return ['table' => $t['name'], 'field' => $field ?? 'email'];
        }
    }
    return ['table' => null, 'field' => null];
}

/**
 * Bind __AUTH_TABLE__ / __AUTH_FIELD__ in a system prompt to the real schema values so the
 * model copies a ready-to-run auth.js instead of inventing one. Safe to call even with no
 * auth table (falls back to neutral defaults; the rules tell the model to omit auth.js then).
 */
function ai_bind_auth_placeholders(string $systemPrompt, array $plan): string
{
    $auth = ai_detect_auth($plan);
    return str_replace(
        ['__AUTH_TABLE__', '__AUTH_FIELD__'],
        [$auth['table'] ?? 'users', $auth['field'] ?? 'email'],
        $systemPrompt
    );
}

/**
 * Convert a validated plan's tables into a human-readable schema string
 * that is injected into the frontend prompt so the AI sees exact column names.
 */
function ai_schema_to_context(array $plan): string
{
    $lines = [];
    foreach ($plan['tables'] as $tbl) {
        $colParts = [];
        foreach ($tbl['columns'] as $col) {
            $part = $col['name'] . ' ' . $col['type'];
            $part .= ($col['nullable'] ?? true) ? ' NULL' : ' NOT NULL';
            if (($col['default'] ?? null) !== null) {
                $part .= ' DEFAULT ' . $col['default'];
            }
            $colParts[] = $part;
        }
        $lines[] = 'Table "' . $tbl['name'] . '": id (INT auto), '
                 . implode(', ', $colParts) . ', created_at (TIMESTAMP auto)';

        foreach ($tbl['policies'] ?? [] as $pol) {
            if ($pol['allowed']) {
                $constraint = $pol['constraint_sql'] ? ' WHERE ' . $pol['constraint_sql'] : '';
                $lines[] = '  policy: ' . $pol['api_role'] . ' ' . strtoupper($pol['operation']) . $constraint;
            }
        }
    }
    return implode("\n", $lines);
}

function ai_schema_from_db(int $projectId, \SupaBein\Catalog $catalog): array
{
    $tables = [];
    foreach ($catalog->listTables($projectId) as $tbl) {
        $cols = array_map(fn($c) => [
            'name'     => $c['name'],
            'type'     => $c['type'],
            'nullable' => (bool)$c['nullable'],
            'default'  => $c['default'] ?? null,
        ], $catalog->listColumns($tbl['id']));
        $tables[] = [
            'name'     => $tbl['logical_name'],
            'columns'  => $cols,
            'policies' => $catalog->listPolicies($tbl['id']),
        ];
    }
    return ['tables' => $tables];
}


// ─── AI output helpers ───────────────────────────────────────────────────────

/**
 * Lenient JSON extraction for models that wrap output in ```json fences or add prose.
 * Strips fences and pulls the first balanced {...} object.
 */
function ai_lenient_json(string $raw): ?array
{
    $s = trim($raw);

    if (str_starts_with($s, '```')) {
        $s = preg_replace('#^```[a-zA-Z]*\s*#', '', $s);
        $s = preg_replace('#\s*```\s*$#', '', $s);
    }

    $start = strpos($s, '{');
    if ($start === false) return null;

    $depth = 0; $inStr = false; $esc = false; $end = null;
    for ($i = $start, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($inStr) {
            if ($esc)            { $esc = false; }
            elseif ($c === '\\') { $esc = true; }
            elseif ($c === '"')  { $inStr = false; }
            continue;
        }
        if ($c === '"')      { $inStr = true; }
        elseif ($c === '{')  { $depth++; }
        elseif ($c === '}')  { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    if ($end === null) return null;

    $data = json_decode(substr($s, $start, $end - $start + 1), true);
    return is_array($data) ? $data : null;
}

/**
 * Collect top-level (global-scope) const/let/var names from a JS string.
 * Strips strings, templates, and comments so their braces don't skew depth.
 */
function ai_collect_top_level_decls(string $js): array
{
    $clean = preg_replace('#/\*.*?\*/#s', '', $js);
    $clean = preg_replace('#//[^\n]*#', '', (string)$clean);
    $clean = preg_replace('#"(?:\\\\.|[^"\\\\])*"#s', '""', (string)$clean);
    $clean = preg_replace("#'(?:\\\\.|[^'\\\\])*'#s", "''", (string)$clean);
    $clean = preg_replace('#`(?:\\\\.|[^`\\\\])*`#s', '``', (string)$clean);

    $names = [];
    $depth = 0;
    $len   = strlen((string)$clean);
    for ($i = 0; $i < $len; $i++) {
        $ch = $clean[$i];
        if ($ch === '{' || $ch === '(' || $ch === '[') { $depth++; continue; }
        if ($ch === '}' || $ch === ')' || $ch === ']') { $depth = max(0, $depth - 1); continue; }
        if ($depth === 0 && ($ch === 'c' || $ch === 'l' || $ch === 'v')) {
            if (preg_match('/(const|let|var)\s+([A-Za-z_$][\w$]*)/A', $clean, $m, 0, $i)) {
                $names[] = $m[2];
                $i += strlen($m[0]) - 1;
            }
        }
    }
    return $names;
}

/**
 * Static pre-publish smoke check on the assembled deploy directory.
 * Returns an error string (deploy should be rejected) or null if it passes.
 */
function ai_smoke_check_dir(string $dir): ?string
{
    $indexPath = $dir . '/index.html';
    if (!is_file($indexPath)) return 'index.html is missing';
    $html = (string)file_get_contents($indexPath);

    // 1. No absolute script paths (break on subdomain hosting).
    if (preg_match('#<script[^>]+src\s*=\s*["\']/[^"\']#i', $html)) {
        return 'index.html loads a script with an absolute path (src="/..."); use relative paths';
    }

    // 2. Every referenced local script must exist in the assembled deploy.
    if (preg_match_all('#<script[^>]+src\s*=\s*["\']([^"\']+)["\']#i', $html, $m)) {
        foreach ($m[1] as $src) {
            if (preg_match('#^https?://#i', $src)) continue;
            $rel = ltrim(preg_replace('#^\./+#', '', $src), '/');
            if (!is_file($dir . '/' . $rel)) {
                return "index.html references a missing script: {$src}";
            }
        }
    }

    // 3. Duplicate top-level const/let across all classic scripts → fatal SyntaxError.
    $counts = [];
    $record = function (string $js) use (&$counts) {
        foreach (ai_collect_top_level_decls($js) as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
    };

    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'js') {
            $record((string)file_get_contents($f->getPathname()));
        }
    }
    // inline <script> blocks in index.html (those WITHOUT a src attribute)
    if (preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $html, $sm)) {
        foreach ($sm[1] as $block) {
            $record($block);
        }
    }

    $dupes = array_keys(array_filter($counts, fn($c) => $c > 1));
    if ($dupes) {
        return 'duplicate top-level declaration(s) — fatal "already declared" SyntaxError: '
             . implode(', ', $dupes)
             . '. Each module/global must be declared exactly once.';
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
        $project   = $catalog->createProject($userId, $projectName, '');
        $projectId = (int)$project['id'];
        $serviceKey = make_service_key($projectId);
        $catalog->setServiceKey($projectId, $serviceKey);
        $project['service_key'] = $serviceKey;
        $partial['project'] = $project;
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
                'default'  => (isset($col['default']) && $col['default'] !== null && $col['default'] !== false)
                               ? (string)$col['default'] : null,
            ];
        }

        try {
            $table = $catalog->createTable($projectId, $tableName);
        } catch (\PDOException $e) {
            $catalog->deleteProject($projectId, $userId);
            abort(500, "Table creation failed for \"$tableName\": " . $e->getMessage(), [
                'partial' => array_merge($partial, ['failed_at' => $tableName]),
            ]);
        }

        try {
            $ddl = \SupaBein\Schema::createTableDDL($table['physical_name'], $columns);
            \SupaBein\Schema::applyDDL($pdo, $projectId, $ddl);
        } catch (\Throwable $e) {
            $catalog->deleteTable($projectId, $tableName);
            $catalog->deleteProject($projectId, $userId);
            abort(500, "DDL failed for table \"$tableName\": " . $e->getMessage(), [
                'partial' => array_merge($partial, ['failed_at' => $tableName]),
            ]);
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

// ─── Frontend file reader ────────────────────────────────────────────────────

function ai_read_frontend_files(array $config, \SupaBein\Catalog $catalog, int $projectId, string $prompt = ''): string
{
    $sites = $catalog->listSites($projectId);
    if (empty($sites)) return '';

    $site = $sites[0];
    if (!($site['current_deploy_id'] ?? null)) return '';

    $sitesPath  = rtrim($config['SITES_PATH'], '/');
    $currentDir = $sitesPath . '/s' . $site['id'] . '/current';
    if (!is_dir($currentDir)) return '';

    // Index all text files
    $textExts = ['html', 'css', 'js', 'json'];
    $allFiles = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($currentDir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if (!in_array(strtolower($file->getExtension()), $textExts, true)) continue;
        $rel = ltrim(substr($file->getPathname(), strlen($currentDir)), '/');
        $allFiles[$rel] = $file->getPathname();
    }
    if (empty($allFiles)) return '';

    $listing = 'Frontend files: ' . implode(', ', array_keys($allFiles));

    // Tier 1 — debug/fix signals: send everything (up to cap)
    $lowerPrompt = strtolower($prompt);
    $isDebug = (bool)preg_match('/\b(why|fix|broken|error|bug|issue|problem|debug|not working|failed|wrong|crash)\b/', $lowerPrompt);

    // Tier 2 — extract meaningful prompt words (4+ chars) to match against file paths
    $stopWords = ['that', 'this', 'with', 'have', 'from', 'they', 'will', 'what', 'when', 'where', 'which', 'there', 'their', 'your', 'about', 'does', 'just', 'like', 'make', 'show', 'give', 'tell'];
    $promptWords = array_unique(array_filter(
        preg_split('/\W+/', $lowerPrompt) ?: [],
        fn($w) => strlen($w) >= 4 && !in_array($w, $stopWords, true)
    ));

    $targeted = [];
    if (!$isDebug && !empty($promptWords)) {
        foreach ($allFiles as $rel => $fullPath) {
            $lowerRel = strtolower($rel);
            foreach ($promptWords as $word) {
                if (str_contains($lowerRel, $word)) {
                    $targeted[] = $rel;
                    break;
                }
            }
        }
    }

    // Tier 3 — no file signals at all: return listing only
    if (!$isDebug && empty($targeted)) {
        return "\n\n" . $listing;
    }

    // Read the relevant files up to 40KB
    $sources    = $isDebug ? array_keys($allFiles) : $targeted;
    $maxTotal   = 40000;
    $totalBytes = 0;
    $fileLines  = [$listing];

    foreach ($sources as $rel) {
        $fullPath = $allFiles[$rel];
        $size     = filesize($fullPath);
        if ($totalBytes + $size > $maxTotal) {
            $fileLines[] = "--- $rel (too large, skipped) ---";
            continue;
        }
        $fileLines[]  = "--- $rel ---\n" . file_get_contents($fullPath);
        $totalBytes  += $size;
    }

    return "\n\nFrontend files (current deploy):\n" . implode("\n\n", $fileLines);
}

// ─── AI provider factory ─────────────────────────────────────────────────────

const AI_ALLOWED_PROVIDERS = ['gemini', 'openrouter', 'nvidia'];
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
        'moonshotai/kimi-k2',
        'google/gemma-4-31b-it:free',
        'google/gemma-4-26b-a4b-it:free',
        'openai/gpt-oss-120b:free',
        'openai/gpt-oss-20b:free',
        'nvidia/nemotron-3-super-120b-a12b:free',
        'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
        'openrouter/owl-alpha',
        'nex-agi/nex-n2-pro:free',
        'poolside/laguna-xs.2:free',
    ],
    'nvidia' => [
        'qwen/qwen3.5-122b-a10b',
        'z-ai/glm-5.1',
        'deepseek-ai/deepseek-v4-pro',
        'deepseek-ai/deepseek-v4-flash',
        'nvidia/nemotron-3-ultra-550b-a55b',
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

    if ($provider === 'nvidia') {
        $key = $config['NVIDIA_API_KEY'] ?? '';
        if (!$key) abort(503, 'NVIDIA API key not configured on this server');
        $allowed = AI_ALLOWED_MODELS['nvidia'];
        $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
        return new \SupaBein\NvidiaClient($key, $model);
    }

    // Default: Gemini
    $key = $config['GEMINI_API_KEY'] ?? '';
    if (!$key) abort(503, 'AI build is not configured on this server (missing GEMINI_API_KEY)');
    $allowed = AI_ALLOWED_MODELS['gemini'];
    $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
    return new \SupaBein\GeminiClient($key, $model);
}

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

    $frontendMsg    = "App description: {$prompt}\n\nExact validated schema — use ONLY these column names in JS:\n"
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

        // Column audit pass
        $allCols  = [];
        foreach ($existingSchema['tables'] as $tbl) {
            foreach ($tbl['columns'] as $col) {
                $allCols[] = '"' . $col['name'] . '" (table "' . $tbl['name'] . '")';
            }
            $allCols[] = '"id" (auto, table "' . $tbl['name'] . '")';
            $allCols[] = '"created_at" (auto, table "' . $tbl['name'] . '")';
        }
        try {
            $audited = $client->generateJson(
                "You are a code reviewer fixing frontend JS column name mismatches. Return only {\"files\":[...]} with corrected file contents.",
                "EXACT column names: " . implode(', ', $allCols)
                . "\n\nFrontend files:\n" . json_encode(['files' => $delta['frontend']['files']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                . "\n\nCheck every data property access and replace any hallucinated column names with the correct ones. Return ONLY: {\"files\": [{\"path\": string, \"content\": string}]}"
            );
            if (!empty($audited['files']) && is_array($audited['files'])) {
                foreach ($audited['files'] as &$aFile) {
                    $aFile['path'] = ltrim(preg_replace('#^\./+#', '', $aFile['path'] ?? ''), '/');
                }
                unset($aFile);
                $delta['frontend']['files'] = $audited['files'];
            }
        } catch (\RuntimeException $e) {
            sb_log('ai_pipeline', 'Column audit skipped: ' . $e->getMessage());
        }
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

// ─── Route registration ──────────────────────────────────────────────────────

function register_ai_routes(\SupaBein\Router $router): void
{
    $router->post('/v1/ai/build', function (array $req): void {
        set_time_limit(420);

        $config  = \App::get('config');
        $userId  = (int)$req['auth']['user_id'];

        // ── 1. Validate inputs ────────────────────────────────────────────────
        $prompt = trim($req['body']['prompt'] ?? '');
        if (!$prompt || strlen($prompt) > 2000) {
            abort(422, 'prompt is required and must be under 2000 characters');
        }

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
                $intent = ai_generate_intent($gemini, $prompt);
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'credits') || str_contains($msg, 'quota')) abort(402, $msg);
                abort(502, 'AI generation failed: ' . $msg);
            }
            json_out(['mode' => 'intent', 'intent' => $intent, 'usage' => $gemini->getLastUsage()]);
            return;
        }

        // If an approved intent was supplied, lock it into the schema prompt as fixed scope.
        $schemaUserMsg = $prompt;
        if ($approvedIntent) {
            $schemaUserMsg = $prompt . "\n\n" . ai_intent_to_context($approvedIntent);
        }

        sb_log('ai_build', 'Calling AI (pass 1: schema)', ['user_id' => $userId, 'provider' => $provider, 'model' => $model, 'locked_intent' => (bool)$approvedIntent]);

        // Pass 1 — schema only (one self-correcting retry on validation failure)
        try {
            $schemaPlan = $gemini->generateJson(AI_BUILD_SCHEMA_PROMPT, $schemaUserMsg);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            sb_log('ai_build', 'AI error (pass 1): ' . $msg, ['user_id' => $userId]);
            if (str_contains($msg, 'credits') || str_contains($msg, 'quota')) abort(402, $msg);
            abort(502, 'AI generation failed: ' . $msg);
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
                $schemaPlan = $gemini->generateJson(AI_BUILD_SCHEMA_PROMPT, $retryPrompt);
                $schemaPlan['frontend'] = ['files' => []];
                $schemaPlan = ai_sanitize_plan($schemaPlan);
                $validationError = ai_validate_plan($schemaPlan);
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'credits') || str_contains($msg, 'quota')) abort(402, $msg);
                abort(502, 'AI generation failed: ' . $msg);
            }
        }
        if ($validationError) {
            sb_log('ai_build', 'Schema validation failed after retry: ' . $validationError, ['plan_keys' => array_keys($schemaPlan)]);
            abort(422, 'AI returned an invalid schema: ' . $validationError);
        }

        // Pass 2 — frontend with exact (post-sanitize) column names + bound auth.js
        sb_log('ai_build', 'Calling AI (pass 2: frontend)', ['user_id' => $userId]);
        $frontendMsg = "App description: {$prompt}\n\nExact validated schema — use ONLY these column names in JS:\n"
                     . ai_schema_to_context($schemaPlan);
        try {
            $frontendResult = $gemini->generateJson(ai_bind_auth_placeholders(AI_BUILD_FRONTEND_PROMPT, $schemaPlan), $frontendMsg);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            sb_log('ai_build', 'AI error (pass 2): ' . $msg, ['user_id' => $userId]);
            if (str_contains($msg, 'credits') || str_contains($msg, 'quota')) abort(402, $msg);
            abort(502, 'AI frontend generation failed: ' . $msg);
        }

        $plan = $schemaPlan;
        $plan['frontend'] = ['files' => $frontendResult['files'] ?? []];
        foreach ($plan['frontend']['files'] as &$file) {
            $file['path'] = ltrim(preg_replace('#^\./+#', '', $file['path'] ?? ''), '/');
        }
        unset($file);

        // ── 4-7. Execute build ────────────────────────────────────────────────
        $result = ai_execute_build($plan, $userId);
        json_out($result, 201);

    }, ['auth_middleware']);

    // ── AI Intent: return capped actors + user stories for review (builds nothing) ──
    $router->post('/v1/ai/intent', function (array $req): void {
        set_time_limit(420);

        $config = \App::get('config');
        $userId = (int)$req['auth']['user_id'];

        $prompt = trim($req['body']['prompt'] ?? '');
        if (!$prompt || strlen($prompt) > 2000) {
            abort(422, 'prompt is required and must be under 2000 characters');
        }

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
        $existingSchema   = ai_schema_from_db($projectId, $catalog);
        $editSystemPrompt = ai_bind_auth_placeholders(AI_EDIT_SYSTEM_PROMPT, $existingSchema);
        try {
            $delta = $gemini->generateJson($editSystemPrompt, $userMessage);

            // Validate the delta; one self-correcting retry with the reason fed back.
            $deltaError = ai_validate_delta($delta, $existingSchema);
            if ($deltaError) {
                sb_log('ai_edit', 'Delta invalid, retrying with feedback: ' . $deltaError, ['project_id' => $projectId]);
                $retryMsg = $userMessage
                    . "\n\nYour previous response was rejected for this reason:\n  " . $deltaError
                    . "\nReturn a corrected JSON delta that fixes exactly this problem and nothing else.";
                $delta = $gemini->generateJson($editSystemPrompt, $retryMsg);
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
                $msg = $e->getMessage();
                if (str_contains($msg, 'credits') || str_contains($msg, 'quota')) abort(402, $msg);
                abort(502, 'AI recovery failed: ' . $msg);
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

            $suggestContext = "Project: " . $project['name']
                . "\n\nExact schema:\n" . $schemaCtx
                . $currentFiles
                . "\n\nUser request: " . $prompt;

            $suggestPrompt = <<<'PROMPT'
You are a SupaBein full-stack AI assistant reviewing an edit request.
Analyze the project schema, frontend files, and user request.
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
PROMPT;

            try {
                $result = $gemini->generateJson($suggestPrompt, $suggestContext);
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'credits') || str_contains($msg, 'quota')) abort(402, $msg);
                abort(502, 'AI suggest failed: ' . $msg);
            }

            json_out([
                'mode'        => 'suggest',
                'suggestions' => $result['suggestions'] ?? [],
                'usage'       => $gemini->getLastUsage(),
            ]);
        }

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
                $res = $gemini->generateJsonWithHistory($chatSystemPrompt, $history, $userQuestion);
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'credits') || str_contains($msg, 'quota')) abort(402, $msg);
                abort(502, 'AI generation failed: ' . $msg);
            }
            json_out([
                'mode'    => 'chat',
                'message' => $res['message'] ?? 'Hi! How can I help you?',
                'usage'   => $gemini->getLastUsage(),
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
                $schemaPlan = $gemini->generateJsonWithHistory(AI_BUILD_SCHEMA_PROMPT, $history, $schemaUserMsg);
                $schemaPlan['frontend'] = ['files' => []];
                $schemaPlan = ai_sanitize_plan($schemaPlan);

                $validationError = ai_validate_plan($schemaPlan);
                if ($validationError) {
                    // One self-correcting retry with the rejection reason fed back.
                    $retryPrompt = $schemaUserMsg
                        . "\n\nYour previous schema was rejected for this reason:\n  " . $validationError
                        . "\nReturn a corrected schema that fixes exactly this problem.";
                    $schemaPlan = $gemini->generateJsonWithHistory(AI_BUILD_SCHEMA_PROMPT, $history, $retryPrompt);
                    $schemaPlan['frontend'] = ['files' => []];
                    $schemaPlan = ai_sanitize_plan($schemaPlan);
                    $validationError = ai_validate_plan($schemaPlan);
                    if ($validationError) {
                        abort(422, 'AI returned an invalid schema: ' . $validationError);
                    }
                }

                // Pass 2 — frontend with exact (post-sanitize) column names + bound auth.js
                $frontendMsg = "App description: {$prompt}\n\nExact validated schema — use ONLY these column names in JS:\n"
                             . ai_schema_to_context($schemaPlan);
                $frontendResult = $gemini->generateJson(ai_bind_auth_placeholders(AI_BUILD_FRONTEND_PROMPT, $schemaPlan), $frontendMsg);

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

                json_out(['mode' => 'build', 'plan' => $plan, 'summary' => $summary, 'usage' => $gemini->getLastUsage()]);

            } elseif ($mode === 'edit') {
                $project = $catalog->getProjectById($projectId, $userId);
                if (!$project) abort(404, 'Project not found');

                $existingSchema = ai_schema_from_db($projectId, $catalog);
                $schemaCtx    = ai_schema_to_context($existingSchema);
                $currentFiles = ai_read_frontend_files($config, $catalog, $projectId, $prompt);
                $userMessage  = "Exact schema:\n{$schemaCtx}\n\nCurrent frontend:\n{$currentFiles}\n\nRequest: {$prompt}";
                $editSystemPrompt = ai_bind_auth_placeholders(AI_EDIT_SYSTEM_PROMPT, $existingSchema);
                $delta = $gemini->generateJsonWithHistory($editSystemPrompt, $history, $userMessage);

                // Validate the delta; one self-correcting retry with the reason fed back.
                $deltaError = ai_validate_delta($delta, $existingSchema);
                if ($deltaError) {
                    $retryMsg = $userMessage
                        . "\n\nYour previous response was rejected for this reason:\n  " . $deltaError
                        . "\nReturn a corrected JSON delta that fixes exactly this problem and nothing else.";
                    $delta = $gemini->generateJsonWithHistory($editSystemPrompt, $history, $retryMsg);
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

                // ── Column audit pass: fix any hallucinated column names in frontend ──
                if (!empty($delta['frontend']['files'])) {
                    $allCols = [];
                    foreach ($existingSchema['tables'] as $tbl) {
                        foreach ($tbl['columns'] as $col) {
                            $allCols[] = '"' . $col['name'] . '" (table "' . $tbl['name'] . '")';
                        }
                        $allCols[] = '"id" (auto, table "' . $tbl['name'] . '")';
                        $allCols[] = '"created_at" (auto, table "' . $tbl['name'] . '")';
                    }
                    $colList   = implode(', ', $allCols);
                    $filesJson = json_encode(['files' => $delta['frontend']['files']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $auditMsg  = "EXACT column names: {$colList}\n\nFrontend files:\n{$filesJson}\n\n"
                               . "Check every data property access (obj.field, obj['field'], row.field) against the exact column list. "
                               . "Replace any hallucinated or incorrect column names with the correct ones from the exact list. "
                               . "Return ONLY: {\"files\": [{\"path\": string, \"content\": string}]}";
                    $auditSystem = "You are a code reviewer fixing frontend JS column name mismatches. "
                                 . "Return only {\"files\":[...]} with corrected file contents.";
                    try {
                        $audited = $gemini->generateJson($auditSystem, $auditMsg);
                        if (!empty($audited['files']) && is_array($audited['files'])) {
                            foreach ($audited['files'] as &$aFile) {
                                $aFile['path'] = ltrim(preg_replace('#^\./+#', '', $aFile['path'] ?? ''), '/');
                            }
                            unset($aFile);
                            $delta['frontend']['files'] = $audited['files'];
                        }
                    } catch (\RuntimeException $e) {
                        sb_log('ai_edit', 'Column audit skipped: ' . $e->getMessage());
                    }
                }

                $summary = [
                    'add_tables'      => array_column($delta['add_tables'] ?? [], 'name'),
                    'add_columns'     => array_merge(...array_map(fn($e) => array_map(fn($c) => $e['table'] . '.' . $c['name'], $e['columns'] ?? []), $delta['add_columns'] ?? [])),
                    'update_policies' => array_map(fn($p) => $p['table'] . ' ' . $p['api_role'] . ' ' . $p['operation'], $delta['update_policies'] ?? []),
                ];
                if (!empty($delta['frontend']['files'])) {
                    $summary['frontend_files'] = count($delta['frontend']['files']);
                }

                $editPlan = array_merge($delta, ['project_id' => $projectId]);
                json_out(['mode' => 'edit', 'plan' => $editPlan, 'summary' => $summary, 'usage' => $gemini->getLastUsage()]);

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

                $apiBase       = rtrim($config['API_BASE_URL'], '/') . '/v1';
                $frontendFiles = ai_read_frontend_files($config, $catalog, $projectId, $prompt);
                $context = "Project: " . $project['name'] . "\nAPI base: " . $apiBase . "\nSchema:\n" . $schemaContext . $frontendFiles . "\n\nIssue: " . $prompt;

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
            json_out($result, 201);

        } elseif ($mode === 'edit') {
            $projectId = (int)($plan['project_id'] ?? 0);
            if (!$projectId) abort(422, 'plan.project_id is required for edit mode');
            $project = $catalog->getProjectById($projectId, $userId);
            if (!$project) abort(404, 'Project not found');
            $deltaError = ai_validate_delta($plan, ai_schema_from_db($projectId, $catalog));
            if ($deltaError) abort(422, 'Invalid edit plan: ' . $deltaError);

            $result = ai_execute_edit($plan, $projectId, $userId);
            if (!empty($plan['frontend']['files'])) {
                $editConfig = \App::get('config');
                $editSites  = $catalog->listSites($projectId);
                if ($editSites) {
                    $deployResult = ai_deploy_files($editConfig, $catalog, (int)$editSites[0]['id'],
                                                    $project, $plan['frontend']['files'],
                                                    true);
                    if (!empty($deployResult['deploy'])) {
                        $result['deploy'] = $deployResult['deploy'];
                    }
                }
            }
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
