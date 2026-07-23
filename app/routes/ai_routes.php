<?php

declare(strict_types=1);

require_once SUPABEIN_ROOT . '/app/core/max_tokens_probe.php';
require_once SUPABEIN_ROOT . '/app/core/gemini_client.php';
require_once SUPABEIN_ROOT . '/app/core/openrouter_client.php';
require_once SUPABEIN_ROOT . '/app/core/nvidia_client.php';
require_once SUPABEIN_ROOT . '/app/core/anthropic_client.php';
require_once SUPABEIN_ROOT . '/app/core/fallback_ai_client.php';
require_once SUPABEIN_ROOT . '/app/core/deploy.php';
require_once SUPABEIN_ROOT . '/app/core/ai_validator.php';

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
- Each table needs enough columns to support a genuinely useful UI — typically 4-8 real columns
  beyond id/created_at. A table with only 1-2 columns (e.g. just "title") can't support a proper
  detail view or status badges no matter how well the frontend is laid out; think through what a
  real record of this type actually needs (status/state, a date, an amount, a description, a
  relation) rather than the bare minimum implied by a short request.

AUTHENTICATION (read carefully — this is the most common design failure):
- Auth exists to keep DIFFERENT people's data apart from each other — it is not a tax on the
  word "my". Add a users table with login ONLY when the description signals that multiple
  distinct people will use the app and each needs their OWN private data that others can't see
  or edit — e.g. "so my team can each track their own tasks", "customers can sign up and view
  their orders", "let users create accounts", "each member has a private journal", "restrict
  this to logged-in users". A single owner using a personal tool is NOT that signal by itself —
  "track my widgets", "a to-do list for me", "log my workouts" normally mean exactly one
  "owner" actor and nobody else ever using this deployment, so there's nothing to protect data
  FROM: use anon policies, wide open, same as a public tool.
- Adding login/signup to a single-actor app is a hard failure, not a safe default: it commits
  the frontend build to auth forms/routes that add pure friction (there's only ever one person
  using this deployment either way, logged in or not), and if the frontend generation pass
  doesn't fully build them out, the entire app is broken behind a login it never needed.
- If genuinely unsure whether multiple people are really involved, prefer NO auth. It's a cheap
  edit to add later if it turns out several people do need separated accounts; a wrongly-added
  login is a fully broken app the instant the frontend doesn't finish building it.
- When auth IS actually warranted (multiple real actors, or an explicit privacy/login request),
  include exactly ONE users table that has a column of type PASSWORD (e.g.
  {"name":"email","type":"VARCHAR(255)"} plus {"name":"password","type":"PASSWORD"}). Without a
  PASSWORD column the platform cannot issue a login token, so :current_user_id is always empty
  and every owner-scoped table is permanently inaccessible.
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

The inline bootstrap contains ONLY (when auth exists, nav-login and nav-logout are TWO SEPARATE,
ALWAYS-PRESENT elements toggled by the 'hidden' class — NEVER one element whose id/text/href you
rewrite between "login" and "logout" state. An id you just reassigned is no longer findable by its
old id on the next call, so re-querying it by that old id later returns null and crashes; toggling
visibility on two static elements has no such trap and needs no listener to be added more than once.
Any OTHER nav link whose route requires a logged-in user — see RULE 3's "gate the nav link itself"
paragraph — gets class="nav-authed-only" and is toggled the same way, in the same function):
  <nav id="nav-menu" ...>
    <a href="#/" class="nav-authed-only hidden" ...>Notes</a>
    <a href="#/login" id="nav-login" ...>Login</a>
    <button id="nav-logout" class="hidden ...">Logout</button>
  </nav>
  <script>
    /* define updateNav() here ONCE (function declaration is fine) */
    function updateNav() {
      const user = auth.getCurrentUser();
      document.getElementById('nav-login').classList.toggle('hidden', !!user);
      document.getElementById('nav-logout').classList.toggle('hidden', !user);
      document.querySelectorAll('.nav-authed-only').forEach(el => el.classList.toggle('hidden', !user));
    }
    document.getElementById('nav-logout').addEventListener('click', (e) => {
      e.preventDefault();
      auth.logout();
    });

    router.defineRoute('/', featureA.renderView);
    router.defineRoute('/login', auth.renderLogin);   // only if schema has a PASSWORD column
    router.defineRoute('/signup', auth.renderSignup); // only if schema has a PASSWORD column
    router.defineRoute('/items/:id', featureA.renderDetail); // ':id' → handler receives {id}
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

core/router.js and core/api.js are PROVIDED BY THE PLATFORM — do NOT include "core/router.js" or
"core/api.js" in your files array at all, even for a full rebuild; the platform always injects its
own known-working versions of both after your output, so anything you write for those two paths is
discarded. This also means: NEVER invent your own routing scheme (no dynamic per-route script
loading, no dispatch-by-string-name via window[featureName] — that requires every feature module to
attach itself to `window`, which they do not, and causes "Module X not found" errors). All feature
scripts are already loaded upfront via <script src> tags (see STRUCTURE), so route handlers are
always a direct function reference to something already in scope — `router.defineRoute(path, fn)` —
never a string that gets looked up dynamically.

router.defineRoute(path, handler) registers a route; path segments starting with ':' are wildcards
(e.g. '/items/:id' matches '/items/42' and calls handler({id: '42'})); a path with no ':' segments
calls handler({}). router.navigate(path) and router.onHashChange() work as already described above.

═══════════════════════════════════════════════════════
RULE 2B — NEVER USE `this` INSIDE A FEATURE MODULE
═══════════════════════════════════════════════════════
router.onHashChange() invokes whatever function you registered as a bare call — handler(params) —
never as a method call on your module object. So when a registered handler is a shorthand method
that refers to a sibling method or the module's own state via `this` (this.loadState(), this.state,
etc.), `this` is undefined inside it at call time and the app crashes with "this.xxx is not a
function" the instant that route loads — indistinguishable from a blank/broken page to the user.
Every feature module (and auth.js-style modules, if you ever touch one) MUST reference itself by its
own top-level const name instead of `this`, with zero exceptions — this applies to every method in
the module, not just ones registered as routes, since any method can end up passed around as a bare
reference (e.g. an event listener callback has the exact same problem):
  ✗ const calculator = { renderCalculator() { this.loadState().then(...); } };
  ✓ const calculator = { renderCalculator() { calculator.loadState().then(...); } };

═══════════════════════════════════════════════════════
RULE 3 — AUTH: PLATFORM-PROVIDED, TWO SEPARATE ROUTES
═══════════════════════════════════════════════════════
features/auth/auth.js is PLATFORM-PROVIDED — do NOT include "features/auth/auth.js" in your files
array (only include it in the STRUCTURE at all when the schema has a PASSWORD column; when it does,
still never write the file yourself). It exposes: ready (a promise resolved after loadUser()
completes — the router must wait on this or protected pages flash "Access Denied" for logged-in
users), getCurrentUser(), login(identifier, password), signup(identifier, password), logout(),
renderLogin(), renderSignup().

Register BOTH as separate routes — they are two real, separate, properly laid-out pages, not one
combined screen:
  router.defineRoute('/login', auth.renderLogin);
  router.defineRoute('/signup', auth.renderSignup);
Each page already cross-links to the other (Login → "Sign up", Signup → "Log in"), so nav only ever
needs a single "Login" link — same as before, nothing extra required for signup to stay reachable.

GATE THE NAV LINK ITSELF, not just the route: if a route's render function immediately redirects an
anonymous visitor to /login (every row it shows is owned by :current_user_id and there's no anon
SELECT policy on that table — the normal shape for "your notes", "your orders", etc.), its nav link
must be hidden until the user is logged in, using class="nav-authed-only" from RULE 2's boilerplate.
A logged-out visitor should see ONLY Login in the nav — showing a link that just bounces them
straight to a login page instead of displaying anything is confusing, not helpful, and looks broken.
If the route's data genuinely has an anon SELECT policy (publicly viewable), leave its nav link
visible at all times instead — it isn't gated, so nothing needs toggling for it.

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
core/api.js is PROVIDED BY THE PLATFORM (like core/router.js) — do NOT include "core/api.js" in
your files array; the platform injects its own known-working version. Feature code calls it exactly
as follows (this is the real interface — code against it, do not redeclare or reimplement any of it):

  api.list(table)          → Promise<array>            all rows (array always, even if empty)
  api.get(table, id)       → Promise<object>            one row
  api.create(table, data)  → Promise<object>            inserts, returns the created row
  api.update(table, id, d) → Promise<object>             updates, returns the updated row
  api.remove(table, id)    → Promise<null>               deletes

All five throw on failure (network error, non-2xx) — wrap calls in try/catch and show the error
message. A 401 (or a 403 when the user was already logged in) auto-redirects to /login itself —
your catch block still fires, so still show/log the error, just don't also hand-navigate on those.

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

NAVIGATION — if a Design Brief is present in the user message, its "layout" field is authoritative;
use the rules below only when no brief was given (e.g. some edit-mode requests):
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
    core/errors.js                     ← PLATFORM-PROVIDED — do not include this path in your output
                                       (captures errors automatically; the platform force-inserts its
                                       <script> tag as the first script in index.html at deploy time,
                                       so you never need to add or even think about it)
    core/api.js                        ← PLATFORM-PROVIDED — do not include this path in your output
    core/router.js                     ← PLATFORM-PROVIDED — do not include this path in your output
    features/auth/auth.js              ← PLATFORM-PROVIDED — do not include this path in your output
                                       (only load it via <script src> when schema has a PASSWORD column)
    features/<feature>/<feature>.js    ← one subfolder per feature
Load with RELATIVE paths in dependency order (config → api → router → auth → features → bootstrap).
Absolute paths like /core/config.js break the site. No frameworks, no npm, no build tools.
index.html still needs <script src="./core/api.js"> and <script src="./core/router.js"> tags in that
load order — the platform writes the files to disk, you just need to reference them normally.

═══════════════════════════════════════════════════════
STYLING
═══════════════════════════════════════════════════════
- Tailwind via CDN in <head>:
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
  Add class="dark" to <html>. No separate CSS file.
- Base colours (always): bg-gray-950 (page), bg-gray-900 (cards),
  text-gray-100 (primary text), text-gray-400 (muted), text-red-400 (danger).
- Accent colour — if a Design Brief is present in the user message, its "accent_color" is
  authoritative: use exactly that Tailwind color name (e.g. "rose" → text-rose-400, bg-rose-500
  hover:bg-rose-600, ring-rose-500) and ignore the domain table below entirely. Only use the table
  below when no brief was given (e.g. some edit-mode requests) — pick ONE based on the app's domain
  and use it consistently for interactive elements (links, primary buttons, focus rings, active states):
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

WHERE this runs matters as much as the code itself: call formEl.addEventListener(...) (or any
"initX()" wiring function that does it) SYNCHRONOUSLY, in the SAME render function that just set
appDiv.innerHTML to the form's markup — never as a separate top-level statement gated by inspecting
window.location.hash (or any other check evaluated once when the script file first loads).
  ✗ // bottom of the file, outside any function:
    if (window.location.hash.startsWith('#/items/')) { initItemForm(); }
This is a fatal, hard-to-notice bug in a hash-routed SPA: script files execute exactly ONCE per
page load. Navigating to the form's route via a normal in-app link (clicking, not reloading) never
re-runs this check, so initItemForm() is simply never called and the form silently has no submit
handler — clicking its button falls through to the browser's native (unhandled) form submission,
which reloads the page. And if the user instead lands on that URL via a hard refresh, the hash DOES
match on that load, but the check runs before the router has rendered anything into #app yet — so
document.getElementById('item-form') is still null, and .addEventListener throws "Cannot read
properties of null (reading 'addEventListener')".
  ✓ renderItemForm: (item) => {
      const appDiv = document.getElementById('app');
      appDiv.innerHTML = `<form id="item-form">...</form>`;
      document.getElementById('item-form').addEventListener('submit', async (e) => { ... });
    }
Every view that needs a listener is self-contained this way: render its HTML, then wire it, in the
same function call, every single time that view renders — regardless of whether this was a fresh
page load or a client-side navigation.

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
RULE 12 — UI RICHNESS: NO BARE OR SCANTY PAGES
═══════════════════════════════════════════════════════
A page that only renders a plain list of rows (or a single column of text) reads as an unfinished
wireframe, even if it's technically correct and bug-free. Every major page needs real visual weight:
- List/dashboard pages open with a stats strip above the list (counts, totals, a highlight metric —
  computed from the real data, not hardcoded), THEN the list/table/grid below it.
- Render list content as cards or a multi-column table, never a bare <ul> of one-line text rows.
  Each card/row should show 3+ pieces of information (not just a name), plus a status badge/tag
  or icon where the data has any kind of state (paid/pending, in-stock/out, active/archived, etc.).
- Detail/"show one record" pages are a layout, not a field:value dump — group related fields into
  labeled sections/cards, and surface any related records (e.g. an order's line items, a user's
  posts) inline rather than making the user hunt for them on another page.
- Empty states are a small designed moment, not gray placeholder text: an icon/emoji, one line
  explaining what goes here, and — when the user can create the first item — a button that takes
  them straight to the create form.
- Every page needs at least two visually distinct sections (e.g. stats + list, or filters + grid +
  pagination) — a single homogeneous block top-to-bottom is the "scanty" look to avoid.
- This is about layout richness, not scope creep: do not invent features, tables, or columns the
  schema doesn't have. Present what's really there with more visual structure, not more content.

  ✗ SCANTY — a bare list, no stats, no state, one plain block:
    appDiv.innerHTML = `
      <ul>${rows.map(r => `<li>${r.name}</li>`).join('')}</ul>
    `;

  ✓ RICH — same data, a stats strip + a card grid with real per-row detail and state:
    appDiv.innerHTML = `
      <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-gray-900 rounded-lg p-4"><div class="text-2xl font-bold">${rows.length}</div><div class="text-sm text-gray-400">Total</div></div>
        <div class="bg-gray-900 rounded-lg p-4"><div class="text-2xl font-bold">${rows.filter(r => r.status === 'active').length}</div><div class="text-sm text-gray-400">Active</div></div>
        <div class="bg-gray-900 rounded-lg p-4"><div class="text-2xl font-bold">$${rows.reduce((s, r) => s + r.amount, 0)}</div><div class="text-sm text-gray-400">Total value</div></div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        ${rows.map(r => `
          <div class="bg-gray-900 rounded-lg p-4">
            <div class="flex justify-between items-start">
              <span class="font-medium">${r.name}</span>
              <span class="text-xs px-2 py-0.5 rounded-full ${r.status === 'active' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-700 text-gray-400'}">${r.status}</span>
            </div>
            <div class="text-sm text-gray-400 mt-1">$${r.amount} · ${r.created_at}</div>
          </div>
        `).join('')}
      </div>
    `;
  Same underlying data both times — the difference is entirely the two rules above (stats strip
  first, cards with 3+ fields + a status badge instead of a bare name).

═══════════════════════════════════════════════════════
PLACEHOLDERS + OWNERSHIP
═══════════════════════════════════════════════════════
- In core/config.js use these EXACT two lines (SB_PID is substituted at deploy time;
  SB_URL is derived at runtime so the app works on both HTTP and HTTPS):
    const SB_URL = window.location.origin + '/api/v1';
    const SB_PID = '__SB_PID__';
  Declared once. Never redeclare anywhere. No SB_KEY — public requests need no auth token.
- Auth (only load features/auth/auth.js via <script src> when the schema has a table with a
  PASSWORD column; if no PASSWORD column exists, omit the script tag and both routes entirely).
  See RULE 3 — the file itself is platform-provided, never written by you. The real users-table
  name is "__AUTH_TABLE__" and its identifier column is "__AUTH_FIELD__", in case you need to
  reference them elsewhere (e.g. displaying the logged-in user's email on a profile page).
  Auth wiring requirements (mandatory when auth exists):
  1. Use the exact two-element, class-toggled nav pattern shown under RULE 2 above — a Login link
     and a Logout button with the EXACT id="nav-logout", both always present in the DOM, shown/hidden
     via the 'hidden' class (never a single element whose id/text you rewrite at runtime). The
     automated test suite looks for this exact id — a missing or differently-named logout button is
     invisible to it and reports as "missing", even though it might work fine for real users.
  2. Protected routes MUST redirect, not dead-end: if a gated render finds no current user, call
     router.navigate('/login') instead of printing "Access Denied" — otherwise the form is
     unreachable.
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
      "policies": [ {"api_role": "anon"|"authenticated", "operation": "SELECT"|"INSERT"|"UPDATE"|"DELETE", "allowed": boolean, "constraint_sql": string or null} ]
    }
  ],
  "add_columns": [
    { "table": string, "columns": [ {"name": string, "type": string, "nullable": boolean} ] }
  ],
  "update_policies": [
    {"table": string, "api_role": "anon"|"authenticated", "operation": "SELECT"|"INSERT"|"UPDATE"|"DELETE", "allowed": boolean, "constraint_sql": string or null}
  ],
  "seed_data": {
    "<table_name>": [ { "<col>": <value>, ... } ]
  },
  "frontend": { "files": [ {"path": string, "content": string} ] }
}

- policy.constraint_sql: WHERE-style expression or null; use ":current_user_id" for the logged-in
  user's ID. Do NOT use "auth.uid()" — it is not supported. You may reference OTHER tables in this
  project by their name exactly as given in "Exact schema" (e.g. "id IN (SELECT project_id FROM
  project_assignments WHERE learner_user_id = :current_user_id)") — the platform resolves those
  names to the real underlying tables for you. Omit constraint_sql (or use null) for a policy that
  should apply to every row with no per-row restriction.

The "frontend" key is OPTIONAL.
- OMIT "frontend" for a pure schema change (add column, change policy).
- INCLUDE "frontend" for any UI / visual / navigation / "broken" / "blank page" request.
- When included, output ONLY the files that actually need to change: the file(s) implementing the
  request, plus any file whose wiring must change as a direct result (e.g. adding a nav link or a
  new route touches index.html; a brand-new feature needs its own new file). Do NOT re-output a file
  that needs no change under this request — every path you omit is left exactly as it is in the
  current live deploy (see MERGE below), so reproducing an untouched file from memory only adds
  tokens and risk of introducing an unrelated regression in something that already worked.
- MERGE: any path you DO return fully REPLACES its old version, so a half-written file breaks the
  site — never return a partial/truncated file. Any path you do NOT return keeps its current content.
- Use the exact column names from the "Exact schema" context — do NOT invent or rename them.

