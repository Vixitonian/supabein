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

OUTPUT FORMAT — include these alongside "tables":

  "seed_data": {
    "<table_name>": [
      { "<col>": <value>, ... }
    ]
  }

Seed rules:
- Include 3–8 realistic, domain-appropriate rows for every table that would look empty and
  meaningless without data (products, articles, menu items, portfolio items, testimonials, etc.)
- Do NOT seed auth/users tables or tables that use :current_user_id ownership (e.g. carts, orders
  belonging to a user). Only seed "global" or "public catalogue" tables.
- Omit "id" and "created_at" — SupaBein inserts them automatically
- Values must match the column types exactly (strings for VARCHAR/TEXT, numbers for INT/DECIMAL,
  null for nullable columns with no obvious value)
- For image_url columns leave null — the frontend substitutes a Picsum placeholder at runtime
- If no table needs seeding, return "seed_data": {}

IMAGE COLUMNS:
- If a table naturally displays images (products, portfolio items, blog posts, recipes, team members,
  menu items, properties, etc.), include an image_url column:
  {"name": "image_url", "type": "VARCHAR(255)", "nullable": true, "default": null}
- Do NOT add image_url to users tables, transactional tables (orders, payments, logs), or pure
  junction/relation tables.

CONTENT BLOCKS (public-facing / marketing sites only):
- If the app has a public landing page or a section a non-technical owner would update (hero copy,
  about text, feature highlights), add a "content_blocks" table:
  columns: section_key VARCHAR(64) NOT NULL, heading VARCHAR(255) NULL, body_text MEDIUMTEXT NULL,
           display_order INT NOT NULL DEFAULT 0
  policies: anon SELECT allowed; authenticated INSERT/UPDATE/DELETE
  Seed it with realistic copy that matches the app's domain (3–5 rows covering hero, features, etc.)

FORMS MUST BE BACKED BY TABLES:
- Every user-submitted form (contact, booking, review, inquiry, newsletter signup) MUST have a
  corresponding table that persists the data. Never design a form without a matching table.
  Example: a "Contact Us" form → a "contact_submissions" table with columns for each form field.

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
- The users table MUST include "anon INSERT allowed" in its policies. Signup calls POST /data/:pid/users
  without a token (the user is not yet authenticated), so anon INSERT must be allowed or every
  registration attempt will return 403 Forbidden. Example minimal policy set for users:
    anon INSERT allowed (no constraint — lets anyone register)
    authenticated SELECT allowed (constraint: id = :current_user_id — own row only)
    authenticated UPDATE allowed (constraint: id = :current_user_id)
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

// ── Intent pass (pass 0): actors + stories + journeys + requirements ──────────
const AI_INTENT_PROMPT = <<<'PROMPT'
You extract the MINIMAL product requirements for a web app from a short description.
Return ONLY JSON with this exact structure — no prose, no markdown fences:
{
  "actors": [
    {
      "name": "actor name",
      "stories": [
        {
          "title": "as a <actor> I can <action>",
          "journeys": ["Journey: <Step A> → <Step B> → <Step C>"],
          "requirements": ["System can ...", "System validates ..."]
        }
      ]
    }
  ],
  "non_functional_requirements": ["Load in under 2 seconds", "Data encrypted at rest"]
}

Rules:
- MINIMAL: include only what the description explicitly asks for. Invent nothing.
- "actors": distinct human user types. For a single-user app, exactly one actor named "owner".
  Add another actor ONLY if the description implies sharing, roles, or multiple user types.
- "stories": 1–4 per actor. Core actions only: create, view, edit, delete. One capability each.
  No admin panels, notifications, comments, search, analytics, tags unless explicitly requested.
- "journeys": 1–2 per story. Format: "Journey: <start> → <middle step> → <end state>"
- "requirements": 2–4 per story. Start each with "System can", "System validates", "System saves", etc.
- "non_functional_requirements": 4–6 items. Cover performance, security, reliability, scalability.
  Examples: "Page loads in under 2 seconds", "Notes persist after browser refresh",
  "Data encrypted at rest", "Support up to 10,000 records per user"
- HARD LIMITS: at most 5 actors, 4 stories per actor, 2 journeys per story, 4 requirements per story
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
core/router.js MUST be EXACTLY this file (copy verbatim — an empty initial hash MUST resolve
to the home route '/', otherwise the very first page load shows a bogus "404 - Not Found"):

  const router = (() => {
    const routes = {};
    const appDiv = document.getElementById('app');
    const defineRoute = (path, handler) => { routes[path] = handler; };
    const navigate = (path) => { window.location.hash = path; };
    const onHashChange = async () => {
      // Empty hash (first load, or "#") means the home route, never 404.
      const path = window.location.hash.replace(/^#/, '') || '/';
      const handler = routes[path] || routes['/404'] ||
        (() => { appDiv.innerHTML = '<h1 class="text-2xl text-red-400 p-8">404 - Not Found</h1>'; });
      if (!appDiv) return;
      appDiv.innerHTML = '<p class="text-gray-400 animate-pulse text-center p-8">Loading...</p>';
      try { await handler(); }
      catch (error) {
        appDiv.innerHTML = '<p class="text-red-400 text-center p-8">Error: ' + error.message + '</p>';
        console.error('Routing error:', error);
      }
    };
    return { defineRoute, navigate, onHashChange };
  })();

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
    const goLogin = () => {
      localStorage.removeItem('sb:token');
      if (!String(location.hash).toLowerCase().includes('login')) location.hash = '#/login';
    };
    const authHeader = () => {
      const t = localStorage.getItem('sb:token');
      if (!t) return {};
      // Drop our own expired token so we never send a dead one and hit "not found".
      try {
        const exp = JSON.parse(atob(t.split('.')[1])).exp;
        if (exp && Date.now() / 1000 > exp) { goLogin(); return {}; }
      } catch {}
      return { 'Authorization': 'Bearer ' + t };
    };
    const base = (table) => `${SB_URL}/data/${SB_PID}/${table}`;
    // Tolerate either a bare array OR a wrapped envelope from the data API.
    const unwrap = (j) => Array.isArray(j) ? j : (j && (j.data ?? j.rows ?? j.records)) ?? j;
    const req = async (url, opts = {}) => {
      const hadToken = !!localStorage.getItem('sb:token');
      const res = await fetch(url, {
        ...opts,
        headers: { 'Content-Type': 'application/json', ...authHeader(), ...(opts.headers || {}) }
      });
      // A logged-in user being denied means their session is stale — re-login
      // instead of showing a dead-end "not found / no permission".
      if (res.status === 401 || (hadToken && res.status === 403)) { goLogin(); throw new Error('Your session expired — please log in again.'); }
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

NAVIGATION — choose based on app complexity:
- For apps with ≤4 nav links: use a top bar with links hidden on mobile behind a hamburger toggle:
    <button id="nav-toggle" class="md:hidden p-2 rounded text-gray-300 hover:text-white">☰</button>
    <nav id="nav-menu" class="hidden md:flex items-center gap-4">...links...</nav>
    JS: document.getElementById('nav-toggle').addEventListener('click', () => document.getElementById('nav-menu').classList.toggle('hidden'));
- For apps with 5+ nav links or complex sections: use a sidebar nav instead of a horizontal bar.
  Sidebar: fixed left column on desktop (w-56 bg-gray-900), slides in from left on mobile triggered by the ☰ hamburger.
  Never use a horizontal nav that could overflow or wrap — choose one of the two patterns above.
Always pair with a hamburger (☰) button visible only on mobile (md:hidden).

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
- Base colours (always): bg-gray-950 (page), bg-gray-900 (cards),
  text-gray-100 (primary text), text-gray-400 (muted), text-red-400 (danger).
- Accent colour — pick ONE based on the app's domain. Use it consistently for interactive
  elements (links, primary buttons, focus rings, active states):
    productivity / tasks / notes  → indigo  (text-indigo-400, bg-indigo-500 hover:bg-indigo-600, ring-indigo-500)
    food / recipes / restaurant   → orange  (text-orange-400, bg-orange-500 hover:bg-orange-600, ring-orange-500)
    finance / budget / invoices   → blue    (text-blue-400,   bg-blue-500   hover:bg-blue-600,   ring-blue-500)
    health / fitness / wellness   → teal    (text-teal-400,   bg-teal-500   hover:bg-teal-600,   ring-teal-500)
    education / learning / quiz   → violet  (text-violet-400, bg-violet-500 hover:bg-violet-600, ring-violet-500)
    social / community / chat     → pink    (text-pink-400,   bg-pink-500   hover:bg-pink-600,   ring-pink-500)
    inventory / logistics / shop  → amber   (text-amber-400,  bg-amber-500  hover:bg-amber-600,  ring-amber-500)
    other / general               → emerald (text-emerald-400, bg-emerald-500 hover:bg-emerald-600, ring-emerald-500)
- Buttons: rounded-lg px-4 py-2 font-medium transition.
  Primary = bg-{accent}-500 hover:bg-{accent}-600 text-white.
- Inputs: bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-gray-100 w-full
  focus:outline-none focus:ring-2 focus:ring-{accent}-500.
- Page wrapper: use min-h-[100dvh] (dynamic viewport height) instead of min-h-screen or h-screen
  so the layout does not get clipped by the mobile virtual keyboard.
- Mobile layout rules (apply to all grid/table/form layouts):
    • Card grids: grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4
    • Data tables: wrap in <div class="overflow-x-auto"> so they scroll horizontally on mobile
    • Forms: always stack vertically (flex flex-col gap-4); inputs use w-full
    • Flex rows with many items: add flex-wrap gap-2 so they wrap instead of overflow
- Never hardcode the year (e.g. a "© 2023" footer). Use new Date().getFullYear().

═══════════════════════════════════════════════════════
RULE 8 — IMAGE COLUMNS: PICSUM RUNTIME FALLBACK
═══════════════════════════════════════════════════════
Some tables have an "image_url" VARCHAR(255) column that is null in seeded rows.
When rendering any image_url field ALWAYS supply a deterministic Picsum fallback:
  const src = row.image_url || `https://picsum.photos/seed/${tableName}-${row.id}/800/600`;
  imgEl.src = src;
The seed string (tableName + row.id) must be deterministic so the same row always shows the
same placeholder image. NEVER render a broken <img> or hide the image slot — always show something.

═══════════════════════════════════════════════════════
RULE 9 — CONTENT BLOCKS: RENDER FROM DATABASE
═══════════════════════════════════════════════════════
If the schema includes a "content_blocks" table, its rows MUST drive the public landing content.
Do NOT hardcode marketing copy — fetch from the DB:
  const blocks = await api.list('content_blocks');
  blocks.sort((a, b) => (a.display_order ?? 0) - (b.display_order ?? 0));
  // render each block's heading + body_text
This lets the site owner update copy without touching code.

═══════════════════════════════════════════════════════
RULE 10 — FORMS: ALWAYS PERSIST VIA api.create()
═══════════════════════════════════════════════════════
Every <form> in the app MUST submit its data via api.create() to its backing table.
Never console.log(), alert(), or silently discard form data — it must reach the database.
Pattern for every form submit handler:
  formEl.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = formEl.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      await api.create('table_name', { field1: input1.value.trim(), field2: input2.value.trim() });
      // show inline success (e.g. green text, reset form)
      formEl.reset();
    } catch (err) {
      errorEl.textContent = 'Failed: ' + err.message;  // text-red-400
    } finally {
      btn.disabled = false;
    }
  });