The "seed_data" key is OPTIONAL — only include it when the user's request is EXPLICITLY about
adding/seeding/generating sample, demo, fake, or test data (e.g. "seed 20 fake orders", "add some
sample products"). Otherwise omit it entirely.
- Target only tables that already exist (from "Exact schema") or that you are adding in this same
  delta via add_tables.
- Honor any specific count the user asks for exactly (e.g. "seed 20 orders" → 20 rows); default to
  5-10 realistic rows if they don't give a count. Cap at 50 rows per table per request.
- Never seed auth/users tables or rows that must belong to a real logged-in user (anything relying
  on :current_user_id ownership) — only seed "global"/catalogue-style data.
- Omit "id" and "created_at" — SupaBein inserts them automatically.
- Values must match the column types exactly (strings for VARCHAR/TEXT, numbers for INT/DECIMAL).

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

// ── Edit: agentic tool-use loop ──────────────────────────────────────────────
// A prompted ReAct-style loop instead of native per-provider tool-calling —
// every provider client here (anthropic/gemini/openrouter/nvidia) is a thin
// generateJson(WithHistory) wrapper with no function-calling support, and
// several OpenRouter models are free-tier with unreliable tool-calling even
// where it exists. Returning one JSON action per turn works identically
// across all of them with zero client changes.
const AI_EDIT_AGENT_SYSTEM_HEADER = <<<'PROMPT'
You are a full-stack developer for SupaBein, a self-hosted BaaS platform, working as an autonomous
coding agent. The user wants to MODIFY an existing project. You do NOT get the full codebase up
front — you have tools to explore and change it, and you decide what to look at.

Respond with ONLY a single JSON object — no markdown fences, no explanation, no extra text — shaped
exactly as one action:
  {"tool": "<name>", "args": { ... }, "thought": "<one short sentence, optional>"}

Available tools:
  list_files   args: {}
    Returns the current file listing (paths only).
  search_code  args: {"query": string}
    Case-insensitive substring search across every file's content. Returns matching
    {path, line, text} entries (capped). Use this to find where something is defined/used
    before deciding which file(s) to read.
  read_file    args: {"path": string}
    Returns the full current content of one file.
  write_file   args: {"path": string, "content": string}
    Works for BOTH editing an existing file and creating a brand-new one — write_file a path that
    doesn't exist yet (read_file on it will say "no such file", which just means it needs creating)
    to create it. Same MERGE semantics as a normal edit delta: any path you don't write_file keeps
    its current content, so a brand-new feature needing its own file is exactly one more write_file
    call, not a reason to rewrite anything else. When you add a new feature file, also write_file
    index.html to add its <script src="./features/<name>/<name>.js"> tag (after its dependencies,
    before the inline bootstrap script) and register any new route(s) it needs. The result tells you
    immediately whether the write passed a syntax check — fix it and write_file again if not.
    HARD RULE: if the path already exists, you MUST read_file it first in this same session before
    write_file'ing it — write_file on an existing path you haven't read is rejected. This is not
    optional: regenerating a file you haven't actually read (even one you're confident you know the
    shape of) silently drops whatever it already did that your request didn't ask you to change —
    e.g. "just add an About page" is not license to rewrite the notes list from memory and lose its
    create/edit form in the process. A path you're creating for the first time has nothing to read,
    so this only applies to paths list_files already showed you.
  write_files  args: {"files": [{"path": string, "content": string}, ...]}
    Same as calling write_file once per entry, in order, but in a single turn — use this whenever
    you're about to write more than one file back-to-back (e.g. a new feature file plus its
    index.html wiring) instead of spending a separate turn per file. The same write_file HARD RULE
    applies to every entry individually.
  syntax_check args: {"path": string}  (path optional — omit to check every file you've written so far)
    Re-runs the syntax check on demand.
  check_policy args: {"table": string, "api_role": "anon"|"authenticated", "operation": "SELECT"|"INSERT"|"UPDATE"|"DELETE"}
    Actually RUNS that policy's constraint_sql against the real database (a harmless, row-count-only
    dry run — never returns row data) and tells you whether it executes cleanly or errors, with the
    real database error message if it does. Use this whenever a request describes something breaking
    for logged-in users specifically (a page erroring, a blank list, "works logged out but not logged
    in") — that shape of bug is almost always a policy whose constraint_sql doesn't actually work, and
    reading the constraint_sql text is not enough to know that; a completely reasonable-looking
    constraint can still fail at execution time for reasons that aren't visible from the text alone.
    If it comes back not executing cleanly, fix it with update_policies (constraint_sql supports
    referencing other tables in this project by name, same as during a build).
  curl_site    args: {"target": "site"|"api", "path": string}
    Makes a REAL, read-only GET request against the project's currently deployed state — either
    "site" (the deployed frontend, e.g. path "/" or "/index.html") or "api" (the real data API,
    path is "/<table_name>" or "/<table_name>/<id>", same policies a real visitor gets). Returns
    {http_status, body, truncated}. Use this to confirm a bug report against reality before trying
    to fix it — e.g. "table X won't load" is often a real 404/500/policy-denial one request away
    from confirming, rather than a guess from reading code. LIMITATION: this only ever reaches the
    server, so it can confirm a static file or API response but NOT what a client-side hash route
    (e.g. "#/dashboard") renders once the browser's JS runs — don't use it to debug a report that's
    specifically about what appears after a hash-route navigation. Use fetch_page below for that.
  fetch_page   args: {"path": string}
    Actually loads the deployed app in a real headless browser, navigates to this hash route (e.g.
    "/dashboard" — same as a user visiting "#/dashboard"), waits for it to render, and returns
    {url, http_status, bodyText, elements, console_errors} — the RENDERED text/visible buttons/links
    after the app's own JS ran, plus any real console errors. This is what curl_site above cannot do:
    use fetch_page whenever a report is about what a user actually SEES on a given page/route ("404
    after login", "page is blank", "button doesn't show up") rather than a raw file/API response.
    Costs real time (spins up a real browser) — reach for curl_site first for anything it can answer,
    and use fetch_page only for reports specifically about rendered page content.
  validate_frontend args: {}
    Runs the same deterministic checks used after you finish (dead routes, api.* calls against
    tables that don't exist, auth handlers that don't exist, nav links with no matching route) against
    whatever you've write_file'd so far — but NOW, so you can see and fix a mistake yourself instead
    of shipping it. Only reflects the schema as it exists right now: any add_tables/add_columns you
    plan to include in your own finish() aren't real yet, so a file that assumes one of those already
    exists will still show a false positive here — that's expected. Worth calling once before finish()
    on any non-trivial change, especially one touching routes or a table you didn't just add yourself.
  smoke_test   args: {}
    Actually loads a disposable, isolated preview build of everything you've write_file'd so far in a
    real headless browser (never the project's real staging/live site — this can't clobber what a user
    might currently be looking at) and returns {ok, url, bodyText, elements, console_errors}. Catches
    exactly what syntax_check and validate_frontend cannot: a file that parses fine and looks correct
    but THROWS at runtime (e.g. a route handler that assumes `this` is bound when the router calls it
    as a bare function — see RULE 2B). Real api.* calls in the preview will 404 (there's no real
    project behind it) — that's expected; smoke_test checks the app doesn't crash, not that seeded
    data round-trips. Costs real time (spins up a real browser) — call it once after a non-trivial
    change, and again right before finish if you touched routing/bootstrap code.
    HARD RULE: if smoke_test's most recent result was ok: false, finish is REJECTED until you either
    fix the problem or call smoke_test again and get ok: true — you cannot finish past a known-broken
    smoke_test by ignoring it or ending the turn some other way.
  fetch_docs   args: {"url": string}
    Fetches a specific URL (e.g. a library's docs page) and returns its text content. This is a plain
    fetch, not a search engine — you need the exact URL already (from the request or something you
    already read), not a topic to search for. Only http(s) URLs to public internet addresses work.
  finish       args: {"add_tables": [...], "add_columns": [...], "update_policies": [...], "seed_data": {...}}
    Ends the session. Every key is optional (omit or use [] / {} for "no schema change of this
    kind") — use the SAME shapes as a normal edit delta, documented below. Do NOT repeat frontend
    file content here — anything you already write_file'd is included automatically.
    HARD RULE: a schema change is rarely the whole job by itself. If the request implies a user
    should be able to SET or SEE the new/changed column anywhere (a form field to enter it, a badge/
    value shown on a card or detail view, a filter, etc.), you must ALSO write_file the frontend
    file(s) that expose it BEFORE calling finish — add_columns with zero frontend files is only
    correct for a request that is explicitly schema-only ("just add the column", "I'll handle the
    UI myself"). Adding a "category" field that the user can set and see, for example, is not done
    until some form actually collects it and some view actually displays it — the column existing
    in the database is not the goal, using it is.

Work iteratively: search/read what you need, write_file your changes, syntax_check if unsure, then
finish. You have a limited number of turns, so don't re-read a file you already have, and don't
write a file you don't need to change. If a write_file's syntax check fails, that error is the
truth — fix the actual problem it names, don't just retry the same content. Before you call finish,
re-read the original request once more and check you actually did all of it, not just the part
that was easiest to satisfy first.

Schema delta shapes for "finish" (identical to a normal edit delta — see full rules below for
column types, identifier rules, and when seed_data applies):
{
  "add_tables": [ {"name": string, "columns": [ {"name": string, "type": string, "nullable": boolean} ], "policies": [ {"api_role": "anon"|"authenticated", "operation": "SELECT"|"INSERT"|"UPDATE"|"DELETE", "allowed": boolean, "constraint_sql": string or null} ] } ],
  "add_columns": [ { "table": string, "columns": [ {"name": string, "type": string, "nullable": boolean} ] } ],
  "update_policies": [ {"table": string, "api_role": "anon"|"authenticated", "operation": "SELECT"|"INSERT"|"UPDATE"|"DELETE", "allowed": boolean, "constraint_sql": string or null} ],
  "seed_data": { "<table_name>": [ { "<col>": <value>, ... } ] }
}
- Do NOT include tables/columns that already exist. Do NOT drop or rename — additions and policy
  changes only. NEVER include "id" or "created_at" as columns.
- policy.constraint_sql: WHERE-style expression or null; use ":current_user_id" for the logged-in
  user's ID (never "auth.uid()"). You may reference other tables in this project by name (e.g. "id IN
  (SELECT project_id FROM project_assignments WHERE learner_user_id = :current_user_id)") — the
  platform resolves those to the real underlying tables. Omit/null for no per-row restriction.
- seed_data: only for an EXPLICIT seed/sample-data request; never seed auth/users tables or rows
  owned by :current_user_id; omit "id"/"created_at"; 5-10 rows by default, cap 50/table.

The FRONTEND RULES below apply to every write_file call:
PROMPT;

const AI_EDIT_AGENT_SYSTEM_PROMPT = AI_EDIT_AGENT_SYSTEM_HEADER . "\n\n" . AI_FRONTEND_RULES;

// Live-caught at 12: a genuine bug-diagnosis request (job 126, project 30)
// burned the entire budget just reading frontend files — including getting
// stuck re-reading the same file for several turns in a row — and ran out
// before it ever reached the actual fix. Now that FallbackAiClient means a
// long-running job surviving a mid-run rate limit no longer risks failing
// outright, there's much less downside to giving the agent real room to
// investigate, use check_policy, and still write the fix, rather than
// racing a tight clock on every single request.
const AI_EDIT_AGENT_MAX_TURNS = 60;

// ── Build frontend agent: same ReAct-style loop as the edit agent above, but
// starting from zero files (a fresh build, not a modification against an
// existing codebase) and with a trivial finish() — the schema is already
// finalized by this point in the pipeline, so there's no schema delta left
// to carry back, just the files themselves.
const AI_BUILD_FRONTEND_AGENT_SYSTEM_HEADER = <<<'PROMPT'
You are a frontend developer for SupaBein, a self-hosted BaaS platform, working as an autonomous
coding agent. The user wants a BRAND-NEW project built from scratch. The database schema and visual
design have already been finalized — you write every frontend file needed to make the app fully
functional, one file at a time, deciding for yourself which files to write and in what order.

Respond with ONLY a single JSON object — no markdown fences, no explanation, no extra text — shaped
exactly as one action:
  {"tool": "<name>", "args": { ... }, "thought": "<one short sentence, optional>"}

Available tools:
  list_files   args: {}
    Returns the files you've written so far (paths only) — empty at the very start.
  search_code  args: {"query": string}
    Case-insensitive substring search across every file you've written so far. Use this to check
    whether you already defined something (a route, a helper, a global) before writing it again.
  read_file    args: {"path": string}
    Returns the full current content of a file you've already written.
  write_file   args: {"path": string, "content": string}
    Creates or overwrites one file. The result tells you immediately whether the write passed a
    syntax check — fix it and write_file again if not. Write index.html first (or early), then add
    each feature's <script src="./features/<name>/<name>.js"> tag to it as you write that feature
    file (after its dependencies, before the inline bootstrap script), and register its route(s).
    HARD RULE: if you're rewriting a path you already write_file'd earlier this session, you must
    read_file it first so your change is based on what you actually wrote, not a guess from memory —
    e.g. adding a second feature's script tag is not license to reconstruct index.html from scratch
    and lose the first feature's tag/route in the process.
  write_files  args: {"files": [{"path": string, "content": string}, ...]}
    Same as calling write_file once per entry, in order, but in a single turn — use this whenever
    you're about to write more than one file back-to-back (e.g. index.html plus a feature file)
    instead of spending a separate turn per file.
  syntax_check args: {"path": string}  (path optional — omit to check every file you've written so far)
    Re-runs the syntax check on demand.
  validate_frontend args: {}
    Runs deterministic checks (dead routes, api.* calls against tables that don't exist in the
    schema, auth handlers that don't exist, nav links with no matching route) against everything
    you've write_file'd so far. Worth calling once you have index.html and at least one feature wired
    up, and again right before finish — catches exactly the kind of mistake ("route registered but
    nothing links to it", "typo'd a table name in an api.list call") that's invisible just re-reading
    your own code, the same way running a test catches things proofreading doesn't.
  smoke_test   args: {}
    Actually loads a disposable preview build of everything you've write_file'd so far in a real
    headless browser and returns {ok, url, bodyText, elements, console_errors}. Catches exactly what
    syntax_check and validate_frontend cannot: a file that parses fine and looks correct but THROWS
    at runtime (e.g. a route handler that assumes `this` is bound when the router calls it as a bare
    function — see RULE 2B). Real api.* calls in the preview will 404 (there's no real project yet) —
    that's expected; smoke_test checks the app doesn't crash, not that data round-trips. Costs real
    time — call it once you have index.html and at least one feature wired up, and again right before
    finish.
    HARD RULE: if smoke_test's most recent result was ok: false, finish is REJECTED until you either
    fix the problem or call smoke_test again and get ok: true — you cannot finish past a known-broken
    smoke_test by ignoring it or ending the turn some other way.
  fetch_docs   args: {"url": string}
    Fetches a specific URL and returns its text content. This is a plain fetch, not a search engine —
    you need the exact URL already, not a topic to search for. Only http(s) URLs to public internet
    addresses work.
  finish       args: {}
    Ends the session once the app is fully functional — real API calls, real CRUD, real auth flows
    where auth exists, and no dangling references (every <script src> you wrote corresponds to a
    real file you write_file'd, and every route you registered has something linking to it). Do NOT
    repeat file content here — anything you already write_file'd is included automatically.

Work iteratively: write index.html first, then each feature file in turn, wiring up its route and
nav entry as you go. Use search_code/read_file to stay consistent with what you've already written
instead of re-deriving it from memory. You have a limited number of turns, so don't re-check
something you're already sure of. If a write_file's syntax check fails, that error is the truth —
fix the actual problem it names, don't just retry the same content. Before you call finish, check
every route you registered actually has something linking to it, and every script tag you wrote
actually corresponds to a file you wrote.

The FRONTEND RULES below apply to every write_file call:
PROMPT;

const AI_BUILD_FRONTEND_AGENT_SYSTEM_PROMPT = AI_BUILD_FRONTEND_AGENT_SYSTEM_HEADER . "\n\n" . AI_FRONTEND_RULES;

const AI_BUILD_FRONTEND_AGENT_MAX_TURNS = 60; // a full build writes more files than a targeted edit

// ─── Platform-provided boilerplate (never AI-authored) ──────────────────────
// core/router.js and core/api.js are the two files every "route not found" /
// "module not found" style bug traced back to — either the AI silently
// deviated from the verbatim template (most often inventing a lazy per-route
// script-loader with a window[featureName] lookup, which requires every
// feature module to attach itself to `window`, which they never do), or a
// later edit regenerated the file from scratch and lost a previous fix.
// Since both files are pure boilerplate with zero app-specific content, the
// server injects its own known-good copy after every deploy, unconditionally,
// regardless of what (if anything) the AI wrote for those two paths.

const AI_CANONICAL_ROUTER_JS = <<<'JS'
const router = (() => {
  const routes = {};
  const defineRoute = (path, handler) => { routes[path] = handler; };
  const navigate = (path) => { window.location.hash = path; };

  // Matches a static route first, then falls back to a ':param' pattern of
  // the same segment length (e.g. '/items/:id' matches '/items/42').
  const matchRoute = (path) => {
    if (routes[path]) return { handler: routes[path], params: {} };
    const segs = path.split('/');
    for (const pattern in routes) {
      const pSegs = pattern.split('/');
      if (pSegs.length !== segs.length) continue;
      const params = {};
      let ok = true;
      for (let i = 0; i < pSegs.length; i++) {
        if (pSegs[i].startsWith(':')) params[pSegs[i].slice(1)] = decodeURIComponent(segs[i]);
        else if (pSegs[i] !== segs[i]) { ok = false; break; }
      }
      if (ok) return { handler: routes[pattern], params };
    }
    return null;
  };

  const onHashChange = async () => {
    // Looked up fresh on every call, not cached at module-load time: this
    // script tag commonly loads in <head>, before <main id="app"> exists in
    // the DOM, which would permanently null out a module-scope reference.
    const appDiv = document.getElementById('app');
    if (!appDiv) return;
    // Empty hash (first load, or "#") means the home route, never 404.
    const path = window.location.hash.replace(/^#/, '') || '/';
    const match = matchRoute(path);
    const handler = match ? match.handler : (routes['/404'] ||
      (() => { appDiv.innerHTML = '<h1 class="text-2xl text-red-400 p-8">404 - Not Found</h1>'; }));
    appDiv.innerHTML = '<p class="text-gray-400 animate-pulse text-center p-8">Loading...</p>';
    try { await handler(match ? match.params : {}); }
    catch (error) {
      appDiv.innerHTML = '<p class="text-red-400 text-center p-8">Error: ' + error.message + '</p>';
      console.error('Routing error:', error);
    }
  };
  return { defineRoute, navigate, onHashChange };
})();
JS;

const AI_CANONICAL_API_JS = <<<'JS'
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
      // JWTs are base64url (RFC 7519), not plain base64 -- atob() throws on
      // a payload containing '-' or '_', which this used to just swallow
      // and silently skip the expiry check for (harmless here since a truly
      // expired token still gets rejected server-side), but the identical
      // decode in auth.js's loadUser() has a much worse failure mode -- kept
      // consistent with that fix rather than leaving two different decodes
      // of the same token in the same generated app.
      const b64 = t.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
      const exp = JSON.parse(atob(b64.padEnd(b64.length + (4 - b64.length % 4) % 4, '='))).exp;
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
    if (!res.ok) {
      const errMsg = `${res.status} ${res.statusText}`;
      if (window.__sbReportApiError) window.__sbReportApiError(errMsg, { url, status: res.status });
      throw new Error(errMsg);
    }
    return res.status === 204 ? null : res.json();
  };
  const list   = async (table)         => unwrap(await req(base(table)));
  const get    = async (table, id)     => req(`${base(table)}/${id}`);
  const create = async (table, data)   => req(base(table), { method: 'POST',   body: JSON.stringify(data) });
  const update = async (table, id, d)  => req(`${base(table)}/${id}`, { method: 'PATCH', body: JSON.stringify(d) });
  const remove = async (table, id)     => req(`${base(table)}/${id}`, { method: 'DELETE' });
  return { list, get, create, update, remove };
})();
JS;

// core/errors.js — like router.js/api.js/auth.js, this is pure platform
// infrastructure with zero app-specific content, so it's injected the same
// way: unconditionally, regardless of whether the AI wrote anything for this
// path, and index.html's <script> tag for it is force-inserted at deploy
// time too (see ai_ensure_error_script_tag) rather than relying on the AI to
// remember it — this is what makes error capture retroactive: any existing
// deployed app gets it on its very next deploy, no edit request required.
// Captures uncaught JS errors, unhandled promise rejections, api.js's own
// failed-request signal (see the req() hook above), and console.error()
// calls, then reports them to the platform's ingestion endpoint. Fire-and-
// forget via sendBeacon (falls back to keepalive fetch) so a report never
// blocks the page; a per-page-load cap plus in-memory de-dup on
// type+message+stack-prefix keeps a tight error loop from spamming.
const AI_CANONICAL_ERRORS_JS = <<<'JS'
(() => {
  const SB_PID = '__SB_PID__';
  const ENDPOINT = window.location.origin + '/api/v1/errors/' + SB_PID;
  const MAX_REPORTS_PER_LOAD = 20;
  let sent = 0;
  const seen = new Set();

  const send = (type, message, stack, meta) => {
    if (sent >= MAX_REPORTS_PER_LOAD) return;
    const key = type + '|' + message + '|' + String(stack || '').slice(0, 200);
    if (seen.has(key)) return;
    seen.add(key);
    sent++;
    const payload = JSON.stringify({
      type,
      message: String(message == null ? 'Unknown error' : message).slice(0, 2000),
      stack: stack ? String(stack).slice(0, 4000) : null,
      url: window.location.href,
      meta: meta || null,
    });
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(ENDPOINT, new Blob([payload], { type: 'application/json' }));
      } else {
        fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload, keepalive: true }).catch(() => {});
      }
    } catch {}
  };

  window.addEventListener('error', (e) => {
    send('js_error', e.message || 'Unknown error', e.error && e.error.stack, { line: e.lineno, col: e.colno, file: e.filename });
  });
  window.addEventListener('unhandledrejection', (e) => {
    const reason = e.reason;
    send('promise_rejection', (reason && reason.message) || String(reason), reason && reason.stack);
  });
  const origConsoleError = console.error.bind(console);
  console.error = (...args) => {
    origConsoleError(...args);
    send('console_error', args.map(a => (a && a.message) || String(a)).join(' '));
  };

  // api.js calls this on any non-2xx response (see req() above) — defined
  // here, on window, so there's no load-order dependency between the two
  // platform files beyond core/errors.js needing to load first.
  window.__sbReportApiError = (message, meta) => send('api_error', message, null, meta);
})();
JS;

// features/auth/auth.js — like router.js/api.js, this used to be a "copy verbatim"
// instruction the AI could deviate from. It also used to force login+signup onto
// one screen ("MUST render BOTH... never login only") purely as a defensive
// workaround: the prompt only ever guaranteed a nav link to /login, never to
// /signup, so a separate signup route risked becoming an unreachable dead end.
// Injecting this file lets /login and /signup be real, separate, properly laid
// out routes (each cross-links to the other) without reintroducing that risk —
// reachability no longer depends on the AI remembering a second nav link.
// __AUTH_TABLE__ / __AUTH_FIELD__ are substituted with the real schema values
// at deploy time, exactly like __SB_PID__ already is.
const AI_CANONICAL_AUTH_JS = <<<'JS'
const auth = (() => {
  const TABLE = '__AUTH_TABLE__';
  const FIELD = '__AUTH_FIELD__';
  let currentUser = null;
  let _resolveReady;
  const ready = new Promise(res => { _resolveReady = res; });

  const loadUser = async () => {
    const t = localStorage.getItem('sb:token');
    if (!t) { _resolveReady(null); document.dispatchEvent(new CustomEvent('auth_status_change')); return; }
    try {
      // JWTs are base64url (RFC 7519), not plain base64 -- a payload
      // containing '-' or '_' (common; depends only on the base64 bytes
      // that happen to land there) makes plain atob() throw, which used to
      // silently wipe a perfectly valid token and leave the user looking
      // logged out on the very next page load.
      const b64 = t.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
      const payload = JSON.parse(atob(b64.padEnd(b64.length + (4 - b64.length % 4) % 4, '=')));
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
    return login(identifier, password); // auto-login after signup
  };

  const logout = () => {
    localStorage.removeItem('sb:token');
    currentUser = null;
    document.dispatchEvent(new CustomEvent('auth_status_change'));
    router.navigate('/login');
  };

  const fieldLabel = FIELD.charAt(0).toUpperCase() + FIELD.slice(1).replace(/_/g, ' ');

  // Neutral dark styling (no accent-color dependency) so this always looks at
  // home regardless of whichever accent color the rest of the app picked.
  const renderAuthCard = (heading, submitLabel, onSubmit, footerHtml) => {
    const appDiv = document.getElementById('app');
    appDiv.innerHTML = `
      <div class="min-h-[100dvh] flex items-center justify-center p-4">
        <div class="w-full max-w-sm bg-gray-900 border border-gray-800 rounded-xl shadow-lg p-6">
          <h1 class="text-xl font-semibold text-gray-100 mb-6">${heading}</h1>
          <form id="auth-form" class="flex flex-col gap-4">
            <div>
              <label class="block text-sm text-gray-400 mb-1">${fieldLabel}</label>
              <input type="text" id="auth-identifier" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-gray-100 w-full focus:outline-none focus:ring-2 focus:ring-gray-500" required>
            </div>
            <div>
              <label class="block text-sm text-gray-400 mb-1">Password</label>
              <input type="password" id="auth-password" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-gray-100 w-full focus:outline-none focus:ring-2 focus:ring-gray-500" required>
            </div>
            <p id="auth-error" class="text-red-400 text-sm hidden"></p>
            <button type="submit" class="rounded-lg px-4 py-2 font-medium transition bg-gray-100 hover:bg-white text-gray-900">${submitLabel}</button>
          </form>
          <p class="text-sm text-gray-400 mt-4 text-center">${footerHtml}</p>
        </div>
      </div>
    `;
    const form  = document.getElementById('auth-form');
    const errEl = document.getElementById('auth-error');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      errEl.classList.add('hidden');
      const btn = form.querySelector('button[type=submit]');
      btn.disabled = true;
      try {
        await onSubmit(
          document.getElementById('auth-identifier').value.trim(),
          document.getElementById('auth-password').value
        );
        router.navigate('/');
      } catch (err) {
        errEl.textContent = err.message;
        errEl.classList.remove('hidden');
      } finally {
        btn.disabled = false;
      }
    });
  };

  const renderLogin = () => renderAuthCard(
    'Log in', 'Log in', login,
    `Don't have an account? <a href="#/signup" class="text-gray-200 underline">Sign up</a>`
  );

  const renderSignup = () => renderAuthCard(
    'Create account', 'Sign up', signup,
    `Already have an account? <a href="#/login" class="text-gray-200 underline">Log in</a>`
  );

  loadUser();
  return { ready, getCurrentUser, login, logout, signup, renderLogin, renderSignup };
})();
JS;

// Injected instead of AI_CANONICAL_AUTH_JS when the schema has no PASSWORD
// column. The AI is told to only reference "auth" when auth exists, but a
// weaker model can still write a <script src="./features/auth/auth.js">
// tag and/or inline bootstrap code like `auth.ready.then(...)` out of habit
// — live-caught: stripping the dangling script tag alone (see the deploy
// pipeline) left that inline code calling a global that was never defined
// at all, throwing a ReferenceError that crashed the whole bootstrap script
// before routing ever ran — a fully blank page, no error visible to the
// user beyond the console. Matching the real interface with inert no-ops
// (an already-resolved `ready`, a null current user, login/signup that
// reject clearly) means any such reference degrades gracefully instead of
// crashing, regardless of what the AI wrote.
const AI_CANONICAL_AUTH_STUB_JS = <<<'JS'
const auth = (() => {
  const ready = Promise.resolve(null);
  const getCurrentUser = () => null;
  const notAvailable = async () => { throw new Error('This app has no login system.'); };
  const renderNotAvailable = () => {
    const appDiv = document.getElementById('app');
    if (appDiv) appDiv.innerHTML = '<p class="text-gray-400 text-center p-8">This app has no login system.</p>';
  };
  return {
    ready, getCurrentUser,
    login: notAvailable, signup: notAvailable, logout: () => {},
    renderLogin: renderNotAvailable, renderSignup: renderNotAvailable,
  };
})();
JS;

// Force these platform-provided paths to their canonical content, adding them
// if the AI omitted them (all are required whenever frontend files are being
// deployed) and overwriting them if the AI wrote something else (its content
// for these paths is always discarded either way). features/auth/auth.js is
// injected unconditionally, like the other platform files — real
// implementation when $authInfo (from ai_detect_auth) has a table, the inert
// stub above otherwise — rather than being omitted for auth-less apps, so a
// stray reference to `auth` never throws regardless of whether the AI's
// markup or bootstrap code assumed auth exists.
function ai_inject_canonical_frontend_files(array $frontendFiles, ?array $authInfo = null): array
{
    $byPath = [];
    foreach ($frontendFiles as $f) {
        if (!is_array($f) || !isset($f['path'])) continue;
        $byPath[ltrim((string)$f['path'], '/')] = $f;
    }
    $byPath['core/router.js'] = ['path' => 'core/router.js', 'content' => AI_CANONICAL_ROUTER_JS];
    $byPath['core/api.js']    = ['path' => 'core/api.js',    'content' => AI_CANONICAL_API_JS];
    $byPath['core/errors.js'] = ['path' => 'core/errors.js', 'content' => AI_CANONICAL_ERRORS_JS];
    if (!empty($authInfo['table'])) {
        $authJs = str_replace(
            ['__AUTH_TABLE__', '__AUTH_FIELD__'],
            [$authInfo['table'], $authInfo['field'] ?? 'email'],
            AI_CANONICAL_AUTH_JS
        );
        $byPath['features/auth/auth.js'] = ['path' => 'features/auth/auth.js', 'content' => $authJs];
    } else {
        $byPath['features/auth/auth.js'] = ['path' => 'features/auth/auth.js', 'content' => AI_CANONICAL_AUTH_STUB_JS];
    }
    return array_values($byPath);
}

// Forces every deployed index.html to load core/errors.js before any other
// script, regardless of whether the AI's markup included the tag. This is
// what makes error capture a deploy-time guarantee instead of a prompt-
// compliance hope: an app generated before this feature existed gets the
// tag added automatically on its very next deploy (build or edit), with no
// edit request needed to "pick up" the fix. Idempotent — a re-deploy that
// already has the tag (e.g. the AI added it anyway, or a second deploy of
// the same index.html) is left alone rather than duplicating it.
function ai_ensure_error_script_tag(string $html): string
{
    if (str_contains($html, 'core/errors.js')) return $html;
    $tag = '<script src="./core/errors.js"></script>' . "\n    ";
    if (preg_match('/<script\b/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        return substr($html, 0, $pos) . $tag . substr($html, $pos);
    }
    if (stripos($html, '</head>') !== false) {
        return str_ireplace('</head>', "    {$tag}</head>", $html);
    }
    return $tag . $html;
}

// ─── File-level helpers (filesystem) ────────────────────────────────────────

function ai_deploy_files(
    array $config,
    \SupaBein\Catalog $catalog,
    int $siteId,
    array $project,
    array $frontendFiles,
    bool $mergeFromCurrent = false,
    bool $publishLive = true,
    ?array $authInfo = null
): array {
    $frontendFiles = ai_inject_canonical_frontend_files($frontendFiles, $authInfo);
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

    // Seed from the site's actual live deploy so an edit that returns only
    // some files doesn't blank the rest of the site. A project can sit in
    // staging-only for its whole test-and-fix loop (Review-off builds deploy
    // to staging first; "current" only exists after an explicit Publish), so
    // this must prefer staging over current exactly like ai_effective_deploy_target()
    // elsewhere — merging from a nonexistent "current" here silently drops
    // every file the edit didn't re-output, which then fails the smoke check
    // below and the whole edit apply fails with nothing actually deployed.
    if ($mergeFromCurrent) {
        $mergeSite   = $catalog->getSiteById($siteId) ?? [];
        $mergeTarget = ai_effective_deploy_target($mergeSite);
        $mergeDir    = $sitesPath . '/s' . $siteId . '/' . $mergeTarget;
        if (is_dir($mergeDir)) {
            \SupaBein\Deploy::rcopy($mergeDir, $deployDir);
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

        $rawContent = (string)($fileDef['content'] ?? '');
        if ($relPath === 'index.html') {
            $rawContent = ai_ensure_error_script_tag($rawContent);
        }
        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $rawContent
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

    // Ensure the error-capture script tag on whatever index.html actually
    // ended up in the deploy dir — not just one freshly written this round.
    // An edit job that doesn't touch index.html merges the file forward
    // unchanged from the previous deploy (see $mergeFromCurrent above), so
    // checking only $frontendFiles here would miss every such deploy. Reading
    // back off disk after both the merge and the write loop is what makes
    // this actually unconditional on every deploy, matching the doc comment
    // on ai_ensure_error_script_tag().
    $indexPath = $deployDir . '/index.html';
    if (is_file($indexPath)) {
        $indexHtml = file_get_contents($indexPath);
        $patched   = ai_ensure_error_script_tag((string)$indexHtml);
        if ($patched !== $indexHtml) {
            file_put_contents($indexPath, $patched);
        }
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

    foreach ($delta['seed_data'] ?? [] as $seedTable => $rows) {
        $tn = strtolower((string)$seedTable);
        if (!isset($existing[$tn]) && !isset($newTables[$tn])) {
            return "seed_data targets unknown table \"{$seedTable}\".";
        }
        if (!is_array($rows)) {
            return "seed_data.\"{$seedTable}\" must be an array of row objects.";
        }
    }

    return null;
}

/**
 * Apply-time only (POST /v1/ai/apply, mode=edit) — NOT used during generation.
 * ai_execute_edit() applies add_tables/add_columns one item at a time with no
 * transaction, each independently try/caught; a plan that fails partway
 * through (a deploy error, a bad policy reference later in the same delta)
 * can leave some tables/columns already created even though the overall
 * request came back as a failure. The dashboard's "Retry apply" then resends
 * that SAME plan — which ai_validate_delta() would permanently reject with
 * "already exists", since it can't tell "the AI is confused" apart from "this
 * specific retry already finished this specific piece". Strip anything the
 * delta wants to add that the live schema already has before validating, so
 * a retry finishes whatever didn't land instead of being blocked outright by
 * the part that already did. Only strips exact matches against schema fetched
 * fresh at apply time — a genuinely stale/wrong table or type still gets
 * caught by ai_validate_delta() on whatever's left.
 */
function ai_reconcile_delta_for_apply(array $delta, array $existingSchema, int $projectId): array
{
    $existing = [];
    foreach ($existingSchema['tables'] ?? [] as $t) {
        $tn = strtolower((string)($t['name'] ?? ''));
        if ($tn === '') continue;
        $existing[$tn] = [];
        foreach ($t['columns'] ?? [] as $c) {
            $existing[$tn][strtolower((string)($c['name'] ?? ''))] = true;
        }
    }

    if (!empty($delta['add_tables'])) {
        $kept = [];
        foreach ($delta['add_tables'] as $t) {
            $tn = strtolower((string)($t['name'] ?? ''));
            if ($tn !== '' && isset($existing[$tn])) {
                sb_log('ai_edit', 'apply retry: skipping already-created table', ['project_id' => $projectId, 'table' => $t['name'] ?? '']);
                continue;
            }
            $kept[] = $t;
        }
        $delta['add_tables'] = $kept;
    }

    if (!empty($delta['add_columns'])) {
        $reconciled = [];
        foreach ($delta['add_columns'] as $entry) {
            $tn = strtolower((string)($entry['table'] ?? ''));
            $cols = [];
            foreach ($entry['columns'] ?? [] as $c) {
                $cn = strtolower((string)($c['name'] ?? ''));
                if ($cn !== '' && isset($existing[$tn][$cn])) {
                    sb_log('ai_edit', 'apply retry: skipping already-added column', ['project_id' => $projectId, 'column' => ($entry['table'] ?? '') . '.' . ($c['name'] ?? '')]);
                    continue;
                }
                $cols[] = $c;
            }
            if ($cols) { $entry['columns'] = $cols; $reconciled[] = $entry; }
        }
        $delta['add_columns'] = $reconciled;
    }

    return $delta;
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
/**
 * @param array $refs Reference material from uploaded attachments, shaped
 *   ['attachments' => [{media_type, data_base64}, ...], 'context' => string]
 *   — see ai_prepare_attachments_for_ai(). Defaults to none.
 */
function ai_generate_intent(object $client, string $prompt, array $history = [], array $refs = []): array
{
    $attachments = $refs['attachments'] ?? [];
    $promptWithCtx = $prompt . (($refs['context'] ?? '') !== '' ? "\n\n" . $refs['context'] : '');
    $systemPrompt = AI_INTENT_PROMPT . ($attachments || !empty($refs['context']) ? ai_attachment_instruction_note() : '');

    $call = static function (string $user) use ($client, $history, $attachments, $systemPrompt) {
        return $history
            ? $client->generateJsonWithHistory($systemPrompt, $history, $user, $attachments)
            : $client->generateJson($systemPrompt, $user, $attachments);
    };

    $intent = $call($promptWithCtx);
    $err = ai_validate_intent($intent);
    if ($err) {
        $intent = $call($promptWithCtx . "\n\nYour previous response was rejected: " . $err . "\nReturn ONLY the JSON structure specified, obeying the hard limits.");
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

// Record which rows were inserted by AI seeding (build's initial seed_data or
// an on-demand edit-mode seed request) so they — and only they — can later be
// removed by "clear seed data" without touching real user-entered rows.
function ai_track_seed_rows(\PDO $pdo, int $projectId, string $tableName, array $insertedIds): void
{
    if (!$insertedIds) return;
    $stmt = $pdo->prepare(
        'INSERT INTO project_seed_rows (project_id, table_name, row_id) VALUES (?, ?, ?)'
    );
    foreach ($insertedIds as $id) {
        try { $stmt->execute([$projectId, $tableName, (int)$id]); }
        catch (\Throwable $e) { sb_log('ai_seed', 'Seed row tracking failed (non-fatal): ' . $e->getMessage()); }
    }
}

// An LLM asked for an image/photo/avatar URL reliably invents a plausible-
// looking but nonexistent one (live-caught: "https://images.example.com/
// blog.jpg" — images.example.com is the RFC 2606 reserved domain that is
// GUARANTEED to never resolve to anything) rather than admit it can't
// actually know a real photo's address. Anchored on a preceding "_" or
// start-of-string so a real column like "discovery_notes" (contains "cover"
// as a raw substring) or "recover_token" never false-positives.
function ai_is_image_like_column(string $colName): bool
{
    return (bool)preg_match('/(^|_)(image|photo|avatar|thumbnail|banner|logo|picture|cover|img)(_url)?$/i', $colName);
}

// Picsum (picsum.photos) is a real, always-up photo CDN — seeding the URL
// deterministically from the table name + row identity (not randomly) means
// re-seeding the same project doesn't churn every image on each run, and two
// different rows in the same table never collide on the same photo.
function ai_real_seed_image_url(string $table, $rowKey): string
{
    $seed = preg_replace('/[^a-z0-9]+/i', '-', strtolower($table)) . '-' . $rowKey;
    return 'https://picsum.photos/seed/' . rawurlencode($seed) . '/800/600';
}

// Shared seed_data-block inserter used by both build (initial seed) and edit
// (on-demand "seed N fake rows" requests) — inserts rows and tracks their ids.
// $excludeTables skips tables handled separately (e.g. the auth table, which
// the on-demand "Seed App" flow seeds itself via ai_seed_test_accounts() so
// passwords get properly hashed instead of inserted as unusable plaintext).
function ai_insert_seed_data(\PDO $pdo, \SupaBein\Catalog $catalog, int $projectId, array $seedData, array $excludeTables = []): array
{
    $excludeTables = array_map('strtolower', $excludeTables);
    $seeded = [];
    foreach ($seedData as $seedTable => $rows) {
        if (!is_array($rows) || empty($rows)) continue;
        if (in_array(strtolower((string)$seedTable), $excludeTables, true)) continue;
        $tbl = $catalog->getTable($projectId, (string)$seedTable);
        if (!$tbl) continue;

        $physical  = $tbl['physical_name'];
        $imageCols = array_values(array_filter(
            array_column($catalog->listColumns((int)$tbl['id']), 'name'),
            'ai_is_image_like_column'
        ));
        $insertedIds = [];
        $rowIndex = 0;
        foreach (array_slice($rows, 0, 50) as $row) {
            if (!is_array($row) || empty($row)) continue;
            unset($row['id'], $row['created_at']);
            if (empty($row)) continue;
            $rowIndex++;

            // Never trust whatever the model put here (or left null/absent) —
            // always a real, working photo, regardless. This is what makes a
            // broken seeded image structurally impossible rather than merely
            // less likely: the model's own guess for this field is discarded
            // unconditionally, not validated or spot-corrected.
            foreach ($imageCols as $col) {
                $row[$col] = ai_real_seed_image_url((string)$seedTable, $rowIndex);
            }

            $cols         = array_keys($row);
            $colList      = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            try {
                $pdo->prepare("INSERT INTO `{$physical}` ({$colList}) VALUES ({$placeholders})")
                    ->execute(array_values($row));
                $insertedIds[] = (int)$pdo->lastInsertId();
            } catch (\Throwable $e) {
                sb_log('ai_seed', 'Seed insert failed (non-fatal): ' . $e->getMessage(), ['table' => $seedTable]);
            }
        }
        if ($insertedIds) {
            ai_track_seed_rows($pdo, $projectId, (string)$seedTable, $insertedIds);
            $seeded[] = "{$seedTable}: " . count($insertedIds) . ' row' . (count($insertedIds) !== 1 ? 's' : '');
        }
    }
    return $seeded;
}

// Retroactive counterpart to the write-time fix in ai_insert_seed_data() above
// — that fix only stops a NEW broken image URL from being written, it can't
// undo one already sitting in the database from before this shipped. Scoped
// via project_seed_rows (the same table "clear seed data" uses) so this only
// ever touches rows the platform itself seeded, never a real user's own
// uploaded/entered data. Returns the number of values actually replaced.
function ai_heal_seed_image_urls(\PDO $pdo, \SupaBein\Catalog $catalog, int $projectId): int
{
    $healed = 0;
    $tableStmt = $pdo->prepare('SELECT DISTINCT table_name FROM project_seed_rows WHERE project_id = ?');
    $tableStmt->execute([$projectId]);

    foreach ($tableStmt->fetchAll(\PDO::FETCH_COLUMN) as $tableName) {
        $tbl = $catalog->getTable($projectId, (string)$tableName);
        if (!$tbl) continue;
        $imageCols = array_values(array_filter(
            array_column($catalog->listColumns((int)$tbl['id']), 'name'),
            'ai_is_image_like_column'
        ));
        if (!$imageCols) continue;
        $physical = $tbl['physical_name'];

        $rowStmt = $pdo->prepare('SELECT row_id FROM project_seed_rows WHERE project_id = ? AND table_name = ?');
        $rowStmt->execute([$projectId, $tableName]);
        foreach ($rowStmt->fetchAll(\PDO::FETCH_COLUMN) as $rowId) {
            foreach ($imageCols as $col) {
                try {
                    $cur = $pdo->prepare("SELECT `{$col}` FROM `{$physical}` WHERE id = ?");
                    $cur->execute([$rowId]);
                    $value = $cur->fetchColumn();
                    if ($value !== false && $value !== null && str_starts_with((string)$value, 'https://picsum.photos/')) {
                        continue; // already a real, working URL — nothing to heal
                    }
                    $pdo->prepare("UPDATE `{$physical}` SET `{$col}` = ? WHERE id = ?")
                        ->execute([ai_real_seed_image_url((string)$tableName, $rowId), $rowId]);
                    $healed++;
                } catch (\Throwable $e) {
                    sb_log('ai_seed', 'Seed image heal failed (non-fatal): ' . $e->getMessage(), ['table' => $tableName, 'row' => $rowId]);
                }
            }
        }
    }
    return $healed;
}

// A policy's constraint_sql is stored and executed verbatim (QueryBuilder
// just interpolates it into the WHERE clause — see app/core/query_builder.php
// and Policy::resolveConstraint, which only substitutes :current_user_id).
// The AI only ever knows tables by their LOGICAL name, so a completely normal
// row-level-security pattern — "visible to the user who owns the related
// program" — comes back as a subquery like
// "id IN (SELECT project_id FROM project_assignments WHERE ...)", written
// against the logical name. The real MySQL table is project-prefixed
// (p{id}_project_assignments), so that subquery 500s the instant it runs.
// Live-caught: a generated app's /projects, /enrollments, and
// /project_applications pages all 500'd this exact way. Rewriting any
// "FROM <logical>" / "JOIN <logical>" reference in the stored SQL to the real
// physical name, once, at write time, means every future read of this policy
// executes correctly forever — no per-request rewriting needed.
function ai_rewrite_constraint_table_refs(?string $sql, int $projectId, array $logicalNames): ?string
{
    if ($sql === null || $sql === '') return $sql;
    foreach ($logicalNames as $name) {
        $name = (string)$name;
        if ($name === '') continue;
        $physical = 'p' . $projectId . '_' . strtolower($name);
        $sql = preg_replace(
            '/\b(FROM|JOIN)\s+' . preg_quote($name, '/') . '\b/i',
            '$1 `' . $physical . '`',
            $sql
        );
    }
    return $sql;
}

// Applies ai_rewrite_constraint_table_refs() retroactively to every existing
// policy in a project, persisting any change. Closes the gap that fix alone
// can't: it only prevents a *new* broken constraint_sql from being written,
// it can't undo one written before it shipped. A project built earlier than
// this fix stays broken forever unless something goes back and repairs its
// already-stored policies — every edit is a natural, low-cost point to do
// that, since the full table list and every policy are already being loaded
// for the edit anyway. Returns the number of policies actually changed.
function ai_heal_project_policy_refs(int $projectId, \SupaBein\Catalog $catalog, array $tables): int
{
    $logicalNames = array_column($tables, 'name');
    $healed = 0;
    foreach ($tables as $t) {
        $tbl = $catalog->getTable($projectId, $t['name']);
        if (!$tbl) continue;
        foreach ($catalog->listPolicies((int)$tbl['id']) as $p) {
            if (empty($p['constraint_sql'])) continue;
            $fixed = ai_rewrite_constraint_table_refs($p['constraint_sql'], $projectId, $logicalNames);
            if ($fixed !== $p['constraint_sql']) {
                $catalog->upsertPolicy((int)$tbl['id'], $p['api_role'], $p['operation'], (bool)$p['allowed'], $fixed);
                $healed++;
            }
        }
    }
    return $healed;
}

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

    // The column an app logs in by (email/username/etc., paired with the
    // PASSWORD column) must be UNIQUE at the DB level -- without it, nothing
    // stops the same identifier from being registered twice, and login-by-
    // identifier becomes ambiguous the moment it happens. Detected once
    // against the whole plan since it's the same table/field for every
    // table in this build.
    $authField = ai_detect_auth($plan);

    // See ai_rewrite_constraint_table_refs() — this build's own table names
    // are the full set any policy in it could plausibly reference.
    $allTableNames = array_column($plan['tables'], 'name');

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
                'unique'   => $tableName === $authField['table'] && $col['name'] === $authField['field'],
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
            $catalog->addColumn($table['id'], $col['name'], $col['type'], $col['nullable'], $col['default'], $col['unique'] ?? false);
        }

        foreach ($tableDef['policies'] ?? [] as $policy) {
            try {
                $catalog->upsertPolicy(
                    $table['id'],
                    $policy['api_role'],
                    strtoupper($policy['operation']),
                    (bool)$policy['allowed'],
                    ai_rewrite_constraint_table_refs($policy['constraint_sql'] ?? null, $projectId, $allTableNames)
                );
            } catch (\Throwable $e) {
                sb_log('ai_build', 'Policy upsert failed (non-fatal): ' . $e->getMessage(), ['table' => $tableName]);
            }
        }
        $catalog->backfillAuthenticatedAccess($table['id']);

        $partial['tables'][] = ['name' => $tableName, 'columns' => count($columns)];
    }

    // ── Seed data insertion ───────────────────────────────────────────────────
    if (!empty($plan['seed_data']) && is_array($plan['seed_data'])) {
        ai_insert_seed_data($pdo, $catalog, $projectId, $plan['seed_data']);
    }

    $subdomain = $plan['subdomain'];
    $site      = null;
    $deploy    = null;

    try {
        $site = $catalog->createSite($projectId, $subdomain, true);
        $catalog->syncSiteRegistry($site, $projectId);
        $partial['site'] = $site;
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            $subdomain = $subdomain . '-' . $projectId;
            try {
                $site = $catalog->createSite($projectId, $subdomain, true);
                $catalog->syncSiteRegistry($site, $projectId);
                $partial['site'] = $site;
            } catch (\PDOException $e2) {
                sb_log('ai_build', 'Site creation failed (non-fatal): ' . $e2->getMessage());
            }
        } else {
            sb_log('ai_build', 'Site creation failed (non-fatal): ' . $e->getMessage());
        }
    }

    $staging = null;
    if ($site !== null && !empty($plan['frontend']['files'])) {
        // Builds deploy to STAGING (preview), same as edits — the user
        // publishes to live explicitly via the Publish button, after
        // reviewing/testing the staged result.
        $deployResult = ai_deploy_files(
            $config,
            $catalog,
            (int)$site['id'],
            $project,
            $plan['frontend']['files'],
            false,
            false,
            ai_detect_auth($plan)
        );
        if ($deployResult['error']) {
            sb_log('ai_build', 'Deploy failed (non-fatal): ' . $deployResult['error']);
        } else {
            $deploy = $deployResult['deploy'];
            $apiBase = rtrim($config['API_BASE_URL'] ?? '', '/');
            $appBase = preg_replace('#/(api|v\d+)(/.*)?$#i', '', $apiBase);
            $staging = [
                'project_id'    => $projectId,
                'site_id'       => (int)$site['id'],
                'deploy_id'     => (int)$deploy['id'],
                'staging_url'   => $appBase . '/sites/s' . $site['id'] . '/staging/',
                'subdomain'     => $site['subdomain'] ?? null,
                'custom_domain' => $site['custom_domain'] ?? null,
            ];
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
        'staging' => $staging,
    ];
}

function ai_execute_edit(array $delta, int $projectId, int $userId): array
{
    $catalog = \SupaBein\Catalog::getInstance();
    $pdo     = \App::get('db');

    $addedTables     = [];
    $addedColumns    = [];
    $updatedPolicies = [];

    // See ai_rewrite_constraint_table_refs() — a policy added or changed by
    // this edit can reference any table already in the project OR one being
    // added in this same delta.
    $allTableNames = array_column($catalog->listTables($projectId), 'table_name');
    foreach ($delta['add_tables'] ?? [] as $t) {
        if (!empty($t['name'])) $allTableNames[] = (string)$t['name'];
    }

    foreach ($delta['add_tables'] ?? [] as $tableDef) {
        try { \SupaBein\Schema::validateIdentifier($tableDef['name'] ?? ''); }
        catch (\InvalidArgumentException $e) { continue; }

        // Same reasoning as ai_execute_build(): if this new table is the one
        // introducing auth (a PASSWORD column), its login identifier column
        // must be UNIQUE. Scoped to just this table's own definition since
        // that's all ai_detect_auth() needs to find it.
        $authField = ai_detect_auth(['tables' => [$tableDef]]);

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
                    'unique'   => $colName === $authField['field'],
                ];
            } catch (\InvalidArgumentException $e) { continue; }
        }

        try {
            $table = $catalog->createTable($projectId, $tableDef['name']);
            $ddl   = \SupaBein\Schema::createTableDDL($table['physical_name'], $columns);
            \SupaBein\Schema::applyDDL($pdo, $projectId, $ddl);
            foreach ($columns as $col) {
                $catalog->addColumn($table['id'], $col['name'], $col['type'], $col['nullable'], $col['default'], $col['unique'] ?? false);
            }
            foreach ($tableDef['policies'] ?? [] as $p) {
                try {
                    $catalog->upsertPolicy(
                        $table['id'],
                        $p['api_role'],
                        strtoupper($p['operation']),
                        (bool)$p['allowed'],
                        ai_rewrite_constraint_table_refs($p['constraint_sql'] ?? null, $projectId, $allTableNames)
                    );
                } catch (\Throwable $e) {}
            }
            $catalog->backfillAuthenticatedAccess($table['id']);
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
                $unique   = (bool)($col['unique'] ?? false);

                $physicalTable = $tbl['physical_name'];
                $ddl = \SupaBein\Schema::addColumnDDL($physicalTable, [
                    'name' => $colName, 'type' => $colType, 'nullable' => $nullable, 'unique' => $unique,
                ]);
                \SupaBein\Schema::applyDDL($pdo, $projectId, $ddl);
                $catalog->addColumn($tbl['id'], $colName, $colType, $nullable, null, $unique);
                $addedColumns[] = $tblName . '.' . $colName;
            } catch (\Throwable $e) {
                sb_log('ai_edit', 'add_column failed: ' . $e->getMessage());
            }
        }
    }

    $policyTouchedTableIds = [];
    foreach ($delta['update_policies'] ?? [] as $p) {
        $tblName = $p['table'] ?? '';
        $tbl = $catalog->getTable($projectId, $tblName);
        if (!$tbl) continue;
        try {
            $catalog->upsertPolicy(
                $tbl['id'],
                $p['api_role'],
                strtoupper($p['operation']),
                (bool)$p['allowed'],
                ai_rewrite_constraint_table_refs($p['constraint_sql'] ?? null, $projectId, $allTableNames)
            );
            $updatedPolicies[] = $tblName . '.' . $p['api_role'] . '.' . $p['operation'];
            $policyTouchedTableIds[$tbl['id']] = true;
        } catch (\Throwable $e) {
            sb_log('ai_edit', 'policy update failed: ' . $e->getMessage());
        }
    }
    foreach (array_keys($policyTouchedTableIds) as $touchedTableId) {
        $catalog->backfillAuthenticatedAccess((int)$touchedTableId);
    }

    $seeded = [];
    if (!empty($delta['seed_data']) && is_array($delta['seed_data'])) {
        $seeded = ai_insert_seed_data($pdo, $catalog, $projectId, $delta['seed_data']);
    }

    sb_log('ai_edit', 'Complete', ['project_id' => $projectId, 'added_tables' => count($addedTables)]);

    return [
        'added_tables'     => $addedTables,
        'added_columns'    => $addedColumns,
        'updated_policies' => $updatedPolicies,
        'seeded'           => $seeded,
    ];
}

// ─── Frontend file reader ────────────────────────────────────────────────────

// Review-off ("watch only") builds deploy to staging first and only promote to
// the "current" (live/published) slot on an explicit Publish click — so a
// project can sit in staging-only for its entire test-and-fix loop. Anything
// that needs to read "what's actually deployed right now" (edit-mode context,
// validation merges) must prefer staging over current, exactly like
// ai_run_project_tests() already does when picking what to test against —
// otherwise it silently reads an empty/nonexistent "current" deploy on any
// project that hasn't been published yet.
function ai_effective_deploy_target(array $site): string
{
    if ($site['staging_deploy_id'] ?? null) return 'staging';
    return 'current';
}

// Full, unfiltered file dump for the validator (an edit's delta only contains
// CHANGED files, so validating it alone would false-positive on every route/
// nav check that depends on a file the edit didn't touch — this reads the
// complete current deploy so the delta can be merged onto it before validating,
// mirroring exactly what ai_deploy_files($mergeFromCurrent=true) does on disk).
function ai_read_full_frontend_files(array $config, \SupaBein\Catalog $catalog, int $projectId, ?string $target = null): array
{
    $sites = $catalog->listSites($projectId);
    if (empty($sites)) return [];

    $site   = $sites[0];
    $target = $target ?? ai_effective_deploy_target($site);
    $deployIdKey = $target === 'staging' ? 'staging_deploy_id' : 'current_deploy_id';
    if (!($site[$deployIdKey] ?? null)) return [];

    $sitesPath  = rtrim($config['SITES_PATH'], '/');
    $currentDir = $sitesPath . '/s' . $site['id'] . '/' . $target;
    if (!is_dir($currentDir)) return [];

    $textExts = ['html', 'css', 'js', 'json'];
    $files    = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($currentDir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if (!in_array(strtolower($file->getExtension()), $textExts, true)) continue;
        $rel = ltrim(substr($file->getPathname(), strlen($currentDir)), '/');
        $files[] = ['path' => $rel, 'content' => (string)file_get_contents($file->getPathname())];
    }
    return $files;
}

function ai_read_frontend_files(array $config, \SupaBein\Catalog $catalog, int $projectId, string $prompt = ''): string
{
    $sites = $catalog->listSites($projectId);
    if (empty($sites)) return '';

    $site        = $sites[0];
    $target      = ai_effective_deploy_target($site);
    $deployIdKey = $target === 'staging' ? 'staging_deploy_id' : 'current_deploy_id';
    if (!($site[$deployIdKey] ?? null)) return '';

    $sitesPath  = rtrim($config['SITES_PATH'], '/');
    $currentDir = $sitesPath . '/s' . $site['id'] . '/' . $target;
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

const AI_ALLOWED_PROVIDERS = ['gemini', 'openrouter', 'nvidia', 'anthropic'];
const AI_ALLOWED_MODELS = [
    'gemini' => [
        'gemini-2.5-flash',
    ],
    'anthropic' => [
        'claude-opus-4-8',
        'claude-sonnet-5',
    ],
    // Ordered best-to-least capable within each provider (index 0 is also that
    // provider's fallback default when an unrecognized model is requested).
    'openrouter' => [
        'moonshotai/kimi-k2',
        'nvidia/nemotron-3-super-120b-a12b:free',
        'openai/gpt-oss-120b:free',
        'poolside/laguna-m.1:free',
        'cohere/north-mini-code:free',
        'mistralai/mistral-small-3.2-24b-instruct',
        'nex-agi/nex-n2-pro',
        'google/gemma-4-26b-a4b-it:free',
        'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
        'openai/gpt-oss-20b:free',
        'poolside/laguna-xs.2:free',
    ],
    'nvidia' => [
        'nvidia/nemotron-3-ultra-550b-a55b',
        'z-ai/glm-5.2',
        'deepseek-ai/deepseek-v4-pro',
        'qwen/qwen3.5-122b-a10b',
        'deepseek-ai/deepseek-v4-flash',
    ],
];

// Builds exactly one raw provider client for one specific (provider, model).
// Only ever called (a) directly, for the simple single-provider case, or
// (b) from FallbackAiClient against candidates ai_build_fallback_chain()
// already filtered to providers with a configured key — never speculatively
// against an unconfigured one, since abort() below is a hard, uncatchable
// process exit (: never), not a throwable a try/catch could react to.
function ai_make_single_client(array $config, ?string $provider, ?string $model): object
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

    if ($provider === 'anthropic') {
        $key = $config['ANTHROPIC_API_KEY'] ?? '';
        if (!$key) abort(503, 'Anthropic API key not configured on this server');
        $allowed = AI_ALLOWED_MODELS['anthropic'];
        $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
        return new \SupaBein\AnthropicClient($key, $model);
    }

    // Default: Gemini
    $key = $config['GEMINI_API_KEY'] ?? '';
    if (!$key) abort(503, 'AI build is not configured on this server (missing GEMINI_API_KEY)');
    $allowed = AI_ALLOWED_MODELS['gemini'];
    $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
    return new \SupaBein\GeminiClient($key, $model);
}

function ai_provider_configured(array $config, string $provider): bool
{
    return match ($provider) {
        'openrouter' => !empty($config['OPENROUTER_API_KEY']),
        'nvidia'     => !empty($config['NVIDIA_API_KEY']),
        'anthropic'  => !empty($config['ANTHROPIC_API_KEY']),
        'gemini'     => !empty($config['GEMINI_API_KEY']),
        default      => false,
    };
}

// Builds the ordered list of (provider, model) candidates a FallbackAiClient
// will try in turn. When the caller has an explicit preference — every real
// dashboard request does, via the model selector's getSelectedModel() —
// that candidate is the ONLY one returned: no silent cross-provider
// fallback. A user who picked a specific model expects that model to
// either do the job or visibly fail so they can switch and retry
// themselves (the dashboard's existing failed-job error + Retry button
// already re-reads the current selector on retry — this is the one piece
// that was missing). Falling back through every other provider/model
// combination behind their back means a "Gemini out of quota" or "NVIDIA
// insufficient credits" failure is invisible: the job just quietly
// finishes on a completely different model than the one they chose.
// Only when NO preference is given at all (preferredProvider is null) does
// this fall through to the old best-effort tier-by-tier default, for any
// caller that genuinely has no user-facing selection to honor. Never
// includes a provider with no configured key (see ai_make_single_client()'s
// doc comment for why that matters here).
function ai_build_fallback_chain(array $config, ?string $preferredProvider, ?string $preferredModel): array
{
    $chain = [];
    $seen  = [];
    $add = function (string $provider, string $model) use (&$chain, &$seen, $config): void {
        if (!ai_provider_configured($config, $provider)) return;
        $key = $provider . ':' . $model;
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $chain[] = ['provider' => $provider, 'model' => $model];
    };

    if ($preferredProvider !== null && in_array($preferredProvider, AI_ALLOWED_PROVIDERS, true)) {
        $models = AI_ALLOWED_MODELS[$preferredProvider] ?? [];
        $model  = ($preferredModel !== null && in_array($preferredModel, $models, true)) ? $preferredModel : ($models[0] ?? null);
        if ($model !== null) $add($preferredProvider, $model);
        return $chain;
    }

    $maxTier = max(array_map('count', AI_ALLOWED_MODELS));
    for ($tier = 0; $tier < $maxTier; $tier++) {
        foreach (AI_ALLOWED_PROVIDERS as $provider) {
            $models = AI_ALLOWED_MODELS[$provider] ?? [];
            if (isset($models[$tier])) $add($provider, $models[$tier]);
        }
    }

    return $chain;
}

// New public entry point — every existing caller of make_ai_client() gets
// automatic cross-provider/cross-model fallback for free, with no changes of
// their own, since FallbackAiClient exposes the exact same generateJson /
// generateJsonWithHistory / getLastUsage surface every raw client already did.
function make_ai_client(array $config, ?string $provider, ?string $model): object
{
    $chain = ai_build_fallback_chain($config, $provider, $model);
    if (!$chain) {
        abort(503, 'No AI provider is configured on this server');
    }
    return new \SupaBein\FallbackAiClient($config, $chain);
}

// ─── Reference file attachments (build/edit prompts) ─────────────────────────
// Lets a build/edit request carry real reference material (a logo to match
// exactly, a sample document/screenshot to build a schema or UI from)
// instead of relying entirely on the AI inventing plausible-looking
// placeholders from a text description alone.

// Binary types sent to the AI as true multimodal attachments (images/PDF —
// see ai_prepare_attachments_for_ai()); a .docx is unpacked server-side
// since no provider accepts it directly. Everything in
// AI_ATTACHMENT_TEXT_MIME is plain-text-ish and needs no extraction at all —
// its bytes ARE the content, just decoded and dropped straight into the
// prompt as context.
const AI_ATTACHMENT_BINARY_MIME = [
    'image/png', 'image/jpeg', 'image/webp', 'image/gif',
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
];
const AI_ATTACHMENT_TEXT_MIME = [
    'text/plain', 'text/markdown', 'text/html', 'text/csv', 'application/json',
];
const AI_ATTACHMENT_ALLOWED_MIME = [...AI_ATTACHMENT_BINARY_MIME, ...AI_ATTACHMENT_TEXT_MIME];

const AI_ATTACHMENT_MAX_COUNT       = 8;
const AI_ATTACHMENT_MAX_BYTES_EACH  = 15 * 1024 * 1024; // 15MB per file
const AI_ATTACHMENT_MAX_BYTES_TOTAL = 40 * 1024 * 1024; // 40MB combined
const AI_ATTACHMENT_TEXT_CHARS_CAP  = 12000; // per text/plain-ish file, in the prompt itself

/**
 * Validates and decodes the `attachments` field of an /v1/ai/build or
 * /v1/ai/edit request body:
 *   attachments: [{ filename, mime_type, data_base64 }, ...]
 * Aborts with 422 on anything invalid rather than silently dropping a file
 * the caller thinks was included.
 *
 * @return array<int, array{filename:string, mime_type:string, bytes:string}>
 */
function ai_validate_attachments($raw): array
{
    if ($raw === null || $raw === []) return [];
    if (!is_array($raw)) abort(422, 'attachments must be an array');
    if (count($raw) > AI_ATTACHMENT_MAX_COUNT) {
        abort(422, 'Too many attachments (max ' . AI_ATTACHMENT_MAX_COUNT . ')');
    }

    $out = [];
    $totalBytes = 0;
    foreach (array_values($raw) as $i => $item) {
        if (!is_array($item)) abort(422, "attachments[$i] must be an object");
        $mime = (string)($item['mime_type'] ?? '');
        $b64  = (string)($item['data_base64'] ?? '');
        $name = trim((string)($item['filename'] ?? '')) ?: "attachment-$i";
        if (!in_array($mime, AI_ATTACHMENT_ALLOWED_MIME, true)) {
            abort(422, "attachments[$i] (\"$name\"): unsupported file type \"$mime\" — allowed: PNG/JPEG/WEBP/GIF images, "
                . 'PDF, .docx, or plain text (txt/markdown/html/csv/json)');
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            abort(422, "attachments[$i] (\"$name\"): data_base64 is missing or not valid base64");
        }
        if (strlen($bytes) > AI_ATTACHMENT_MAX_BYTES_EACH) {
            abort(422, "attachments[$i] (\"$name\") exceeds the " . (AI_ATTACHMENT_MAX_BYTES_EACH / 1024 / 1024) . 'MB per-file limit');
        }
        $totalBytes += strlen($bytes);
        if ($totalBytes > AI_ATTACHMENT_MAX_BYTES_TOTAL) {
            abort(422, 'Attachments exceed the ' . (AI_ATTACHMENT_MAX_BYTES_TOTAL / 1024 / 1024) . 'MB combined limit');
        }
        $out[] = ['filename' => $name, 'mime_type' => $mime, 'bytes' => $bytes];
    }
    return $out;
}

/**
 * Unpacks a .docx (it's a zip archive) to pull out its embedded media
 * (logos, watermarks, photos — word/media/*) as images, and its visible
 * paragraph text (word/document.xml's <w:t> runs) as plain text. Word text
 * boxes (<w:txbxContent>) aren't specially walked — some real-world letter
 * templates carry their actual body copy inside one, which this can't see
 * any more than the schema/frontend prompts could reason about full OOXML
 * layout either way — but the embedded images (the part that actually
 * matters most for visual fidelity: logos, watermarks, letterhead art) are
 * always plain files in the zip regardless of whether they're referenced
 * from a text box or the main document body, so those are never missed.
 *
 * @return array{images: array<int, array{media_type:string, data_base64:string}>, text: string}
 */
function ai_extract_docx(string $bytes, string $filename): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'sb_docx_');
    file_put_contents($tmp, $bytes);

    $images = [];
    $text   = '';
    $zip = new \ZipArchive();
    if ($zip->open($tmp) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false || count($images) >= 6) continue;
            if (!preg_match('#^word/media/[^/]+\.(png|jpe?g|gif|webp)$#i', $entry, $m)) continue;
            $data = $zip->getFromIndex($i);
            if ($data === false) continue;
            $mime = match (strtolower($m[1])) {
                'png'          => 'image/png',
                'jpg', 'jpeg'  => 'image/jpeg',
                'gif'          => 'image/gif',
                'webp'         => 'image/webp',
                default        => null,
            };
            if ($mime === null) continue;
            $images[] = ['media_type' => $mime, 'data_base64' => base64_encode($data)];
        }
        $docXml = $zip->getFromName('word/document.xml');
        if ($docXml !== false && preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $docXml, $m)) {
            $text = trim(implode(' ', array_map(
                fn($s) => html_entity_decode($s, ENT_QUOTES | ENT_XML1),
                $m[1]
            )));
            $text = mb_substr($text, 0, AI_ATTACHMENT_TEXT_CHARS_CAP);
        }
        $zip->close();
    }
    @unlink($tmp);

    return ['images' => $images, 'text' => $text];
}

/**
 * Turns validated request attachments into what the AI clients can actually
 * consume:
 *   - images/PDF pass straight through as multimodal attachments (so the AI
 *     can SEE them — match colors, read a layout, etc.)
 *   - a directly-uploaded image is ALSO persisted as a real project asset
 *     (see below) so it can be used as-is (a logo, a photo) rather than
 *     just looked at for inspiration
 *   - .docx has no direct multimodal API support anywhere, so it's unpacked
 *     instead (see ai_extract_docx()) — its embedded images are treated as
 *     reference material only, not uploaded as standalone assets, since an
 *     image buried inside a reference document is rarely "the logo" itself
 *   - plain-text-ish files (txt/markdown/html/csv/json) need no extraction
 *     at all — their bytes ARE the content, decoded as UTF-8 and dropped
 *     straight into the prompt as context, capped per-file so one huge
 *     upload can't blow out the whole prompt
 *
 * Persisting an uploaded image as a real asset needs a project to store it
 * under. For an edit, $projectId is already known, so it's uploaded
 * immediately and the AI is told its real URL. For a fresh build, no
 * project exists yet — the file is staged in the returned 'pending_assets'
 * list instead, and the AI is told the URL it WILL have (using the same
 * __SB_PID__ placeholder ai_deploy_files() already substitutes into every
 * deployed file), so the caller can actually write the bytes once
 * ai_execute_build() creates the real project (see ai_run_build_and_deploy()).
 *
 * @param array<int, array{filename:string, mime_type:string, bytes:string}> $validated
 * @return array{
 *   attachments: array<int, array{media_type:string, data_base64:string}>,
 *   context: string,
 *   pending_assets: array<int, array{filename:string, bytes:string}>
 * }
 */