═══════════════════════════════════════════════════════
RULE 11 — HTML HEAD + QUALITY FLOOR
═══════════════════════════════════════════════════════
Every index.html <head> MUST include ALL of the following:
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="[specific 8-15 word description of THIS app]">
  <title>[App Name] — [4-6 word tagline specific to this app]</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>[one relevant emoji]</text></svg>">
- Title must name the app and its purpose — never "My App", "SupaBein App", or a generic placeholder
- Meta description must describe what this specific app does
- Favicon MUST use the inline SVG emoji data URL (no external file dependency)

═══════════════════════════════════════════════════════
PLACEHOLDERS + OWNERSHIP
═══════════════════════════════════════════════════════
- In core/config.js use these EXACT two lines (SB_PID is substituted at deploy time;
  SB_URL is derived at runtime so the app works on both HTTP and HTTPS):
    const SB_URL = window.location.origin + '/api/v1';
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
    bool $mergeFromCurrent = false,
    bool $publishLive = true
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

    // Substitution map — replace placeholders with real values.
    // SB_URL is intentionally NOT substituted here; generated code uses
    // window.location.origin + '/api/v1' at runtime for HTTP/HTTPS compatibility.
    $replacements = [
        '__SB_PID__' => (string)$project['id'],
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

    // Staging-only: leave the deploy in staging/ with staging_deploy_id set so the
    // user can preview and explicitly publish it to live later.
    if (!$publishLive) {
        return ['error' => null, 'deploy' => $catalog->getDeployById($deployId), 'published' => false, 'staged' => true];
    }

    // Auto-publish to current/ so the site is live immediately.
    $currentDir = $sitesPath . '/s' . $siteId . '/current';
    if (is_dir($currentDir))  \SupaBein\Deploy::rrmdir($currentDir);
    if (is_link($currentDir)) unlink($currentDir);
    \SupaBein\Deploy::rcopy($stagingDir, $currentDir);
    $catalog->updateSiteCurrentDeploy($siteId, $deployId);
    $catalog->updateSiteStagingDeploy($siteId, null);

    return ['error' => null, 'deploy' => $catalog->getDeployById($deployId), 'published' => true];
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
 * Cap intent to hard limits. Handles both the legacy flat format
 * {actors:string[], stories:string[]} and the new nested format
 * {actors:[{name, stories:[{title, journeys, requirements}]}], non_functional_requirements:[]}.
 */
function ai_cap_intent(array $intent): array
{
    $cleanStrings = static function (array $list, int $max): array {
        $out = [];
        foreach ($list as $v) {
            if (!is_string($v)) continue;
            $v = trim($v);
            if ($v === '') continue;
            $out[] = $v;
            if (count($out) >= $max) break;
        }
        return $out;
    };

    // Legacy flat format — convert to nested
    if (!empty($intent['actors']) && is_string($intent['actors'][0] ?? null)) {
        $actorNames = $cleanStrings($intent['actors'], 5);
        $stories    = $cleanStrings($intent['stories'] ?? [], 7);
        return [
            'actors' => array_map(fn($name) => ['name' => $name, 'stories' => array_map(
                fn($s) => ['title' => $s, 'journeys' => [], 'requirements' => []], $stories
            )], $actorNames),
            'non_functional_requirements' => [],
        ];
    }

    // New nested format
    $actors = [];
    foreach (array_slice((array)($intent['actors'] ?? []), 0, 5) as $actor) {
        if (!is_array($actor) || empty($actor['name'])) continue;
        $stories = [];
        foreach (array_slice((array)($actor['stories'] ?? []), 0, 4) as $story) {
            if (!is_array($story) || empty($story['title'])) continue;
            $stories[] = [
                'title'        => (string)$story['title'],
                'journeys'     => $cleanStrings((array)($story['journeys']     ?? []), 2),
                'requirements' => $cleanStrings((array)($story['requirements'] ?? []), 4),
            ];
        }
        if (empty($stories)) continue;
        $actors[] = ['name' => (string)$actor['name'], 'stories' => $stories];
    }

    return [
        'actors'                      => $actors,
        'non_functional_requirements' => $cleanStrings((array)($intent['non_functional_requirements'] ?? []), 6),
    ];
}

/**
 * Structural validation of the new nested intent format.
 */
function ai_validate_intent(array $intent): ?string
{
    if (empty($intent['actors']) || !is_array($intent['actors']))
        return 'intent.actors must be a non-empty array';
    foreach ($intent['actors'] as $actor) {
        if (!is_array($actor) || empty($actor['name']))
            return 'each actor must be an object with a name';
        if (empty($actor['stories']) || !is_array($actor['stories']))
            return 'each actor must have a non-empty stories array';
        foreach ($actor['stories'] as $story) {
            if (!is_array($story) || empty($story['title']))
                return 'each story must have a title';
        }
    }
    return null;
}

/**
 * Run the intent pass (pass 0) with one self-correcting retry, then cap deterministically.
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
        $intent = $call($prompt . "\n\nYour previous response was rejected: " . $err . "\nReturn ONLY the JSON structure specified, obeying the hard limits.");
        $err = ai_validate_intent($intent);
        if ($err) {
            // Last-resort fallback
            $intent = ['actors' => [['name' => 'owner', 'stories' => [['title' => $prompt, 'journeys' => [], 'requirements' => []]]]], 'non_functional_requirements' => []];
        }
    }
    return ai_cap_intent($intent);
}

/**
 * Serialize an approved intent into a locked context block for the schema pass. Works with
 * both the new nested format and the legacy flat format.
 */
function ai_intent_to_context(array $intent): string
{
    $intent = ai_cap_intent($intent);
    $lines  = "Locked product intent — design the schema for EXACTLY these, add nothing and drop nothing.\nActors:\n";
    foreach ($intent['actors'] as $actor) {
        $lines .= '- ' . (is_array($actor) ? $actor['name'] : $actor) . "\n";
    }
    $lines .= "\nUser stories:\n";
    foreach ($intent['actors'] as $actor) {
        if (!is_array($actor)) continue;
        foreach ($actor['stories'] ?? [] as $story) {
            if (!is_array($story)) {
                $lines .= '- ' . $story . "\n";
                continue;
            }
            $lines .= '- ' . ($story['title'] ?? '') . "\n";
            foreach ($story['journeys'] ?? [] as $j) {
                $lines .= '  Journey: ' . $j . "\n";
            }
            foreach ($story['requirements'] ?? [] as $r) {
                $lines .= '  Requirement: ' . $r . "\n";
            }
        }
    }
    $nfrs = $intent['non_functional_requirements'] ?? [];
    if ($nfrs) {
        $lines .= "\nNon-functional requirements:\n";
        foreach ($nfrs as $r) {
            $lines .= '- ' . $r . "\n";
        }
    }
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

    // ── Seed data insertion ───────────────────────────────────────────────────
    if (!empty($plan['seed_data']) && is_array($plan['seed_data'])) {
        foreach ($plan['seed_data'] as $seedTable => $rows) {
            if (!is_array($rows) || empty($rows)) continue;
            $tbl = $catalog->getTable($projectId, (string)$seedTable);
            if (!$tbl) continue;

            $physical = $tbl['physical_name'];
            foreach (array_slice($rows, 0, 20) as $row) {
                if (!is_array($row) || empty($row)) continue;
                unset($row['id'], $row['created_at']);
                if (empty($row)) continue;

                $cols         = array_keys($row);
                $colList      = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                try {
                    $pdo->prepare("INSERT INTO `{$physical}` ({$colList}) VALUES ({$placeholders})")
                        ->execute(array_values($row));
                } catch (\Throwable $e) {
                    sb_log('ai_build', 'Seed insert failed (non-fatal): ' . $e->getMessage(), ['table' => $seedTable]);
                }
            }
        }
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
    ],
    'openrouter' => [
        'mistralai/mistral-small-3.2-24b-instruct',
        'moonshotai/kimi-k2',
        'google/gemma-4-26b-a4b-it:free',
        'openai/gpt-oss-120b:free',
        'openai/gpt-oss-20b:free',
        'nvidia/nemotron-3-super-120b-a12b:free',
        'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
        'nex-agi/nex-n2-pro',
        'poolside/laguna-xs.2:free',
        'poolside/laguna-m.1:free',
        'cohere/north-mini-code:free',
    ],
    'nvidia' => [
        'qwen/qwen3.5-122b-a10b',
        'z-ai/glm-5.2',
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

// ─── Design brief (pass 1.5) ─────────────────────────────────────────────────

const AI_DESIGN_BRIEF_PROMPT = <<<'PROMPT'
You are a UI/UX director commissioning a web app. Based on the app description and its database
schema, commit to a specific visual design direction.
Return ONLY valid JSON — no markdown fences, no explanation:

{
  "personality": "2-4 words for the brand vibe, e.g. 'clean and minimal', 'bold and energetic'",
  "accent_color": "ONE Tailwind color name (no shade): indigo | orange | blue | teal | violet | pink | amber | emerald | rose | cyan | lime | fuchsia",
  "font_choice": "system-sans | mono | or a Google Fonts import line, e.g. \"<link rel='stylesheet' href='https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap'>\"",
  "card_style": "rounded-xl shadow-lg | rounded-2xl shadow-md | rounded-sm shadow | rounded-none border border-gray-700",
  "layout": "sidebar-nav | top-nav-hamburger | landing-then-dashboard | single-page-scroll",
  "hero_style": "gradient-text-hero | split-text-image | large-image-banner | stats-bar | none",
  "unique_detail": "ONE concrete distinctive UI element for this domain, e.g. 'Star ratings rendered as ★ emoji', 'Price tags with a colored badge', 'Progress bar on each task card'"
}

Rules:
- accent_color MUST match the app domain (finance→blue, food→orange, health→teal, tasks→indigo, social→pink, shop→amber, etc.)
- layout depends on table count: 1-2 tables → single-page-scroll or top-nav-hamburger; 3+ tables → sidebar-nav or landing-then-dashboard
- Avoid the defaults (dark+emerald+top-nav) — pick something that gives this specific app a distinct personality
- unique_detail must be specific to this app's domain, not generic ("add animations" is banned)
PROMPT;

function ai_classify_error(string $msg): string
{
    $lower = strtolower($msg);
    if (str_contains($lower, '429') || str_contains($lower, 'rate limit') || str_contains($lower, 'too many requests')) return 'rate_limit';
    if (str_contains($lower, '401') || str_contains($lower, 'invalid key') || str_contains($lower, 'api key')) return 'api_key';
    if (str_contains($lower, '403') || str_contains($lower, 'permission denied')) return 'permission';
    if (str_contains($lower, 'no content') || str_contains($lower, 'content filter') || str_contains($lower, 'safety') || str_contains($lower, 'blocked')) return 'content_filter';
    if (str_contains($lower, 'not valid json') || str_contains($lower, 'invalid json') || str_contains($lower, 'unexpected response')) return 'invalid_json';
    if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) return 'timeout';
    if (str_contains($lower, 'network') || str_contains($lower, 'curl') || str_contains($lower, 'connection')) return 'network';
    if (str_contains($lower, '500') || str_contains($lower, '503') || str_contains($lower, 'internal server error')) return 'provider_error';
    return 'unknown';
}

function ai_abort_error(string $stage, string $msg): never
{
    if (str_contains(strtolower($msg), 'credits') || str_contains(strtolower($msg), 'quota')) {
        abort(402, $msg, ['stage' => $stage, 'code' => 'rate_limit', 'raw' => $msg]);
    }
    abort(502, 'AI error', ['stage' => $stage, 'code' => ai_classify_error($msg), 'raw' => $msg]);
}

function ai_generate_design_brief(object $client, string $prompt, array $schemaPlan): array
{
    $schemaCtx = ai_schema_to_context($schemaPlan);
    $userMsg   = "App description: {$prompt}\n\nSchema:\n{$schemaCtx}";
    try {
        $brief = $client->generateJson(AI_DESIGN_BRIEF_PROMPT, $userMsg);
    } catch (\Throwable) {
        return [];
    }
    return is_array($brief) ? $brief : [];
}

function ai_brief_to_context(array $brief): string
{
    if (empty($brief)) return '';
    $lines = ['Design brief — implement these choices exactly:'];
    $labels = [
        'personality'   => 'Brand personality',
        'accent_color'  => 'Accent color (Tailwind name)',
        'font_choice'   => 'Font',
        'card_style'    => 'Card style (Tailwind classes)',
        'layout'        => 'Layout pattern',
        'hero_style'    => 'Hero element',
        'unique_detail' => 'Unique UI detail',
    ];
    foreach ($labels as $k => $label) {
        if (!empty($brief[$k]) && is_string($brief[$k])) {
            $lines[] = "  {$label}: {$brief[$k]}";
        }
    }
    return implode("\n", $lines);
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

    // Pass 1.5 — design brief (best-effort; skip silently if it fails)
    $brief    = ai_generate_design_brief($client, $prompt, $schemaPlan);
    $briefCtx = ai_brief_to_context($brief);

    $frontendMsg    = "App description: {$prompt}\n\n"
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

// ─── Playwright user-story test generation ────────────────────────────────────

function ai_playwright_test_generate(
    string $appUrl,
    string $token,
    array  $schema,
    string $indexHtml,
    int    $projectId,
    string $storyBlock = ''
): string {
    // ── Schema analysis ────────────────────────────────────────────────────────────
    $authTable  = null;
    $authField  = 'email';
    $dataTables = [];
    foreach ($schema['tables'] as $table) {
        $hasPassword  = false;
        $firstVarchar = null;
        foreach ($table['columns'] as $col) {
            if ($col['type'] === 'PASSWORD') {
                $hasPassword = true;
            } elseif ($firstVarchar === null && preg_match('/^VARCHAR|^TEXT/i', $col['type'])) {
                $firstVarchar = $col['name'];
            }
        }
        if ($hasPassword) {
            $authTable = $table['name'];
            $authField = $firstVarchar ?? 'email';
        } else {
            $dataTables[] = $table;
        }
    }
    $hasAuth = $authTable !== null;

    // ── Route detection from deployed index.html ───────────────────────────────────
    $newRoute        = null;
    $itemPrefix      = null;
    $editRouteExists = false;
    if (preg_match_all('/defineRoute\([\'"]([^\'"]+)[\'"]/u', $indexHtml, $rm)) {
        foreach ($rm[1] as $r) {
            if (str_ends_with($r, '/new') && $newRoute === null) {
                $newRoute   = $r;
                $itemPrefix = substr($r, 0, -4);
            }
        }
        if ($itemPrefix) {
            foreach ($rm[1] as $r) {
                if (str_starts_with($r, $itemPrefix . '/') && str_ends_with($r, '/edit')) {
                    $editRouteExists = true;
                    break;
                }
            }
        }
    }

    // ── Fill lines for Record A and Record B ───────────────────────────────────────
    $firstTable    = $dataTables[0] ?? null;
    $fillLines     = [];
    $fillLines2    = [];
    $assertValue   = 'PW Test Value';
    $assertValue2  = 'PW Record B';
    $firstInputSel = 'input';
    $firstField    = true;
    if ($firstTable) {
        foreach ($firstTable['columns'] as $col) {
            $cn = strtolower($col['name']);
            $ct = strtoupper($col['type']);
            if ($ct === 'PASSWORD' || $cn === 'user_id') continue;
            if ($ct === 'BOOLEAN' || $ct === 'TINYINT(1)') continue;

            if (preg_match('/^TEXT|MEDIUMTEXT|LONGTEXT/', $ct)) {
                $tag  = 'textarea';
                $valA = 'PW test content for ' . $col['name'];
                $valB = 'PW record B content ' . $col['name'];
            } elseif (preg_match('/^(INT|BIGINT|SMALLINT|TINYINT)/', $ct)) {
                $tag  = 'input';
                $valA = '42';
                $valB = '99';
            } elseif (preg_match('/^DECIMAL|^FLOAT|^DOUBLE/', $ct)) {
                $tag  = 'input';
                $valA = '9.99';
                $valB = '19.99';
            } elseif ($ct === 'DATE') {
                $tag  = 'input';
                $valA = '2024-01-15';
                $valB = '2024-02-20';
            } elseif (preg_match('/^DATETIME|^TIMESTAMP/', $ct)) {
                $tag  = 'input';
                $valA = '2024-01-15T10:00';
                $valB = '2024-02-20T14:00';
            } else {
                $tag  = 'input';
                $valA = 'Playwright Test ' . ucfirst(str_replace('_', ' ', $col['name']));
                $valB = 'Record B ' . ucfirst(str_replace('_', ' ', $col['name']));
            }

            if ($firstField) {
                $assertValue   = $valA;
                $assertValue2  = $valB;
                $firstInputSel = $tag . '[name="' . $col['name'] . '"]';
                $firstField    = false;
            }
            $fillLines[]  = "  await page.fill('" . $tag . '[name="' . $col['name'] . '"]' . "', "
                          . json_encode($valA, JSON_UNESCAPED_UNICODE) . ');';
            $fillLines2[] = "  await page.fill('" . $tag . '[name="' . $col['name'] . '"]' . "', "
                          . json_encode($valB, JSON_UNESCAPED_UNICODE) . ');';
        }
    }

    $hasCrud   = $firstTable !== null && $newRoute !== null;
    $tokenJson = json_encode($token);
    $urlJson   = json_encode($appUrl);
    $afJson    = json_encode($authField);
    $avJson    = json_encode($assertValue);
    $av2Json   = json_encode($assertValue2);
    $fillStr   = implode("\n", $fillLines);
    $fillStr2  = implode("\n", $fillLines2);
    $ipfx      = $itemPrefix ?? '/item';

    $newBtnSel = $newRoute
        ? 'a[href="#' . $newRoute . '"], a:has-text("New")'
        : 'a:has-text("New")';

    $authTableJson   = json_encode($authTable ?? 'users');
    $firstTableJson  = json_encode($firstTable['name'] ?? null);

    // For list assertion: if edit route exists record A will have been edited
    if ($editRouteExists) {
        $listAssertJson = json_encode('PW Edited ' . $assertValue);
        $editValJson    = json_encode('PW Edited ' . $assertValue);
    } else {
        $listAssertJson = $avJson;
        $editValJson    = $avJson;
    }

    // ── Static JS header (helpers) ─────────────────────────────────────────────────
    $header = <<<'JSEOF'
import { chromium } from 'playwright-core';
import https from 'https';
import http from 'http';

let passed = 0, failed = 0;
const stories = [];

function log(msg) { console.log('\n' + msg); }
function assert(label, cond, detail) {
  const rec = { label, passed: Boolean(cond) };
  if (detail) rec.detail = detail;
  stories.push(rec);
  if (cond) { console.log('  ✓  ' + label); passed++; }
  else       { console.error('  ✗  ' + label + (detail ? ' — ' + detail : '')); failed++; }
}

async function captureToken(pg) {
  try {
    const raw = await pg.evaluate(() => localStorage.getItem('sb:token'));
    if (!raw) return [null, null];
    const p = JSON.parse(atob(raw.split('.')[1]));
    return [raw, parseInt(p.sub, 10)];
  } catch (_) { return [null, null]; }
}

async function waitLoggedIn(pg, ms = 15000) {
  await pg.waitForFunction(
    () => { const el = document.querySelector('#nav-logout'); return el && !el.classList.contains('hidden'); },
    { timeout: ms }
  );
}

function apiRequest(method, apiUrl, bearerToken) {
  return new Promise((resolve) => {
    let u;
    try { u = new URL(apiUrl); } catch (_) { resolve(0); return; }
    const isHttps = u.protocol === 'https:';
    const opts = {
      hostname: u.hostname,
      port: u.port ? parseInt(u.port) : (isHttps ? 443 : 80),
      path: u.pathname,
      method,
      headers: { 'Authorization': 'Bearer ' + bearerToken }
    };
    const req = (isHttps ? https : http).request(opts, res => { res.resume(); resolve(res.statusCode); });
    req.on('error', () => resolve(0));
    req.end();
  });
}
const apiDelete = (apiUrl, bearerToken) => apiRequest('DELETE', apiUrl, bearerToken);
const apiGet    = (apiUrl, bearerToken) => apiRequest('GET', apiUrl, bearerToken);
JSEOF;

    // ── Dynamic constants ──────────────────────────────────────────────────────────
    // PID is the numeric project id — the data API casts :project_id to int, so
    // the old 'p<id>' form silently resolved to project 0 and cleanup never worked.
    $constants = 'const TOKEN      = ' . $tokenJson . ";\n"
               . 'const APP_URL    = ' . $urlJson . ";\n"
               . 'const PID        = ' . $projectId . ";\n"
               . 'const AUTH_TABLE = ' . $authTableJson . ";\n"
               . 'const FIRST_TABLE = ' . $firstTableJson . ";\n"
               . 'const TEST_EMAIL   = `pw-a-${Date.now()}@testmail.dev`;' . "\n"
               . 'const TEST_EMAIL_B = `pw-b-${Date.now() + 1}@testmail.dev`;' . "\n"
               . "const TEST_PASS    = 'TestPass123!';\n"
               . "const SB_API = (() => { try { return new URL(APP_URL).origin + '/api/v1'; } catch(_) { return ''; } })();\n";

    // ── Browser connection ─────────────────────────────────────────────────────────
    $connect = <<<'JSEOF'
let tokenA = null, userIdA = null;
let tokenB = null, userIdB = null;
let browser, page;
try {
  browser = await chromium.connectOverCDP(`wss://chrome.browserless.io?token=${TOKEN}`);
  page    = await browser.newPage();
} catch (connErr) {
  console.error('Browser connect failed: ' + connErr.message);
  failed++;
  console.log('__STORIES_JSON__' + JSON.stringify(stories));
  process.exit(1);
}
page.setDefaultTimeout(15000);
const pageErrors = [];
page.on('console', m => { if (m.type() === 'error') pageErrors.push(m.text()); });
page.on('pageerror', e => pageErrors.push(e.message));
JSEOF;

    // ── Auth block ─────────────────────────────────────────────────────────────────
    $authBlock = '';
    if ($hasAuth) {
        $authBlock .= "  log('Story: Wrong credentials are rejected');\n";
        $authBlock .= "  await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $authBlock .= "  await page.waitForTimeout(500);\n";
        $authBlock .= "  await page.fill('#login-form input[name=' + " . $afJson . " + ']', 'wrong@nowhere.dev');\n";
        $authBlock .= "  await page.fill('#login-form input[name=\"password\"]', 'BadPass000');\n";
        $authBlock .= "  await page.click('#login-form button[type=\"submit\"], #login-form button');\n";
        $authBlock .= "  await page.waitForTimeout(2000);\n";
        $authBlock .= "  const stillLogin = await page.\$('#login-form') !== null;\n";
        $authBlock .= "  const loginErrTxt = await page.\$eval('#login-error', el => el.textContent.trim()).catch(() => '');\n";
        $authBlock .= "  assert('Wrong credentials are rejected',\n";
        $authBlock .= "    stillLogin || loginErrTxt.length > 0, loginErrTxt);\n\n";

        $authBlock .= "  log('Story: Unauthenticated access shows login form');\n";
        $authBlock .= "  await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $authBlock .= "  await page.waitForTimeout(1000);\n";
        $authBlock .= "  assert('Login form visible to unauthenticated users', await page.\$('#login-form') !== null);\n\n";

        $authBlock .= "  log('Story: User can sign up');\n";
        $authBlock .= "  await page.fill('#signup-form input[name=' + " . $afJson . " + ']', TEST_EMAIL);\n";
        $authBlock .= "  await page.fill('#signup-form input[name=\"password\"]', TEST_PASS);\n";
        $authBlock .= "  await page.click('#signup-form button[type=\"submit\"], #signup-form button');\n";
        $authBlock .= "  let loggedIn = false;\n";
        $authBlock .= "  try {\n";
        $authBlock .= "    await waitLoggedIn(page);\n";
        $authBlock .= "    loggedIn = true;\n";
        $authBlock .= "    [tokenA, userIdA] = await captureToken(page);\n";
        $authBlock .= "  } catch (_) {\n";
        $authBlock .= "    const errTxt = await page.\$eval('#signup-error', el => el.textContent.trim()).catch(() => '');\n";
        $authBlock .= "    console.log('  signup-error: ' + errTxt);\n";
        $authBlock .= "  }\n";
        $authBlock .= "  assert('Signup succeeds and user is logged in', loggedIn);\n";
        $authBlock .= "  if (!loggedIn) throw Object.assign(new Error('auth_failed'), { abort: true });\n\n";
    } else {
        $authBlock .= "  await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $authBlock .= "  await page.waitForTimeout(1000);\n";
    }

    // ── CRUD block ─────────────────────────────────────────────────────────────────
    $crudBlock = '';
    if ($hasCrud) {
        $crudBlock .= "  log('Story: App loads with new-record button');\n";
        $crudBlock .= "  await page.waitForTimeout(500);\n";
        $crudBlock .= "  assert('New record button is visible', await page.\$('" . $newBtnSel . "') !== null);\n";
        $crudBlock .= "  assert('No API fetch errors on load',\n";
        $crudBlock .= "    !pageErrors.some(e => e.toLowerCase().includes('failed to fetch')),\n";
        $crudBlock .= "    pageErrors.slice(0, 2).join(' | '));\n\n";

        // XSS check
        $crudBlock .= "  log('Story: XSS payload in form field does not execute');\n";
        $crudBlock .= "  await page.click('" . $newBtnSel . "');\n";
        $crudBlock .= "  await page.waitForTimeout(600);\n";
        $crudBlock .= "  await page.evaluate(() => { window.__xss = false; });\n";
        $crudBlock .= "  const xssInput = await page.\$('" . $firstInputSel . "');\n";
        $crudBlock .= "  if (xssInput) await xssInput.fill('<img src=x onerror=\"window.__xss=true\">');\n";
        $crudBlock .= "  await page.waitForTimeout(800);\n";
        $crudBlock .= "  const xssRan = await page.evaluate(() => window.__xss === true).catch(() => false);\n";
        $crudBlock .= "  assert('XSS payload in form field does not execute', !xssRan);\n";
        $crudBlock .= "  await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $crudBlock .= "  await page.waitForTimeout(500);\n\n";

        // Empty form / submit button check
        $crudBlock .= "  log('Story: Form renders and empty submit causes no JS errors');\n";
        $crudBlock .= "  const errsBefore = pageErrors.length;\n";
        $crudBlock .= "  await page.click('" . $newBtnSel . "');\n";
        $crudBlock .= "  await page.waitForTimeout(600);\n";
        $crudBlock .= "  assert('New record form has a submit button',\n";
        $crudBlock .= "    await page.\$('button[type=\"submit\"], input[type=\"submit\"]') !== null);\n";
        $crudBlock .= "  await page.click('button[type=\"submit\"]').catch(() => {});\n";
        $crudBlock .= "  await page.waitForTimeout(800);\n";
        $crudBlock .= "  const errsAfterEmpty = pageErrors.slice(errsBefore);\n";
        $crudBlock .= "  assert('Submitting empty form causes no new JS errors',\n";
        $crudBlock .= "    errsAfterEmpty.every(e => !e.toLowerCase().includes('typeerror') && !e.toLowerCase().includes('uncaught')),\n";
        $crudBlock .= "    errsAfterEmpty.join(' | '));\n";
        $crudBlock .= "  await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $crudBlock .= "  await page.waitForTimeout(500);\n\n";

        // Create record A
        $crudBlock .= "  log('Story: User can create a record');\n";
        $crudBlock .= "  await page.click('" . $newBtnSel . "');\n";
        $crudBlock .= "  await page.waitForTimeout(600);\n";
        if ($fillStr) $crudBlock .= $fillStr . "\n";
        $crudBlock .= "  await page.click('button[type=\"submit\"]');\n";
        $crudBlock .= "  try {\n";
        $crudBlock .= "    await page.waitForFunction(\n";
        $crudBlock .= "      () => window.location.hash.includes('" . $ipfx . "/') && !window.location.hash.includes('/new'),\n";
        $crudBlock .= "      { timeout: 8000 }\n";
        $crudBlock .= "    );\n";
        $crudBlock .= "  } catch (_) {}\n";
        $crudBlock .= "  assert('After create, navigated to detail view',\n";
        $crudBlock .= "    page.url().includes('" . $ipfx . "/') && !page.url().includes('/new'), 'url: ' + page.url());\n";
        $crudBlock .= "  // Record A's id, used later for direct-API policy checks\n";
        $crudBlock .= "  const recAIdMatch = page.url().match(/\\/(\\d+)(?:[?#].*)?\$/);\n";
        $crudBlock .= "  const recAId = recAIdMatch ? recAIdMatch[1] : null;\n\n";

        $crudBlock .= "  log('Story: Created record content is displayed');\n";
        $crudBlock .= "  const bodyTxtA = await page.textContent('body').catch(() => '');\n";
        $crudBlock .= "  assert('Record A content shown on detail view', bodyTxtA.includes(" . $avJson . "));\n\n";

        // Edit record (if edit route detected)
        if ($editRouteExists) {
            $crudBlock .= "  log('Story: User can edit a record');\n";
            $crudBlock .= "  const editLink = await page.\$('a:has-text(\"Edit\"), a[href*=\"/edit\"], button:has-text(\"Edit\")');\n";
            $crudBlock .= "  assert('Edit link/button is present on detail view', editLink !== null);\n";
            $crudBlock .= "  if (editLink) {\n";
            $crudBlock .= "    await editLink.click();\n";
            $crudBlock .= "    await page.waitForTimeout(600);\n";
            $crudBlock .= "    const editField = await page.\$('" . $firstInputSel . "');\n";
            $crudBlock .= "    if (editField) await editField.fill(" . $editValJson . ");\n";
            $crudBlock .= "    await page.click('button[type=\"submit\"]');\n";
            $crudBlock .= "    await page.waitForTimeout(2000);\n";
            $crudBlock .= "    const editedBody = await page.textContent('body').catch(() => '');\n";
            $crudBlock .= "    assert('Edited value shown after save', editedBody.includes(" . $editValJson . "),\n";
            $crudBlock .= "      'got: ' + editedBody.substring(0, 150));\n";
            $crudBlock .= "  }\n\n";
        }

        // List view — record A
        $crudBlock .= "  log('Story: Record appears in list view');\n";
        $crudBlock .= "  await page.click('a:has-text(\"Back\"), a[href=\"#/\"]');\n";
        $crudBlock .= "  await page.waitForTimeout(1200);\n";
        $crudBlock .= "  const listTxtA = await page.textContent('body').catch(() => '');\n";
        $crudBlock .= "  assert('Record A is visible in list', listTxtA.includes(" . $listAssertJson . "));\n\n";

        // Open record from list
        $crudBlock .= "  log('Story: User can open a record from list');\n";
        $crudBlock .= "  const listItemLink = await page.\$('a[href*=\"" . $ipfx . "/\"]');\n";
        $crudBlock .= "  if (listItemLink) {\n";
        $crudBlock .= "    await listItemLink.click();\n";
        $crudBlock .= "    await page.waitForTimeout(800);\n";
        $crudBlock .= "    assert('Clicking record in list opens detail view',\n";
        $crudBlock .= "      page.url().includes('" . $ipfx . "/') && !page.url().includes('/new'));\n";
        $crudBlock .= "    await page.click('a:has-text(\"Back\"), a[href=\"#/\"]').catch(() => {});\n";
        $crudBlock .= "    await page.waitForTimeout(800);\n";
        $crudBlock .= "  }\n\n";

        // Create record B — verify both appear
        $crudBlock .= "  log('Story: Multiple records appear in list');\n";
        $crudBlock .= "  await page.click('" . $newBtnSel . "');\n";
        $crudBlock .= "  await page.waitForTimeout(600);\n";
        if ($fillStr2) $crudBlock .= $fillStr2 . "\n";
        $crudBlock .= "  await page.click('button[type=\"submit\"]');\n";
        $crudBlock .= "  try {\n";
        $crudBlock .= "    await page.waitForFunction(\n";
        $crudBlock .= "      () => window.location.hash.includes('" . $ipfx . "/') && !window.location.hash.includes('/new'),\n";
        $crudBlock .= "      { timeout: 8000 }\n";
        $crudBlock .= "    );\n";
        $crudBlock .= "  } catch (_) {}\n";
        $crudBlock .= "  await page.click('a:has-text(\"Back\"), a[href=\"#/\"]').catch(() => {});\n";
        $crudBlock .= "  await page.waitForTimeout(1200);\n";
        $crudBlock .= "  const listTxtBoth = await page.textContent('body').catch(() => '');\n";
        $crudBlock .= "  assert('Record A still in list after adding Record B', listTxtBoth.includes(" . $listAssertJson . "));\n";
        $crudBlock .= "  assert('Record B is visible in list', listTxtBoth.includes(" . $av2Json . "));\n\n";

        // Mobile viewport
        $crudBlock .= "  log('Story: App works on mobile viewport (375px)');\n";
        $crudBlock .= "  {\n";
        $crudBlock .= "    const mobilePage = await browser.newPage();\n";
        $crudBlock .= "    mobilePage.setDefaultTimeout(12000);\n";
        $crudBlock .= "    await mobilePage.setViewportSize({ width: 375, height: 667 });\n";
        if ($hasAuth) {
            $crudBlock .= "    await mobilePage.goto(APP_URL, { waitUntil: 'networkidle' });\n";
            $crudBlock .= "    if (tokenA) await mobilePage.evaluate(t => localStorage.setItem('sb:token', t), tokenA);\n";
            $crudBlock .= "    await mobilePage.reload({ waitUntil: 'networkidle' });\n";
        } else {
            $crudBlock .= "    await mobilePage.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        }
        $crudBlock .= "    await mobilePage.waitForTimeout(1000);\n";
        $crudBlock .= "    const mOverflow = await mobilePage.evaluate(() => document.body.scrollWidth > window.innerWidth).catch(() => false);\n";
        $crudBlock .= "    assert('No horizontal overflow on mobile viewport', !mOverflow);\n";
        $crudBlock .= "    const mNewBtn = await mobilePage.\$('" . $newBtnSel . "');\n";
        $crudBlock .= "    assert('New record button accessible on mobile', mNewBtn !== null);\n";
        $crudBlock .= "    await mobilePage.close();\n";
        $crudBlock .= "  }\n\n";
    }

    // ── Multi-user isolation block ─────────────────────────────────────────────────
    $isolationBlock = '';
    if ($hasAuth && $hasCrud) {
        $isolationBlock .= "  log('Story: Multi-user data isolation');\n";
        $isolationBlock .= "  {\n";
        $isolationBlock .= "    const pageB = await browser.newPage();\n";
        $isolationBlock .= "    pageB.setDefaultTimeout(15000);\n";
        $isolationBlock .= "    await pageB.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $isolationBlock .= "    await pageB.waitForTimeout(500);\n";
        $isolationBlock .= "    await pageB.fill('#signup-form input[name=' + " . $afJson . " + ']', TEST_EMAIL_B);\n";
        $isolationBlock .= "    await pageB.fill('#signup-form input[name=\"password\"]', TEST_PASS);\n";
        $isolationBlock .= "    await pageB.click('#signup-form button[type=\"submit\"], #signup-form button');\n";
        $isolationBlock .= "    let bLoggedIn = false;\n";
        $isolationBlock .= "    try {\n";
        $isolationBlock .= "      await waitLoggedIn(pageB);\n";
        $isolationBlock .= "      bLoggedIn = true;\n";
        $isolationBlock .= "      [tokenB, userIdB] = await captureToken(pageB);\n";
        $isolationBlock .= "      await pageB.waitForTimeout(1500);\n";
        $isolationBlock .= "      const pageBBody = await pageB.textContent('body').catch(() => '');\n";
        $isolationBlock .= "      assert('User B cannot see User A records', !pageBBody.includes(" . $listAssertJson . "));\n";
        $isolationBlock .= "      // Direct-API policy checks: the UI may filter client-side, but the\n";
        $isolationBlock .= "      // API itself must refuse cross-user access to User A's record.\n";
        $isolationBlock .= "      if (tokenB && recAId && FIRST_TABLE) {\n";
        $isolationBlock .= "        log('Story: API blocks cross-user record access');\n";
        $isolationBlock .= "        const stGet = await apiGet(SB_API + '/data/' + PID + '/' + FIRST_TABLE + '/' + recAId, tokenB);\n";
        $isolationBlock .= "        assert('API refuses User B reading User A record directly', stGet === 403 || stGet === 404, 'GET status: ' + stGet);\n";
        $isolationBlock .= "        const stDel = await apiDelete(SB_API + '/data/' + PID + '/' + FIRST_TABLE + '/' + recAId, tokenB);\n";
        $isolationBlock .= "        assert('API refuses User B deleting User A record directly', stDel === 403 || stDel === 404, 'DELETE status: ' + stDel);\n";
        $isolationBlock .= "      }\n";
        $isolationBlock .= "    } catch (_) {\n";
        $isolationBlock .= "      assert('User B signup for isolation test', bLoggedIn, 'signup failed');\n";
        $isolationBlock .= "    }\n";
        $isolationBlock .= "    await pageB.close();\n";
        $isolationBlock .= "  }\n\n";
    }

    // ── Delete-all + empty-state block ─────────────────────────────────────────────
    $deleteAllBlock = '';
    if ($hasCrud) {
        $deleteAllBlock .= "  log('Story: Delete all records — app renders empty state without crash');\n";
        $deleteAllBlock .= "  await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $deleteAllBlock .= "  await page.waitForTimeout(1000);\n";
        $deleteAllBlock .= "  for (let _i = 0; _i < 20; _i++) {\n";
        $deleteAllBlock .= "    const itemLink = await page.\$('a[href*=\"" . $ipfx . "/\"]');\n";
        $deleteAllBlock .= "    if (!itemLink) break;\n";
        $deleteAllBlock .= "    await itemLink.click();\n";
        $deleteAllBlock .= "    await page.waitForTimeout(500);\n";
        $deleteAllBlock .= "    const delBtn = await page.\$('#delete-btn, button:has-text(\"Delete\")');\n";
        $deleteAllBlock .= "    if (!delBtn) { await page.goto(APP_URL, { waitUntil: 'networkidle' }); break; }\n";
        $deleteAllBlock .= "    page.once('dialog', d => d.accept());\n";
        $deleteAllBlock .= "    await delBtn.click();\n";
        $deleteAllBlock .= "    await page.waitForTimeout(1500);\n";
        $deleteAllBlock .= "    if (!page.url().startsWith(APP_URL.replace(/\\/+$/, ''))) {\n";
        $deleteAllBlock .= "      await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $deleteAllBlock .= "      await page.waitForTimeout(800);\n";
        $deleteAllBlock .= "    }\n";
        $deleteAllBlock .= "  }\n";
        $deleteAllBlock .= "  const crashErrors = pageErrors.filter(e =>\n";
        $deleteAllBlock .= "    e.toLowerCase().includes('typeerror') || e.toLowerCase().includes('uncaught'));\n";
        $deleteAllBlock .= "  assert('App renders without crash on empty list', crashErrors.length === 0, crashErrors.join(' | '));\n";
        $deleteAllBlock .= "  assert('New record button present in empty state', await page.\$('" . $newBtnSel . "') !== null);\n\n";
    }

    // ── Re-login block ─────────────────────────────────────────────────────────────
    $reloginBlock = '';
    if ($hasAuth) {
        $reloginBlock .= "  log('Story: User can re-login with same credentials');\n";
        $reloginBlock .= "  const logoutForRL = await page.\$('#nav-logout');\n";
        $reloginBlock .= "  if (logoutForRL) {\n";
        $reloginBlock .= "    const isHiddenRL = await logoutForRL.evaluate(el => el.classList.contains('hidden')).catch(() => true);\n";
        $reloginBlock .= "    if (!isHiddenRL) await logoutForRL.click();\n";
        $reloginBlock .= "    await page.waitForTimeout(1000);\n";
        $reloginBlock .= "  }\n";
        $reloginBlock .= "  const loginFormRL = await page.\$('#login-form');\n";
        $reloginBlock .= "  if (loginFormRL) {\n";
        $reloginBlock .= "    await page.fill('#login-form input[name=' + " . $afJson . " + ']', TEST_EMAIL);\n";
        $reloginBlock .= "    await page.fill('#login-form input[name=\"password\"]', TEST_PASS);\n";
        $reloginBlock .= "    await page.click('#login-form button[type=\"submit\"], #login-form button');\n";
        $reloginBlock .= "  }\n";
        $reloginBlock .= "  let reLoggedIn = false;\n";
        $reloginBlock .= "  try { await waitLoggedIn(page); reLoggedIn = true; } catch (_) {}\n";
        $reloginBlock .= "  assert('User can re-login with same credentials', reLoggedIn);\n\n";
    }

    // ── Final logout block ─────────────────────────────────────────────────────────
    $logoutBlock = '';
    if ($hasAuth) {
        $logoutBlock .= "  log('Story: User can log out');\n";
        $logoutBlock .= "  const logoutEl = await page.\$('#nav-logout');\n";
        $logoutBlock .= "  assert('Logout button is present', logoutEl !== null);\n";
        $logoutBlock .= "  if (logoutEl) {\n";
        $logoutBlock .= "    await logoutEl.click();\n";
        $logoutBlock .= "    await page.waitForTimeout(1000);\n";
        $logoutBlock .= "    const loginBack = await page.\$('#login-form') !== null;\n";
        $logoutBlock .= "    const navHidden = await page.\$eval('#nav-logout', el => el.classList.contains('hidden')).catch(() => true);\n";
        $logoutBlock .= "    assert('After logout, login form is shown', loginBack && navHidden);\n";
        $logoutBlock .= "  }\n";
    }

    // ── Cleanup block (always runs, outside try/catch) ─────────────────────────────
    $cleanupBlock = '';
    if ($hasAuth) {
        $cleanupBlock .= "// Cleanup test users via API\n";
        $cleanupBlock .= "try {\n";
        $cleanupBlock .= "  if (tokenA && userIdA) await apiDelete(SB_API + '/data/' + PID + '/' + AUTH_TABLE + '/' + userIdA, tokenA);\n";
        $cleanupBlock .= "  if (tokenB && userIdB) await apiDelete(SB_API + '/data/' + PID + '/' + AUTH_TABLE + '/' + userIdB, tokenB);\n";
        $cleanupBlock .= "} catch (_) {}\n\n";
    }

    $footer = <<<'JSEOF'
await page.screenshot({ path: '/tmp/sb_test_screenshot.png', fullPage: true }).catch(() => {});
await browser.close();

console.log('\n' + '═'.repeat(44));
console.log('Results: ' + passed + ' passed, ' + failed + ' failed');
console.log('═'.repeat(44));
console.log('__STORIES_JSON__' + JSON.stringify(stories));
process.exit(failed > 0 ? 1 : 0);
JSEOF;

    // AI-generated story tests run after the CRUD block (records exist, User A
    // is logged in). Wrapped in their own braces so any const/let the AI
    // declares can't collide with the harness's own declarations.
    $storySection = $storyBlock !== ''
        ? "  // ── AI-generated user-story tests ──\n  {\n" . $storyBlock . "\n  }\n\n"
        : '';

    return $header . "\n\n"
         . $constants . "\n"
         . $connect . "\n\n"
         . "try {\n"
         . $authBlock
         . $crudBlock
         . $storySection
         . $isolationBlock
         . $deleteAllBlock
         . $reloginBlock
         . $logoutBlock
         . "} catch (e) {\n"
         . "  if (!e.abort) { console.error('Unexpected error: ' + e.message); failed++; }\n"
         . "}\n\n"
         . $cleanupBlock
         . $footer;
}

function ai_playwright_test_run(string $script, array $config): array
{
    $nodeBin     = $config['NODE_BIN']           ?? '/opt/alt/alt-nodejs16/root/usr/bin/node';
    $nodeModules = $config['PLAYWRIGHT_MODULES'] ?? '/home/dxinethn/playwright-test/node_modules';

    // ESM bare-specifier resolution starts from the script file's directory, NOT NODE_PATH.
    // Write the script into the same directory as node_modules so `import 'playwright-core'`
    // resolves to the sibling node_modules/ folder.
    $playwrightDir = rtrim(dirname($nodeModules), '/');
    $tmpFile       = $playwrightDir . '/sb_test_' . getmypid() . '_' . time() . '.mjs';

    file_put_contents($tmpFile, $script);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge(getenv() ?: [], [
        'HOME' => dirname($playwrightDir),
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
    ]);

    $process = proc_open(
        escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmpFile),
        $descriptors,
        $pipes,
        $playwrightDir,   // CWD = playwright-test dir for module resolution
        $env
    );

    if (!is_resource($process)) {
        @unlink($tmpFile);
        return ['stories' => [], 'passed' => 0, 'failed' => 1, 'error' => 'Failed to spawn Node process'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($tmpFile);

    // Parse structured results from __STORIES_JSON__ marker
    $stories = [];
    $passed  = 0;
    $failed  = 0;

    $combinedOut = $stdout . "\n" . $stderr;
    if (preg_match('/__STORIES_JSON__(.+)$/m', $combinedOut, $m)) {
        $stories = json_decode($m[1], true) ?? [];
        foreach ($stories as $s) {
            if ($s['passed'] ?? false) $passed++; else $failed++;
        }
    }

    // Surface Node error lines when no stories were produced
    $nodeError = null;
    if (empty($stories) && trim($stderr)) {
        $nodeError = trim(substr($stderr, 0, 500));
    }

    // Screenshot
    $screenshotB64 = null;
    $ssPath = '/tmp/sb_test_screenshot.png';
    if (file_exists($ssPath)) {
        $screenshotB64 = base64_encode((string)file_get_contents($ssPath));
        @unlink($ssPath);
    }

    return [
        'stories'    => $stories,
        'passed'     => $passed,
        'failed'     => $failed,
        'exit_code'  => $exitCode,
        'error'      => $nodeError,
        'screenshot' => $screenshotB64,
    ];
}

// ─── Job-backed generation ───────────────────────────────────────────────────
// Shared by the /v1/ai/build/job and /v1/ai/edit/job routes and the background
// worker (app/workers/ai_worker.php) — one implementation so the two never
// drift apart the way the old (dead) worker pipeline did relative to these
// routes. $report(array $event) is called at the same points the old NDJSON
// stream's $emit() was; failures throw instead of emitting an 'error' event
// and returning, since callers now run inside a job, not a live HTTP response.

function ai_run_build_generation(string $prompt, array $history, ?array $approvedIntent, object $client, callable $report): array
{
    $aiTrace = [];

    // ── Stage 1: schema ───────────────────────────────────────────────────
    $report(['stage' => 'schema', 'status' => 'start', 'label' => 'Designing database schema…']);
    $schemaUserMsg = $approvedIntent
        ? $prompt . "\n\n" . ai_intent_to_context($approvedIntent)
        : $prompt;
    $_t0 = microtime(true);
    $schemaPlan = $client->generateJsonWithHistory(AI_BUILD_SCHEMA_PROMPT, $history, $schemaUserMsg);
    $aiTrace[] = ['stage' => 'schema_pass_1', 'system' => AI_BUILD_SCHEMA_PROMPT, 'history' => $history, 'user_msg' => $schemaUserMsg, 'response' => $schemaPlan, 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];
    $schemaPlan['frontend'] = ['files' => []];
    $schemaPlan = ai_sanitize_plan($schemaPlan);

    $validationError = ai_validate_plan($schemaPlan);
    if ($validationError) {
        $report(['stage' => 'schema', 'status' => 'retry', 'label' => 'Refining schema…', 'detail' => $validationError]);
        $retryPrompt = $schemaUserMsg
            . "\n\nYour previous schema was rejected for this reason:\n  " . $validationError
            . "\nReturn a corrected schema that fixes exactly this problem.";
        $_t0 = microtime(true);
        $schemaPlan = $client->generateJsonWithHistory(AI_BUILD_SCHEMA_PROMPT, $history, $retryPrompt);
        $aiTrace[] = ['stage' => 'schema_retry', 'system' => AI_BUILD_SCHEMA_PROMPT, 'history' => $history, 'user_msg' => $retryPrompt, 'response' => $schemaPlan, 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => true, 'error' => $validationError];
        $schemaPlan['frontend'] = ['files' => []];
        $schemaPlan = ai_sanitize_plan($schemaPlan);
        $validationError = ai_validate_plan($schemaPlan);
        if ($validationError) throw new \RuntimeException('AI returned an invalid schema: ' . $validationError);
    }
    $tableNames = array_map(fn($t) => $t['name'], $schemaPlan['tables'] ?? []);
    $report(['stage' => 'schema', 'status' => 'done', 'label' => 'Database schema ready', 'detail' => count($tableNames) . ' table' . (count($tableNames) === 1 ? '' : 's') . ': ' . implode(', ', $tableNames)]);

    // ── Stage 2: design brief (best-effort) ───────────────────────────────
    $report(['stage' => 'design', 'status' => 'start', 'label' => 'Choosing a visual design…']);
    $_t0   = microtime(true);
    $brief = ai_generate_design_brief($client, $prompt, $schemaPlan);
    if (!empty($brief)) {
        $aiTrace[] = ['stage' => 'design_brief', 'system' => AI_DESIGN_BRIEF_PROMPT, 'history' => [], 'user_msg' => "App description: {$prompt}\n\nSchema:\n" . ai_schema_to_context($schemaPlan), 'response' => $brief, 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];
    }
    $briefCtx = ai_brief_to_context($brief);
    $report(['stage' => 'design', 'status' => 'done', 'label' => 'Visual design chosen', 'detail' => trim(($brief['personality'] ?? '') . (isset($brief['accent_color']) ? ' · ' . $brief['accent_color'] : '')) ?: 'default theme']);

    // ── Stage 3: frontend ──────────────────────────────────────────────────
    $report(['stage' => 'frontend', 'status' => 'start', 'label' => 'Generating frontend code…']);
    $frontendMsg        = "App description: {$prompt}\n\n"
                        . ($briefCtx ? "{$briefCtx}\n\n" : '')
                        . "Exact validated schema — use ONLY these column names in JS:\n"
                        . ai_schema_to_context($schemaPlan);
    $_frontendSysPrompt = ai_bind_auth_placeholders(AI_BUILD_FRONTEND_PROMPT, $schemaPlan);
    $_t0 = microtime(true);
    $frontendResult = $client->generateJson($_frontendSysPrompt, $frontendMsg);
    $aiTrace[] = ['stage' => 'frontend_pass_2', 'system' => $_frontendSysPrompt, 'history' => [], 'user_msg' => $frontendMsg, 'response' => ['files' => array_map(fn($f) => ['path' => $f['path'], 'bytes' => mb_strlen($f['content'] ?? '')], $frontendResult['files'] ?? [])], 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];

    $plan = $schemaPlan;
    $plan['frontend'] = ['files' => $frontendResult['files'] ?? []];
    foreach ($plan['frontend']['files'] as &$file) {
        $file['path'] = ltrim(preg_replace('#^\./+#', '', $file['path'] ?? ''), '/');
    }
    unset($file);
    $report(['stage' => 'frontend', 'status' => 'done', 'label' => 'Frontend generated', 'detail' => count($plan['frontend']['files']) . ' file' . (count($plan['frontend']['files']) === 1 ? '' : 's')]);

    $summary = [
        'project_name'   => $plan['project_name'],
        'tables'         => array_map(fn($t) => $t['name'] . ' (' . count($t['columns'] ?? []) . ' cols)', $plan['tables']),
        'frontend_files' => count($plan['frontend']['files'] ?? []),
    ];

    return ['plan' => $plan, 'summary' => $summary, 'usage' => $client->getLastUsage(), 'aiTrace' => $aiTrace];
}

function ai_run_edit_generation(int $projectId, string $prompt, array $history, object $client, \SupaBein\Catalog $catalog, array $config, callable $report): array
{
    $aiTrace = [];

    $report(['stage' => 'read', 'status' => 'start', 'label' => 'Reading current schema & files…']);
    $existingSchema = ai_schema_from_db($projectId, $catalog);
    $schemaCtx      = ai_schema_to_context($existingSchema);
    $currentFiles   = ai_read_frontend_files($config, $catalog, $projectId, $prompt);
    $report(['stage' => 'read', 'status' => 'done', 'label' => 'Loaded current project']);

    $report(['stage' => 'changes', 'status' => 'start', 'label' => 'Generating changes…']);
    $userMessage      = "Exact schema:\n{$schemaCtx}\n\nCurrent frontend:\n{$currentFiles}\n\nRequest: {$prompt}";
    $editSystemPrompt = ai_bind_auth_placeholders(AI_EDIT_SYSTEM_PROMPT, $existingSchema);
    $_t0 = microtime(true);
    $delta = $client->generateJsonWithHistory($editSystemPrompt, $history, $userMessage);
    $aiTrace[] = ['stage' => 'edit_pass', 'system' => $editSystemPrompt, 'history' => $history, 'user_msg' => mb_strlen($userMessage) > 5000 ? mb_substr($userMessage, 0, 5000) : $userMessage, 'response' => array_merge(array_intersect_key($delta, array_flip(['add_tables', 'add_columns', 'update_policies'])), ['frontend_files' => count($delta['frontend']['files'] ?? [])]), 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];

    $deltaError = ai_validate_delta($delta, $existingSchema);
    if ($deltaError) {
        $report(['stage' => 'changes', 'status' => 'retry', 'label' => 'Refining changes…', 'detail' => $deltaError]);
        $retryMsg = $userMessage . "\n\nYour previous response was rejected for this reason:\n  " . $deltaError . "\nReturn a corrected JSON delta that fixes exactly this problem and nothing else.";
        $_t0 = microtime(true);
        $delta = $client->generateJsonWithHistory($editSystemPrompt, $history, $retryMsg);
        $aiTrace[] = ['stage' => 'edit_retry', 'system' => $editSystemPrompt, 'history' => $history, 'user_msg' => mb_strlen($retryMsg) > 5000 ? mb_substr($retryMsg, 0, 5000) : $retryMsg, 'response' => array_merge(array_intersect_key($delta, array_flip(['add_tables', 'add_columns', 'update_policies'])), ['frontend_files' => count($delta['frontend']['files'] ?? [])]), 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => true, 'error' => $deltaError];
        $deltaError = ai_validate_delta($delta, $existingSchema);
        if ($deltaError) throw new \RuntimeException('AI returned an invalid edit: ' . $deltaError);
    }

    if (!empty($delta['frontend']['files'])) {
        foreach ($delta['frontend']['files'] as &$file) {
            $file['path'] = ltrim(preg_replace('#^\./+#', '', $file['path'] ?? ''), '/');
        }
        unset($file);
    }
    $report(['stage' => 'changes', 'status' => 'done', 'label' => 'Changes ready', 'detail' => (count($delta['add_tables'] ?? []) + count($delta['add_columns'] ?? []) + count($delta['update_policies'] ?? [])) . ' schema change(s), ' . count($delta['frontend']['files'] ?? []) . ' file(s)']);

    $summary = [
        'add_tables'      => array_column($delta['add_tables'] ?? [], 'name'),
        'add_columns'     => array_merge(...array_map(fn($e) => array_map(fn($c) => $e['table'] . '.' . $c['name'], $e['columns'] ?? []), $delta['add_columns'] ?? []) ?: [[]]),
        'update_policies' => array_map(fn($p) => $p['table'] . ' ' . $p['api_role'] . ' ' . $p['operation'], $delta['update_policies'] ?? []),
    ];
    if (!empty($delta['frontend']['files'])) $summary['frontend_files'] = count($delta['frontend']['files']);

    $editPlan = array_merge($delta, ['project_id' => $projectId]);

    return ['plan' => $editPlan, 'summary' => $summary, 'usage' => $client->getLastUsage(), 'aiTrace' => $aiTrace];
}

// Runs the Playwright user-story tests for a project against its most recent
// deploy (staging if present — edits land there by design — else live).
// Called from the worker's 'test' job mode; throws on unrecoverable failure.
const AI_STORY_TESTS_PROMPT = <<<'PROMPT'
You write a block of Playwright test code that verifies specific user stories against a deployed web app. Your code is inserted verbatim into an existing test harness, inside an async context, its own block scope, and a surrounding try/catch. Already defined and available to you:

- `page` — a Playwright Page, already open on the app and logged in as a test user (when the app has auth)
- `APP_URL` — string constant, the app's base URL (a hash-routed SPA)
- `log(msg)` — prints a section header
- `assert(label, condition, detailString)` — records one pass/fail story result
- `pageErrors` — array collecting the page's console/runtime errors so far

STRICT RULES — violating any of these makes your output unusable:
1. Respond with JSON only: {"code": "<the JavaScript block>"}.
2. For EACH user story you are given, produce exactly this shape (nothing outside it):
   log('Story: <short story label>');
   try {
     await page.goto(APP_URL, { waitUntil: 'networkidle' });
     await page.waitForTimeout(800);
     // ...actions: page.fill / page.click / page.textContent / page.$ ...
     assert('<short story label>', <boolean expression>, <short detail string>);
   } catch (e) { assert('<short story label>', false, e.message); }
3. Selectors: use ONLY names, ids, hrefs and visible text (via :has-text()) that actually appear in the provided index.html. Never invent selectors.
4. FORBIDDEN anywhere in your code: import, require, process, browser, chromium, fetch, XMLHttpRequest, page.close, page.context, eval, while loops, waitForTimeout above 3000, loops over 10 iterations.
5. Each story must be self-contained: navigate first, create any data it needs through the UI, and make ONE meaningful assertion about the story's outcome (not just "element exists" unless that IS the story).
6. If a story cannot be verified through this UI (needs email, external services, etc.), emit exactly:
   log('Story: <label>');
   assert('<label> (skipped — not verifiable in browser)', true, 'skipped');
7. Keep the whole block under 200 lines. Prefer covering fewer stories well over covering all of them badly.
PROMPT;

// Validate an AI-written JS block compiles — a syntax error in the block would
// otherwise be a parse error for the ENTIRE test script, producing zero results.
function ai_js_block_syntax_ok(string $block, array $config): bool
{
    $nodeBin = $config['NODE_BIN'] ?? '/opt/alt/alt-nodejs16/root/usr/bin/node';
    $tmp = sys_get_temp_dir() . '/sb_storycheck_' . getmypid() . '_' . time() . '.mjs';
    file_put_contents($tmp, "async function __sbStories(page, APP_URL, log, assert, pageErrors) {\n{\n" . $block . "\n}\n}\n");
    exec(escapeshellarg($nodeBin) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    @unlink($tmp);
    return $code === 0;
}

// Pull the flat list of user-story labels out of a saved project_requirements
// intent (the Review flow's nested actors[].stories[] shape).
function ai_extract_saved_stories(?array $requirements): array
{
    $stories = [];
    foreach (($requirements['actors'] ?? []) as $actor) {
        if (!is_array($actor)) continue;
        foreach (($actor['stories'] ?? []) as $s) {
            $label = is_string($s) ? $s : (string)($s['title'] ?? '');
            if (trim($label) !== '') $stories[] = trim($label);
        }
    }
    return array_values(array_unique($stories));
}

const AI_INFER_STORIES_PROMPT = <<<'PROMPT'
You are given a web app's database schema and its deployed frontend HTML. Infer the concrete user stories the app is meant to support — the things a real user would actually do with it — so they can be turned into browser tests. Base every story on evidence in the schema/HTML; do not invent features that aren't there. Respond with JSON only: {"stories": ["short imperative story", ...]}. Return at most 6 stories, each a short phrase like "Create a task with a due date" or "Mark an item as purchased". Prefer the app's distinctive features over generic CRUD.
PROMPT;

// Fallback for projects that never went through the Review flow (no saved
// requirements): ask the model to infer user stories from the schema + the
// deployed frontend, so any project still gets story-driven tests.
function ai_infer_stories(object $client, array $schema, string $indexHtml): array
{
    $userMsg = "Schema:\n" . ai_schema_to_context($schema)
             . "\n\nDeployed index.html:\n" . mb_substr($indexHtml, 0, 8000);
    $res = $client->generateJson(AI_INFER_STORIES_PROMPT, $userMsg);
    $out = [];
    foreach ((array)($res['stories'] ?? []) as $s) {
        if (is_string($s) && trim($s) !== '') $out[] = trim($s);
    }
    return array_values(array_unique($out));
}

/**
 * Turn a list of user-story labels into an AI-written Playwright test block.
 * Returns '' when there are no stories or the AI output fails validation —
 * story tests are additive, never a reason to fail the run.
 */
function ai_generate_story_tests(object $client, array $stories, array $schema, string $indexHtml, array $config): string
{
    $stories = array_slice($stories, 0, 8);
    if (!$stories) return '';

    $userMsg = "User stories to verify (one test each):\n"
             . implode("\n", array_map(fn($s, $i) => ($i + 1) . '. ' . $s, $stories, array_keys($stories)))
             . "\n\nSchema:\n" . ai_schema_to_context($schema)
             . "\n\nDeployed index.html (selectors must come from here):\n"
             . mb_substr($indexHtml, 0, 8000);

    $res  = $client->generateJson(AI_STORY_TESTS_PROMPT, $userMsg);
    $code = trim((string)($res['code'] ?? ''));
    if ($code === '' || strlen($code) > 20000) return '';

    foreach (['import ', 'require(', 'process.', 'browser.', 'chromium', 'fetch(', 'XMLHttpRequest', 'page.close', 'page.context', 'eval('] as $banned) {
        if (stripos($code, $banned) !== false) return '';
    }
    if (!ai_js_block_syntax_ok($code, $config)) return '';

    return $code;
}

function ai_run_project_tests(int $projectId, int $userId, \SupaBein\Catalog $catalog, array $config, callable $report, ?object $client = null): array
{
    $project = $catalog->getProjectById($projectId, $userId);
    if (!$project) throw new \RuntimeException('Project not found');

    $sites = $catalog->listSites($projectId);
    if (!$sites) throw new \RuntimeException('No deployed site found — build the project first');

    $site = $sites[0];
    if ($site['staging_deploy_id'] ?? null) {
        $target = 'staging';
    } elseif ($site['current_deploy_id'] ?? null) {
        $target = 'current';
    } else {
        throw new \RuntimeException('No deploy found — build or edit the project first');
    }

    $browserlessToken = $config['BROWSERLESS_TOKEN'] ?? '';
    if (!$browserlessToken) throw new \RuntimeException('Browserless token not configured (add BROWSERLESS_TOKEN to config/secrets.php)');

    $report(['stage' => 'script', 'status' => 'start', 'label' => 'Preparing test script…']);
    $siteId    = (int)$site['id'];
    $sitesPath = rtrim($config['SITES_PATH'], '/');
    $appUrl    = rtrim($config['API_BASE_URL'], '/') . "/sites/s{$siteId}/{$target}/";
    $indexPath = "{$sitesPath}/s{$siteId}/{$target}/index.html";
    $indexHtml = file_exists($indexPath) ? (string)file_get_contents($indexPath) : '';

    $schema = ai_schema_from_db($projectId, $catalog);
    $report(['stage' => 'script', 'status' => 'done', 'label' => 'Test script ready']);

    // Story-driven tests: the user stories captured in the Review flow (saved
    // to project_requirements) become their own AI-written test cases. If the
    // project never went through Review, infer stories from the schema + the
    // deployed frontend instead, so every project still gets story tests. Best
    // effort throughout — any failure here just means the template tests run alone.
    $storyBlock = '';
    if ($client) {
        $report(['stage' => 'stories', 'status' => 'start', 'label' => 'Writing user-story tests…']);
        try {
            $stories = ai_extract_saved_stories($catalog->getProjectRequirements($projectId));
            $source  = 'saved';
            if (!$stories) {
                $stories = ai_infer_stories($client, $schema, $indexHtml);
                $source  = 'inferred';
            }
            $storyBlock = $stories ? ai_generate_story_tests($client, $stories, $schema, $indexHtml, $config) : '';
            $covered    = $storyBlock !== '' ? substr_count($storyBlock, "log('Story:") : 0;
            $report(['stage' => 'stories', 'status' => 'done',
                     'label'  => $storyBlock !== '' ? 'Story tests ready' : 'No story tests',
                     'detail' => $storyBlock !== ''
                         ? "$covered user stories covered (" . $source . ")"
                         : 'stories could not be turned into tests']);
        } catch (\Throwable $e) {
            $report(['stage' => 'stories', 'status' => 'done', 'label' => 'Story tests skipped', 'detail' => $e->getMessage()]);
        }
    }

    $script = ai_playwright_test_generate($appUrl, $browserlessToken, $schema, $indexHtml, $projectId, $storyBlock);

    $report(['stage' => 'run', 'status' => 'start', 'label' => 'Running browser tests…']);
    $result = ai_playwright_test_run($script, $config);
    $report(['stage' => 'run', 'status' => 'done',
             'label'  => 'Tests finished',
             'detail' => ($result['passed'] ?? 0) . ' passed, ' . ($result['failed'] ?? 0) . ' failed']);

    sb_log('ai_test', !empty($result['error']) ? 'Failed: ' . $result['error'] : 'Complete', [
        'project_id' => $projectId,
        'target'     => $target,
        'passed'     => $result['passed'] ?? null,
        'failed'     => $result['failed'] ?? null,
    ]);

    return array_merge($result, ['target' => $target]);
}

// Spawns a fully independent OS process to run one job. This — not a shared
// queue/consumer — is what lets every user's build/edit run in true parallel:
// each job gets its own process the moment it's created, so nobody waits on
// anybody else's job. (cPanel/LVE process limits could throttle this under
// extreme simultaneous load, but each worker is short-lived and normal usage
// never approaches that.)
function ai_spawn_job_worker(array $config, int $jobId): void
{
    $phpBin = $config['PHP_BIN'] ?? '/usr/local/bin/php';
    $worker = SUPABEIN_ROOT . '/app/workers/ai_worker.php';
    exec($phpBin . ' ' . escapeshellarg($worker) . ' ' . $jobId . ' > /dev/null 2>&1 &');
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

        sb_log('ai_build', 'Calling AI (pass 1: schema)', ['user_id' => $userId, 'provider' => $provider, 'model' => $model, 'locked_intent' => (bool)$approvedIntent]);

        // Pass 1 — schema only (one self-correcting retry on validation failure)
        try {
            $schemaPlan = $gemini->generateJson(AI_BUILD_SCHEMA_PROMPT, $schemaUserMsg);
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
                $schemaPlan = $gemini->generateJson(AI_BUILD_SCHEMA_PROMPT, $retryPrompt);
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
        $brief    = ai_generate_design_brief($gemini, $prompt, $schemaPlan);
        $briefCtx = ai_brief_to_context($brief);

        // Pass 2 — frontend with exact (post-sanitize) column names + bound auth.js
        sb_log('ai_build', 'Calling AI (pass 2: frontend)', ['user_id' => $userId]);
        $frontendMsg = "App description: {$prompt}\n\n"
                     . ($briefCtx ? "{$briefCtx}\n\n" : '')
                     . "Exact validated schema — use ONLY these column names in JS:\n"
                     . ai_schema_to_context($schemaPlan);
        try {
            $frontendResult = $gemini->generateJson(ai_bind_auth_placeholders(AI_BUILD_FRONTEND_PROMPT, $schemaPlan), $frontendMsg);
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
                ai_abort_error('suggest', $e->getMessage());
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
                ai_abort_error('chat', $e->getMessage());
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

        $prompt = trim($req['body']['prompt'] ?? '');
        if ($prompt === '' || strlen($prompt) > 2000) {
            abort(422, 'prompt is required and must be under 2000 characters');
        }

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
            'prompt'   => $prompt,
            'history'  => $history,
            'intent'   => $intent,
            'provider' => $req['body']['provider'] ?? null,
            'model'    => $req['body']['model'] ?? null,
        ];
        $job = $catalog->createJob($userId, $sessionId, 'build', $payload);
        ai_spawn_job_worker($config, (int)$job['id']);
        json_out(['job_id' => (int)$job['id']], 202);
    }, ['auth_middleware']);

    // ── AI Edit (job): same job-backed pattern, for editing an existing project.
    $router->post('/v1/ai/edit/job', function (array $req): void {
        $config  = \App::get('config');
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];

        $prompt    = trim($req['body']['prompt'] ?? '');
        $projectId = isset($req['body']['project_id']) ? (int)$req['body']['project_id'] : 0;
        if ($prompt === '' || strlen($prompt) > 2000) abort(422, 'prompt is required and must be under 2000 characters');
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

        $payload = [
            'prompt'     => $prompt,
            'project_id' => $projectId,
            'history'    => $history,
            'provider'   => $req['body']['provider'] ?? null,
            'model'      => $req['body']['model'] ?? null,
        ];
        $job = $catalog->createJob($userId, $sessionId, 'edit', $payload);
        ai_spawn_job_worker($config, (int)$job['id']);
        json_out(['job_id' => (int)$job['id']], 202);
    }, ['auth_middleware']);

    // ── AI Jobs: poll progress/result, list active jobs, or cancel one ────────
    $router->get('/v1/ai/jobs/:id', function (array $req): void {
        $catalog = \SupaBein\Catalog::getInstance();
        $userId  = (int)$req['auth']['user_id'];
        $job = $catalog->getJobById((int)$req['params']['id'], $userId);
        if (!$job) abort(404, 'Job not found');

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

    $router->get('/v1/ai/jobs', function (array $req): void {
        $catalog = \SupaBein\Catalog::getInstance();
        json_out($catalog->listActiveJobs((int)$req['auth']['user_id']));
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
                    $editSiteId = (int)$editSites[0]['id'];
                    // Edits deploy to STAGING (preview) — the user publishes to live explicitly.
                    $deployResult = ai_deploy_files($editConfig, $catalog, $editSiteId,
                                                    $project, $plan['frontend']['files'],
                                                    true, false);
                    if (!empty($deployResult['deploy'])) {
                        $result['deploy'] = $deployResult['deploy'];
                        $apiBase = rtrim($editConfig['API_BASE_URL'] ?? '', '/');
                        $appBase = preg_replace('#/(api|v\d+)(/.*)?$#i', '', $apiBase);
                        $result['staging'] = [
                            'project_id'  => $projectId,
                            'site_id'     => $editSiteId,
                            'deploy_id'   => (int)$deployResult['deploy']['id'],
                            'staging_url' => $appBase . '/sites/s' . $editSiteId . '/staging/',
                        ];
                    } elseif (!empty($deployResult['error'])) {
                        $result['deploy_error'] = $deployResult['error'];
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

    // Generate a short, descriptive title for a chat session from its first message.
    // Best-effort: the client falls back to a truncated prompt if this fails.
    $router->post('/v1/ai/session-title', function (array $req): void {
        $prompt = trim($req['body']['prompt'] ?? '');
        if ($prompt === '') abort(422, 'prompt is required');
        $config = \App::get('config');
        try {
            $client = make_ai_client($config, null, null); // default fast model — keep it cheap
            $sys = 'You title chat sessions. Given the user\'s first message, return ONLY JSON {"title": "..."} '
                 . 'with a concise, specific 2-5 word Title Case label (max 40 chars, no trailing punctuation, no quotes).';
            $res   = $client->generateJson($sys, mb_substr($prompt, 0, 500));
            $title = is_array($res) ? trim((string)($res['title'] ?? '')) : '';
            $title = trim($title, " \t\n\r\0\x0B\"'.");
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
        ]);
        ai_spawn_job_worker($config, (int)$job['id']);
        json_out(['job_id' => (int)$job['id']], 202);
    }, ['auth_middleware']);

}