function ai_prepare_attachments_for_ai(array $validated, ?int $projectId = null): array
{
    $attachments    = [];
    $contextParts   = [];
    $pendingAssets  = [];
    $usedNames      = [];
    $assetLines     = [];

    foreach ($validated as $item) {
        if ($item['mime_type'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            $extracted = ai_extract_docx($item['bytes'], $item['filename']);
            foreach ($extracted['images'] as $img) $attachments[] = $img;
            if ($extracted['text'] !== '') {
                $contextParts[] = "--- Text extracted from \"{$item['filename']}\" ---\n" . $extracted['text'];
            }
            continue;
        }

        if (in_array($item['mime_type'], AI_ATTACHMENT_TEXT_MIME, true)) {
            $text = mb_substr(
                mb_convert_encoding($item['bytes'], 'UTF-8', 'UTF-8, ISO-8859-1'),
                0,
                AI_ATTACHMENT_TEXT_CHARS_CAP
            );
            if (trim($text) !== '') {
                $contextParts[] = "--- Contents of \"{$item['filename']}\" ({$item['mime_type']}) ---\n" . $text;
            }
            continue;
        }

        $attachments[] = ['media_type' => $item['mime_type'], 'data_base64' => base64_encode($item['bytes'])];

        if (str_starts_with($item['mime_type'], 'image/')) {
            $assetName = ai_dedupe_asset_filename($item['filename'], $item['mime_type'], $usedNames);
            if ($projectId !== null) {
                $stored = \SupaBein\Storage::putBytes($projectId, 'assets', $assetName, $item['bytes']);
                $assetLines[] = "- \"{$item['filename']}\" is now stored at: {$stored['url']}";
            } else {
                $pendingAssets[] = ['filename' => $assetName, 'bytes' => $item['bytes']];
                $assetLines[] = "- \"{$item['filename']}\" WILL be stored at: /api/v1/storage/__SB_PID__/assets/{$assetName} "
                    . '(write that exact literal path — including __SB_PID__ verbatim — into your generated code; '
                    . 'it is substituted with the real project ID automatically at deploy time)';
            }
        }
    }

    if ($assetLines) {
        $contextParts[] = "--- Uploaded image files (real, working assets — not just visual reference) ---\n"
            . implode("\n", $assetLines)
            . "\nIf the request implies using one of these directly (e.g. \"use this as the logo\"), reference "
            . 'its exact URL above in your generated code (an <img> src, a CSS background-image, etc.) instead '
            . 'of inventing a placeholder image or a different source.';
    }

    return ['attachments' => $attachments, 'context' => implode("\n\n", $contextParts), 'pending_assets' => $pendingAssets];
}

/** Sanitizes an original filename into a safe, collision-free asset filename within one request's scope. */
function ai_dedupe_asset_filename(string $originalFilename, string $mimeType, array &$usedNames): string
{
    $ext = match ($mimeType) {
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'bin',
    };
    $base = strtolower(pathinfo($originalFilename, PATHINFO_FILENAME));
    $base = trim(preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '', '-');
    if ($base === '') $base = 'asset';

    $name = "{$base}.{$ext}";
    $i = 2;
    while (isset($usedNames[$name])) {
        $name = "{$base}-{$i}.{$ext}";
        $i++;
    }
    $usedNames[$name] = true;
    return $name;
}

/**
 * Writes out the images ai_prepare_attachments_for_ai() staged as
 * 'pending_assets' (uploaded before a project existed) now that a real
 * project ID is available — see the doc comment on ai_prepare_attachments_for_ai().
 *
 * @param array<int, array{filename:string, bytes:string}> $pendingAssets
 */
function ai_upload_pending_assets(array $pendingAssets, int $projectId): void
{
    foreach ($pendingAssets as $asset) {
        try {
            \SupaBein\Storage::putBytes($projectId, 'assets', $asset['filename'], $asset['bytes']);
        } catch (\Throwable $e) {
            // Best-effort — a failed asset write shouldn't fail the whole
            // build/apply when the schema, tables, and rest of the frontend
            // already deployed successfully. The generated code's __SB_PID__
            // URL just 404s for this one file if this happens.
            sb_log('ai_build', 'Pending asset upload failed: ' . $e->getMessage(), ['project_id' => $projectId, 'filename' => $asset['filename']]);
        }
    }
}

/** Appended to a system prompt only when the request actually carries attachments. */
function ai_attachment_instruction_note(): string
{
    return "\n\nATTACHMENTS: One or more reference files were uploaded with this request (attached as images/"
        . 'documents, and/or extracted as text below). Extract concrete details from them — exact colors, '
        . 'logos, layout, field names, exact wording — and use those details directly instead of inventing '
        . 'generic placeholders. Do not describe the attachments back to the user; just build to match them.';
}

/**
 * Validates `attachments` from a job-creating route body and re-encodes it
 * into the plain-JSON shape a job's LONGTEXT payload column can hold (the
 * raw decoded bytes ai_validate_attachments() returns aren't JSON-safe).
 * The worker reverses this via ai_job_payload_refs() right before it
 * actually needs the attachments.
 */
function ai_validate_attachments_for_job($raw): array
{
    return array_map(
        fn($a) => ['filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'data_base64' => base64_encode($a['bytes'])],
        ai_validate_attachments($raw)
    );
}

/** Reverses ai_validate_attachments_for_job() and runs ai_prepare_attachments_for_ai() on the result — call once per job in the worker. */
function ai_job_payload_refs(array $payload, ?int $projectId = null): array
{
    $attachments = array_map(
        fn($a) => ['filename' => $a['filename'] ?? '', 'mime_type' => $a['mime_type'] ?? '', 'bytes' => base64_decode($a['data_base64'] ?? '')],
        $payload['attachments'] ?? []
    );
    return ai_prepare_attachments_for_ai($attachments, $projectId);
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

/** @param array $refs See ai_generate_intent()'s doc comment for the shape. */
function ai_generate_design_brief(object $client, string $prompt, array $schemaPlan, array $refs = []): array
{
    $attachments = $refs['attachments'] ?? [];
    $schemaCtx = ai_schema_to_context($schemaPlan);
    $userMsg   = "App description: {$prompt}\n\nSchema:\n{$schemaCtx}"
               . (!empty($refs['context']) ? "\n\n" . $refs['context'] : '');
    $systemPrompt = AI_DESIGN_BRIEF_PROMPT . ($attachments || !empty($refs['context']) ? ai_attachment_instruction_note() : '');
    try {
        $brief = $client->generateJson($systemPrompt, $userMsg, $attachments);
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
    // Same base64url (RFC 7519) fix as the generated app's own auth.js/api.js
    // -- plain atob() throws on a payload containing '-' or '_', which used
    // to make this test helper itself unreliable for exactly the tokens most
    // worth testing.
    const b64 = raw.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    const p = JSON.parse(atob(b64.padEnd(b64.length + (4 - b64.length % 4) % 4, '=')));
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
  // assert(), not a bare failed++ — the stories array is the only thing the
  // PHP result parser counts, so this must land there to be visible upstream.
  assert('Browser connection', false, 'Browser connect failed: ' + connErr.message);
  console.log('__STORIES_JSON__' + JSON.stringify(stories));
  process.exit(1);
}
page.setDefaultTimeout(15000);
const pageErrors = [];
page.on('console', m => { if (m.type() === 'error') pageErrors.push(m.text()); });
page.on('pageerror', e => pageErrors.push(e.message));
JSEOF;

    // ── Auth block ─────────────────────────────────────────────────────────────────
    // Login and signup are platform-provided, separate routes (features/auth/auth.js,
    // see ai_routes.php's AI_CANONICAL_AUTH_JS) — same #auth-form/#auth-identifier/
    // #auth-password/#auth-error ids regardless of which route rendered them, so tests
    // navigate to the right route first rather than assuming a combined page.
    $authBlock = '';
    if ($hasAuth) {
        $authBlock .= "  log('Story: Wrong credentials are rejected');\n";
        $authBlock .= "  await page.goto(APP_URL + '#/login', { waitUntil: 'networkidle' });\n";
        $authBlock .= "  await page.waitForTimeout(500);\n";
        $authBlock .= "  await page.fill('#auth-form #auth-identifier', 'wrong@nowhere.dev');\n";
        $authBlock .= "  await page.fill('#auth-form #auth-password', 'BadPass000');\n";
        $authBlock .= "  await page.click('#auth-form button[type=\"submit\"]');\n";
        $authBlock .= "  await page.waitForTimeout(2000);\n";
        $authBlock .= "  const stillLogin = page.url().includes('/login');\n";
        $authBlock .= "  const loginErrTxt = await page.\$eval('#auth-error', el => el.textContent.trim()).catch(() => '');\n";
        $authBlock .= "  assert('Wrong credentials are rejected',\n";
        $authBlock .= "    stillLogin || loginErrTxt.length > 0, loginErrTxt);\n\n";

        $authBlock .= "  log('Story: Unauthenticated access shows login form');\n";
        $authBlock .= "  await page.goto(APP_URL + '#/login', { waitUntil: 'networkidle' });\n";
        $authBlock .= "  await page.waitForTimeout(1000);\n";
        $authBlock .= "  assert('Login form visible to unauthenticated users', await page.\$('#auth-form') !== null);\n\n";

        $authBlock .= "  log('Story: User can sign up');\n";
        $authBlock .= "  await page.goto(APP_URL + '#/signup', { waitUntil: 'networkidle' });\n";
        $authBlock .= "  await page.waitForTimeout(500);\n";
        $authBlock .= "  await page.fill('#auth-form #auth-identifier', TEST_EMAIL);\n";
        $authBlock .= "  await page.fill('#auth-form #auth-password', TEST_PASS);\n";
        $authBlock .= "  await page.click('#auth-form button[type=\"submit\"]');\n";
        $authBlock .= "  let loggedIn = false;\n";
        $authBlock .= "  try {\n";
        $authBlock .= "    await waitLoggedIn(page);\n";
        $authBlock .= "    loggedIn = true;\n";
        $authBlock .= "    [tokenA, userIdA] = await captureToken(page);\n";
        $authBlock .= "  } catch (_) {\n";
        $authBlock .= "    const errTxt = await page.\$eval('#auth-error', el => el.textContent.trim()).catch(() => '');\n";
        $authBlock .= "    console.log('  signup-error: ' + errTxt);\n";
        $authBlock .= "  }\n";
        $authBlock .= "  assert('Signup succeeds and user is logged in', loggedIn);\n";
        $authBlock .= "  if (!loggedIn) throw Object.assign(new Error('auth_failed'), { abort: true });\n\n";

        // A prior real bug (a plain atob() on a base64url JWT payload, fixed
        // in AI_CANONICAL_AUTH_JS's loadUser()) only ever manifested on a
        // genuine full page reload -- every other story here navigates via
        // page.goto() to a same-origin hash-only URL, which browsers treat as
        // in-document SPA navigation and never re-runs loadUser() at all, so
        // this exact class of bug was invisible to every test in this script
        // until now. page.reload() forces the real thing: a full script
        // re-parse against whatever's already sitting in localStorage.
        $authBlock .= "  log('Story: Session survives a page reload');\n";
        $authBlock .= "  await page.reload({ waitUntil: 'networkidle' });\n";
        $authBlock .= "  let stillLoggedInAfterReload = false;\n";
        $authBlock .= "  try { await waitLoggedIn(page); stillLoggedInAfterReload = true; } catch (_) {}\n";
        $authBlock .= "  assert('Still logged in after a page reload', stillLoggedInAfterReload,\n";
        $authBlock .= "    stillLoggedInAfterReload ? '' : 'Reloading the page logged the user out — check the token decode in loadUser()');\n\n";
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
        $isolationBlock .= "    await pageB.goto(APP_URL + '#/signup', { waitUntil: 'networkidle' });\n";
        $isolationBlock .= "    await pageB.waitForTimeout(500);\n";
        $isolationBlock .= "    await pageB.fill('#auth-form #auth-identifier', TEST_EMAIL_B);\n";
        $isolationBlock .= "    await pageB.fill('#auth-form #auth-password', TEST_PASS);\n";
        $isolationBlock .= "    await pageB.click('#auth-form button[type=\"submit\"]');\n";
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
        $reloginBlock .= "  await page.goto(APP_URL + '#/login', { waitUntil: 'networkidle' });\n";
        $reloginBlock .= "  await page.waitForTimeout(500);\n";
        $reloginBlock .= "  const loginFormRL = await page.\$('#auth-form');\n";
        $reloginBlock .= "  if (loginFormRL) {\n";
        $reloginBlock .= "    await page.fill('#auth-form #auth-identifier', TEST_EMAIL);\n";
        $reloginBlock .= "    await page.fill('#auth-form #auth-password', TEST_PASS);\n";
        $reloginBlock .= "    await page.click('#auth-form button[type=\"submit\"]');\n";
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
        $logoutBlock .= "    const onLoginRoute = page.url().includes('/login');\n";
        $logoutBlock .= "    const navHidden = await page.\$eval('#nav-logout', el => el.classList.contains('hidden')).catch(() => true);\n";
        $logoutBlock .= "    assert('After logout, login form is shown', onLoginRoute && navHidden);\n";
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
         . "  // Record the crash as a story, not just failed++ — PHP's result parser\n"
         . "  // (ai_playwright_test_run) counts passed/failed from the stories array\n"
         . "  // alone, so a bare counter bump reported '0 passed, 0 failed' upstream\n"
         . "  // while the real error surfaced only as a detached raw string.\n"
         . "  if (!e.abort) assert('Test run aborted mid-way', false, 'Unexpected error: ' + e.message);\n"
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

// One-shot Playwright script for the edit-agent's fetch_page tool: connect,
// navigate to a hash route (handling the SPA's client-side routing the way a
// real visitor's browser does — curl_site categorically cannot), snapshot the
// RENDERED page (post-JS text + visible interactive elements) and any console
// errors, then exit. Deliberately not the persistent bidirectional protocol
// ai_browser_agent_spawn() uses for the browser-test-agent (multi-turn
// click/fill interaction) — a single navigate+look needs none of that
// complexity, just a script that runs once and prints one result line.
function ai_fetch_page_script_generate(string $appUrl, string $token, string $path, bool $hasAuth): string
{
    $loginBlock = $hasAuth ? <<<'JSEOF'
    try {
      await page.goto(APP_URL + '#/signup', { waitUntil: 'networkidle', timeout: 15000 });
      await page.waitForTimeout(500);
      const idField = await page.$('#auth-form #auth-identifier');
      if (idField) {
        await page.fill('#auth-form #auth-identifier', TEST_EMAIL);
        await page.fill('#auth-form #auth-password', TEST_PASS);
        await page.click('#auth-form button[type="submit"]');
        await page.waitForFunction(
          () => { const el = document.querySelector('#nav-logout'); return el && !el.classList.contains('hidden'); },
          { timeout: 15000 }
        ).catch(() => {});
      }
    } catch (_) {}
JSEOF
        : '';

    $script = <<<'JSEOF'
import { chromium } from 'playwright-core';

const TOKEN = '__TOKEN__';
const APP_URL = '__APP_URL__';
const TARGET_PATH = '__PATH__';
const TEST_EMAIL = `pw-fetch-${Date.now()}@testmail.dev`;
const TEST_PASS = 'TestPass123!';

function sendResult(obj) {
  process.stdout.write('@@FETCH_RESULT@@' + JSON.stringify(obj) + '\n');
}

(async () => {
  let browser;
  try {
    browser = await chromium.connectOverCDP(`wss://chrome.browserless.io?token=${TOKEN}`);
    const page = await browser.newPage();
    page.setDefaultTimeout(15000);
    const consoleErrors = [];
    page.on('pageerror', (e) => consoleErrors.push(String((e && e.message) || e)));
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text().slice(0, 300)); });

__LOGIN_BLOCK__

    const cleanPath = TARGET_PATH.replace(/^#?\/?/, '/');
    const resp = await page.goto(APP_URL + '#' + cleanPath, { waitUntil: 'networkidle', timeout: 15000 }).catch(() => null);
    await page.waitForTimeout(700);

    const candidates = await page.$$('button, a, input, select, textarea, [role="button"]');
    const elements = [];
    for (const h of candidates) {
      let visible = false;
      try { visible = await h.isVisible(); } catch (_) { visible = false; }
      if (!visible) continue;
      try {
        const info = await h.evaluate(el => ({
          tag: el.tagName.toLowerCase(),
          text: (el.innerText || el.value || el.placeholder || '').trim().slice(0, 80),
        }));
        elements.push(info);
      } catch (_) {}
      if (elements.length >= 40) break;
    }

    let bodyText = '';
    try { bodyText = await page.evaluate(() => document.body.innerText); } catch (_) {}

    sendResult({
      ok: true,
      url: page.url(),
      http_status: resp ? resp.status() : null,
      bodyText: bodyText.slice(0, 1500),
      elements,
      console_errors: consoleErrors.slice(0, 10),
    });
  } catch (e) {
    sendResult({ ok: false, error: String((e && e.message) || e) });
  } finally {
    if (browser) await browser.close().catch(() => {});
  }
})();
JSEOF;

    return str_replace(
        ['__TOKEN__', '__APP_URL__', '__PATH__', '__LOGIN_BLOCK__'],
        [$token, $appUrl, addslashes($path), $loginBlock],
        $script
    );
}

// Blocking one-shot runner for ai_fetch_page_script_generate()'s script —
// same proc_open mechanics as ai_playwright_test_run() (write to a temp .mjs
// file next to node_modules/ so ESM bare-specifier resolution finds
// 'playwright-core', run it, collect stdout/stderr) but parses the
// '@@FETCH_RESULT@@' marker this script emits instead of the test runner's
// '__STORIES_JSON__' one — kept as its own function rather than sharing code
// with ai_playwright_test_run() since the two return shapes and markers
// don't otherwise overlap.
function ai_fetch_page_run(string $script, array $config): array
{
    $nodeBin     = $config['NODE_BIN']           ?? '/opt/alt/alt-nodejs16/root/usr/bin/node';
    $nodeModules = $config['PLAYWRIGHT_MODULES'] ?? '/home/dxinethn/playwright-test/node_modules';
    $playwrightDir = rtrim(dirname($nodeModules), '/');
    $tmpFile       = $playwrightDir . '/sb_fetch_' . getmypid() . '_' . time() . '.mjs';

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
        $playwrightDir,
        $env
    );

    if (!is_resource($process)) {
        @unlink($tmpFile);
        return ['ok' => false, 'error' => 'Failed to spawn Node process'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    @unlink($tmpFile);

    if (preg_match('/@@FETCH_RESULT@@(.+)$/m', $stdout . "\n" . $stderr, $m)) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded)) return $decoded;
    }
    return ['ok' => false, 'error' => trim(substr($stderr, 0, 500)) ?: 'no result produced'];
}

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
        $approvedIntent = ai_generate_intent($client, $prompt, $history, $refs);
        $aiTrace[] = ['stage' => 'intent', 'system' => AI_INTENT_PROMPT, 'history' => $history, 'user_msg' => $prompt, 'response' => $approvedIntent, 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];
        $actorNames = array_filter(array_map(fn($a) => is_array($a) ? ($a['name'] ?? '') : (string)$a, $approvedIntent['actors'] ?? []));
        $storyCount = array_sum(array_map(fn($a) => is_array($a) ? count($a['stories'] ?? []) : 0, $approvedIntent['actors'] ?? []));
        $report(['stage' => 'requirements', 'status' => 'done', 'label' => 'Requirements understood',
                 'detail' => count($actorNames) . ' actor(s): ' . implode(', ', $actorNames) . ' — ' . $storyCount . ' user stor' . ($storyCount === 1 ? 'y' : 'ies')]);
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
        $frontendResult = ai_run_build_frontend($schemaResult['schema'], $schemaResult['design_brief'], $prompt, $client, $config, $report, $validate, $refs);
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
    }

    $hasDeployed = !empty($applyResult['deploy']) || !empty($applyResult['staging']) || !empty($applyResult['site']);
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

    return array_merge($genResult, ['apply' => $applyResult, 'test' => $testResult]);
}

// Stage 1+2 of a build: schema (with one self-correcting retry) and a
// best-effort visual design brief. Split out from frontend generation so the
// "Review" build flow can pause here and let the user confirm the schema and
// design before any frontend code gets written — the "watch only" (Review
// off) flow just calls this immediately followed by ai_run_build_frontend().
/** @param array $refs See ai_generate_intent()'s doc comment for the shape. */
function ai_run_build_schema_design(string $prompt, array $history, ?array $approvedIntent, object $client, callable $report, array $refs = []): array
{
    $aiTrace = [];
    $attachments = $refs['attachments'] ?? [];
    $hasRefs = $attachments || !empty($refs['context']);
    $schemaSystemPrompt = AI_BUILD_SCHEMA_PROMPT . ($hasRefs ? ai_attachment_instruction_note() : '');

    $lockedName = trim((string)($approvedIntent['project_name'] ?? ''));

    // ── Stage 1: schema ───────────────────────────────────────────────────
    $report(['stage' => 'schema', 'status' => 'start', 'label' => 'Designing database schema…']);
    $schemaUserMsg = $approvedIntent
        ? $prompt . "\n\n" . ai_intent_to_context($approvedIntent)
        : $prompt;
    if ($lockedName !== '') {
        $schemaUserMsg .= "\n\nLocked project name — use EXACTLY this as \"project_name\" in your JSON output: {$lockedName}";
    }
    if (!empty($refs['context'])) $schemaUserMsg .= "\n\n" . $refs['context'];
    $_t0 = microtime(true);
    $schemaPlan = $client->generateJsonWithHistory($schemaSystemPrompt, $history, $schemaUserMsg, $attachments);
    $aiTrace[] = ['stage' => 'schema_pass_1', 'system' => $schemaSystemPrompt, 'history' => $history, 'user_msg' => $schemaUserMsg, 'response' => $schemaPlan, 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];
    $schemaPlan['frontend'] = ['files' => []];
    $schemaPlan = ai_sanitize_plan($schemaPlan);
    if ($lockedName !== '') $schemaPlan['project_name'] = $lockedName;

    $validationError = ai_validate_plan($schemaPlan);
    if ($validationError) {
        $report(['stage' => 'schema', 'status' => 'retry', 'label' => 'Refining schema…', 'detail' => $validationError]);
        $retryPrompt = $schemaUserMsg
            . "\n\nYour previous schema was rejected for this reason:\n  " . $validationError
            . "\nReturn a corrected schema that fixes exactly this problem.";
        $_t0 = microtime(true);
        $schemaPlan = $client->generateJsonWithHistory($schemaSystemPrompt, $history, $retryPrompt, $attachments);
        $aiTrace[] = ['stage' => 'schema_retry', 'system' => $schemaSystemPrompt, 'history' => $history, 'user_msg' => $retryPrompt, 'response' => $schemaPlan, 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => true, 'error' => $validationError];
        $schemaPlan['frontend'] = ['files' => []];
        $schemaPlan = ai_sanitize_plan($schemaPlan);
        if ($lockedName !== '') $schemaPlan['project_name'] = $lockedName;
        $validationError = ai_validate_plan($schemaPlan);
        if ($validationError) throw new \RuntimeException('AI returned an invalid schema: ' . $validationError);
    }
    $tableNames = array_map(fn($t) => $t['name'], $schemaPlan['tables'] ?? []);
    $report(['stage' => 'schema', 'status' => 'done', 'label' => 'Database schema ready', 'detail' => count($tableNames) . ' table' . (count($tableNames) === 1 ? '' : 's') . ': ' . implode(', ', $tableNames)]);

    // ── Stage 2: design brief (best-effort) ───────────────────────────────
    $report(['stage' => 'design', 'status' => 'start', 'label' => 'Choosing a visual design…']);
    $_t0   = microtime(true);
    $brief = ai_generate_design_brief($client, $prompt, $schemaPlan, $refs);
    if (!empty($brief)) {
        $aiTrace[] = ['stage' => 'design_brief', 'system' => AI_DESIGN_BRIEF_PROMPT, 'history' => [], 'user_msg' => "App description: {$prompt}\n\nSchema:\n" . ai_schema_to_context($schemaPlan), 'response' => $brief, 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];
    }
    $report(['stage' => 'design', 'status' => 'done', 'label' => 'Visual design chosen', 'detail' => trim(($brief['personality'] ?? '') . (isset($brief['accent_color']) ? ' · ' . $brief['accent_color'] : '')) ?: 'default theme']);

    return ['schema' => $schemaPlan, 'design_brief' => $brief, 'aiTrace' => $aiTrace, 'usage' => $client->getLastUsage()];
}

// Stage 3+4 of a build: frontend code generation against an already-confirmed
// schema and design brief, then deterministic validation. Split out so the
// "Review" build flow can run this as its own job, after the user has
// confirmed the schema/design in the previous stage.
/** @param array $refs See ai_generate_intent()'s doc comment for the shape. */
function ai_run_build_frontend(array $schemaPlan, array $designBrief, string $prompt, object $client, array $config, callable $report, bool $validate = true, array $refs = []): array
{
    // ── Stage 3: frontend — agentic tool-calling loop (search/read/write/
    // syntax-check), same machinery the edit agent uses, instead of a single
    // shot at the whole file set. Lets the model verify its own output (a
    // deterministic syntax check on every write) and read back a file it
    // wrote earlier before extending it, instead of hoping a one-shot
    // multi-file JSON blob comes back internally consistent.
    $report(['stage' => 'frontend', 'status' => 'start', 'label' => 'Generating frontend code…']);
    $frontendResult = ai_run_build_frontend_agentic($schemaPlan, $designBrief, $prompt, $client, $config, $report, $refs);
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
        return $result;
    } finally {
        \SupaBein\Deploy::rrmdir($previewRoot);
    }
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
function ai_run_edit_agent_tool(string $tool, array $args, array $byPath, array &$changedFiles, array &$readPaths, array $config, int $projectId, array $schema = []): array
{
    static $platformPaths = ['core/router.js', 'core/api.js', 'core/errors.js', 'features/auth/auth.js'];

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
                return ['tool' => 'read_file', 'result' => ['path' => $path, 'content' => null,
                    'note' => 'This file is platform-provided and always overwritten at deploy time — reading or writing it has no effect.']];
            }
            $content = $changedFiles[$path] ?? $byPath[$path] ?? null;
            if ($content === null) return ['tool' => 'read_file', 'error' => "no such file: {$path}"];
            $readPaths[$path] = true;
            return ['tool' => 'read_file', 'result' => ['path' => $path, 'content' => $content]];

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
            $findings = ai_validator_check_project($schema, $files);
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
            return ['tool' => 'smoke_test', 'result' => ai_smoke_test_files($files, $config, $authInfo)];

        case 'write_files':
            $filesArg = is_array($args['files'] ?? null) ? $args['files'] : null;
            if ($filesArg === null) return ['tool' => 'write_files', 'error' => 'args.files must be an array of {path, content}'];
            $results = [];
            foreach ($filesArg as $f) {
                $results[] = is_array($f)
                    ? ai_run_edit_agent_tool('write_file', $f, $byPath, $changedFiles, $readPaths, $config, $projectId, $schema)
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
            return ['error' => "unknown tool \"{$tool}\" — must be one of: list_files, search_code, read_file, write_file, write_files, syntax_check, check_policy, curl_site, fetch_page, smoke_test, fetch_docs, validate_frontend, finish"];
    }
}

// A hard, permanent provider failure (a daily free-tier quota exhausted, out
// of credits, an invalid key) fails identically no matter how many more turns
// are spent retrying it -- unlike a truncated or malformed JSON response,
// which retrying can genuinely fix. Every agent loop below used to treat
// every \Throwable from generateJsonWithHistory() the same way ("recoverable,
// give it another turn"), which for one of these silently burned the entire
// turn budget doing nothing but repeat the exact same doomed call, then
// force-finished with an empty delta that looks like a normal (if unusually
// small) result -- an edit that changed nothing sails straight through
// validate+deploy+test looking successful, with the user's actual request
// never having been attempted and no visible failure anywhere.
function ai_is_unrecoverable_provider_error(string $msg): bool
{
    $msg = strtolower($msg);
    if (str_contains($msg, 'rate limit') && (str_contains($msg, 'per-day') || str_contains($msg, 'per day') || str_contains($msg, 'daily'))) return true;
    foreach (['insufficient credit', 'add credit', 'add 10 credits', 'credit balance is too low', 'quota exceeded', 'exceeded your current quota', 'invalid api key', 'unauthorized'] as $needle) {
        if (str_contains($msg, $needle)) return true;
    }
    // Live-caught: attaching an image to a build/edit request can land on a
    // text-only model deep in the OpenRouter/NVIDIA fallback chain (not
    // every model of the several dozen routed through OPENROUTER's tiers
    // supports vision), which rejects the request outright instead of just
    // ignoring the image — e.g. OpenRouter's "No endpoints found that
    // support image input". Without this, the whole job hard-fails instead
    // of moving on to the next candidate the same way a rate limit does.
    foreach (['support image input', 'support images', 'does not support vision', 'multimodal messages are not supported', 'image_url is not supported'] as $needle) {
        if (str_contains($msg, $needle)) return true;
    }
    // A request that times out (curl's own "Operation timed out..."/"Connection
    // timed out" wording, or DNS/connect-level failures) never reached the
    // model at all -- retrying the exact same provider is no more likely to
    // succeed than the first attempt was, whereas the next candidate in the
    // chain is live right now. Treated as unrecoverable so it falls forward
    // instead of surfacing the timeout straight to the caller.
    foreach (['timed out', 'timeout', 'could not resolve host', 'couldn\'t resolve host', 'connection refused', 'connection reset', 'empty reply from server', 'failed to connect'] as $needle) {
        if (str_contains($msg, $needle)) return true;
    }
    return false;
}

// A rate-limited request never reached the model at all -- there is no
// "response" to have been malformed. Live-caught by profiling a real test
// run: of 21 turns that showed "Response was invalid, retrying…" on
// screen, 20 were plain HTTP 429s from the provider, not the model
// producing bad JSON. Every one of the three agent turn loops below caught
// ALL \Throwable the same way and labeled every one of them identically,
// which is what made a pure rate-limiting problem look like a model/schema
// quality problem in the UI and in any trace pulled afterward for analysis.
function ai_agent_is_rate_limited(string $errorMsg): bool
{
    return stripos($errorMsg, '429') !== false
        || stripos($errorMsg, 'too many requests') !== false
        || stripos($errorMsg, 'rate limit') !== false;
}

// General stuck-loop detector shared by every agent turn loop below. Two
// live-caught bugs this session — an edit agent re-reading the same
// unchanged file ~9 turns in a row, and a test agent re-attempting login
// over and over off one wrong hypothesis — turned out to be the same
// underlying gap wearing different clothes: nothing noticed "the last few
// tool calls are byte-identical and nothing is changing." Rather than patch
// each new instance of this pattern with its own one-off prompt rule
// forever, this catches the whole class: if the exact same (tool, args)
// repeats $threshold times in a row, the call is skipped (no point actually
// re-running something that will just return what it already did) and the
// caller gets a forcing nudge back instead, telling the model plainly that
// repeating this exact action isn't working and to do something else.
// $recentCalls is the loop's own rolling window, passed by reference so it
// persists turn to turn; resets naturally the moment a different action
// breaks the streak.
function ai_agent_detect_stuck_repeat(array &$recentCalls, string $tool, array $args, int $threshold = 3): bool
{
    $signature = $tool . ':' . json_encode($args);
    $recentCalls[] = $signature;
    if (count($recentCalls) > $threshold) {
        array_shift($recentCalls);
    }
    if (count($recentCalls) < $threshold) {
        return false;
    }
    $stuck = count(array_unique($recentCalls)) === 1;
    if ($stuck) {
        $recentCalls = []; // give the next, different action a clean window
    }
    return $stuck;
}

// A model that fails to produce valid JSON for one action rarely recovers by
// just being told "try again" — left alone, the retry catch blocks below
// repeat that identical soft nudge every time, and a model that's stuck
// tends to send back the SAME broken response verbatim turn after turn.
// Confirmed live: one build failed the same write_file action 4 times in a
// row, ~11 minutes of retries with zero forward progress, before finally
// succeeding on the 5th attempt. Escalate to a much more forceful
// instruction once a few consecutive failures pile up, mirroring how
// ai_agent_detect_stuck_repeat() above already escalates for a *successful*
// action repeated pointlessly.
function ai_agent_note_parse_failure(int &$consecutiveFailures, string $errorMsg, int $threshold = 2): string
{
    $consecutiveFailures++;
    if ($consecutiveFailures < $threshold) {
        return "\n\n(Your previous response to this could not be parsed as valid JSON — it may have been cut "
             . "off: {$errorMsg}. Respond again with a single valid JSON action; if you were writing a large "
             . 'file, keep the content more concise.)';
    }
    return "\n\n(You have now failed to produce valid JSON {$consecutiveFailures} times in a row for this exact "
         . "action: {$errorMsg}. Repeating the same attempt again will not work either. Do ONE of: write a "
         . 'substantially shorter/simpler version of whatever you were writing, split it into a smaller piece, '
         . 'switch to a completely different action, or call finish with whatever is already staged.)';
}

// Turn budgets across the three agent loops now run 60-120 turns (up from
// 12-60), and every turn appends two messages to $loopHistory with no cap —
// resent in full on every single subsequent call. Left unbounded, a long-
// running session's token cost (and latency) grows roughly with the square
// of its turn count. The model only ever needs recent context to keep making
// progress on the CURRENT step; nothing about turn 3 is still relevant by
// turn 50. Keeping the most recent messages and dropping the rest bounds
// this without meaningfully hurting the model's ability to continue.
const AI_AGENT_HISTORY_WINDOW_MESSAGES = 30; // ~15 turns of (user, model) pairs

// Compaction-lite: rather than silently dropping everything past the
// window (the model then has zero record of, say, already having written
// index.html, and can waste a turn re-deriving or re-checking something it
// already settled), fold the dropped turns' own tool calls into one cheap
// summary line prepended to what's kept. Built entirely from data already
// in $history — no extra AI call, just string/array work — so this doesn't
// add any latency or cost of its own.
function ai_agent_trim_history(array $history): array
{
    if (count($history) <= AI_AGENT_HISTORY_WINDOW_MESSAGES) return $history;

    $dropped = array_slice($history, 0, count($history) - AI_AGENT_HISTORY_WINDOW_MESSAGES);
    $kept    = array_slice($history, -AI_AGENT_HISTORY_WINDOW_MESSAGES);

    $actions = [];
    foreach ($dropped as $msg) {
        if (($msg['role'] ?? '') !== 'model') continue;
        $decoded = json_decode((string)($msg['text'] ?? ''), true);
        if (!is_array($decoded)) continue;
        $tool = (string)($decoded['tool'] ?? '');
        if ($tool === '') continue;
        $path = (string)($decoded['args']['path'] ?? '');
        $actions[] = $path !== '' ? "{$tool}({$path})" : $tool;
    }

    if ($actions) {
        $shown = array_slice($actions, 0, 20);
        $summary = ['role' => 'user', 'text' =>
            '(Summary of ' . count($dropped) . ' earlier messages, dropped from context to save space — actions '
            . "you already took: " . implode(', ', $shown) . (count($actions) > count($shown) ? ', …' : '')
            . '. Do not repeat these unless something is actually broken.)'];
        array_unshift($kept, $summary);
    }

    return $kept;
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

    for ($turn = 1; $turn <= AI_EDIT_AGENT_MAX_TURNS; $turn++) {
        $_t0 = microtime(true);
        try {
            // Same one-time-only attach as the frontend build agent — see its
            // matching comment in ai_run_build_frontend_agentic().
            $turnAttachments = $turn === 1 ? ($refs['attachments'] ?? []) : [];
            $action = $client->generateJsonWithHistory($editAgentPrompt, $loopHistory, $turnMsg, $turnAttachments);
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

        $report(['stage' => 'changes', 'status' => 'active', 'label' => 'Generating changes…',
            'detail' => ai_edit_agent_step_label($tool, $args)]);

        $loopHistory[] = ['role' => 'user', 'text' => $turnMsg];
        $loopHistory[] = ['role' => 'model', 'text' => json_encode($action)];
        $loopHistory   = ai_agent_trim_history($loopHistory);

        if ($tool === 'finish') {
            // A smoke_test that came back broken and was never fixed (or
            // re-checked) must not be allowed to silently ship — this is
            // exactly the gap that let the calculator app's "this.loadState
            // is not a function" crash reach a real deploy: the tool to
            // catch it existed by the time this check was added, but
            // nothing stopped finish() from being called anyway.
            if ($lastSmokeTestOk === false) {
                $turnMsg = json_encode(['tool' => 'finish', 'error' =>
                    'Your last smoke_test came back with errors and you called finish() without fixing them or ' .
                    're-running smoke_test clean. Fix the actual problem it reported, then call smoke_test again ' .
                    'to confirm it is clean before calling finish.']);
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
            $turnMsg = json_encode(['tool' => 'finish', 'error' =>
                "Your finish() was rejected: {$deltaError}. Continue working and call finish() again once it's fixed."]);
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

        $toolResult = ai_run_edit_agent_tool($tool, $args, $byPath, $changedFiles, $readPaths, $config, $projectId, $existingSchema);
        if ($tool === 'smoke_test') {
            $lastSmokeTestOk = $toolResult['result']['ok'] ?? null;
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

// ── Build frontend agent: the loop itself ────────────────────────────────────
// Same ReAct-style loop and tool executor (ai_run_edit_agent_tool) as the edit
// agent above, starting from zero files. Returns ['files'=>[...], 'aiTrace'=>[...],
// 'usage'=>[...]] — the exact shape ai_run_build_frontend()'s old single-shot
// $frontendResult had, so the surgical swap there needs no other changes.
/** @param array $refs See ai_generate_intent()'s doc comment for the shape. */
function ai_run_build_frontend_agentic(
    array $schemaPlan, array $designBrief, string $prompt, object $client, array $config, callable $report, array $refs = []
): array {
    $briefCtx    = ai_brief_to_context($designBrief);
    $hasRefs     = !empty($refs['attachments']) || !empty($refs['context']);
    $agentPrompt = ai_bind_auth_placeholders(AI_BUILD_FRONTEND_AGENT_SYSTEM_PROMPT, $schemaPlan)
                 . ($hasRefs ? ai_attachment_instruction_note() : '');
    $schemaCtx   = ai_schema_to_context($schemaPlan);

    $byPath       = []; // a fresh build starts with nothing on disk
    $changedFiles = [];
    $readPaths    = [];
    $aiTrace      = [];
    $finished     = false;
    $totalUsage   = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

    $turnMsg = "App description: {$prompt}\n\n"
             . ($briefCtx ? "{$briefCtx}\n\n" : '')
             . "Exact validated schema — use ONLY these column names in JS:\n{$schemaCtx}\n\n"
             . (!empty($refs['context']) ? "{$refs['context']}\n\n" : '')
             . 'Respond with your first tool action.';
    $loopHistory = [];
    $recentCalls = [];
    $consecutiveParseFailures = 0;
    $lastSmokeTestOk = null; // null = never called this session; true/false = its last result

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

        $report(['stage' => 'frontend', 'status' => 'active', 'label' => 'Generating frontend code…',
            'detail' => ai_edit_agent_step_label($tool, $args)]);

        $loopHistory[] = ['role' => 'user', 'text' => $turnMsg];
        $loopHistory[] = ['role' => 'model', 'text' => json_encode($action)];
        $loopHistory   = ai_agent_trim_history($loopHistory);

        if ($tool === 'finish') {
            if (empty($changedFiles)) {
                // Nothing written at all — reject and force at least one file
                // before letting it stop, the same "must actually do
                // something" guarantee ai_validate_delta gives the edit
                // agent's finish.
                $turnMsg = json_encode(['tool' => 'finish', 'error' =>
                    "No files have been written yet — write_file the app's frontend before calling finish."]);
                continue;
            }
            // A smoke_test that came back broken and was never fixed (or
            // re-checked) must not be allowed to silently ship — this is
            // exactly the gap that let the calculator app's "this.loadState
            // is not a function" crash reach a real deploy: the tool to
            // catch it existed by the time this check was added, but
            // nothing stopped finish() from being called anyway.
            if ($lastSmokeTestOk === false) {
                $turnMsg = json_encode(['tool' => 'finish', 'error' =>
                    'Your last smoke_test came back with errors and you called finish() without fixing them or ' .
                    're-running smoke_test clean. Fix the actual problem it reported, then call smoke_test again ' .
                    'to confirm it is clean before calling finish.']);
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

        // No real project exists yet at this stage of a build — 0 is a safe
        // placeholder; this agent's own system prompt never advertises
        // check_policy, so it has no route to actually call it. validate_frontend
        // works fine here though — it only needs the schema plan, not a live DB.
        $toolResult = ai_run_edit_agent_tool($tool, $args, $byPath, $changedFiles, $readPaths, $config, 0, $schemaPlan);
        if ($tool === 'smoke_test') {
            $lastSmokeTestOk = $toolResult['result']['ok'] ?? null;
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

/**
 * @param array $refs See ai_generate_intent()'s doc comment for the shape.
 * @param callable|null $checkpoint See ai_run_edit_generation_agentic()'s doc
 *   comment — wired by the worker to persist progress DURING the agentic
 *   loop via Catalog::saveJobCheckpoint(), not just when it returns.
 */
function ai_run_edit_generation(int $projectId, string $prompt, array $history, object $client, \SupaBein\Catalog $catalog, array $config, callable $report, bool $validate = true, ?array $resumeState = null, array $refs = [], ?callable $checkpoint = null): array
{
    $aiTrace = [];

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

const AI_SEED_PROMPT = <<<'PROMPT'
You generate realistic sample/demo data for an existing app's database, given its exact schema.
Return ONLY a single valid JSON object — no markdown fences, no explanation, no extra text:

{ "seed_data": { "<table_name>": [ { "<col>": <value>, ... } ] } }

Rules:
- Target only tables that already exist in the given schema.
- Never seed auth/users tables or rows that must belong to a real logged-in user (anything relying
  on a current-user/owner column) — only seed "global"/catalogue-style data real visitors would see.
- Generate 5-10 realistic rows per eligible table. Cap at 50 rows per table.
- Omit "id" and "created_at" — they are inserted automatically.
- Values must match the column types exactly (strings for VARCHAR/TEXT, numbers for INT/DECIMAL).
- For any image/photo/avatar/thumbnail/banner/logo/cover URL column: omit it or set it to null —
  never invent a URL. The platform fills in a real, working photo for these automatically; anything
  you put there yourself is discarded and replaced either way.
- If no table is eligible for seeding, return "seed_data": {}.
PROMPT;

// Variant used once test login accounts already exist (see ai_seed_test_accounts) —
// unlocks seeding user-owned tables by tying rows to those specific test users,
// instead of refusing to touch anything current-user-scoped at all.
const AI_SEED_PROMPT_WITH_ACCOUNTS = <<<'PROMPT'
You generate realistic sample/demo data for an existing app's database, given its exact schema.
Return ONLY a single valid JSON object — no markdown fences, no explanation, no extra text:

{ "seed_data": { "<table_name>": [ { "<col>": <value>, ... } ] } }

Rules:
- Target only tables that already exist in the given schema.
- Never seed the auth/users table itself — working test login accounts for it already exist and
  are listed below; do not touch that table.
- For any OTHER table whose rows belong to a specific user (a column enforced by a
  ":current_user_id" ownership policy in the schema), set that column to one of the given test
  user IDs so the seeded rows are actually visible when logged in as that test user. Distribute
  rows across the given test user IDs rather than putting them all under one.
- Generate 5-10 realistic rows per eligible table. Cap at 50 rows per table.
- Omit "id" and "created_at" — they are inserted automatically.
- Values must match the column types exactly (strings for VARCHAR/TEXT, numbers for INT/DECIMAL).
- For any image/photo/avatar/thumbnail/banner/logo/cover URL column: omit it or set it to null —
  never invent a URL. The platform fills in a real, working photo for these automatically; anything
  you put there yourself is discarded and replaced either way.
- If no table is eligible for seeding, return "seed_data": {}.
PROMPT;

// Fixed, well-known password for AI-seeded test login accounts — these exist
// purely so seeded data in user-owned tables is actually reachable by logging
// in as somebody; the value only matters in that it's properly bcrypt-hashed
// before it ever reaches the database (never inserted as plaintext).
const AI_TEST_ACCOUNT_PASSWORD = 'Test1234!';
const AI_TEST_ACCOUNT_COUNT = 2;

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
    $result = $client->generateJson($prompt, $userMsg);
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

// Runs the Playwright user-story tests for a project against its most recent
// deploy (staging if present — edits land there by design — else live).
// Called from the worker's 'test' job mode; throws on unrecoverable failure.

// ── Live browser-testing agent ───────────────────────────────────────────────
// Story verification used to be a single LLM call writing a whole Playwright
// code block against the STATIC index.html shell — the only DOM it could ever
// see, since a hash-routed SPA's real markup (a task row, a "mark complete"
// control) is rendered by JS at runtime and simply isn't in that file. The
// model had no choice but to guess selectors like `button:has-text("Mark
// Complete")`, and a real app rendering that control differently (a checkbox,
// different wording, a different element entirely) made the test time out and
// report "failed" for a feature that actually worked — testing the model's
// guess, not the app. This replaces that with a real turn-by-turn agent that
// drives a persistent Playwright process: it can only click/fill an element
// it just observed via `snapshot` (addressed by index into that exact
// observation, never a selector it invents), so there is nothing left to
// hallucinate. The existing deterministic script (auth, generic CRUD,
// isolation, logout — ai_playwright_test_generate/ai_playwright_test_run)
// is unchanged; this only replaces the freeform "distinctive feature" story
// tests it used to splice in via $storyBlock.

const AI_BROWSER_TEST_AGENT_SYSTEM_HEADER = <<<'PROMPT'
You are testing a live web app in a real browser, one action at a time. You do NOT get its source
code — only what `snapshot` shows you: the actual rendered interactive elements and visible text on
the page right now. Nothing about the app's structure is knowable in advance; find out by looking.

Respond with ONLY a single JSON object — no markdown fences, no explanation, no extra text — shaped
exactly as one action:
  {"tool": "<name>", "args": { ... }, "thought": "<one short sentence, optional>"}

Available tools — navigate/click/fill/wait each already return a fresh snapshot of the page as
part of their own result (no need to follow any of them with a separate snapshot call just to see
what they did):
  navigate      args: {"path": string}
    Goes to a hash route of this app, e.g. "/" or "/notes/3/edit". Result includes a fresh snapshot.
  click         args: {"index": number}
    Clicks the element at that index from the MOST RECENT snapshot. Result includes a fresh snapshot
    taken right after, so you see the effect immediately — no separate look needed.
  fill          args: {"index": number, "value": string}
    Types into the input/textarea at that index from the most recent snapshot. Result includes a
    fresh snapshot taken right after.
  wait          args: {"ms": number}  (max 3000)
    Pauses for async UI updates (a save request, a re-render) to settle, then returns a fresh
    snapshot — use this when you need MORE time to pass before checking, not just to see current state.
  snapshot      args: {}
    Returns the CURRENT page's visible interactive elements, each tagged with an "index" — the ONLY
    way to address an element in click/fill. Also returns a short excerpt of visible text. You
    usually don't need to call this explicitly — only if you want to re-check without taking an
    action first, or an index from an earlier turn may no longer point at the same thing.
  report_story  args: {"label": string, "passed": boolean, "detail": string}
    Records ONE story's real, observed result, then move on to testing the next one. "passed" must
    reflect what a snapshot actually showed you — never assume an action worked, verify it.
  finish        args: {}
    Ends the session. Only valid once every story you were given has a report_story call.

Hard rules:
- NEVER invent a selector or assume a page's structure — the only elements you may click or fill are
  ones an index from your most recent snapshot actually showed you.
- Verify, don't assume: after an action that should change something (checking a box, saving a form,
  deleting a row), read the snapshot that came back with it — use `wait` first if the change might be
  async (its result is a fresh snapshot too) — before calling report_story.
- Never click the same element twice in a row to "make sure" or because you didn't see the expected
  change yet. A checkbox/toggle you click again before its first click's own effect has landed gets
  flipped right back to where it started — that looks exactly like "nothing happened" but is actually
  your own two actions canceling out. If an effect isn't visible yet, `wait` — never re-click the same
  control to check again.
- A tool error saying the page/browser was closed, disconnected, or crashed is a TEST-INFRASTRUCTURE
  failure, not evidence about the app. Never conclude a feature "doesn't work" from an error like
  that — report_story it false with a detail that plainly says the test session was interrupted
  before the story could be verified, not a claim that the feature is broken.
- That kind of error is scoped to the ONE command that hit it — the harness reconnects a fresh
  browser session before your very next command runs. It does NOT mean the session is permanently
  dead for the rest of the run. Never report multiple remaining stories as failed off the back of a
  single browser-closed error without trying them: still attempt each later story for real (navigate,
  then snapshot) before deciding it also can't be verified. Most of the time the next attempt just
  works.
- If, after genuinely trying (the obvious navigation, a snapshot, maybe one retry) — and ruling out
  the two causes above — a story's target truly isn't findable or doesn't behave as expected,
  report_story it false with a specific, concrete detail (what you looked for, what you saw
  instead). That is a real finding, not a tool failure — do not report a story true just to move on.
- You cannot see the database or the source code — only the rendered page. When a list/page looks
  empty, describe exactly what you saw ("the page shows 0 items and the text 'No products available
  yet'"), never WHY it's empty. "No data exists" and "data exists but isn't being displayed" look
  IDENTICAL from the browser, and only one of them is something you actually verified. Live-caught: a
  detail that asserted "the store has no products loaded" (a guess, not an observation) got copied
  into a fix request as if it were fact, and sent an otherwise-capable fix agent off re-seeding a
  table that already had real rows in it — the actual bug (a frontend display filter) never got found
  because nothing ever contradicted the false premise. Report the symptom, not a diagnosis.
- Live-caught: an agent couldn't find a "create project" form anywhere in the app, wrongly concluded
  its OWN login must have silently failed, and spent most of its remaining turns re-logging-in over
  and over instead of reporting the real finding ("this action has no UI anywhere"). If you already
  confirmed you're logged in earlier this session (an authed-only page loaded, a nav link only
  logged-in users see was present, an earlier story's own login/signup succeeded), a LATER story's
  missing button/form/page is essentially never evidence your login broke — it is evidence that
  action isn't implemented in the frontend. Never re-attempt login as a diagnostic step more than
  once per session; if you're already logged in and can't find something, look harder for it (other
  pages, a menu, a detail view) or report that it's missing — don't relitigate whether you're logged
  in.
- Don't snapshot twice in a row without having done anything in between — you already know what it
  will show.
- Work through the given stories in order, one at a time.
PROMPT;

const AI_BROWSER_TEST_AGENT_MAX_TURNS = 120;
const AI_BROWSER_TEST_AGENT_TURN_TIMEOUT_SEC = 20;

// Builds the persistent Node/Playwright process the agent loop drives one
// action at a time. Unlike ai_playwright_test_generate()'s script (assembled
// then run start-to-finish in one shot), this one sits in a request/response
// loop over stdin/stdout: one JSON command in, one JSON result out, prefixed
// with a marker so any stray console output from Playwright can't be mistaken
// for a protocol line. click/fill resolve purely against real element HANDLES
// captured by the most recent snapshot — never a selector string — so there
// is no selector-guessing surface at all, by construction.
function ai_browser_agent_script_generate(string $appUrl, string $token, bool $hasAuth): string
{
    // Login-first, signup-fallback rather than always signing up: this same
    // block runs again on every periodic page recycle (see __recycle__
    // below), not just once at startup, and TEST_EMAIL is the same constant
    // for the whole run — a second signup attempt with an email that
    // already exists silently fails (swallowed by the outer catch), leaving
    // every recycled page running unauthenticated and false-failing any
    // story that needs a login. Trying login first makes every call after
    // the very first one succeed the normal way instead.
    $loginBlock = $hasAuth ? <<<'JSEOF'
  let loggedIn = false;
  try {
    await page.goto(APP_URL + '#/login', { waitUntil: 'networkidle', timeout: 15000 });
    await page.waitForTimeout(500);
    const loginIdField = await page.$('#auth-form #auth-identifier');
    if (loginIdField) {
      await page.fill('#auth-form #auth-identifier', TEST_EMAIL);
      await page.fill('#auth-form #auth-password', TEST_PASS);
      await page.click('#auth-form button[type="submit"]');
      loggedIn = await page.waitForFunction(
        () => { const el = document.querySelector('#nav-logout'); return el && !el.classList.contains('hidden'); },
        { timeout: 8000 }
      ).then(() => true).catch(() => false);
    }
  } catch (_) {}
  if (!loggedIn) {
    try {
      await page.goto(APP_URL + '#/signup', { waitUntil: 'networkidle', timeout: 15000 });
      await page.waitForTimeout(500);
      const idField = await page.$('#auth-form #auth-identifier');
      if (idField) {
        await page.fill('#auth-form #auth-identifier', TEST_EMAIL);
        await page.fill('#auth-form #auth-password', TEST_PASS);
        await page.click('#auth-form button[type="submit"]');
        await page.waitForFunction(
          () => { const el = document.querySelector('#nav-logout'); return el && !el.classList.contains('hidden'); },
          { timeout: 15000 }
        ).catch(() => {});
      }
    } catch (_) {}
  }
JSEOF
        : '';

    $script = <<<'JSEOF'
import { chromium } from 'playwright-core';
import readline from 'readline';

const TOKEN = '__TOKEN__';
const APP_URL = '__APP_URL__';
const TEST_EMAIL = `pw-agent-${Date.now()}@testmail.dev`;
const TEST_PASS = 'TestPass123!';

let browser, page;
let lastHandles = [];
let lastPath = '/';

function sendResult(obj) {
  process.stdout.write('@@RESULT@@' + JSON.stringify(obj) + '\n');
}

function isDisconnectError(e) {
  const msg = String((e && e.message) || e || '').toLowerCase();
  return msg.includes('closed') || msg.includes('disconnected') || msg.includes('crashed');
}

async function connectAndLogin() {
  browser = await chromium.connectOverCDP(`wss://chrome.browserless.io?token=${TOKEN}`);
  page = await browser.newPage();
  page.setDefaultTimeout(15000);
__LOGIN_BLOCK__
}

async function doSnapshot() {
  const candidates = await page.$$('button, a, input, select, textarea, [role="button"]');
  const items = [];
  for (const h of candidates) {
    let visible = false;
    try { visible = await h.isVisible(); } catch (_) { visible = false; }
    if (!visible) continue;
    let info;
    try {
      info = await h.evaluate(el => ({
        tag: el.tagName.toLowerCase(),
        text: (el.innerText || el.value || el.placeholder || '').trim().slice(0, 80),
        type: el.type || null,
        checked: typeof el.checked === 'boolean' ? el.checked : null,
        disabled: !!el.disabled,
      }));
    } catch (_) { continue; }
    items.push({ handle: h, info });
    if (items.length >= 60) break;
  }
  lastHandles = items.map(it => it.handle);
  let bodyText = '';
  try { bodyText = await page.evaluate(() => document.body.innerText); } catch (_) {}
  return {
    url: page.url(),
    elements: items.map((it, i) => Object.assign({ index: i }, it.info)),
    bodyText: bodyText.slice(0, 1500),
  };
}

// Runs one command against the current page. Thrown errors propagate to the
// caller, which decides whether they're worth a reconnect-and-retry.
async function runCommand(cmd) {
  const tool = cmd.tool;
  const args = cmd.args || {};
  if (tool === 'navigate') {
    const path = String(args.path || '/').replace(/^#?\/?/, '/');
    lastPath = path;
    await page.goto(APP_URL + '#' + path, { waitUntil: 'networkidle', timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(500);
    return { tool, result: await doSnapshot() };
  }
  if (tool === 'snapshot') return { tool, result: await doSnapshot() };
  if (tool === 'click') {
    const h = lastHandles[args.index];
    if (!h) return { tool, error: 'no element at index ' + args.index + ' — call snapshot again' };
    await h.click({ timeout: 8000 });
    // Give an async handler (a fetch + re-render) a moment to at least start
    // before control returns — without this, a snapshot called immediately
    // after can catch the page mid-update, or a second click on what LOOKS
    // like the same still-live element can double-toggle it back before the
    // first click's own effect ever became visible.
    await page.waitForTimeout(300);
    // Bundling a fresh snapshot into the result (same as navigate already
    // does) instead of making the model spend a whole separate LLM turn on
    // an explicit snapshot call to see what its own action just did — that
    // was ~10s of pure model-decision latency per click/fill/wait for
    // nothing but permission to look at the page it just acted on.
    return { tool, result: Object.assign({ ok: true }, await doSnapshot()) };
  }
  if (tool === 'fill') {
    const h = lastHandles[args.index];
    if (!h) return { tool, error: 'no element at index ' + args.index + ' — call snapshot again' };
    await h.fill(String(args.value ?? ''), { timeout: 8000 });
    return { tool, result: Object.assign({ ok: true }, await doSnapshot()) };
  }
  if (tool === 'wait') {
    const ms = Math.min(Math.max(parseInt(args.ms, 10) || 500, 0), 3000);
    await page.waitForTimeout(ms);
    return { tool, result: Object.assign({ ok: true }, await doSnapshot()) };
  }
  return { tool, error: 'unknown tool: ' + tool };
}

// The remote Browserless session can die mid-test for reasons that have
// nothing to do with the app under test (a session/idle cap, a network
// blip) — a multi-story agent loop's real wall-clock time (every turn
// waits on a full model round-trip) can run well past what a single
// linear script ever needed. Treating that the same as "the feature is
// broken" would be exactly the false-negative superstition this whole
// agent replaced the old selector-guessing tests to avoid. One
// reconnect-and-retry attempt distinguishes a transient infra hiccup from
// a real failure; only a SECOND failure is reported to the model as-is.
async function handleCommand(cmd) {
  try {
    return await runCommand(cmd);
  } catch (e) {
    if (!isDisconnectError(e)) return { tool: cmd.tool, error: String((e && e.message) || e) };
    try {
      try { await browser.close(); } catch (_) {}
      await connectAndLogin();
      await page.goto(APP_URL + '#' + lastPath, { waitUntil: 'networkidle', timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(500);
      return await runCommand(cmd);
    } catch (e2) {
      return { tool: cmd.tool, error: 'browser session was interrupted (' + String((e && e.message) || e)
        + ') and could not be recovered — this is a test-infrastructure failure, not evidence the feature itself is broken' };
    }
  }
}

(async () => {
  try {
    await connectAndLogin();
  } catch (e) {
    sendResult({ tool: '__init__', error: 'Browser connect failed: ' + e.message });
    process.exit(1);
  }

  sendResult({ tool: '__init__', result: { ok: true } });

  const rl = readline.createInterface({ input: process.stdin });
  for await (const line of rl) {
    const trimmed = line.trim();
    if (!trimmed) continue;
    let cmd;
    try { cmd = JSON.parse(trimmed); } catch (_) { continue; }
    if (cmd.tool === '__shutdown__') {
      try { await browser.close(); } catch (_) {}
      process.exit(0);
    }
    if (cmd.tool === '__recycle__') {
      // Deliberate periodic close+reconnect (called by the PHP loop at each
      // story boundary, not just on error) -- a long-lived page/browser
      // accumulates enough local + remote state over many turns to risk
      // this account's per-process memory ceiling on a big multi-story run
      // (confirmed live: a 140-turn run died mid-test from exactly this).
      // Recycling bounds growth to roughly one story's worth of turns.
      try {
        try { await browser.close(); } catch (_) {}
        await connectAndLogin();
        await page.goto(APP_URL + '#' + lastPath, { waitUntil: 'networkidle', timeout: 15000 }).catch(() => {});
        await page.waitForTimeout(500);
        sendResult({ tool: '__recycle__', result: { ok: true } });
      } catch (e) {
        sendResult({ tool: '__recycle__', error: 'recycle failed: ' + String((e && e.message) || e) });
      }
      continue;
    }
    const res = await handleCommand(cmd);
    sendResult(res);
  }
  try { await browser.close(); } catch (_) {}
  process.exit(0);
})();
JSEOF;

    return str_replace(
        ['__TOKEN__', '__APP_URL__', '__LOGIN_BLOCK__'],
        [addslashes($token), addslashes($appUrl), $loginBlock],
        $script
    );
}

// Spawns the persistent agent script as its own OS process with stdin/stdout
// kept open as pipes across many send/receive turns (unlike
// ai_playwright_test_run's one-shot proc_open, which closes immediately after
// a single run). stderr goes to a file, not a pipe — an unread stderr pipe
// fills its OS buffer and blocks the child process, and nothing here ever
// drains it mid-run the way stdout is drained every turn.
function ai_browser_agent_spawn(string $script, array $config): ?array
{
    $nodeBin       = $config['NODE_BIN'] ?? '/opt/alt/alt-nodejs16/root/usr/bin/node';
    $nodeModules   = $config['PLAYWRIGHT_MODULES'] ?? '/home/dxinethn/playwright-test/node_modules';
    $playwrightDir = rtrim(dirname($nodeModules), '/');
    $tmpFile       = $playwrightDir . '/sb_agent_' . getmypid() . '_' . time() . '.mjs';
    file_put_contents($tmpFile, $script);

    $errFile     = sys_get_temp_dir() . '/sb_agent_stderr_' . getmypid() . '_' . time() . '.log';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'a']];
    $env = array_merge(getenv() ?: [], ['HOME' => dirname($playwrightDir), 'PATH' => '/usr/local/bin:/usr/bin:/bin']);

    $process = proc_open(escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmpFile), $descriptors, $pipes, $playwrightDir, $env);
    if (!is_resource($process)) { @unlink($tmpFile); @unlink($errFile); return null; }
    stream_set_blocking($pipes[1], false);

    return ['proc' => $process, 'pipes' => $pipes, 'tmpFile' => $tmpFile, 'errFile' => $errFile];
}

// Sends one command and blocks (up to $timeoutSec) for the matching
// '@@RESULT@@'-prefixed response line — using stream_select rather than a
// blocking fgets() so a wedged/crashed Node process degrades to a clear
// timeout error instead of hanging this PHP process (and the job it's part
// of) forever.
// Pure read, no write — used both for the startup handshake (the process
// sends its __init__ result unprompted, before it ever reads stdin) and by
// ai_browser_agent_send() below, so a caller waiting on a response can never
// also be the one that desyncs the request/response pairing by writing an
// extra, uninvited command just to "wait" for something.
function ai_browser_agent_read(array $handles, int $timeoutSec): array
{
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) break;
        $read = [$handles['pipes'][1]];
        $write = null; $except = null;
        $sec  = (int)$remaining;
        $usec = (int)(($remaining - $sec) * 1_000_000);
        $n = @stream_select($read, $write, $except, $sec, $usec);
        if ($n === false || $n === 0) continue;
        $chunk = fgets($handles['pipes'][1]);
        if ($chunk === false) break; // EOF — the process died
        $pos = strpos($chunk, '@@RESULT@@');
        if ($pos === false) continue; // stray output — not a protocol line
        $decoded = json_decode(trim(substr($chunk, $pos + strlen('@@RESULT@@'))), true);
        if (is_array($decoded)) return $decoded;
    }
    return ['tool' => '', 'error' => 'timed out waiting for the browser agent to respond'];
}

function ai_browser_agent_send(array $handles, array $cmd, int $timeoutSec = AI_BROWSER_TEST_AGENT_TURN_TIMEOUT_SEC): array
{
    $tool = (string)($cmd['tool'] ?? '');
    if (@fwrite($handles['pipes'][0], json_encode($cmd) . "\n") === false) {
        return ['tool' => $tool, 'error' => 'failed to write to browser agent process'];
    }
    return ai_browser_agent_read($handles, $timeoutSec);
}

// Sends the shutdown command and gives the process a few seconds to close its
// browser connection cleanly before forcing it closed either way.
function ai_browser_agent_shutdown(array $handles): void
{
    @fwrite($handles['pipes'][0], json_encode(['tool' => '__shutdown__']) . "\n");
    @fclose($handles['pipes'][0]);
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $status = @proc_get_status($handles['proc']);
        if (!$status || !$status['running']) break;
        usleep(200_000);
    }
    @fclose($handles['pipes'][1]);
    @proc_close($handles['proc']);
    @unlink($handles['tmpFile']);
    @unlink($handles['errFile']);
}

// Friendly one-line label for the live progress card, mirroring
// ai_edit_agent_step_label's role for the code-editing agent.
function ai_browser_agent_step_label(string $tool, array $args): string
{
    return match ($tool) {
        'navigate'      => 'Navigating to ' . ($args['path'] ?? '/') . '…',
        'snapshot'       => 'Looking at the page…',
        'click'          => 'Clicking element ' . ($args['index'] ?? '?') . '…',
        'fill'           => 'Filling element ' . ($args['index'] ?? '?') . '…',
        'wait'           => 'Waiting…',
        'report_story'   => 'Recording: ' . ($args['label'] ?? '?') . '…',
        'finish'         => 'Finishing up…',
        default          => 'Working…',
    };
}

// Drives the live browser-testing agent to completion. Returns the same
// ['stories'=>[...], 'passed'=>N, 'failed'=>N] shape ai_playwright_test_run()
// returns, so ai_run_project_tests() can merge the two directly with no
// shape changes downstream.
function ai_run_browser_test_agent(
    object $client, array $stories, string $appUrl, string $browserlessToken, bool $hasAuth, callable $report
): array {
    $zeroUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    if (!$stories) return ['stories' => [], 'passed' => 0, 'failed' => 0, 'usage' => $zeroUsage];

    $script  = ai_browser_agent_script_generate($appUrl, $browserlessToken, $hasAuth);
    $handles = ai_browser_agent_spawn($script, \App::get('config'));
    if ($handles === null) {
        return ['stories' => [], 'passed' => 0, 'failed' => 1, 'error' => 'Failed to launch the browser testing agent', 'usage' => $zeroUsage];
    }

    // Wait for the __init__ handshake (browser connected + logged in) before
    // handing control to the model — a connect failure here is a hard stop,
    // not something for the agent to work around turn by turn. Pure read, no
    // write: the process sends this line on its own, before it ever reads
    // stdin, so writing a command here would just sit unread in its stdin
    // buffer and desync every response that follows.
    $init = ai_browser_agent_read($handles, 25);
    if (!empty($init['error']) && ($init['tool'] ?? '') === '__init__') {
        ai_browser_agent_shutdown($handles);
        return ['stories' => [], 'passed' => 0, 'failed' => 1, 'error' => $init['error'], 'usage' => $zeroUsage];
    }

    $storiesText = implode("\n", array_map(fn($s, $i) => ($i + 1) . '. ' . $s, $stories, array_keys($stories)));
    $agentPrompt = AI_BROWSER_TEST_AGENT_SYSTEM_HEADER;
    $turnMsg = "User stories to verify, in order:\n{$storiesText}\n\nBegin with your first tool action.";
    $loopHistory = [];
    $recorded = [];
    $aiTrace  = [];
    $finished = false;
    $recentCalls = [];
    // Aggregated across every turn — a loop of up to AI_BROWSER_TEST_AGENT_MAX_TURNS
    // calls makes $client->getLastUsage() alone wildly undercount the real cost.
    $totalUsage = $zeroUsage;
    $consecutiveParseFailures = 0;

    // The whole run's turn budget is shared across every story with no per-
    // story limit, so a story the agent gets stuck on (e.g. a broken login
    // loop) can burn the entire budget and leave every later story completely
    // untested -- "turn budget exhausted before this story could be tested"
    // for story after story, even though most of them were never actually
    // attempted. A per-story ceiling guarantees every story gets a real
    // attempt: once a story's own share runs out, it's marked failed and the
    // agent is explicitly told to move on, instead of silently consuming
    // turns meant for stories still waiting.
    //
    // Recomputed every check (not fixed once upfront) from whatever turns and
    // stories are ACTUALLY left at that moment: a story that finishes well
    // under its share leaves the remaining turns to divide across fewer
    // remaining stories, so later ones automatically get more room instead of
    // a flat allotment that treats "logout button is present" the same as
    // "schedule a mentorship session".
    $turnsAtLastReport = 0;

    for ($turn = 1; $turn <= AI_BROWSER_TEST_AGENT_MAX_TURNS; $turn++) {
        $storiesLeft    = count($stories) - count($recorded);
        $turnsLeft      = AI_BROWSER_TEST_AGENT_MAX_TURNS - $turn + 1;
        $perStoryBudget = max(4, (int)floor($turnsLeft / max(1, $storiesLeft)));
        if (count($recorded) < count($stories) && ($turn - $turnsAtLastReport) > $perStoryBudget) {
            $stuckStory = $stories[count($recorded)];
            // 'skipped' (never actually observed either way) is a materially
            // different signal than a real, observed failure — conflating the
            // two as one flat 'passed: false' bucket is what let a Resolve
            // run burn its entire turn budget hunting for bugs in features
            // that were never actually shown broken, live-caught fixing
            // nothing for project 30. 'passed' stays false so every existing
            // consumer (pass/fail counts, dashboard summaries) keeps working
            // unchanged; 'skipped' is purely additive for anything that wants
            // to tell the two apart.
            $recorded[] = ['label' => $stuckStory, 'passed' => false, 'skipped' => true,
                'detail' => "Exceeded its share of the run's turn budget ({$perStoryBudget} turns) — moved on so later stories still get tested"];
            $turnsAtLastReport = $turn;
            $turnMsg = json_encode(['tool' => 'system', 'note' =>
                "\"{$stuckStory}\" took too long and was marked failed to protect the remaining stories' turn budget. Move on to the next untested story now."]);
            continue;
        }
        $_t0 = microtime(true);
        try {
            $action = $client->generateJsonWithHistory($agentPrompt, $loopHistory, $turnMsg);
        } catch (\Throwable $e) {
            if (ai_is_unrecoverable_provider_error($e->getMessage())) {
                throw new \RuntimeException('AI provider error during browser testing: ' . $e->getMessage());
            }
            $ms = (int)((microtime(true) - $_t0) * 1000);
            $aiTrace[] = ['stage' => 'browser_test_agent', 'system' => $agentPrompt, 'history' => [],
                'user_msg' => mb_strlen($turnMsg) > 3000 ? mb_substr($turnMsg, 0, 3000) : $turnMsg,
                'response' => ['error' => $e->getMessage()], 'tokens' => $client->getLastUsage(), 'ms' => $ms, 'retry' => true, 'error' => $e->getMessage()];
            if (ai_agent_is_rate_limited($e->getMessage())) {
                $report(['stage' => 'stories', 'status' => 'active', 'label' => 'Testing user stories…', 'detail' => 'Rate limited by the AI provider — waiting…']);
            } else {
                $report(['stage' => 'stories', 'status' => 'active', 'label' => 'Testing user stories…', 'detail' => 'Response was invalid, retrying…']);
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

        $aiTrace[] = ['stage' => 'browser_test_agent', 'system' => $agentPrompt, 'history' => [],
            'user_msg' => mb_strlen($turnMsg) > 3000 ? mb_substr($turnMsg, 0, 3000) : $turnMsg,
            'response' => $action, 'tokens' => $usage, 'ms' => $ms, 'retry' => false];

        $report(['stage' => 'stories', 'status' => 'active', 'label' => 'Testing user stories…', 'detail' => ai_browser_agent_step_label($tool, $args)]);

        $loopHistory[] = ['role' => 'user', 'text' => $turnMsg];
        $loopHistory[] = ['role' => 'model', 'text' => json_encode($action)];
        $loopHistory   = ai_agent_trim_history($loopHistory);

        if ($tool === 'report_story') {
            $label = (string)($args['label'] ?? 'Untitled story');
            $entry = [
                'label'  => $label,
                'passed' => (bool)($args['passed'] ?? false),
                'detail' => (string)($args['detail'] ?? ''),
            ];
            // The turn-budget timeout above can already have auto-failed this
            // exact story (moving $recorded forward so later stories still get
            // a turn) before the model, still mid-investigation, gets around
            // to reporting it for real. Replace that placeholder instead of
            // appending — the model's own specific result is strictly better
            // information than the generic timeout message, and appending
            // would double-count one story as two entries in the final tally.
            $dupIndex = null;
            foreach ($recorded as $i => $r) {
                if ($r['label'] === $label) { $dupIndex = $i; break; }
            }
            if ($dupIndex !== null) {
                $recorded[$dupIndex] = $entry;
            } else {
                $recorded[] = $entry;
            }
            $turnsAtLastReport = $turn;
            // Recycle the browser/page at this natural story boundary — see
            // the Node script's __recycle__ handler's own comment for why.
            // Best-effort: if it fails, the next real command's existing
            // disconnect-and-retry path recovers anyway.
            ai_browser_agent_send($handles, ['tool' => '__recycle__']);
            $turnMsg = json_encode(['tool' => 'report_story', 'result' => ['ok' => true, 'recorded' => count($recorded), 'of' => count($stories)]]);
            continue;
        }

        if ($tool === 'finish') {
            if (count($recorded) < count($stories)) {
                $turnMsg = json_encode(['tool' => 'finish', 'error' =>
                    'Only ' . count($recorded) . ' of ' . count($stories) . ' stories have been reported — continue testing the rest before finishing.']);
                continue;
            }
            $finished = true;
            break;
        }

        if (ai_agent_detect_stuck_repeat($recentCalls, $tool, $args)) {
            $turnMsg = json_encode(['tool' => $tool, 'error' =>
                'You have called this exact action with these exact arguments several times in a row with no ' .
                'different result to show for it. Repeating it again will not work either — try a genuinely ' .
                'different action, or if this story truly cannot be verified, report_story it false with what ' .
                'you actually observed.']);
            continue;
        }

        $result = ai_browser_agent_send($handles, ['tool' => $tool, 'args' => $args]);
        $turnMsg = json_encode($result);
    }

    if (!$finished) {
        // Turn budget exhausted — anything not yet reported is an honest,
        // visible gap rather than a silently dropped story.
        $reportedLabels = array_column($recorded, 'label');
        foreach ($stories as $s) {
            $alreadyCovered = false;
            foreach ($reportedLabels as $rl) if (str_contains($rl, $s) || str_contains($s, $rl)) { $alreadyCovered = true; break; }
            if (!$alreadyCovered) {
                $recorded[] = ['label' => $s, 'passed' => false, 'skipped' => true, 'detail' => 'Turn budget exhausted before this story could be tested'];
            }
        }
    }

    ai_browser_agent_shutdown($handles);

    $passed = count(array_filter($recorded, fn($s) => $s['passed']));
    $failed = count($recorded) - $passed;
    return ['stories' => $recorded, 'passed' => $passed, 'failed' => $failed, 'aiTrace' => $aiTrace, 'usage' => $totalUsage];
}

// Deterministic syntax check for a single agent-written file — used by the
// edit agent loop's write_file/syntax_check tools. A .js file is checked
// directly; an .html file has each inline (non-src) <script> block extracted
// and checked individually, since `node --check` only understands plain JS.
// Returns ['ok' => bool, 'error' => ?string] — never throws.
function ai_check_js_syntax(string $path, string $content, array $config): array
{
    $nodeBin = $config['NODE_BIN'] ?? '/opt/alt/alt-nodejs16/root/usr/bin/node';
    $ext     = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

    $blocks = [];
    if ($ext === 'js') {
        $blocks[] = $content;
    } elseif ($ext === 'html') {
        if (preg_match_all('#<script(?![^>]*\bsrc\s*=)[^>]*>(.*?)</script>#is', $content, $m)) {
            foreach ($m[1] as $inline) {
                if (trim($inline) !== '') $blocks[] = $inline;
            }
        }
    } else {
        return ['ok' => true, 'error' => null]; // nothing to check (e.g. .json, .css)
    }

    foreach ($blocks as $i => $block) {
        $tmp = sys_get_temp_dir() . '/sb_agentcheck_' . getmypid() . '_' . time() . '_' . $i . '.mjs';
        file_put_contents($tmp, $block);
        exec(escapeshellarg($nodeBin) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
        @unlink($tmp);
        if ($code !== 0) {
            return ['ok' => false, 'error' => trim(implode("\n", $out))];
        }
        $out = [];
    }
    return ['ok' => true, 'error' => null];
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

// A test run creates real rows through the live app itself -- the fixed
// TEST_EMAIL signup, plus whatever data a story's own interactions leave
// behind (an enrollment, a project application, a mentorship booking,
// anything a story exercises with agent-invented content that has no fixed,
// recognizable pattern to match on afterward). None of that goes through the
// PHP seed-insert path, so project_seed_rows never learns about it and Clear
// Seed Data has no way to find it. Snapshotting each table's MAX(id) before
// the run and tracking whatever's newer afterward catches all of it, in any
// table, regardless of what the row actually looks like.
function ai_snapshot_table_high_marks(int $projectId, \SupaBein\Catalog $catalog): array
{
    $pdo   = \App::get('db');
    $marks = [];
    foreach ($catalog->listTables($projectId) as $t) {
        try {
            $marks[$t['table_name']] = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$t['physical_name']}`")->fetchColumn();
        } catch (\Throwable $e) { /* non-fatal -- table may be mid-migration */ }
    }
    return $marks;
}

function ai_track_new_rows_since(int $projectId, \SupaBein\Catalog $catalog, array $beforeMarks): void
{
    $pdo = \App::get('db');
    foreach ($catalog->listTables($projectId) as $t) {
        $before = $beforeMarks[$t['table_name']] ?? null;
        if ($before === null) continue; // wasn't there for the "before" snapshot -- nothing to diff against
        try {
            $stmt = $pdo->prepare("SELECT id FROM `{$t['physical_name']}` WHERE id > ?");
            $stmt->execute([$before]);
            $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            if ($ids) ai_track_seed_rows($pdo, $projectId, $t['table_name'], $ids);
        } catch (\Throwable $e) { /* non-fatal */ }
    }
}

function ai_run_project_tests(int $projectId, int $userId, \SupaBein\Catalog $catalog, array $config, callable $report, ?object $client = null): array
{
    $project = $catalog->getProjectById($projectId, $userId);
    if (!$project) throw new \RuntimeException('Project not found');

    $sites = $catalog->listSites($projectId);
    if (!$sites) throw new \RuntimeException('No deployed site found — build the project first');

    $preTestMarks = ai_snapshot_table_high_marks($projectId, $catalog);

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

    // Generic auth/CRUD/isolation/logout tests, deterministic and unchanged —
    // these already target conventions AI_FRONTEND_RULES mandates (name="col",
    // #auth-form ids, etc.), so they don't share the "distinctive feature"
    // story tests' guessing problem below.
    $script = ai_playwright_test_generate($appUrl, $browserlessToken, $schema, $indexHtml, $projectId, '');

    $report(['stage' => 'run', 'status' => 'start', 'label' => 'Running browser tests…']);
    $result = ai_playwright_test_run($script, $config);
    $report(['stage' => 'run', 'status' => 'done',
             'label'  => 'Tests finished',
             'detail' => ($result['passed'] ?? 0) . ' passed, ' . ($result['failed'] ?? 0) . ' failed']);

    // Story-driven tests: the user stories captured in the Review flow (saved
    // to project_requirements) become their own live, agent-driven browser
    // checks. If the project never went through Review, infer stories from
    // the schema + the deployed frontend instead, so every project still gets
    // story tests. Best effort throughout — any failure here just means the
    // deterministic tests above stand alone.
    $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    if ($client) {
        $report(['stage' => 'stories', 'status' => 'start', 'label' => 'Testing user stories…']);
        try {
            $stories = ai_extract_saved_stories($catalog->getProjectRequirements($projectId));
            $source  = 'saved';
            if (!$stories) {
                $stories = ai_infer_stories($client, $schema, $indexHtml);
                $source  = 'inferred';
                $usage   = $client->getLastUsage();
                foreach ($totalUsage as $k => $v) $totalUsage[$k] = $v + (int)($usage[$k] ?? 0);
            }
            $authInfo   = ai_detect_auth($schema);
            $agentResult = ai_run_browser_test_agent($client, $stories, $appUrl, $browserlessToken, !empty($authInfo['table']), $report);
            foreach ($totalUsage as $k => $v) $totalUsage[$k] = $v + (int)($agentResult['usage'][$k] ?? 0);
            if (!empty($agentResult['stories'])) {
                $result['stories'] = array_merge($result['stories'] ?? [], $agentResult['stories']);
                $result['passed']  = ($result['passed'] ?? 0) + $agentResult['passed'];
                $result['failed']  = ($result['failed'] ?? 0) + $agentResult['failed'];
            }
            if (!empty($agentResult['aiTrace'])) {
                $result['aiTrace'] = array_merge($result['aiTrace'] ?? [], $agentResult['aiTrace']);
            }
            $report(['stage' => 'stories', 'status' => 'done',
                     'label'  => $stories ? 'User stories tested' : 'No user stories',
                     'detail' => $stories
                         ? count($stories) . ' user stories tested (' . $source . ') — '
                             . $agentResult['passed'] . ' passed, ' . $agentResult['failed'] . ' failed'
                         : (!empty($agentResult['error']) ? $agentResult['error'] : 'no stories to test')]);
        } catch (\Throwable $e) {
            $report(['stage' => 'stories', 'status' => 'done', 'label' => 'User-story testing skipped', 'detail' => $e->getMessage()]);
        }
    }

    sb_log('ai_test', !empty($result['error']) ? 'Failed: ' . $result['error'] : 'Complete', [
        'project_id' => $projectId,
        'target'     => $target,
        'passed'     => $result['passed'] ?? null,
        'failed'     => $result['failed'] ?? null,
    ]);

    // Validate the same deployed files being tested, so one job produces one
    // combined picture of the app's health instead of two separate checks.
    $validation = [];
    $report(['stage' => 'validate', 'status' => 'start', 'label' => 'Checking for mismatches…']);
    try {
        $frontendFiles = ai_read_full_frontend_files($config, $catalog, $projectId, $target);
        $validation    = ai_validator_check_project($schema, $frontendFiles);
        if ($client && array_filter($validation, fn($f) => $f['severity'] === 'error')) {
            $validation = ai_validator_explain_findings($validation, $client);
            $usage = $client->getLastUsage();
            foreach ($totalUsage as $k => $v) $totalUsage[$k] = $v + (int)($usage[$k] ?? 0);
        }
        $errCount  = count(array_filter($validation, fn($f) => $f['severity'] === 'error'));
        $warnCount = count(array_filter($validation, fn($f) => $f['severity'] === 'warning'));
        $report(['stage' => 'validate', 'status' => 'done',
                 'label'  => $validation ? 'Validation found issues' : 'No issues found',
                 'detail' => $validation ? "{$errCount} error(s), {$warnCount} warning(s)" : '']);
    } catch (\Throwable $e) {
        $report(['stage' => 'validate', 'status' => 'done', 'label' => 'Validation skipped', 'detail' => $e->getMessage()]);
    }

    ai_track_new_rows_since($projectId, $catalog, $preTestMarks);

    return array_merge($result, ['target' => $target, 'validation' => $validation, 'usage' => $totalUsage]);
}

// Hard cap on auto-fix cycles in ai_run_test_and_autofix() below. Each cycle
// is a full edit generation + deploy + re-test, not a single API call — an
// unbounded "just keep trying" loop here would be the same failure mode as
// today's JSON-parse retry storm, just at a much higher cost per iteration.
const AI_TEST_AUTOFIX_MAX_ATTEMPTS = 2;

// Runs the test suite, and if it reports failing user stories, feeds the
// SPECIFIC failures (story label + what was actually observed, not just
// "something failed") back in as an edit request, applies and deploys the
// fix to STAGING ONLY (identical to how every normal edit apply already
// works — the user still publishes to live explicitly, autofix never does
// that for them), then re-tests. Always goes through the edit agent, never
// the build agent — once a project exists, fixing it is an edit no matter
// whether the failure surfaced right after the very first build or much
// later. Stops on a clean pass, the hard cap above, or the failing-story
// set coming back byte-identical to the previous attempt's — a fix that
// visibly changed nothing is not worth paying for a second time.
function ai_run_test_and_autofix(int $projectId, int $userId, \SupaBein\Catalog $catalog, array $config, callable $report, object $client): array
{
    $fixAttempts = [];
    $prevFailingSignature = null;
    // ai_run_project_tests()'s own 'usage' only ever covers that ONE call —
    // each retest below overwrites it on $result, so the running total
    // across every test pass AND every autofix edit in between has to be
    // tracked separately here, not read back off $result at the end.
    $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    $addUsage = function (?array $usage) use (&$totalUsage) {
        foreach ($totalUsage as $k => $v) $totalUsage[$k] = $v + (int)($usage[$k] ?? 0);
    };

    $result = ai_run_project_tests($projectId, $userId, $catalog, $config, $report, $client);
    $addUsage($result['usage'] ?? null);

    for ($fixAttempt = 1; $fixAttempt <= AI_TEST_AUTOFIX_MAX_ATTEMPTS; $fixAttempt++) {
        $failingStories = array_values(array_filter($result['stories'] ?? [], fn($s) => empty($s['passed'])));
        if (!$failingStories) break; // clean — nothing to fix

        $signature = implode('|', array_map(
            fn($s) => ($s['label'] ?? '') . ':' . ($s['detail'] ?? ''),
            $failingStories
        ));
        if ($signature === $prevFailingSignature) {
            $result['autofix_stalled'] = true;
            break; // the previous fix attempt visibly changed nothing — don't repeat it
        }
        $prevFailingSignature = $signature;

        $report(['stage' => 'autofix', 'status' => 'active', 'label' =>
            "Auto-fix attempt {$fixAttempt}/" . AI_TEST_AUTOFIX_MAX_ATTEMPTS . ': fixing '
            . count($failingStories) . ' failing ' . (count($failingStories) === 1 ? 'story' : 'stories') . '…']);

        $fixPrompt = "The following user stories failed real browser testing against the deployed app. Fix the "
            . "actual underlying problem behind each one:\n\n" . implode("\n\n", array_map(
                fn($s) => '- "' . ($s['label'] ?? 'Untitled story') . '": ' . ($s['detail'] ?? 'no detail captured'),
                $failingStories
            ));

        $editResult = ai_run_edit_generation($projectId, $fixPrompt, [], $client, $catalog, $config, $report, true);
        $plan       = $editResult['plan'] ?? [];
        $addUsage($editResult['usage'] ?? null);

        $deltaError = ai_validate_delta($plan, ai_schema_from_db($projectId, $catalog));
        if ($deltaError !== null) {
            // Nothing safe to apply — stop rather than deploy a broken
            // change or loop again on a generation that's already failing.
            $result['autofix_error'] = "Auto-fix attempt {$fixAttempt} produced an invalid change: {$deltaError}";
            break;
        }

        ai_execute_edit($plan, $projectId, $userId);

        $project = $catalog->getProjectById($projectId, $userId);
        $sites   = $catalog->listSites($projectId);
        if ($project && $sites && !empty($plan['frontend']['files'])) {
            $updatedSchema = ai_schema_from_db($projectId, $catalog);
            // Staging only — same deploy call and same $publishLive=false
            // every normal edit apply already uses.
            ai_deploy_files($config, $catalog, (int)$sites[0]['id'], $project,
                             $plan['frontend']['files'], true, false, ai_detect_auth($updatedSchema));
        }

        $fixAttempts[] = [
            'attempt'         => $fixAttempt,
            'failing_stories' => array_map(fn($s) => $s['label'] ?? '', $failingStories),
            'fix_summary'     => [
                'add_tables'      => count($plan['add_tables'] ?? []),
                'add_columns'     => count($plan['add_columns'] ?? []),
                'update_policies' => count($plan['update_policies'] ?? []),
                'frontend_files'  => count($plan['frontend']['files'] ?? []),
            ],
        ];

        $report(['stage' => 'autofix', 'status' => 'active', 'label' => "Auto-fix attempt {$fixAttempt}: re-testing…"]);
        $result = ai_run_project_tests($projectId, $userId, $catalog, $config, $report, $client);
        $addUsage($result['usage'] ?? null);
    }

    $result['autofix_attempts'] = $fixAttempts;
    $result['autofix_gave_up']  = (bool)array_filter($result['stories'] ?? [], fn($s) => empty($s['passed']));
    $result['usage'] = $totalUsage;
    return $result;
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

// A worker's shutdown handler marks its own job failed on a caught error, but
// nothing catches a SIGKILL from the host's process/resource limits (cPanel
// LVE, OOM, etc.) — that leaves the row stuck at status='running' forever,
// with the panel polling a job that will never resolve. Detect that: it's
// been quiet for a while AND the OS process it was claimed under is gone.
function ai_job_is_orphaned(array $job): bool
{
    if (($job['status'] ?? null) !== 'running' || empty($job['pid'])) return false;

    $updatedAt = strtotime((string)$job['updated_at'] . ' UTC');
    if ($updatedAt === false || (time() - $updatedAt) < 300) return false; // give slow stages room to breathe

    $pid = (int)$job['pid'];
    if (function_exists('posix_kill')) {
        return !@posix_kill($pid, 0); // signal 0: existence check only, sends nothing
    }
    return !is_dir('/proc/' . $pid);
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

        $prompt = trim($req['body']['prompt'] ?? '');
        if ($prompt === '' || strlen($prompt) > 2000) {
            abort(422, 'prompt is required and must be under 2000 characters');
        }
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
            // see them too.
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
            json_out($result);

        } else {
            abort(422, 'mode must be build or edit');
        }

    }, ['auth_middleware']);

    // ── AI Sessions (DB-backed) ────────────────────────────────────────────────

    // History is project-scoped: pass ?project_id=<id> for a specific project's
    // sessions, ?project_id=none for the "Build with AI" bucket (no project yet),
    // or omit entirely for the full unscoped list.
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
            $client = make_ai_client($config, null, null); // default fast model — keep it cheap
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
