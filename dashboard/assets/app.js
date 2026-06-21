'use strict';

// ─── Auth ────────────────────────────────────────────────────────────────────

const Auth = (() => {
  const KEY = 'sb_token';

  function getToken() { return localStorage.getItem(KEY); }
  function setToken(t) { localStorage.setItem(KEY, t); }
  function clear() { localStorage.removeItem(KEY); }
  function isLoggedIn() { return !!getToken(); }

  function parseJwt(token) {
    try {
      return JSON.parse(atob(token.split('.')[1]));
    } catch { return null; }
  }

  function getUser() {
    const t = getToken();
    return t ? parseJwt(t) : null;
  }

  return { getToken, setToken, clear, isLoggedIn, getUser };
})();

// ─── API ─────────────────────────────────────────────────────────────────────

const Api = (() => {
  // Derive API base from current location
  const BASE = (() => {
    const parts = window.location.pathname.split('/');
    // Remove 'dashboard/index.html' or similar to get to /api
    const base = window.location.origin + '/api';
    return base;
  })();

  async function request(method, path, data, isFormData = false) {
    const headers = {};
    const token = Auth.getToken();
    if (token) headers['Authorization'] = 'Bearer ' + token;

    let body;
    if (isFormData) {
      body = data; // FormData — no Content-Type header (browser sets it with boundary)
    } else if (data && method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(data);
    }

    const res = await fetch(BASE + path, { method, headers, body });

    if (res.status === 401) {
      Auth.clear();
      Router.navigate('/login');
      return null;
    }

    const json = await res.json().catch(() => ({}));

    if (!res.ok) {
      const err = new ApiError(json.error || 'Request failed', res.status, json);
      console.error('[SupaBein] API error', { method, path, status: res.status, message: err.message });
      throw err;
    }

    return json;
  }

  class ApiError extends Error {
    constructor(msg, status, data = {}) {
      super(msg);
      this.status = status;
      this.data = data;
    }
  }

  return {
    BASE,
    get:    (path)       => request('GET',    path),
    post:   (path, data) => request('POST',   path, data),
    patch:  (path, data) => request('PATCH',  path, data),
    put:    (path, data) => request('PUT',    path, data),
    delete: (path)       => request('DELETE', path),
    upload: (path, formData) => request('POST', path, formData, true),
    ApiError,
  };
})();

// ─── Helpers ─────────────────────────────────────────────────────────────────

function el(tag, attrs = {}, ...children) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k.startsWith('on')) e.addEventListener(k.slice(2).toLowerCase(), v);
    else if (k === 'class') e.className = v;
    else e.setAttribute(k, v);
  }
  for (const c of children.flat()) {
    if (typeof c === 'string') e.appendChild(document.createTextNode(c));
    else if (c) e.appendChild(c);
  }
  return e;
}

function h(html) {
  const d = document.createElement('div');
  d.innerHTML = html.trim();
  return d;
}

function setApp(node) {
  const app = document.getElementById('app');
  app.innerHTML = '';
  if (typeof node === 'string') {
    app.innerHTML = node;
  } else {
    app.appendChild(node instanceof HTMLElement ? node : node.firstChild);
  }
}

function showAlert(container, msg, type = 'error') {
  const a = container.querySelector('.alert');
  if (a) a.remove();
  const div = el('div', { class: `alert alert-${type}` }, msg);
  container.insertBefore(div, container.firstChild);
}

function fmtDate(d) {
  return d ? new Date(d).toLocaleString() : '—';
}


function downloadText(filename, content) {
  const blob = new Blob([content], { type: 'text/plain' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
}

function makeCollapsible(card, open = false) {
  const title = card.querySelector('.api-table-title');
  if (!title) return;
  const bodyChildren = Array.from(card.children).filter(c => c !== title);
  const body = document.createElement('div');
  body.className = 'collaps-body';
  body.style.display = open ? '' : 'none';
  bodyChildren.forEach(c => body.appendChild(c));
  card.appendChild(body);
  const chevron = el('span', { class: 'collaps-chevron' }, open ? '▾' : '▸');
  title.classList.add('collaps-title');
  title.appendChild(chevron);
  title.addEventListener('click', () => {
    const nowOpen = body.style.display !== 'none';
    body.style.display = nowOpen ? 'none' : '';
    chevron.textContent = nowOpen ? '▸' : '▾';
  });
}

function generateClaudeMd(baseUrl, projectId, serviceKey, pat) {
  const pid  = projectId  || 'YOUR_PROJECT_ID';
  const skey = serviceKey || 'YOUR_SERVICE_KEY';
  const token = pat       || 'sb_pat_xxxx          # create one above in Personal Access Tokens';
  const siteUrl = baseUrl;

  return `# CLAUDE.md — SupaBein Project

This project uses **SupaBein** as its backend (database, REST API, and site hosting).
Read this file before writing any backend code — all data and hosting goes through SupaBein.

---

## Configuration

\`\`\`
SUPABEIN_URL=${siteUrl}
SUPABEIN_TOKEN=${token}
SUPABEIN_PROJECT_ID=${pid}
SUPABEIN_SERVICE_KEY=${skey}
SUPABEIN_SITE_ID=YOUR_SITE_ID       # Fill in after creating a site (from Deploy tab)
\`\`\`

> The PAT authenticates as the project owner — use it for control-plane operations (tables, policies, deploys).
> The service_key bypasses all row-level policies — use it only in trusted server-side code, never in frontend bundles.

---

## First-time Setup (run once)

### 1. Create the project
\`\`\`bash
PROJECT=$(curl -s -X POST "${siteUrl}/api/v1/projects" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{"name":"my-app"}')

PROJECT_ID=$(echo $PROJECT | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")
SERVICE_KEY=$(echo $PROJECT | python3 -c "import sys,json; print(json.load(sys.stdin)['service_key'])")

echo "Project ID:  $PROJECT_ID"
echo "Service key: $SERVICE_KEY"
\`\`\`

### 2. Create a site (for frontend hosting)
\`\`\`bash
curl -s -X POST "${siteUrl}/api/v1/projects/$PROJECT_ID/sites" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{"subdomain":"my-app","spa_mode":true}'
\`\`\`
Save the returned \`id\` as \`SUPABEIN_SITE_ID\`.

> **spa_mode** — when \`true\`, every URL path serves \`index.html\` so your client-side router (React Router, Vue Router, etc.) handles routing. Set \`false\` for plain static sites where each URL must match a real file.

---

## Tables

### Create a table with columns
\`\`\`bash
curl -s -X POST "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/tables" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{
    "name": "posts",
    "columns": [
      { "name": "title",   "type": "VARCHAR(255)", "nullable": false },
      { "name": "body",    "type": "TEXT",         "nullable": true  },
      { "name": "user_id", "type": "INT",          "nullable": false }
    ]
  }'
\`\`\`

### Allowed column types
\`INT\` \`BIGINT\` \`VARCHAR(255)\` \`TEXT\` \`BOOLEAN\` \`DATETIME\` \`DECIMAL(10,2)\` \`FLOAT\` \`PASSWORD\`


> \`PASSWORD\` columns are stored as bcrypt hashes. Reads always return \`null\`. Write a plaintext value and it is hashed automatically. Use the data login endpoint to verify credentials.

### List / delete tables
\`\`\`bash
curl -s "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/tables" -H "Authorization: Bearer $SUPABEIN_TOKEN"
curl -s -X DELETE "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/tables/posts" -H "Authorization: Bearer $SUPABEIN_TOKEN"
\`\`\`

---

## Row-Level Policies

Every table defaults to **deny all**. Set policies before querying as anon.

The endpoint accepts **one policy object per call** OR **an array** for batch upsert:

\`\`\`bash
# Batch — send an array (recommended)
curl -s -X PUT "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/tables/posts/policies" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '[
    { "api_role": "anon",          "operation": "SELECT", "allowed": true  },
    { "api_role": "anon",          "operation": "INSERT", "allowed": false },
    { "api_role": "authenticated", "operation": "SELECT", "allowed": true  },
    { "api_role": "authenticated", "operation": "INSERT", "allowed": true  },
    { "api_role": "authenticated", "operation": "UPDATE", "allowed": true  },
    { "api_role": "authenticated", "operation": "DELETE", "allowed": true  }
  ]'

# Single — send one object
curl -s -X PUT "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/tables/posts/policies" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{ "api_role": "anon", "operation": "SELECT", "allowed": true }'
\`\`\`

Roles: \`anon\` (no token / public), \`authenticated\` (valid project-user JWT from /login).
Operations: \`SELECT\` \`INSERT\` \`UPDATE\` \`DELETE\`

---

## Data API

Requests with **no Authorization header** are treated as the \`anon\` role and are subject to table policies. Use the **service_key** for trusted server-side calls that need to bypass policies.

> **ID types**: \`id\` fields in responses are numbers (integers), not strings.
> Use \`===\` comparisons safely.

### Query parameters for list endpoints

| Param | Effect |
|-------|--------|
| \`?limit=N\` | Max rows to return (1–1000, default 20) |
| \`?offset=N\` | Skip N rows for pagination |
| \`?col=value\` | Exact-match filter (shorthand for eq) |
| \`?col=op.value\` | Filter with operator: \`eq\` \`neq\` \`gt\` \`gte\` \`lt\` \`lte\` \`like\` |
| \`?order=col.dir\` | Sort: \`?order=name.asc\` or multiple: \`?order=age.desc,name.asc\` |

Filter examples: \`?age=gte.18\` \`?name=like.Alice%25\` \`?status=neq.archived\`

\`\`\`bash
# List rows — returns {data: [...], count: N, limit: N, offset: N} — always unwrap .data
curl -s "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/posts?limit=20&offset=0&status=active"
# Response: {"data":[...],"count":42,"limit":20,"offset":0}

# Insert a single row (authenticated user JWT from /login)
curl -s -X POST "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/posts" \\
  -H "Authorization: Bearer $USER_JWT" \\
  -H "Content-Type: application/json" \\
  -d '{"title":"Hello","body":"World","user_id":1}'

# Bulk insert up to 500 rows in one request (service key for seeding)
curl -s -X POST "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/posts/batch" \\
  -H "Authorization: Bearer $SUPABEIN_SERVICE_KEY" \\
  -H "Content-Type: application/json" \\
  -d '[{"title":"Row 1"},{"title":"Row 2"},{"title":"Row 3"}]'
# Response: {"inserted":3,"rows":[...]}

# Get (anon) / Update (user JWT) / Delete (service key)
curl -s "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/posts/1"
curl -s -X PATCH "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/posts/1" -H "Authorization: Bearer $USER_JWT" -H "Content-Type: application/json" -d '{"title":"Updated"}'
curl -s -X DELETE "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/posts/1" -H "Authorization: Bearer $SUPABEIN_SERVICE_KEY"
\`\`\`

---

## Auth (optional — only when project has a PASSWORD column)

When any project table has a column of type \`PASSWORD\`, passwords are auto-hashed on write
and never returned on read. Use the data API login endpoint for authentication.

\`\`\`bash
# Insert a user (password is auto-hashed; use service key to bypass policies)
curl -s -X POST "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/users" \\
  -H "Authorization: Bearer $SUPABEIN_SERVICE_KEY" \\
  -H "Content-Type: application/json" \\
  -d '{"email":"user@example.com","password":"secret123"}'

# Log in → returns { token: "eyJ...", row: { id, email, ... } }
curl -s -X POST "${siteUrl}/api/v1/data/${pid}/users/login" \\
  -H "Content-Type: application/json" \\
  -d '{"email":"user@example.com","password":"secret123"}'

# Use the token for authenticated requests
curl -s "${siteUrl}/api/v1/data/${pid}/posts" \\
  -H "Authorization: Bearer $USER_TOKEN"
\`\`\`

Tokens contain \`sub\` (user row id), \`pid\` (project id), \`type: "project_user"\`.
Store as \`sb:token\` in localStorage. Policy \`api_role: "authenticated"\` matches these tokens.

---

## File Storage

\`\`\`bash
# Upload  (multipart, field name: file)
curl -s -X POST "${siteUrl}/api/v1/projects/${pid}/storage/avatars" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN" -F "file=@photo.jpg"
# returns { name, bucket, size, url }

# List files in bucket
curl -s "${siteUrl}/api/v1/projects/${pid}/storage/avatars" -H "Authorization: Bearer $SUPABEIN_TOKEN"

# Delete a file
curl -s -X DELETE "${siteUrl}/api/v1/projects/${pid}/storage/avatars/photo.jpg" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
\`\`\`

Public URL (use in <img> tags, no auth): \`${siteUrl}/api/v1/storage/${pid}/avatars/photo.jpg\`

> Bucket names: 1–63 chars, lowercase/numbers/hyphens/underscores. Max 50 MB. No .php/.py/.sh uploads.

---

## Rate Limiting

The data API allows **600 requests per minute per project**. Excess requests get \`429\` with \`Retry-After: 60\`.

---

## Deploying the Frontend

> **Staging-first rule (mandatory):** Every deploy lands in **staging** first — never directly live.
> Do NOT publish to live unless the user explicitly says so (e.g. "go live", "publish", "push to production").
> Staging URL: \`${siteUrl}/sites/s$SUPABEIN_SITE_ID/staging/\`
> Live URL:    \`${siteUrl}/sites/s$SUPABEIN_SITE_ID/current/\`

### Step 1 — Upload to staging

#### Option A — Zip upload
> **Zip structure**: files must be at the **root** of the zip, not inside a subfolder.
> ✓ correct: \`cd dist && zip -r ../deploy.zip .\`
> ✗ wrong: \`zip -r deploy.zip dist/\` — creates a \`dist/\` subfolder inside the zip and the site will 404.

\`\`\`bash
cd dist && zip -r ../deploy.zip . && cd ..
DEPLOY=$(curl -s -X POST "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN" -F "zipfile=@./deploy.zip" -F "label=v1.0.0")
DEPLOY_ID=$(echo $DEPLOY | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")
echo "Staged at: ${siteUrl}/sites/s$SUPABEIN_SITE_ID/staging/"
\`\`\`

#### Option B — File by file (CI/CD)
\`\`\`bash
DID=$(curl -sX POST "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/open" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN" -H "Content-Type: application/json" \\
  -d '{"label":"v1.0.0"}' | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")

find dist -type f | while read f; do
  REL="\${f#dist/}"
  curl -sX POST "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/$DID/files?path=$REL" \\
    -H "Authorization: Bearer $SUPABEIN_TOKEN" --data-binary "@$f"
done

# Finalize moves to staging — not live yet
curl -sX POST "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/$DID/finalize" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
\`\`\`

### Step 2 — Publish to live (only when explicitly told)

\`\`\`bash
curl -sX POST "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/$DEPLOY_ID/publish" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
\`\`\`

---

## Querying the Data API from Frontend JS

\`\`\`js
const SB_URL = '${siteUrl}/api/v1';
const SB_PID = ${pid};

const authHeaders = () => {
  const t = localStorage.getItem('sb:token');
  return t ? { Authorization: \`Bearer \${t}\` } : {};
};

// Anonymous request (no auth — subject to anon policies)
// List response is a paginated envelope {data, count, limit, offset} — unwrap .data
async function sbQuery(table, params = {}) {
  const qs  = new URLSearchParams(params).toString();
  const res = await fetch(\`\${SB_URL}/data/\${SB_PID}/\${table}?\${qs}\`);
  if (!res.ok) throw new Error(await res.text());
  const result = await res.json();
  return result.data ?? result;
}

// Authenticated request — pass a project_user JWT obtained from the login endpoint
async function sbQueryAuth(table, token, params = {}) {
  const qs  = new URLSearchParams(params).toString();
  const res = await fetch(\`\${SB_URL}/data/\${SB_PID}/\${table}?\${qs}\`, {
    headers: { Authorization: \`Bearer \${token}\` }
  });
  if (!res.ok) throw new Error(await res.text());
  const result = await res.json();
  return result.data ?? result;
}

async function sbInsert(table, data, token = null) {
  const headers = { 'Content-Type': 'application/json' };
  if (token) headers['Authorization'] = \`Bearer \${token}\`;
  const res = await fetch(\`\${SB_URL}/data/\${SB_PID}/\${table}\`, {
    method: 'POST', headers, body: JSON.stringify(data)
  });
  if (!res.ok) throw new Error(await res.text());
  return res.json();
}
\`\`\`

> Never put the \`service_key\` in frontend code — it bypasses all policies. Use anon requests (no header) for public data, and project_user JWTs (from the login endpoint) for per-user authenticated access.

---

## Rules for Claude

- Never use a separate database — all data goes through the SupaBein data API.
- Never hardcode project ID or tokens in source files — read from environment variables.
- Always create tables before inserting data.
- Always set policies on new tables — the default is deny all.
- Prefer the file-by-file deploy (Option B) for CI/CD; use zip upload for one-off deploys.
- **Always deploy to staging first. Never publish to live unless the user explicitly instructs it** (e.g. "go live", "publish", "push to production"). Staging is safe to overwrite at any time.
- Never expose your PAT or service_key in client-side code — the PAT gives owner access to all projects; the service_key bypasses all policies.
- Anon requests (no Authorization header) are subject to table policies. Use a project-user JWT (from /login) for authenticated user access.
- Do not invent API endpoints — the full reference is at ${siteUrl}/docs.
- For app-user authentication, use \`POST /v1/data/:pid/:table/login\` with a table that has a \`PASSWORD\` column. Project-user JWTs are scoped to their project.
- Use \`/v1/auth/*\` only for SupaBein platform management (operators/CI), never for app end-users.
`;
}

function requireAuth() {
  if (!Auth.isLoggedIn()) {
    Router.navigate('/login');
    return false;
  }
  return true;
}

// ─── Layout ──────────────────────────────────────────────────────────────────

function renderLayout(projectId, activeTab, content, opts = {}) {
  const user = Auth.getUser();
  const initials = user?.email ? user.email[0].toUpperCase() : '?';

  let projectName = null;
  if (projectId) {
    projectName = opts.projectName
      || sessionStorage.getItem('sb_proj_' + projectId)
      || ('Project #' + projectId);
    if (opts.projectName) {
      sessionStorage.setItem('sb_proj_' + projectId, opts.projectName);
    }
  }

  const isOn = (tab) => activeTab === tab ? ' active' : '';

  const projectNavItems = projectId ? [
    el('div', { class: 'sb-divider' }),
    el('div', { class: 'sb-nav' },
      el('div', { class: 'sb-project-ctx' },
        el('span', { class: 'sb-project-dot' }),
        el('span', { class: 'sb-project-name' }, projectName)
      ),
      el('a', { href: `#/projects/${projectId}/tables`, class: 'sb-link' + isOn('tables') },
        el('span', { class: 'sb-icon' }, '▦'),
        'Tables'
      ),
      el('a', { href: `#/projects/${projectId}/sites`, class: 'sb-link' + isOn('sites') },
        el('span', { class: 'sb-icon' }, '⇧'),
        'Deploy'
      ),
      el('a', { href: `#/projects/${projectId}/api`, class: 'sb-link' + isOn('api') },
        el('span', { class: 'sb-icon' }, '⟨⟩'),
        'API'
      ),

    )
  ] : [];

  const sidebar = el('nav', { class: 'sidebar' },
    el('div', { class: 'sb-brand' },
      el('span', { class: 'sb-logo-mark' }, 'SB'),
      el('span', { class: 'sb-logo-text' }, 'SupaBein')
    ),
    el('div', { class: 'sb-nav' },
      el('div', { class: 'sb-section-label' }, 'Workspace'),
      el('a', { href: '#/projects', class: 'sb-link' + (!projectId && activeTab !== 'account' ? ' active' : '') },
        el('span', { class: 'sb-icon' }, '⊟'),
        'Projects'
      )
    ),
    ...projectNavItems,
    el('div', { class: 'sb-spacer' }),
    el('div', { class: 'sb-divider' }),
    el('div', { class: 'sb-bottom' },
      el('a', { href: '#/account', class: 'sb-link sb-user-link' + isOn('account') },
        el('span', { class: 'sb-avatar' }, initials),
        el('div', { class: 'sb-user-details' },
          el('span', { class: 'sb-user-name' }, user?.email || 'Account'),
          el('span', { class: 'sb-user-sub' }, 'Account & Settings')
        )
      ),
      el('a', { href: '#/logout', class: 'sb-link sb-logout' },
        el('span', { class: 'sb-icon' }, '→'),
        'Sign Out'
      )
    )
  );

  // Mobile: hamburger button that toggles the drawer
  const burger = el('button', { class: 'sb-hamburger', 'aria-label': 'Toggle menu' },
    el('span'), el('span'), el('span')
  );
  const topbar = el('div', { class: 'sb-topbar' },
    el('div', { class: 'sb-topbar-brand' },
      el('span', { class: 'sb-logo-mark' }, 'SB'),
      el('span', { class: 'sb-logo-text' }, 'SupaBein')
    ),
    burger
  );

  // Dark overlay behind the open drawer
  const overlay = el('div', { class: 'sb-overlay' });

  function openDrawer() {
    sidebar.classList.add('open');
    overlay.classList.add('open');
    burger.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeDrawer() {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
    burger.classList.remove('open');
    document.body.style.overflow = '';
  }

  burger.addEventListener('click', () =>
    sidebar.classList.contains('open') ? closeDrawer() : openDrawer()
  );
  overlay.addEventListener('click', closeDrawer);

  // Any nav link tap closes the drawer (navigation happened)
  sidebar.querySelectorAll('.sb-link').forEach(a => a.addEventListener('click', closeDrawer));

  const wrap = el('div', { class: 'layout' }, sidebar, el('main', { class: 'main', id: 'content' }, ...content));
  const app = document.getElementById('app');
  app.innerHTML = '';
  app.appendChild(topbar);
  app.appendChild(overlay);
  app.appendChild(wrap);
}

// ─── Pages ───────────────────────────────────────────────────────────────────

// Login
function authBrand() {
  const homeHref = Auth.isLoggedIn() ? '#/projects' : '/';
  return h(`<a class="auth-brand" href="${homeHref}">
    <div class="auth-mark">SB</div>
    <span class="auth-brand-name">SupaBein</span>
  </a>`);
}

function renderLogin() {
  const wrap = el('div', { class: 'auth-wrap' },
    el('div', { class: 'auth-box card' },
      authBrand(),
      el('div', { class: 'auth-sub' }, 'Sign in to your account'),
      el('div', { class: 'form-group' },
        el('label', {}, 'Email'),
        el('input', { type: 'email', id: 'email', placeholder: 'you@example.com', autocomplete: 'email' })
      ),
      el('div', { class: 'form-group' },
        el('label', {}, 'Password'),
        el('input', { type: 'password', id: 'password', autocomplete: 'current-password' }),
        el('a', { class: 'auth-forgot', href: '#/forgot' }, 'Forgot password?')
      ),
      el('button', { class: 'btn btn-primary w-full', id: 'submit' }, 'Sign In'),
      el('div', { class: 'auth-switch' },
        'No account? ', el('a', { href: '#/signup' }, 'Sign up')
      )
    )
  );

  wrap.querySelector('#submit').addEventListener('click', async () => {
    const email    = wrap.querySelector('#email').value;
    const password = wrap.querySelector('#password').value;
    const btn = wrap.querySelector('#submit');
    btn.disabled = true; btn.textContent = 'Signing in…';
    try {
      const res = await Api.post('/v1/auth/login', { email, password });
      Auth.setToken(res.token);
      Router.navigate('/projects');
    } catch (e) {
      showAlert(wrap.querySelector('.auth-box'), e.message);
      btn.disabled = false; btn.textContent = 'Sign In';
    }
  });

  wrap.querySelector('#password').addEventListener('keydown', e => {
    if (e.key === 'Enter') wrap.querySelector('#submit').click();
  });

  setApp(wrap);
}

function renderSignup() {
  const wrap = el('div', { class: 'auth-wrap' },
    el('div', { class: 'auth-box card' },
      authBrand(),
      el('div', { class: 'auth-sub' }, 'Create your SupaBein account'),
      el('div', { class: 'form-group' },
        el('label', {}, 'Email'),
        el('input', { type: 'email', id: 'email', placeholder: 'you@example.com', autocomplete: 'email' })
      ),
      el('div', { class: 'form-group' },
        el('label', {}, 'Password'),
        el('input', { type: 'password', id: 'password', placeholder: 'At least 8 characters', autocomplete: 'new-password' })
      ),
      el('button', { class: 'btn btn-primary w-full', id: 'submit' }, 'Create Account'),
      el('div', { class: 'auth-switch' },
        'Already have an account? ', el('a', { href: '#/login' }, 'Sign in')
      )
    )
  );

  wrap.querySelector('#submit').addEventListener('click', async () => {
    const email    = wrap.querySelector('#email').value;
    const password = wrap.querySelector('#password').value;
    const btn = wrap.querySelector('#submit');
    btn.disabled = true; btn.textContent = 'Creating account…';
    try {
      const res = await Api.post('/v1/auth/signup', { email, password });
      Auth.setToken(res.token);
      Router.navigate('/projects');
    } catch (e) {
      showAlert(wrap.querySelector('.auth-box'), e.message);
      btn.disabled = false; btn.textContent = 'Create Account';
    }
  });

  wrap.querySelector('#password').addEventListener('keydown', e => {
    if (e.key === 'Enter') wrap.querySelector('#submit').click();
  });

  setApp(wrap);
}

function renderForgot() {
  const wrap = el('div', { class: 'auth-wrap' },
    el('div', { class: 'auth-box card' },
      authBrand(),
      el('div', { class: 'auth-sub' }, 'Reset your password'),
      el('p', { style: 'font-size:13px;color:var(--text-muted);margin-bottom:20px;text-align:center' },
        'Enter your email and we\'ll generate a reset token.'
      ),
      el('div', { class: 'form-group' },
        el('label', {}, 'Email'),
        el('input', { type: 'email', id: 'email', placeholder: 'you@example.com', autocomplete: 'email' })
      ),
      el('button', { class: 'btn btn-primary w-full', id: 'submit' }, 'Generate Reset Token'),
      el('div', { class: 'auth-switch' }, el('a', { href: '#/login' }, '← Back to sign in'))
    )
  );

  wrap.querySelector('#submit').addEventListener('click', async () => {
    const email = wrap.querySelector('#email').value;
    const btn = wrap.querySelector('#submit');
    btn.disabled = true; btn.textContent = 'Sending…';
    try {
      const res = await Api.post('/v1/auth/forgot', { email });
      const box = wrap.querySelector('.auth-box');
      box.innerHTML = '';
      box.appendChild(authBrand());
      box.appendChild(h(`<div class="auth-sub">Check your email</div>`));
      if (res.token) {
        box.appendChild(h(`<p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;text-align:center">Your reset token (copy and keep it safe):</p>`));
        const codeBlock = h(`<div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px 14px;font-family:monospace;font-size:12px;word-break:break-all;margin-bottom:16px">${res.token}</div>`);
        box.appendChild(codeBlock);
        const copyBtn = el('button', { class: 'btn btn-sm w-full', style: 'margin-bottom:16px' }, 'Copy Token');
        copyBtn.addEventListener('click', () => {
          navigator.clipboard.writeText(res.token);
          copyBtn.textContent = 'Copied!';
          setTimeout(() => { copyBtn.textContent = 'Copy Token'; }, 1500);
        });
        box.appendChild(copyBtn);
      } else {
        box.appendChild(h(`<p style="font-size:13px;color:var(--text-muted);text-align:center;margin-bottom:16px">${res.message}</p>`));
      }
      box.appendChild(el('a', { class: 'btn btn-primary w-full', href: '#/reset' }, 'Enter Reset Token →'));
    } catch (e) {
      showAlert(wrap.querySelector('.auth-box'), e.message);
      btn.disabled = false; btn.textContent = 'Generate Reset Token';
    }
  });

  setApp(wrap);
}

function renderReset() {
  const wrap = el('div', { class: 'auth-wrap' },
    el('div', { class: 'auth-box card' },
      authBrand(),
      el('div', { class: 'auth-sub' }, 'Set a new password'),
      el('div', { class: 'form-group' },
        el('label', {}, 'Reset Token'),
        el('input', { type: 'text', id: 'token', placeholder: 'Paste your reset token' })
      ),
      el('div', { class: 'form-group' },
        el('label', {}, 'New Password'),
        el('input', { type: 'password', id: 'password', placeholder: 'At least 8 characters', autocomplete: 'new-password' })
      ),
      el('button', { class: 'btn btn-primary w-full', id: 'submit' }, 'Reset Password'),
      el('div', { class: 'auth-switch' }, el('a', { href: '#/login' }, '← Back to sign in'))
    )
  );

  wrap.querySelector('#submit').addEventListener('click', async () => {
    const token    = wrap.querySelector('#token').value.trim();
    const password = wrap.querySelector('#password').value;
    const btn = wrap.querySelector('#submit');
    btn.disabled = true; btn.textContent = 'Resetting…';
    try {
      const res = await Api.post('/v1/auth/reset', { token, password });
      Auth.setToken(res.token);
      Router.navigate('/projects');
    } catch (e) {
      showAlert(wrap.querySelector('.auth-box'), e.message);
      btn.disabled = false; btn.textContent = 'Reset Password';
    }
  });

  setApp(wrap);
}

// ─── AI Panel ────────────────────────────────────────────────────────────────

const AiPanel = (() => {
  let isOpen = false;
  let sessions = [];
  let currentSessionId = null;
  let projects = [];
  let selectedProjectId = null;
  let panelEl = null;
  let backdropEl = null;
  let sidebarVisible = false;
  let reviewEnabled = localStorage.getItem('sb:ai_review') === '1';
  let activeJobId   = null;
  let jobPollTimer  = null;
  let jobIndicator  = null;

  const AI_MODELS = [
    { label: 'Gemini 2.5 Flash',     provider: 'gemini',     model: 'gemini-2.5-flash',                                  badge: 'Fast' },
    { label: 'Gemini 2.5 Pro',       provider: 'gemini',     model: 'gemini-2.5-pro',                                    badge: 'Smart' },
    { label: 'GPT-4o',               provider: 'openrouter', model: 'openai/gpt-4o',                                     badge: 'OpenRouter' },
    { label: 'Claude Sonnet 4.5',    provider: 'openrouter', model: 'anthropic/claude-sonnet-4-5',                       badge: 'OpenRouter' },
    { label: 'Mistral Small 3.2',    provider: 'openrouter', model: 'mistralai/mistral-small-3.2-24b-instruct',          badge: 'OpenRouter' },
    { label: 'Kimi K2',              provider: 'openrouter', model: 'moonshotai/kimi-k2',                                badge: 'OpenRouter' },
    { label: 'Gemma 4 31B',          provider: 'openrouter', model: 'google/gemma-4-31b-it:free',                        badge: 'Free' },
    { label: 'Gemma 4 26B (MoE)',    provider: 'openrouter', model: 'google/gemma-4-26b-a4b-it:free',                    badge: 'Free' },
    { label: 'GPT OSS 120B',         provider: 'openrouter', model: 'openai/gpt-oss-120b:free',                          badge: 'Free' },
    { label: 'GPT OSS 20B',          provider: 'openrouter', model: 'openai/gpt-oss-20b:free',                           badge: 'Free' },
    { label: 'Nemotron Super 120B',  provider: 'openrouter', model: 'nvidia/nemotron-3-super-120b-a12b:free',            badge: 'Free' },
    { label: 'Nemotron Nano Omni',   provider: 'openrouter', model: 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free', badge: 'Free' },
    { label: 'OWL Alpha',            provider: 'openrouter', model: 'openrouter/owl-alpha',                              badge: 'Free' },
    { label: 'Nex N2 Pro',           provider: 'openrouter', model: 'nex-agi/nex-n2-pro:free',                           badge: 'Free' },
    { label: 'Laguna XS.2',          provider: 'openrouter', model: 'poolside/laguna-xs.2:free',                         badge: 'Free' },
    { label: 'Qwen 3.5 122B',        provider: 'nvidia',     model: 'qwen/qwen3.5-122b-a10b',                            badge: 'NVIDIA' },
  ];

  function getSelectedModel() {
    try { return JSON.parse(localStorage.getItem('sb:ai_model')) || AI_MODELS[0]; }
    catch { return AI_MODELS[0]; }
  }
  function setSelectedModel(m) { localStorage.setItem('sb:ai_model', JSON.stringify(m)); }

  function updateModelBtn(m) {
    if (!panelEl) return;
    const btn = panelEl.querySelector('.ai-model-btn');
    if (btn) btn.textContent = m.label + ' ▾';
  }

  function showToast(message, duration = 4500) {
    if (!panelEl) return;
    const toast = el('div', { class: 'ai-toast' }, message);
    panelEl.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('ai-toast-visible'));
    setTimeout(() => {
      toast.classList.remove('ai-toast-visible');
      setTimeout(() => toast.remove(), 300);
    }, duration);
  }

  const THINKING_STAGES = {
    build:    ['Analyzing your idea…', 'Designing data schema…', 'Writing frontend code…', 'Polishing the output…', 'Almost done…'],
    edit:     ['Reading current schema…', 'Planning the changes…', 'Generating edits…', 'Almost done…'],
    diagnose: ['Analyzing the issue…', 'Checking schema & policies…', 'Preparing suggestions…', 'Almost done…'],
    chat:     ['Thinking…', 'Looking up your projects…', 'Formulating reply…'],
    intent:   ['Analyzing your idea…', 'Identifying actors…', 'Distilling user stories…'],
    default:  ['Working on it…', 'Still going…', 'Almost done…'],
  };

  let stopThinkingStages = null;

  function startThinkingStages(labelEl, mode) {
    stopThinkingStages?.();
    const stages = THINKING_STAGES[mode] || THINKING_STAGES.default;
    let i = 0;
    labelEl.textContent = stages[0];
    const timer = setInterval(() => {
      i++;
      if (i < stages.length) labelEl.textContent = stages[i];
      if (i >= stages.length - 1) clearInterval(timer);
    }, 6000);
    stopThinkingStages = () => { clearInterval(timer); stopThinkingStages = null; };
  }

  async function callWithFallback(path, body) {
    let selectedM = getSelectedModel();
    const tried = new Set();
    while (true) {
      tried.add(selectedM.model);
      try {
        return await Api.post(path, { ...body, provider: selectedM.provider, model: selectedM.model });
      } catch (e) {
        if (e.status === 402) {
          const nextModel = AI_MODELS.find(m => !tried.has(m.model));
          if (nextModel) {
            showToast(`${selectedM.label} hit its limit — switching to ${nextModel.label}`);
            setSelectedModel(nextModel);
            updateModelBtn(nextModel);
            selectedM = nextModel;
            continue;
          }
        }
        throw e;
      }
    }
  }

  function renderTokenUsage(usage) {
    if (!usage || !usage.total_tokens) return null;
    return el('div', { class: 'ai-token-usage' },
      `↑ ${usage.prompt_tokens.toLocaleString()} / ↓ ${usage.completion_tokens.toLocaleString()} tokens`
    );
  }

  function getOrCreateBackdrop() {
    if (!backdropEl) {
      backdropEl = document.createElement('div');
      backdropEl.className = 'ai-panel-backdrop';
      backdropEl.addEventListener('click', close);
      document.body.appendChild(backdropEl);
    }
    return backdropEl;
  }

  function getSession(id) { return sessions.find(s => s.id === id) || null; }
  function currentSession() { return getSession(currentSessionId); }

  async function persistSession(sess) {
    if (!sess) return;
    try {
      if (sess._new) {
        const created = await Api.post('/v1/ai/sessions', {
          name: sess.name,
          project_id: sess.projectId || undefined,
        });
        sess.id = created.id;
        sess._new = false;
      } else {
        await Api.patch('/v1/ai/sessions/' + sess.id, {
          name: sess.name,
          messages: sess.messages,
        });
      }
    } catch(e) { /* non-fatal — UI already updated */ }
  }

  async function loadSessions() {
    try {
      const result = await Api.get('/v1/ai/sessions');
      sessions = Array.isArray(result) ? result : [];
      sessions.forEach(s => { s.messages = s.messages || []; });
    } catch(e) {
      console.error('[AiPanel] loadSessions failed:', e);
      sessions = [];
    }
  }

  async function createSession(projectId) {
    const sess = {
      id: null, _new: true,
      name: 'New session',
      projectId: projectId || null,
      messages: [],
      created_at: new Date().toISOString(),
    };
    sessions.unshift(sess);
    await persistSession(sess);
    return sess;
  }

  async function addMessage(sessionId, message) {
    const sess = getSession(sessionId);
    if (!sess) return;
    sess.messages.push({ ...message, id: 'msg_' + Date.now() + '_' + Math.random().toString(36).slice(2) });
    if (sess.name === 'New session' && message.role === 'user') {
      sess.name = message.content.slice(0, 42) + (message.content.length > 42 ? '…' : '');
    }
    await persistSession(sess);
  }

  function saveSessions() {
    const sess = currentSession();
    if (sess) persistSession(sess);
  }

  async function loadProjects() {
    try {
      const result = await Api.get('/v1/projects');
      projects = Array.isArray(result) ? result : [];
    }
    catch(e) { projects = []; }
  }

  function detectCurrentProject() {
    const path = window.location.hash.replace('#', '') || '/';
    const m = path.match(/^\/projects\/(\d+)/);
    return m ? parseInt(m[1]) : null;
  }

  function renderSidebar() {
    if (!panelEl) return;
    const container = panelEl.querySelector('.ai-session-list');
    if (!container) return;
    container.innerHTML = '';
    if (!sessions.length) {
      container.appendChild(el('div', { class: 'ai-session-empty' }, 'No sessions yet'));
      return;
    }
    sessions.forEach(sess => {
      const item = el('div', {
        class: 'ai-session-item' + (sess.id === currentSessionId ? ' active' : ''),
        onClick: () => {
          switchSession(sess.id);
          if (window.innerWidth < 768) toggleSidebar(false);
        }
      }, sess.name);
      container.appendChild(item);
    });
  }

  function renderMessages() {
    if (!panelEl) return;
    const container = panelEl.querySelector('.ai-messages');
    if (!container) return;
    container.innerHTML = '';
    const sess = currentSession();
    if (!sess || !sess.messages.length) {
      container.appendChild(el('div', { class: 'ai-welcome' },
        el('div', { class: 'ai-welcome-icon' }, '✦'),
        el('p', {}, 'What do you want to do?'),
        el('p', { class: 'text-muted', style: 'font-size:12px;margin-top:4px' },
          'Build a new project, edit an existing one, or describe a problem.')
      ));
      return;
    }
    sess.messages.forEach(msg => container.appendChild(renderMessage(msg)));
    container.scrollTop = container.scrollHeight;
  }

  function renderIntentCard(intent, onConfirm, onCancel) {
    const actors  = [...(intent.actors  || [])];
    const stories = [...(intent.stories || [])];

    const actorsWrap  = el('div', { class: 'ai-intent-actors' });
    const storiesWrap = el('div', { class: 'ai-intent-stories' });

    function refreshActors() {
      actorsWrap.innerHTML = '';
      actors.forEach((a, i) => {
        const textSpan = el('span', { class: 'ai-actor-text' }, a);
        textSpan.addEventListener('click', () => {
          const inp = el('input', { class: 'ai-actor-inline-input', type: 'text' });
          inp.value = a;
          inp.style.width = Math.max(60, a.length * 8) + 'px';
          textSpan.replaceWith(inp);
          inp.focus(); inp.select();
          const save = () => {
            const v = inp.value.trim();
            if (v && v !== a) actors[i] = v;
            refreshActors();
          };
          inp.addEventListener('blur', save);
          inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); save(); }
            if (e.key === 'Escape') refreshActors();
          });
        });
        const chip = el('span', { class: 'ai-actor-chip' },
          textSpan,
          el('button', { class: 'ai-chip-remove', title: 'Remove', onClick: () => { actors.splice(i, 1); refreshActors(); }}, '×')
        );
        actorsWrap.appendChild(chip);
      });
      const inp = el('input', { class: 'ai-intent-add-input', placeholder: '+ actor', type: 'text' });
      inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          const v = inp.value.trim();
          if (v && !actors.includes(v)) { actors.push(v); refreshActors(); }
        }
      });
      actorsWrap.appendChild(inp);
    }

    function refreshStories() {
      storiesWrap.innerHTML = '';
      stories.forEach((s, i) => {
        const textSpan = el('span', { class: 'ai-story-text' }, s);
        textSpan.addEventListener('click', () => {
          const inp = el('input', { class: 'ai-story-inline-input', type: 'text' });
          inp.value = s;
          inp.style.width = '100%';
          textSpan.replaceWith(inp);
          inp.focus(); inp.select();
          const save = () => {
            const v = inp.value.trim();
            if (v && v !== s) stories[i] = v;
            refreshStories();
          };
          inp.addEventListener('blur', save);
          inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); save(); }
            if (e.key === 'Escape') refreshStories();
          });
        });
        storiesWrap.appendChild(el('div', { class: 'ai-story-item' },
          el('button', { class: 'ai-story-remove', title: 'Remove', onClick: () => { stories.splice(i, 1); refreshStories(); }}, '−'),
          textSpan
        ));
      });
      const addRow = el('div', { class: 'ai-story-add-row' });
      const inp = el('input', { class: 'ai-intent-add-input', placeholder: '+ add a story…', type: 'text' });
      inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          const v = inp.value.trim();
          if (v) { stories.push(v); refreshStories(); }
        }
      });
      addRow.appendChild(inp);
      storiesWrap.appendChild(addRow);
    }

    refreshActors();
    refreshStories();

    return el('div', { class: 'ai-msg ai-msg-ai ai-intent-card' },
      el('div', { class: 'ai-intent-header' }, 'Intent — review before building'),
      el('div', { class: 'ai-intent-section-label' }, 'Actors'),
      actorsWrap,
      el('div', { class: 'ai-intent-divider' }),
      el('div', { class: 'ai-intent-section-label' }, 'User Stories'),
      storiesWrap,
      el('div', { class: 'ai-intent-actions' },
        el('button', { class: 'btn btn-secondary btn-sm', onClick: onCancel }, 'Cancel'),
        el('button', { class: 'btn btn-ai btn-sm', onClick: () => onConfirm({ actors, stories }) }, 'Build with this →')
      )
    );
  }

  function showIntentReviewCard(intent, body) {
    const container = panelEl?.querySelector('.ai-messages');
    if (!container) return;
    const existing = container.querySelector('.ai-intent-card');
    if (existing) existing.remove();

    const card = renderIntentCard(
      intent,
      async (confirmedIntent) => {
        card.remove();
        await addMessage(currentSessionId, { role: 'ai', type: 'intent', data: confirmedIntent });
        body.intent = confirmedIntent;
        await proceedWithPlan(body);
      },
      () => { card.remove(); renderMessages(); }
    );
    container.appendChild(card);
    container.scrollTop = container.scrollHeight;
  }

  async function proceedWithPlan(body) {
    const sess = currentSession();
    const stageMode = body.project_id ? 'edit' : 'build';
    const thinkingId = 'thinking_' + Date.now();
    if (sess) sess.messages.push({ id: thinkingId, role: 'ai', type: 'thinking', content: '', stageMode });
    renderMessages();

    try {
      const response = await callWithFallback('/v1/ai/plan', body);
      stopThinkingStages?.();
      if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
      await handlePlanResponse(response);
    } catch(e) {
      stopThinkingStages?.();
      if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
      await addMessage(currentSessionId, { role: 'ai', type: 'error', content: `Something went wrong: ${e.message} — try rephrasing your request or check the project for partial changes.` });
    }
    renderSidebar();
    renderMessages();
  }

  async function handlePlanResponse(response) {
    if (response.mode === 'chat') {
      await addMessage(currentSessionId, { role: 'ai', type: 'chat', content: response.message, usage: response.usage });
    } else if (response.mode === 'diagnose') {
      await addMessage(currentSessionId, { role: 'ai', type: 'diagnosis', content: '', data: response });
    } else {
      await addMessage(currentSessionId, { role: 'ai', type: 'plan', content: '', data: response, settled: false });
    }
  }

  function renderIntentSummaryCard(msg) {
    const intent = msg.data || {};
    const actors = intent.actors || [];
    const stories = intent.stories || [];
    const parts = [];
    if (actors.length) {
      parts.push(el('div', { class: 'ai-intent-summary-actors' },
        el('span', { class: 'ai-intent-section-label', style: 'margin-right:6px' }, 'Actors:'),
        ...actors.map(a => el('span', { class: 'ai-actor-chip' }, a))
      ));
    }
    if (stories.length) {
      parts.push(el('div', { class: 'ai-intent-summary-stories' },
        ...stories.map(s => el('div', { class: 'ai-intent-summary-story' }, '• ' + s))
      ));
    }
    return el('div', { class: 'ai-msg ai-msg-ai ai-intent-summary' },
      el('div', { class: 'ai-intent-summary-header' }, '✓ Intent confirmed'),
      ...parts
    );
  }

  function renderRecoveryCard(msg) {
    const data = msg.data || {};
    const options = data.options || [];
    const optionBtns = [];

    options.forEach(opt => {
      const labelEl = el('div', { class: 'ai-recovery-option-label' }, opt.label || '');
      const btn = el('button', { class: 'ai-recovery-option' },
        labelEl,
        el('div', { class: 'ai-recovery-option-desc' }, opt.description || '')
      );
      btn.addEventListener('click', async () => {
        optionBtns.forEach(b => { b.disabled = true; b.style.opacity = '0.5'; });
        labelEl.textContent = '⏳ Applying…';
        await applyPlan(opt.plan, 'build');
      });
      optionBtns.push(btn);
    });

    const tokenEl = renderTokenUsage(data.usage || msg.usage);

    return el('div', { class: 'ai-msg ai-msg-ai ai-recovery-card' },
      el('div', { class: 'ai-recovery-header' }, '⚠ Build failed — here\'s what I can do:'),
      el('div', { class: 'ai-recovery-diagnosis' }, data.diagnosis || ''),
      ...(optionBtns.length ? [
        el('div', { class: 'ai-recovery-options-label' }, 'Choose an option:'),
        el('div', { class: 'ai-recovery-options' }, ...optionBtns),
      ] : []),
      ...(tokenEl ? [tokenEl] : [])
    );
  }

  function renderEditReviewCard(suggestions, onConfirm, onCancel) {
    const confirmed = new Set(suggestions.map(s => s.id));
    const customItems = [];

    const listEl = el('div', { class: 'ai-edit-review-list' });

    function refreshList() {
      listEl.innerHTML = '';
      suggestions.forEach(s => {
        const labelSpan = el('span', { class: 'ai-edit-review-label' }, s.label);
        labelSpan.addEventListener('click', () => {
          const inp = el('input', { class: 'ai-edit-review-inline-input', type: 'text' });
          inp.value = s.label;
          labelSpan.replaceWith(inp);
          inp.focus(); inp.select();
          const save = () => {
            const v = inp.value.trim();
            if (v) s.label = v;
            refreshList();
          };
          inp.addEventListener('blur', save);
          inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); save(); }
            if (e.key === 'Escape') refreshList();
          });
        });

        const cb = el('input', { class: 'ai-edit-review-check', type: 'checkbox' });
        cb.checked = confirmed.has(s.id);
        cb.addEventListener('change', () => { if (cb.checked) confirmed.add(s.id); else confirmed.delete(s.id); });

        listEl.appendChild(el('div', { class: 'ai-edit-review-item' },
          cb,
          el('div', { class: 'ai-edit-review-text' },
            labelSpan,
            el('span', { class: 'ai-edit-review-desc' }, s.description || '')
          )
        ));
      });

      customItems.forEach((item, ci) => {
        const cb = el('input', { class: 'ai-edit-review-check', type: 'checkbox' });
        cb.checked = true;
        cb.addEventListener('change', () => {
          if (!cb.checked) { customItems.splice(ci, 1); refreshList(); }
        });
        listEl.appendChild(el('div', { class: 'ai-edit-review-item' },
          cb,
          el('div', { class: 'ai-edit-review-text' },
            el('span', { class: 'ai-edit-review-label' }, item.label)
          )
        ));
      });
    }

    refreshList();

    const addInp = el('input', { class: 'ai-intent-add-input', placeholder: '+ add your own change…', type: 'text', style: 'margin-top:8px;width:100%' });
    addInp.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        const v = addInp.value.trim();
        if (v) { customItems.push({ id: 'custom_' + Date.now(), label: v, description: '' }); addInp.value = ''; refreshList(); }
      }
    });

    return el('div', { class: 'ai-msg ai-msg-ai ai-edit-review-card' },
      el('div', { class: 'ai-intent-header' }, 'Edit Review — select changes to apply'),
      listEl,
      addInp,
      el('div', { class: 'ai-intent-actions' },
        el('button', { class: 'btn btn-secondary btn-sm', onClick: onCancel }, 'Cancel'),
        el('button', { class: 'btn btn-ai btn-sm', onClick: () => {
          const selected = [
            ...suggestions.filter(s => confirmed.has(s.id)),
            ...customItems
          ];
          if (!selected.length) return;
          onConfirm(selected);
        }}, 'Apply selected →')
      )
    );
  }

  function showEditReviewCard(suggestions, body) {
    const container = panelEl?.querySelector('.ai-messages');
    if (!container) return;
    const existing = container.querySelector('.ai-edit-review-card');
    if (existing) existing.remove();

    const card = renderEditReviewCard(
      suggestions,
      async (selected) => {
        card.remove();
        const confirmedData = { confirmed: selected, original_prompt: body.prompt };
        await addMessage(currentSessionId, { role: 'ai', type: 'edit-intent', data: confirmedData });
        const refinedPrompt = body.prompt
          + '\n\nApply ONLY these specific changes (ignore everything else):\n'
          + selected.map((s, i) => `${i + 1}. ${s.label}`).join('\n');
        await proceedWithPlan({ ...body, prompt: refinedPrompt });
      },
      () => { card.remove(); renderMessages(); }
    );
    container.appendChild(card);
    container.scrollTop = container.scrollHeight;
  }

  function renderEditIntentSummaryCard(msg) {
    const data = msg.data || {};
    const confirmed = data.confirmed || [];
    return el('div', { class: 'ai-msg ai-msg-ai ai-intent-summary' },
      el('div', { class: 'ai-intent-summary-header' }, '✓ Edit changes confirmed'),
      el('div', { class: 'ai-intent-summary-stories' },
        ...confirmed.map(s => el('div', { class: 'ai-intent-summary-story' }, '• ' + s.label))
      )
    );
  }

  function renderMessage(msg) {
    if (msg.role === 'user') {
      return el('div', { class: 'ai-msg ai-msg-user' }, msg.content);
    }
    if (msg.type === 'thinking') {
      const thinkingLabel = el('span', { class: 'ai-thinking-label' });
      const bubble = el('div', { class: 'ai-msg ai-msg-ai ai-msg-thinking' },
        el('span', { class: 'ai-thinking-dots' }, '● ● ●'),
        thinkingLabel
      );
      startThinkingStages(thinkingLabel, msg.stageMode || 'default');
      return bubble;
    }
    if (msg.type === 'job') {
      const label = el('span', { class: 'ai-thinking-label' });
      label.textContent = 'Working in the background…';
      return el('div', { class: 'ai-msg ai-msg-ai ai-msg-thinking' },
        el('span', { class: 'ai-thinking-dots' }, '● ● ●'), label
      );
    }
    if (msg.type === 'intent') return renderIntentSummaryCard(msg);
    if (msg.type === 'edit-intent') return renderEditIntentSummaryCard(msg);
    if (msg.type === 'recover') return renderRecoveryCard(msg);
    if (msg.type === 'plan') return renderPlanCard(msg);
    if (msg.type === 'result') return renderResultCard(msg);
    if (msg.type === 'diagnosis') return renderDiagnosisCard(msg);
    if (msg.type === 'error') {
      return el('div', { class: 'ai-msg ai-msg-ai ai-msg-error' }, '✗ ' + msg.content);
    }
    const bubble = el('div', { class: 'ai-msg ai-msg-ai' }, msg.content);
    const tokenEl = renderTokenUsage(msg.usage);
    if (tokenEl) bubble.appendChild(tokenEl);
    return bubble;
  }

  function renderPlanCard(msg) {
    const { plan, summary, mode } = msg.data;
    const lines = [];

    if (mode === 'build') {
      lines.push(el('div', { class: 'ai-plan-row' }, el('strong', {}, 'Project: '), summary.project_name));
      if (summary.tables && summary.tables.length) {
        lines.push(el('div', { class: 'ai-plan-section' }, 'Tables'));
        summary.tables.forEach(t => lines.push(el('div', { class: 'ai-plan-item' }, '+ ' + t)));
      }
      if (summary.frontend_files) {
        lines.push(el('div', { class: 'ai-plan-row', style: 'margin-top:6px' },
          el('strong', {}, 'Frontend: '), summary.frontend_files + ' files'
        ));
      }

      // Collapsible schema detail
      if (plan && plan.tables && plan.tables.length) {
        const schemaDetails = el('details', { class: 'ai-plan-details' },
          el('summary', { class: 'ai-plan-details-summary' }, 'Schema'),
          ...plan.tables.map(t =>
            el('div', { class: 'ai-plan-details-table' },
              el('div', { class: 'ai-plan-details-tname' }, t.name),
              ...(t.columns || []).map(c =>
                el('div', { class: 'ai-plan-details-col' },
                  el('span', { class: 'ai-plan-details-colname' }, c.name),
                  el('span', { class: 'ai-plan-details-coltype' }, c.type),
                  el('span', { class: 'ai-plan-details-colnull' + (c.nullable ? ' muted' : '') },
                    c.nullable ? 'NULL' : 'NOT NULL'
                  )
                )
              )
            )
          )
        );
        lines.push(schemaDetails);
      }

      // Collapsible file list
      if (plan && plan.frontend && plan.frontend.files && plan.frontend.files.length) {
        const filesDetails = el('details', { class: 'ai-plan-details' },
          el('summary', { class: 'ai-plan-details-summary' }, 'Files'),
          ...plan.frontend.files.map(f =>
            el('div', { class: 'ai-plan-details-file' }, f.path)
          )
        );
        lines.push(filesDetails);
      }

      // Download plan JSON button
      if (plan) {
        const dlBtn = el('button', { class: 'ai-plan-dl-btn', onClick: () => {
          const name = (plan.subdomain || plan.project_name || 'plan').replace(/\s+/g, '-').toLowerCase();
          downloadText(name + '.json', JSON.stringify(plan, null, 2));
        }}, '↓ Download JSON');
        lines.push(dlBtn);
      }
    } else if (mode === 'edit') {
      const hasChanges = (summary.add_tables && summary.add_tables.length) ||
                         (summary.add_columns && summary.add_columns.length) ||
                         (summary.update_policies && summary.update_policies.length) ||
                         summary.frontend_files;
      if (!hasChanges) {
        lines.push(el('div', { class: 'text-muted', style: 'font-size:13px' }, 'No changes needed for this request.'));
      } else {
        if (summary.add_tables && summary.add_tables.length) {
          lines.push(el('div', { class: 'ai-plan-section' }, 'New tables'));
          summary.add_tables.forEach(t => lines.push(el('div', { class: 'ai-plan-item' }, '+ ' + t)));
        }
        if (summary.add_columns && summary.add_columns.length) {
          lines.push(el('div', { class: 'ai-plan-section' }, 'New columns'));
          summary.add_columns.forEach(c => lines.push(el('div', { class: 'ai-plan-item' }, '+ ' + c)));
        }
        if (summary.update_policies && summary.update_policies.length) {
          lines.push(el('div', { class: 'ai-plan-section' }, 'Policy changes'));
          summary.update_policies.forEach(p => lines.push(el('div', { class: 'ai-plan-item' }, '~ ' + p)));
        }
        if (summary.frontend_files) {
          lines.push(el('div', { class: 'ai-plan-row', style: 'margin-top:6px' },
            el('strong', {}, 'Frontend: '), summary.frontend_files + ' files updated'
          ));
        }
        // Collapsible file list
        if (plan && plan.frontend && plan.frontend.files && plan.frontend.files.length) {
          const filesDetails = el('details', { class: 'ai-plan-details' },
            el('summary', { class: 'ai-plan-details-summary' }, 'Files'),
            ...plan.frontend.files.map(f =>
              el('div', { class: 'ai-plan-details-file' }, f.path)
            )
          );
          lines.push(filesDetails);
        }
        // Download plan JSON button
        if (plan && plan.frontend && plan.frontend.files && plan.frontend.files.length) {
          const dlBtn = el('button', { class: 'ai-plan-dl-btn', onClick: () => {
            const name = 'edit-' + (plan.project_id || 'plan');
            downloadText(name + '.json', JSON.stringify(plan, null, 2));
          }}, '↓ Download JSON');
          lines.push(dlBtn);
        }
      }
    }

    const tokenEl = renderTokenUsage(msg.data?.usage || msg.usage);
    const card = el('div', { class: 'ai-msg ai-msg-ai ai-plan-card' + (msg.settled ? ' ai-plan-settled' : '') },
      el('div', { class: 'ai-plan-title' }, "Here's my plan:"),
      ...lines,
      ...(tokenEl ? [tokenEl] : [])
    );

    if (!msg.settled) {
      const actionsDiv = el('div', { class: 'ai-plan-actions' });

      const cancelBtn = el('button', { class: 'btn btn-secondary btn-sm', onClick: () => {
        msg.settled = true;
        msg.cancelled = true;
        saveSessions();
        actionsDiv.innerHTML = '';
        actionsDiv.appendChild(el('span', { class: 'text-muted', style: 'font-size:12px' }, 'Cancelled'));
        card.classList.add('ai-plan-settled');
      }}, 'Cancel');

      const applyBtn = el('button', { class: 'btn btn-ai btn-sm', onClick: async () => {
        msg.settled = true;
        saveSessions();
        actionsDiv.innerHTML = '';
        actionsDiv.appendChild(el('span', { class: 'text-muted', style: 'font-size:12px' }, '⏳ Applying…'));
        card.classList.add('ai-plan-settled');
        await applyPlan(plan, mode);
      }}, '✓ Apply');

      actionsDiv.appendChild(cancelBtn);
      actionsDiv.appendChild(applyBtn);
      card.appendChild(actionsDiv);
    } else if (msg.cancelled) {
      card.appendChild(el('div', { class: 'ai-plan-actions' },
        el('span', { class: 'text-muted', style: 'font-size:12px' }, 'Cancelled')
      ));
    } else {
      card.appendChild(el('div', { class: 'ai-plan-actions' },
        el('span', { style: 'font-size:12px;color:var(--accent)' }, '✓ Applied')
      ));
    }

    return card;
  }

  function renderResultCard(msg) {
    const data = msg.data;
    const lines = [];
    if (data.project) lines.push(el('div', { class: 'ai-result-row' }, '✓ Project: ', el('strong', {}, data.project.name)));
    if (data.tables && data.tables.length) lines.push(el('div', { class: 'ai-result-row' }, '✓ Tables: ' + data.tables.map(t => t.name || t).join(', ')));
    if (data.added_tables && data.added_tables.length) lines.push(el('div', { class: 'ai-result-row' }, '✓ Tables added: ' + data.added_tables.join(', ')));
    if (data.added_columns && data.added_columns.length) lines.push(el('div', { class: 'ai-result-row' }, '✓ Columns: ' + data.added_columns.join(', ')));
    if (data.site) lines.push(el('div', { class: 'ai-result-row' }, '✓ Frontend deployed'));

    const card = el('div', { class: 'ai-msg ai-msg-ai ai-result-card' }, ...lines);

    if (data.project) {
      card.appendChild(el('button', {
        class: 'btn btn-primary btn-sm', style: 'margin-top:10px',
        onClick: () => { close(); Router.navigate('/projects/' + data.project.id); }
      }, 'Open Project →'));
    }
    return card;
  }

  function renderDiagnosisCard(msg) {
    const data = msg.data;
    const lines = [el('div', { class: 'ai-diagnosis-text' }, data.diagnosis)];
    if (data.suggestions && data.suggestions.length) {
      lines.push(el('div', { class: 'ai-plan-section', style: 'margin-top:10px' }, 'Suggestions'));
      data.suggestions.forEach((s, i) =>
        lines.push(el('div', { class: 'ai-plan-item' }, (i + 1) + '. ' + s))
      );
    }
    const tokenEl = renderTokenUsage(data?.usage || msg.usage);
    if (tokenEl) lines.push(tokenEl);
    return el('div', { class: 'ai-msg ai-msg-ai ai-diagnosis-card' }, ...lines);
  }

  function renderProjectPicker() {
    if (!panelEl) return;
    const picker = panelEl.querySelector('#ai-project-picker');
    if (!picker) return;
    const current = picker.value;
    picker.innerHTML = '';
    picker.appendChild(el('option', { value: '' }, '✦ Platform'));
    projects.forEach(p => picker.appendChild(el('option', { value: String(p.id) }, p.name)));
    picker.value = selectedProjectId ? String(selectedProjectId) : '';
    if (picker.value === '' && current) picker.value = '';
  }

  async function loadSessionMessages(id) {
    if (!id) return;
    try {
      const full = await Api.get('/v1/ai/sessions/' + id);
      if (full && Array.isArray(full.messages)) {
        const idx = sessions.findIndex(s => s.id === id);
        if (idx !== -1) sessions[idx].messages = full.messages;
      }
    } catch(e) {}
  }

  async function switchSession(id) {
    currentSessionId = id;
    const sess = getSession(id);
    if (sess) selectedProjectId = sess.projectId;
    renderSidebar();
    renderMessages();
    renderProjectPicker();
    await loadSessionMessages(id);
    renderMessages();
  }

  async function newSession() {
    const sess = await createSession(selectedProjectId);
    currentSessionId = sess.id;
    renderSidebar();
    renderMessages();
  }

  function toggleSidebar(force) {
    sidebarVisible = force !== undefined ? force : !sidebarVisible;
    if (!panelEl) return;
    const sidebar = panelEl.querySelector('.ai-sessions');
    const overlay = panelEl.querySelector('.ai-sidebar-overlay');
    if (sidebar) sidebar.classList.toggle('ai-sidebar-visible', sidebarVisible);
    if (overlay) overlay.classList.toggle('ai-sidebar-overlay-visible', sidebarVisible);
  }

  async function sendMessage() {
    if (!panelEl) return;
    const textarea = panelEl.querySelector('#ai-textarea');
    const prompt = textarea ? textarea.value.trim() : '';
    if (!prompt) return;
    if (textarea) { textarea.value = ''; textarea.style.height = 'auto'; const sb = panelEl.querySelector('.ai-send-btn'); if (sb) sb.disabled = true; }

    if (!currentSessionId || !getSession(currentSessionId)) {
      const sess = await createSession(selectedProjectId);
      currentSessionId = sess.id;
    } else {
      const sess = currentSession();
      if (sess) { sess.projectId = selectedProjectId; persistSession(sess); }
    }

    await addMessage(currentSessionId, { role: 'user', content: prompt });
    renderSidebar();
    renderMessages();

    const sess = currentSession();

    // Build conversation history for Gemini context
    const body = { prompt };
    if (selectedProjectId) body.project_id = selectedProjectId;
    const priorMessages = (sess ? sess.messages : []).filter(m => m.type !== 'thinking');
    if (priorMessages.length > 0) {
      body.history = priorMessages.slice(-20).map(m => {
        if (m.role === 'user') return { role: 'user', text: m.content };
        if (m.type === 'plan') {
          const s = m.data?.summary || {};
          return { role: 'model', text: 'I proposed a plan. Summary: ' + JSON.stringify(s) };
        }
        if (m.type === 'result') return { role: 'model', text: 'The changes were applied successfully.' };
        if (m.type === 'diagnosis') return { role: 'model', text: 'Diagnosis: ' + (m.data?.diagnosis || '') + (m.data?.suggestions?.length ? ' Suggestions: ' + m.data.suggestions.join('; ') : '') };
        if (m.type === 'intent') return { role: 'model', text: 'Intent confirmed — actors: [' + (m.data?.actors || []).join(', ') + '], stories: [' + (m.data?.stories || []).join('; ') + ']' };
        if (m.type === 'edit-intent') return { role: 'model', text: 'Edit changes confirmed: ' + (m.data?.confirmed || []).map(s => s.label).join('; ') };
        if (m.type === 'recover') return { role: 'model', text: 'Build failed and recovery was offered: ' + (m.data?.diagnosis || '') };
        if (m.type === 'error') return { role: 'model', text: 'Error: ' + m.content };
        return { role: 'model', text: m.content || '' };
      }).filter(h => h.text.trim() !== '');
    }

    // ── Intent review pass (only for new builds, not edits) ──────────────────
    if (reviewEnabled && !selectedProjectId) {
      const thinkingId = 'intent_thinking_' + Date.now();
      if (sess) sess.messages.push({ id: thinkingId, role: 'ai', type: 'thinking', content: '', stageMode: 'intent' });
      renderMessages();
      try {
        const intentResp = await callWithFallback('/v1/ai/intent', { prompt });
        stopThinkingStages?.();
        if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
        renderMessages();
        showIntentReviewCard(intentResp.intent, body);
      } catch(e) {
        stopThinkingStages?.();
        if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
        await addMessage(currentSessionId, { role: 'ai', type: 'error', content: `Something went wrong: ${e.message} — try rephrasing your request.` });
        renderSidebar();
        renderMessages();
      }
      return;
    }

    // ── Edit suggest/review pass (review on + project selected = editing) ─────
    if (reviewEnabled && selectedProjectId) {
      const thinkingId = 'suggest_thinking_' + Date.now();
      if (sess) sess.messages.push({ id: thinkingId, role: 'ai', type: 'thinking', content: '', stageMode: 'edit' });
      renderMessages();
      try {
        const { provider: sProvider, model: sModel } = getSelectedModel();
        const suggestResp = await callWithFallback('/v1/ai/plan', {
          ...body, mode: 'suggest', provider: sProvider, model: sModel
        });
        stopThinkingStages?.();
        if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
        renderMessages();
        showEditReviewCard(suggestResp.suggestions || [], body);
      } catch(e) {
        stopThinkingStages?.();
        if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
        await addMessage(currentSessionId, { role: 'ai', type: 'error', content: `Something went wrong: ${e.message} — try rephrasing your request.` });
        renderSidebar();
        renderMessages();
      }
      return;
    }

    // ── Regular plan flow ─────────────────────────────────────────────────────
    const stageMode = selectedProjectId ? 'edit' : 'build';
    const thinkingId = 'thinking_' + Date.now();
    if (sess) sess.messages.push({ id: thinkingId, role: 'ai', type: 'thinking', content: '', stageMode });
    renderMessages();

    try {
      const response = await callWithFallback('/v1/ai/plan', body);
      stopThinkingStages?.();
      if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
      await handlePlanResponse(response);
    } catch(e) {
      stopThinkingStages?.();
      if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
      await addMessage(currentSessionId, { role: 'ai', type: 'error', content: `Something went wrong: ${e.message} — try rephrasing your request or check the project for partial changes.` });
    }

    renderSidebar();
    renderMessages();
  }

  function buildApplySummary(result, mode) {
    const lines = [];
    if (mode === 'build') {
      if (result.project) lines.push(`**${result.project.name}** is ready.`);
      if (result.tables?.length) {
        const names = result.tables.map(t => `${t.name} (${t.columns} col${t.columns !== 1 ? 's' : ''})`).join(', ');
        lines.push(`Created ${result.tables.length} table${result.tables.length !== 1 ? 's' : ''}: ${names}.`);
      }
      if (result.site && result.deploy) lines.push('Frontend deployed and live.');
      else if (result.site)             lines.push('Site created — no frontend files were generated.');
    }
    if (mode === 'edit') {
      if (result.added_tables?.length)     lines.push(`Added ${result.added_tables.length} new table${result.added_tables.length !== 1 ? 's' : ''}: ${result.added_tables.join(', ')}.`);
      if (result.added_columns?.length)    lines.push(`Added ${result.added_columns.length} column${result.added_columns.length !== 1 ? 's' : ''}.`);
      if (result.updated_policies?.length) lines.push(`Updated ${result.updated_policies.length} polic${result.updated_policies.length !== 1 ? 'ies' : 'y'}.`);
      if (result.deploy)                   lines.push('Frontend redeployed.');
      if (!lines.length)                   lines.push('No changes were needed.');
    }
    return lines.length ? 'Done! ' + lines.join(' ') : 'Applied successfully.';
  }

  async function applyPlan(plan, mode) {
    const sess = currentSession();
    const thinkingId = 'apply_' + Date.now();
    if (sess) sess.messages.push({ id: thinkingId, role: 'ai', type: 'thinking', content: '', stageMode: mode });
    renderMessages();

    const { provider: aProvider, model: aModel } = getSelectedModel();
    try {
      const result = await Api.post('/v1/ai/apply', { mode, plan, provider: aProvider, model: aModel, async: true });
      stopThinkingStages?.();
      if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);

      if (result.job_id) {
        activeJobId = result.job_id;
        const jobMsgId = 'job_' + result.job_id;
        await addMessage(currentSessionId, { id: jobMsgId, role: 'ai', type: 'job', content: '' });
        showJobIndicator();
        startJobPolling(result.job_id, mode, jobMsgId);
      } else {
        await addMessage(currentSessionId, { role: 'ai', type: 'result', content: '', data: result });
        await addMessage(currentSessionId, { role: 'ai', type: 'chat', content: buildApplySummary(result, mode) });
      }
    } catch(e) {
      stopThinkingStages?.();
      if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);

      if (mode === 'build' && e.data?.partial) {
        const recoverThinkId = 'recover_' + Date.now();
        if (sess) sess.messages.push({ id: recoverThinkId, role: 'ai', type: 'thinking', content: '', stageMode: 'diagnose' });
        renderMessages();
        try {
          const recovery = await Api.post('/v1/ai/plan', {
            mode: 'recover',
            error: e.message,
            plan,
            partial: e.data.partial,
            provider: aProvider,
            model: aModel,
          });
          stopThinkingStages?.();
          if (sess) sess.messages = sess.messages.filter(m => m.id !== recoverThinkId);
          await addMessage(currentSessionId, { role: 'ai', type: 'recover', content: '', data: recovery });
        } catch(re) {
          stopThinkingStages?.();
          if (sess) sess.messages = sess.messages.filter(m => m.id !== recoverThinkId);
          await addMessage(currentSessionId, { role: 'ai', type: 'error', content: `Build failed: ${e.message}` });
        }
      } else {
        await addMessage(currentSessionId, { role: 'ai', type: 'error', content: `Something went wrong: ${e.message} — try rephrasing your request or check the project for partial changes.` });
      }
    }

    renderMessages();
  }

  function startJobPolling(jobId, mode, jobMsgId) {
    let polls = 0;
    const MAX_POLLS = 240;
    stopJobPolling();
    jobPollTimer = setInterval(async () => {
      polls++;
      if (polls > MAX_POLLS) {
        stopJobPolling();
        hideJobIndicator();
        const sess = currentSession();
        if (sess && jobMsgId) sess.messages = sess.messages.filter(m => m.id !== jobMsgId);
        await addMessage(currentSessionId, { role: 'ai', type: 'error', content: 'AI job timed out after 12 minutes.' });
        renderMessages();
        return;
      }
      try {
        const job = await Api.get('/v1/ai/jobs/' + jobId);
        if (job.status === 'done') {
          stopJobPolling();
          hideJobIndicator();
          await handleJobDone(job, mode, jobMsgId);
        } else if (job.status === 'failed') {
          stopJobPolling();
          hideJobIndicator();
          await handleJobFailed(job, mode, jobMsgId);
        }
      } catch(e) { /* keep polling on network error */ }
    }, 3000);
  }

  function stopJobPolling() {
    if (jobPollTimer) { clearInterval(jobPollTimer); jobPollTimer = null; }
  }

  async function handleJobDone(job, mode, jobMsgId) {
    const sess = currentSession();
    if (sess && jobMsgId) sess.messages = sess.messages.filter(m => m.id !== jobMsgId);
    const result = job.result || {};
    await addMessage(currentSessionId, { role: 'ai', type: 'result', content: '', data: result });
    await addMessage(currentSessionId, { role: 'ai', type: 'chat', content: buildApplySummary(result, mode) });
    renderMessages();
    renderSidebar();
    activeJobId = null;
  }

  async function handleJobFailed(job, mode, jobMsgId) {
    const sess = currentSession();
    if (sess && jobMsgId) sess.messages = sess.messages.filter(m => m.id !== jobMsgId);
    await addMessage(currentSessionId, { role: 'ai', type: 'error', content: `AI job failed: ${job.error || 'Unknown error'}` });
    renderMessages();
    activeJobId = null;
  }

  function showJobIndicator() {
    if (!jobIndicator) {
      jobIndicator = el('div', { class: 'ai-job-indicator' },
        el('span', { class: 'ai-job-indicator-dot' }),
        el('span', { class: 'ai-job-indicator-label' }, 'AI is building…'),
        el('button', { class: 'ai-job-indicator-view', onClick: () => AiPanel.open() }, 'View')
      );
      document.body.appendChild(jobIndicator);
    }
    requestAnimationFrame(() => jobIndicator.classList.add('ai-job-indicator-visible'));
  }

  function hideJobIndicator() {
    if (jobIndicator) jobIndicator.classList.remove('ai-job-indicator-visible');
  }

  async function checkForActiveJobs() {
    if (activeJobId || jobPollTimer) return;
    try {
      const jobs = await Api.get('/v1/ai/jobs');
      if (Array.isArray(jobs) && jobs.length > 0) {
        const job = jobs[0];
        activeJobId = job.id;
        showJobIndicator();
        startJobPolling(job.id, job.mode, null);
      }
    } catch(e) { /* ignore */ }
  }

  function buildPanel() {
    const panel = document.createElement('div');
    panel.className = 'ai-panel';
    panel.id = 'ai-panel';

    const sidebarOverlay = el('div', { class: 'ai-sidebar-overlay', onClick: () => toggleSidebar(false) });

    const sessionList = el('div', { class: 'ai-session-list' });
    const sidebar = el('div', { class: 'ai-sessions' },
      el('div', { class: 'ai-sessions-header' },
        el('span', { class: 'ai-sessions-title' }, 'History')
      ),
      sessionList
    );

    const messages = el('div', { class: 'ai-messages' });

    const projectPicker = el('select', { id: 'ai-project-picker', class: 'ai-project-picker' });
    projectPicker.addEventListener('change', () => {
      selectedProjectId = projectPicker.value ? parseInt(projectPicker.value) : null;
    });

    const textarea = el('textarea', {
      id: 'ai-textarea',
      class: 'ai-textarea',
      placeholder: 'Build, edit, or diagnose…'
    });
    textarea.setAttribute('rows', '1');
    textarea.addEventListener('input', () => {
      textarea.style.height = 'auto';
      textarea.style.height = Math.min(textarea.scrollHeight, 180) + 'px';
      sendBtn.disabled = textarea.value.trim() === '';
    });

    const sendBtn = el('button', { class: 'btn btn-ai ai-send-btn', onClick: sendMessage }, '↑');
    sendBtn.disabled = true;

    const reviewToggle = el('button', {
      class: 'ai-review-toggle' + (reviewEnabled ? ' active' : ''),
      title: 'Review intent before building',
      onClick: () => {
        reviewEnabled = !reviewEnabled;
        localStorage.setItem('sb:ai_review', reviewEnabled ? '1' : '0');
        reviewToggle.classList.toggle('active', reviewEnabled);
      }
    }, 'Review');

    const inputBar = el('div', { class: 'ai-input-bar' },
      el('div', { class: 'ai-input-card' },
        textarea,
        el('div', { class: 'ai-input-actions' },
          projectPicker,
          reviewToggle,
          sendBtn
        )
      )
    );

    const hamburgerBtn = el('button', { class: 'ai-hamburger', onClick: () => toggleSidebar() }, '☰');
    const newSessionBtn = el('button', { class: 'ai-new-session-btn', title: 'New session', onClick: newSession }, '✎');
    const closeBtn = el('button', { class: 'ai-header-close', onClick: close }, '×');

    // Model selector button + dropdown
    function buildModelSelector() {
      const sel = getSelectedModel();
      const btn = el('button', { class: 'ai-model-btn', title: 'Switch AI model' }, sel.label + ' ▾');
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const existing = header.querySelector('.ai-model-dropdown');
        if (existing) { existing.remove(); return; }
        const dropdown = el('div', { class: 'ai-model-dropdown' });
        AI_MODELS.forEach(m => {
          const cur = getSelectedModel();
          const item = el('div', { class: 'ai-model-option' + (m.model === cur.model ? ' active' : '') },
            el('span', {}, m.label),
            el('span', { class: 'ai-model-badge' }, m.badge)
          );
          item.addEventListener('click', () => {
            setSelectedModel(m);
            btn.textContent = m.label + ' ▾';
            dropdown.remove();
          });
          dropdown.appendChild(item);
        });
        header.appendChild(dropdown);
        const onOutside = () => { dropdown.remove(); document.removeEventListener('click', onOutside); };
        setTimeout(() => document.addEventListener('click', onOutside), 0);
      });
      return btn;
    }
    const modelSelectorBtn = buildModelSelector();

    const header = el('div', { class: 'ai-header' },
      hamburgerBtn,
      el('span', { class: 'ai-header-title' }, '✦ SupaBein AI'),
      modelSelectorBtn,
      newSessionBtn,
      closeBtn
    );

    const mainArea = el('div', { class: 'ai-main' }, header, messages, inputBar);

    panel.appendChild(sidebarOverlay);
    panel.appendChild(sidebar);
    panel.appendChild(mainArea);

    return panel;
  }

  async function open(options = {}) {
    if (isOpen) return;
    isOpen = true;

    if (!panelEl) {
      panelEl = buildPanel();
      document.body.appendChild(panelEl);
    }

    const autoProject = detectCurrentProject();
    selectedProjectId = (options.projectId !== undefined) ? options.projectId : autoProject;

    await Promise.all([loadSessions(), loadProjects()]);
    renderProjectPicker();

    if (!currentSessionId || !getSession(currentSessionId)) {
      if (sessions.length) {
        currentSessionId = sessions[0].id;
        const sess = getSession(currentSessionId);
        if (sess) selectedProjectId = sess.projectId || selectedProjectId;
      } else {
        const sess = await createSession(selectedProjectId);
        currentSessionId = sess.id;
      }
    }

    renderSidebar();
    renderMessages();
    renderProjectPicker();

    await loadSessionMessages(currentSessionId);
    renderMessages();

    panelEl.classList.add('ai-panel-open');
    getOrCreateBackdrop().classList.add('active');

    const fab = document.getElementById('ai-fab');
    if (fab) fab.classList.add('ai-fab-hidden');

    setTimeout(() => panelEl.querySelector('#ai-textarea')?.focus(), 100);
    checkForActiveJobs();
  }

  function close() {
    if (!panelEl) return;
    panelEl.classList.remove('ai-panel-open');
    if (backdropEl) backdropEl.classList.remove('active');
    isOpen = false;
    sidebarVisible = false;
    toggleSidebar(false);
    const fab = document.getElementById('ai-fab');
    if (fab) fab.classList.remove('ai-fab-hidden');
  }

  function toggle(options) {
    if (isOpen) close(); else open(options);
  }

  return { open, close, toggle, checkForActiveJobs };
})();

function initAiFab() {
  const fab = el('button', { class: 'ai-fab', id: 'ai-fab', onClick: () => AiPanel.toggle() }, '✦ AI');
  document.body.appendChild(fab);
}

// Projects list
async function renderProjects() {
  if (!requireAuth()) return;

  renderLayout(null, '', [el('div', { class: 'page-header' },
    el('h1', { class: 'page-title' }, 'Projects'),
    el('button', { class: 'btn btn-primary', id: 'new-project' }, '+')
  ), el('div', { id: 'project-list' }, 'Loading...')]);

  document.getElementById('new-project').addEventListener('click', () => showNewProjectModal());

  try {
    const projects = await Api.get('/v1/projects');
    const list = document.getElementById('project-list');

    if (!projects.length) {
      list.innerHTML = '';
      list.appendChild(el('div', { class: 'ai-empty-state' },
        el('p', { class: 'text-muted' }, 'No projects yet.'),
        el('button', { class: 'btn btn-secondary', style: 'margin-top:16px', onClick: () => showNewProjectModal() }, 'New Project')
      ));
      return;
    }

    const grid = el('div', { class: 'proj-grid' },
      ...projects.map(p => {
        const initial = (p.name || '?')[0].toUpperCase();
        const menuBtn = el('button', { class: 'proj-menu-btn', title: 'More options' }, '⋮');
        const dropdown = el('div', { class: 'proj-menu-drop hidden' },
          el('button', { class: 'dropdown-item dropdown-item-danger' }, 'Delete')
        );
        menuBtn.addEventListener('click', e => {
          e.preventDefault(); e.stopPropagation();
          document.querySelectorAll('.proj-menu-drop').forEach(d => { if (d !== dropdown) d.classList.add('hidden'); });
          dropdown.classList.toggle('hidden');
        });
        dropdown.querySelector('button').addEventListener('click', async e => {
          e.preventDefault();
          if (!confirm(`Delete project "${p.name}"? This cannot be undone.`)) return;
          try { await Api.delete(`/v1/projects/${p.id}`); renderProjects(); }
          catch (err) { alert(err.message); }
        });

        const card = el('a', { class: 'proj-card', href: `#/projects/${p.id}` },
          el('div', { class: 'proj-initial' }, initial),
          el('div', { class: 'proj-card-body' },
            el('div', { class: 'proj-card-name' }, p.name),
            el('div', { class: 'proj-card-meta' }, `ID ${p.id} · ${fmtDate(p.created_at)}`)
          ),
          el('div', { class: 'proj-menu-wrap' }, menuBtn, dropdown)
        );
        return card;
      })
    );

    list.innerHTML = '';
    list.appendChild(grid);
  } catch (e) {
    document.getElementById('project-list').innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

function showNewProjectModal() {
  const overlay = h(`
    <div class="modal-overlay" id="new-project-modal">
      <div class="modal">
        <div class="modal-title">New Project</div>
        <div class="form-group"><label>Project Name</label><input type="text" id="proj-name" placeholder="my-app"></div>
        <div class="modal-footer">
          <button class="btn btn-secondary" id="cancel">Cancel</button>
          <button class="btn btn-primary" id="create">Create</button>
        </div>
      </div>
    </div>
  `);

  const modalEl = overlay.firstElementChild;
  modalEl.querySelector('#cancel').addEventListener('click', () => modalEl.remove());
  modalEl.querySelector('#create').addEventListener('click', async () => {
    const name = modalEl.querySelector('#proj-name').value.trim();
    if (!name) return;
    try {
      await Api.post('/v1/projects', { name });
      modalEl.remove();
      renderProjects();
    } catch (e) {
      console.error('[SupaBein] Create project failed', e);
      alert(e.message);
    }
  });

  document.body.appendChild(modalEl);
}

// Project overview
async function renderProject({ id }) {
  if (!requireAuth()) return;
  renderLayout(id, '', [el('p', { class: 'text-muted' }, 'Loading…')]);

  try {
    const project = await Api.get(`/v1/projects/${id}`);


    const tabTables = el('div', { class: 'tab active' }, 'Tables');
    const tabApi    = el('div', { class: 'tab' }, 'API');
    const tabDeploy = el('div', { class: 'tab' }, 'Deploy');

    const paneTablesEl = el('div', { id: 'pane-proj-tables' });
    const paneApiEl    = el('div', { id: 'pane-proj-api',    class: 'hidden' });
    const paneDeployEl = el('div', { id: 'pane-proj-deploy', class: 'hidden' });

    const allTabs  = [tabTables, tabApi, tabDeploy];
    const allPanes = [paneTablesEl, paneApiEl, paneDeployEl];

    function switchProjectTab(idx) {
      allTabs.forEach((t, i)  => t.classList.toggle('active', i === idx));
      allPanes.forEach((p, i) => p.classList.toggle('hidden', i !== idx));
    }

    tabTables.addEventListener('click', () => {
      switchProjectTab(0);
      if (!paneTablesEl.dataset.loaded) {
        paneTablesEl.dataset.loaded = '1';
        loadTablesPane(id, paneTablesEl);
      }
    });
    tabApi.addEventListener('click', () => {
      switchProjectTab(1);
      if (!paneApiEl.dataset.loaded) {
        paneApiEl.dataset.loaded = '1';
        loadApiPane(id, paneApiEl);
      }
    });
    tabDeploy.addEventListener('click', () => {
      switchProjectTab(2);
      if (!paneDeployEl.dataset.loaded) {
        paneDeployEl.dataset.loaded = '1';
        loadDeployPane(id, paneDeployEl);
      }
    });

    // Async: fetch site to show "View Site" in header if a live deploy exists
    Api.get(`/v1/projects/${id}/sites`).then(sites => {
      const liveSite = Array.isArray(sites) && sites.find(s => s.current_deploy_id);
      if (!liveSite) return;
      const hdr = document.querySelector('.page-header');
      if (hdr && !hdr.querySelector('#proj-view-site-btn')) {
        hdr.appendChild(el('a', {
          id: 'proj-view-site-btn',
          class: 'btn btn-primary btn-sm',
          href: `/sites/s${liveSite.id}/current/`,
          target: '_blank',
          rel: 'noopener'
        }, 'View Site →'));
      }
    }).catch(() => {});

    const content = [
      el('div', { class: 'page-header' },
        el('h1', { class: 'page-title' }, project.name),
        el('span', { class: 'text-muted', style: 'font-size:0.8rem' },
          `ID ${project.id} · ${fmtDate(project.created_at)}`
        )
      ),


      el('div', { class: 'tabs', style: 'margin-top:24px' }, tabTables, tabApi, tabDeploy),
      paneTablesEl, paneApiEl, paneDeployEl,
    ];

    renderLayout(id, '', content, { projectName: project.name });
    // Eagerly load the default (Tables) tab
    paneTablesEl.dataset.loaded = '1';
    loadTablesPane(id, paneTablesEl);
  } catch (e) {
    setApp(`<div class="alert alert-danger">${e.message}</div>`);
  }
}

async function loadTablesPane(projectId, container) {
  container.innerHTML = '<div class="text-muted">Loading…</div>';
  try {
    const tables = await Api.get(`/v1/projects/${projectId}/tables`);

    const newTableBtn = el('button', { class: 'btn btn-primary btn-sm' }, '+ New Table');
    newTableBtn.addEventListener('click', () => showNewTableModal(projectId));

    let tableContent;
    if (!tables.length) {
      tableContent = el('div', { class: 'text-muted', style: 'padding:20px 0' }, 'No tables yet.');
    } else {
      tableContent = el('table', { class: 'data-table' },
        el('thead', {}, el('tr', {},
          el('th', {}, 'Name'), el('th', {}, 'Physical Name'), el('th', {}, '')
        )),
        el('tbody', {}, ...tables.map(t =>
          el('tr', {},
            el('td', {}, el('a', { href: `#/projects/${projectId}/tables/${t.table_name}` }, t.table_name)),
            el('td', { class: 'text-muted text-sm' }, t.physical_name),
            el('td', {},
              el('button', {
                class: 'btn btn-sm btn-danger',
                onClick: async () => {
                  if (!confirm(`Drop table "${t.table_name}"?`)) return;
                  try {
                    await Api.delete(`/v1/projects/${projectId}/tables/${t.table_name}`);
                    loadTablesPane(projectId, container);
                  } catch (e) { alert(e.message); }
                }
              }, 'Drop')
            )
          )
        ))
      );
    }

    container.innerHTML = '';
    container.appendChild(el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:16px' },
      el('span', { class: 'text-muted', style: 'font-size:0.85rem' }, `${tables.length} table${tables.length !== 1 ? 's' : ''}`),
      newTableBtn
    ));
    container.appendChild(tableContent);
  } catch (e) {
    container.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

async function loadUsersPane(projectId, container) {
  container.innerHTML = '<div class="text-muted">Loading…</div>';
  try {
    const result = await Api.get(`/v1/projects/${projectId}/users`);
    const users = result.users || [];

    async function deleteUser(uid) {
      if (!confirm('Delete this user? This cannot be undone.')) return;
      try {
        await Api.delete(`/v1/projects/${projectId}/users/${uid}`);
        loadUsersPane(projectId, container);
      } catch (e) { alert('Failed to delete user: ' + e.message); }
    }

    const rows = users.length === 0
      ? [el('tr', {}, el('td', { colspan: '4', style: 'text-align:center;color:var(--text-muted);padding:32px' },
          'No end-users yet. Users appear here after they sign up via your app.'))]
      : users.map(u =>
          el('tr', {},
            el('td', {}, u.email),
            el('td', {}, String(u.id)),
            el('td', {}, String(u.created_at ?? '')),
            el('td', {}, el('button', { class: 'btn btn-sm btn-danger', onClick: () => deleteUser(u.id) }, 'Delete'))
          )
        );

    container.innerHTML = '';
    container.appendChild(el('p', { class: 'text-muted', style: 'font-size:0.85rem;margin-bottom:12px' },
      `${users.length} end-user${users.length !== 1 ? 's' : ''} registered`
    ));
    container.appendChild(
      el('div', { class: 'card' },
        el('div', { class: 'table-responsive' },
          el('table', { class: 'table' },
            el('thead', {}, el('tr', {},
              el('th', {}, 'Email'), el('th', {}, 'ID'), el('th', {}, 'Joined'), el('th', {}, '')
            )),
            el('tbody', {}, ...rows)
          )
        )
      )
    );
    container.appendChild(
      el('div', { class: 'card' },
        el('h3', { style: 'margin-top:0;font-size:0.95rem' }, 'Project User Auth API'),
        el('p', { class: 'text-muted', style: 'font-size:0.85rem;margin-bottom:0' },
          "Your app's end-users sign up and log in via the project auth endpoints. See the ",
          el('a', { href: `#/projects/${projectId}/api` }, 'API tab'),
          ' for curl examples.'
        )
      )
    );
  } catch (e) {
    container.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

async function renderApi({ id }, container) {
  const projectId = id;
  const useContainer = container || document.getElementById('app');
  if (!container) {
    if (!requireAuth()) return;
    renderLayout(projectId, '', [el('p', { class: 'text-muted' }, 'Loading…')]);
  } else {
    container.innerHTML = '<div class="text-muted">Loading…</div>';
  }

  try {
    const project = await Api.get(`/v1/projects/${projectId}`);
    const baseUrl  = window.location.origin;

    let keyDisplay = project.service_key || '(none — create a new project to generate)';
    const keyPre   = el('pre', { class: 'api-code-block', style: 'word-break:break-all;white-space:pre-wrap' }, keyDisplay);

    function copyServiceKey(btn) {
      navigator.clipboard.writeText(project.service_key || '').then(() => {
        const orig = btn.textContent; btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = orig; }, 1500);
      });
    }

    const copyBtn   = el('button', { class: 'btn btn-sm btn-secondary' }, 'Copy');
    const rotateBtn = el('button', { class: 'btn btn-sm btn-danger' }, 'Rotate key');

    copyBtn.onclick   = () => copyServiceKey(copyBtn);
    rotateBtn.onclick = async () => {
      if (!confirm('Rotate the service key? The old key will stop working immediately.')) return;
      try {
        rotateBtn.disabled = true; rotateBtn.textContent = 'Rotating…';
        const res = await Api.post(`/v1/projects/${projectId}/rotate-service-key`, {});
        project.service_key = res.service_key;
        keyPre.textContent  = res.service_key;
      } catch (e) { alert(e.message); }
      finally { rotateBtn.disabled = false; rotateBtn.textContent = 'Rotate key'; }
    };

    const keyCard = el('div', { class: 'api-table-card' },
      el('div', { class: 'api-table-title' }, 'Service Key'),
      el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin:6px 0 12px' },
        'Use the service key from a trusted server or backend script. It bypasses all row-level policies — never expose it in frontend code.'
      ),
      el('div', { style: 'position:relative;margin-bottom:10px' }, keyPre),
      el('div', { style: 'display:flex;gap:8px' }, copyBtn, rotateBtn)
    );

    const curlBlock = (code) => {
      const pre = el('pre', { class: 'api-code-block' }, code);
      const btn = el('button', { class: 'copy-btn btn btn-sm' }, 'Copy');
      btn.onclick = () => { navigator.clipboard.writeText(code).then(() => { const o = btn.textContent; btn.textContent = 'Copied!'; setTimeout(() => btn.textContent = o, 1500); }); };
      return el('div', { style: 'position:relative;margin-bottom:16px' }, pre, btn);
    };

    const sk = project.service_key || '<service_key>';
    const base = `${baseUrl}/api/v1/data/${projectId}`;

    const docsCard = el('div', { class: 'api-table-card' },
      el('div', { class: 'api-table-title' }, 'Data API'),
      el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin:6px 0 14px' },
        'Base URL: ', el('code', {}, `${base}/{table}`)
      ),

      el('div', { class: 'api-section-label' }, 'List rows'),
      curlBlock(`curl "${base}/{table}?limit=20&offset=0"`),

      el('div', { class: 'api-section-label' }, 'List with filter + ordering'),
      curlBlock(`curl "${base}/{table}?status=active&order=created_at.desc&limit=50"`),

      el('div', { class: 'api-section-label' }, 'Get single row'),
      curlBlock(`curl "${base}/{table}/{id}"`),

      el('div', { class: 'api-section-label' }, 'Insert a row'),
      curlBlock(`curl -X POST "${base}/{table}" \\\n  -H "Content-Type: application/json" \\\n  -d '{"col": "value"}'`),

      el('div', { class: 'api-section-label' }, 'Update a row'),
      curlBlock(`curl -X PATCH "${base}/{table}/{id}" \\\n  -H "Content-Type: application/json" \\\n  -d '{"col": "new value"}'`),

      el('div', { class: 'api-section-label' }, 'Delete a row'),
      curlBlock(`curl -X DELETE "${base}/{table}/{id}"`),

      el('div', { class: 'api-section-label' }, 'Login — tables with a PASSWORD column'),
      curlBlock(`curl -X POST "${base}/{table}/login" \\\n  -H "Content-Type: application/json" \\\n  -d '{"email": "user@example.com", "password": "secret"}'`),

      el('p', { class: 'text-muted', style: 'font-size:0.8rem;margin:4px 0 8px' },
        'Returns ', el('code', {}, '{ token, user }'), '. Use token as Bearer in subsequent requests (authenticated role).'
      ),

      el('div', { class: 'api-section-label' }, 'Service key — bypasses all row-level policies'),
      curlBlock(`curl "${base}/{table}" \\\n  -H "Authorization: Bearer ${sk}"`)
    );

    const nodes = [keyCard, docsCard];

    if (!container) {
      renderLayout(projectId, '', [
        el('div', { class: 'page-header' },
          el('a', { class: 'btn btn-secondary btn-sm', href: `#/projects/${projectId}` }, '← Project'),
          el('h1', { class: 'page-title' }, 'API')
        ),
        ...nodes
      ]);
    } else {
      container.innerHTML = '';
      nodes.forEach(n => container.appendChild(n));
    }
  } catch (e) {
    (container || document.getElementById('app')).innerHTML =
      `<div class="alert alert-danger">${e.message}</div>`;
  }
}

async function loadApiPane(projectId, container) {
  return renderApi({ id: projectId }, container);
}

async function loadDeployPane(projectId, container) {
  container.innerHTML = '<div class="text-muted">Loading…</div>';
  try {
    const sites = await Api.get(`/v1/projects/${projectId}/sites`);

    if (!sites.length) {
      container.innerHTML = '';
      const subdomainInp = el('input', { type: 'text', class: 'input', placeholder: 'my-app' });
      const spaModeInp   = el('input', { type: 'checkbox', id: 'dp-spa-mode' });
      spaModeInp.checked = true;
      const createBtn    = el('button', { class: 'btn btn-primary' }, 'Create Site');
      createBtn.addEventListener('click', async () => {
        const subdomain = subdomainInp.value.trim();
        const spa_mode  = spaModeInp.checked;
        if (!subdomain) return;
        try {
          await Api.post(`/v1/projects/${projectId}/sites`, { subdomain, spa_mode });
          container.dataset.loaded = '';
          loadDeployPane(projectId, container);
        } catch (e) { alert(e.message); }
      });
      container.appendChild(el('div', { class: 'card' },
        el('div', { class: 'card-title' }, 'Create your site'),
        el('div', { class: 'form-group' },
          el('label', {}, 'Subdomain'),
          subdomainInp
        ),
        el('div', { class: 'form-group', style: 'display:flex;align-items:center;gap:8px' },
          spaModeInp,
          el('label', { for: 'dp-spa-mode' }, 'SPA Mode (React/Vue/etc — rewrites all paths to index.html)')
        ),
        createBtn
      ));
      return;
    }

    const site   = sites[0];
    const siteId = site.id;

    const menuBtn  = el('button', { class: 'btn btn-secondary btn-sm', style: 'position:relative' }, '•••');
    const dropdown = el('div', { class: 'dropdown hidden' },
      el('button', { class: 'dropdown-item dropdown-item-danger' }, 'Delete Site')
    );
    menuBtn.addEventListener('click', e => {
      e.stopPropagation();
      dropdown.classList.toggle('hidden');
    });
    document.addEventListener('click', () => dropdown.classList.add('hidden'));
    dropdown.querySelector('button').addEventListener('click', async () => {
      dropdown.classList.add('hidden');
      if (!confirm('Delete this site and all its deploys? This cannot be undone.')) return;
      try {
        await Api.delete(`/v1/projects/${projectId}/sites/${siteId}`);
        container.dataset.loaded = '';
        loadDeployPane(projectId, container);
      } catch (e) { alert(e.message); }
    });

    const deployContentEl = el('div', { id: 'deploy-content' }, 'Loading…');
    container.innerHTML = '';
    container.appendChild(el('div', { style: 'display:flex;justify-content:flex-end;margin-bottom:8px;gap:8px;position:relative' }, menuBtn, dropdown));
    container.appendChild(deployContentEl);

    await loadDeployContent(projectId, siteId);
  } catch (e) {
    container.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

// Tables list
async function renderTables({ id }) {
  if (!requireAuth()) return;

  renderLayout(id, 'tables', [
    el('div', { class: 'page-header' },
      el('a', { class: 'btn btn-secondary btn-sm', href: `#/projects/${id}` }, '← Project'),
      el('h1', { class: 'page-title' }, 'Tables'),
      el('button', { class: 'btn btn-primary', id: 'new-table' }, '+')
    ),
    el('div', { id: 'table-list' }, 'Loading...')
  ]);

  document.getElementById('new-table').addEventListener('click', () => showNewTableModal(id));

  try {
    const tables = await Api.get(`/v1/projects/${id}/tables`);
    const list   = document.getElementById('table-list');

    if (!tables.length) {
      list.innerHTML = '<div class="text-muted">No tables yet.</div>';
      return;
    }

    const tbl = el('table', { class: 'data-table' },
      el('thead', {}, el('tr', {},
        el('th', {}, 'Name'), el('th', {}, 'Physical Name'), el('th', {}, '')
      )),
      el('tbody', {}, ...tables.map(t =>
        el('tr', {},
          el('td', {}, el('a', { href: `#/projects/${id}/tables/${t.table_name}` }, t.table_name)),
          el('td', { class: 'text-muted text-sm' }, t.physical_name),
          el('td', {},
            el('button', {
              class: 'btn btn-sm btn-danger',
              onClick: async () => {
                if (!confirm(`Drop table "${t.table_name}"?`)) return;
                await Api.delete(`/v1/projects/${id}/tables/${t.table_name}`);
                renderTables({ id });
              }
            }, 'Drop')
          )
        )
      ))
    );

    list.innerHTML = '';
    list.appendChild(tbl);
  } catch (e) {
    document.getElementById('table-list').innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

function showNewTableModal(projectId) {
  const overlay = h(`
    <div class="modal-overlay">
      <div class="modal">
        <div class="modal-title">New Table</div>
        <div class="form-group"><label>Table Name</label><input type="text" id="tname" placeholder="posts"></div>
        <div class="modal-footer">
          <button class="btn btn-secondary" id="cancel">Cancel</button>
          <button class="btn btn-primary" id="create">Create</button>
        </div>
      </div>
    </div>
  `);
  const modalElT = overlay.firstElementChild;
  modalElT.querySelector('#cancel').addEventListener('click', () => modalElT.remove());
  modalElT.querySelector('#create').addEventListener('click', async () => {
    const name = modalElT.querySelector('#tname').value.trim();
    if (!name) return;
    try {
      await Api.post(`/v1/projects/${projectId}/tables`, { name });
      modalElT.remove();
      renderTables({ id: projectId });
    } catch (e) { console.error('[SupaBein] Create table failed', e); alert(e.message); }
  });
  document.body.appendChild(modalElT);
}

// Table editor: columns + rows + policies
async function renderTableEditor({ id, name }) {
  if (!requireAuth()) return;

  renderLayout(id, 'tables', [
    el('div', { class: 'page-header' },
      el('h1', { class: 'page-title' }, name),
      el('a', { class: 'btn btn-secondary btn-sm', href: `#/projects/${id}/tables` }, '← Tables')
    ),
    el('div', { class: 'tabs' },
      el('div', { class: 'tab active', id: 'tab-cols', onClick: () => switchTab('cols') }, 'Columns'),
      el('div', { class: 'tab', id: 'tab-rows', onClick: () => switchTab('rows') }, 'Data'),
      el('div', { class: 'tab', id: 'tab-policies', onClick: () => switchTab('policies') }, 'Policies')
    ),
    el('div', { id: 'tab-content' })
  ]);

  switchTab('cols');

  function switchTab(tab) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + tab)?.classList.add('active');
    if (tab === 'cols') renderColumnsTab(id, name);
    else if (tab === 'rows') renderRowsTab(id, name);
    else if (tab === 'policies') renderPoliciesTab(id, name);
  }
}

async function renderColumnsTab(projectId, tableName) {
  const content = document.getElementById('tab-content');
  content.innerHTML = 'Loading...';

  const TYPES = ['INT','BIGINT','VARCHAR(255)','TEXT','BOOLEAN','DECIMAL(10,2)','DATETIME','DATE','TIMESTAMP','JSON','FLOAT'];

  try {
    const cols = await Api.get(`/v1/projects/${projectId}/tables/${tableName}/columns`);

    const addForm = h(`
      <div class="card">
        <div class="card-title">Add Column</div>
        <div class="add-col-row">
          <input type="text" id="col-name" placeholder="column_name" class="add-col-name">
          <select id="col-type" class="add-col-type">${TYPES.map(t => `<option>${t}</option>`).join('')}</select>
          <label class="add-col-nullable">
            <input type="checkbox" id="col-nullable" checked> Nullable
          </label>
          <button class="btn btn-primary btn-sm" id="add-col">Add</button>
        </div>
      </div>
    `);

    const addFormEl = addForm.firstElementChild;
    addFormEl.querySelector('#add-col').addEventListener('click', async () => {
      const colName  = addFormEl.querySelector('#col-name').value.trim();
      const dataType = addFormEl.querySelector('#col-type').value;
      const nullable = addFormEl.querySelector('#col-nullable').checked;
      if (!colName) return;
      try {
        await Api.post(`/v1/projects/${projectId}/tables/${tableName}/columns`, { name: colName, type: dataType, nullable });
        renderColumnsTab(projectId, tableName);
      } catch (e) { console.error('[SupaBein] Add column failed', e); alert(e.message); }
    });

    const colsHtml = cols.length
      ? el('table', { class: 'data-table' },
          el('thead', {}, el('tr', {}, el('th', {}, 'Name'), el('th', {}, 'Type'), el('th', {}, 'Nullable'), el('th', {}, ''))),
          el('tbody', {}, ...cols.map(c =>
            el('tr', {},
              el('td', {}, c.col_name),
              el('td', { class: 'text-muted' }, c.data_type),
              el('td', {}, c.nullable ? 'Yes' : 'No'),
              el('td', {},
                el('button', {
                  class: 'btn btn-sm btn-danger',
                  onClick: async () => {
                    if (!confirm(`Drop column "${c.col_name}"?`)) return;
                    await Api.delete(`/v1/projects/${projectId}/tables/${tableName}/columns/${c.col_name}`);
                    renderColumnsTab(projectId, tableName);
                  }
                }, 'Drop')
              )
            )
          ))
        )
      : el('div', { class: 'text-muted' }, 'No custom columns. (id and created_at are always present)');

    content.innerHTML = '';
    content.appendChild(addFormEl);
    content.appendChild(el('div', { class: 'card mt-3' },
      el('div', { class: 'card-title' }, 'Columns'),
      colsHtml
    ));
  } catch (e) {
    content.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

async function renderRowsTab(projectId, tableName) {
  const content = document.getElementById('tab-content');
  content.innerHTML = 'Loading...';

  let offset    = 0;
  const limit   = 25;
  let cols      = [];
  let filterVal = '';
  let orderVal  = '';

  function openRowModal(title, row, onSave) {
    const overlay = el('div', { class: 'modal-overlay' });
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    const inputs = {};
    const fields = cols.map(c => {
      const inp = el('input', { type: 'text', placeholder: c.data_type });
      if (row) inp.value = String(row[c.col_name] ?? '');
      inputs[c.col_name] = inp;
      return el('div', { class: 'form-group' },
        el('label', {}, c.col_name + ' (' + c.data_type + ')'),
        inp
      );
    });
    const modal = el('div', { class: 'modal' },
      el('div', { class: 'modal-title' }, title),
      ...fields,
      el('div', { class: 'modal-footer' },
        el('button', { class: 'btn btn-secondary', onClick: () => overlay.remove() }, 'Cancel'),
        el('button', { class: 'btn btn-primary', onClick: async () => {
          const data = {};
          for (const [k, inp] of Object.entries(inputs)) data[k] = inp.value;
          try { await onSave(data); overlay.remove(); await refresh(); }
          catch (e) { alert(e.message); }
        }}, 'Save')
      )
    );
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    modal.querySelector('input')?.focus();
  }

  async function refresh() {
    const area = document.getElementById('rows-area');
    if (!area) return;
    area.innerHTML = '<span class="text-muted">Loading…</span>';
    try {
      let qs = `limit=${limit}&offset=${offset}`;
      if (orderVal)  qs += '&order='  + encodeURIComponent(orderVal);
      if (filterVal) qs += '&' + filterVal.replace(/^\?/, '');
      const res  = await Api.get(`/v1/data/${projectId}/${tableName}?${qs}`);
      const rows = res.data || [];
      const displayCols = [{ col_name: 'id' }, { col_name: 'created_at' }, ...cols];

      area.innerHTML = '';

      if (!rows.length) {
        area.appendChild(el('p', { class: 'text-muted mt-2' }, 'No rows match the current filter.'));
      } else {
        area.appendChild(el('div', { style: 'overflow-x:auto' },
          el('table', { class: 'data-table' },
            el('thead', {}, el('tr', {},
              ...displayCols.map(c => el('th', {}, c.col_name)),
              el('th', {}, 'Actions')
            )),
            el('tbody', {}, ...rows.map(r =>
              el('tr', {},
                ...displayCols.map(c =>
                  el('td', { style: 'max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap', title: String(r[c.col_name] ?? '') },
                    String(r[c.col_name] ?? ''))
                ),
                el('td', { style: 'white-space:nowrap;display:flex;gap:4px' },
                  el('button', { class: 'btn btn-sm btn-secondary',
                    onClick: () => openRowModal('Edit Row #' + r.id, r, data =>
                      Api.patch(`/v1/data/${projectId}/${tableName}/${r.id}`, data)
                    )
                  }, 'Edit'),
                  el('button', { class: 'btn btn-sm btn-danger',
                    onClick: async () => {
                      if (!confirm('Delete row #' + r.id + '?')) return;
                      try { await Api.delete(`/v1/data/${projectId}/${tableName}/${r.id}`); await refresh(); }
                      catch (e) { alert(e.message); }
                    }
                  }, 'Del')
                )
              )
            ))
          )
        ));
      }

      area.appendChild(el('div', { style: 'display:flex;align-items:center;gap:12px;margin-top:14px;font-size:13px' },
        el('button', { class: 'btn btn-secondary btn-sm', disabled: offset === 0,
          onClick: () => { offset = Math.max(0, offset - limit); refresh(); }
        }, '← Prev'),
        el('span', { class: 'text-muted' }, `${offset + 1}–${offset + rows.length} · total returned: ${res.count}`),
        el('button', { class: 'btn btn-secondary btn-sm', disabled: rows.length < limit,
          onClick: () => { offset += limit; refresh(); }
        }, 'Next →')
      ));
    } catch (e) {
      area.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
    }
  }

  try {
    cols = await Api.get(`/v1/projects/${projectId}/tables/${tableName}/columns`);

    const filterInp = el('input', { type: 'text', placeholder: 'Filter  e.g. status=active  or  age=gte.18', style: 'flex:1;min-width:180px' });
    const orderInp  = el('input', { type: 'text', placeholder: 'Order  e.g. created_at.desc', style: 'width:170px' });

    content.innerHTML = '';
    content.appendChild(el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px' },
      filterInp, orderInp,
      el('button', { class: 'btn btn-secondary btn-sm', onClick: () => {
        filterVal = filterInp.value.trim(); orderVal = orderInp.value.trim(); offset = 0; refresh();
      }}, 'Apply'),
      el('button', { class: 'btn btn-secondary btn-sm', onClick: () => {
        filterInp.value = ''; orderInp.value = ''; filterVal = ''; orderVal = ''; offset = 0; refresh();
      }}, 'Clear'),
      el('button', { class: 'btn btn-primary btn-sm', style: 'margin-left:auto',
        onClick: () => openRowModal('Insert Row', null, data =>
          Api.post(`/v1/data/${projectId}/${tableName}`, data)
        )
      }, '+')
    ));
    content.appendChild(el('div', { id: 'rows-area' }));
    await refresh();
  } catch (e) {
    content.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

async function renderPoliciesTab(projectId, tableName) {
  const content = document.getElementById('tab-content');
  content.innerHTML = 'Loading...';

  const OPERATIONS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

  try {
    const policies = await Api.get(`/v1/projects/${projectId}/tables/${tableName}/policies`);

    const policyForm = h(`
      <div class="card">
        <div class="card-title">Add / Update Policy</div>
        <div class="flex gap-2" style="flex-wrap:wrap">
          <div class="form-group" style="width:180px"><label>API Role</label><input type="text" id="p-role" placeholder="anon / authenticated" list="role-options"><datalist id="role-options"><option value="anon"><option value="authenticated"></datalist></div>
          <div class="form-group" style="width:140px"><label>Operation</label><select id="p-op">${OPERATIONS.map(o=>`<option>${o}</option>`).join('')}</select></div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:22px">
            <input type="checkbox" id="p-allowed"> <label for="p-allowed">Allowed</label>
          </div>
        </div>
        <div class="form-group"><label>Constraint SQL <span class="text-muted">(optional, e.g. user_id = :current_user_id)</span></label>
          <input type="text" id="p-constraint" placeholder="user_id = :current_user_id">
        </div>
        <button class="btn btn-primary btn-sm" id="save-policy">Save Policy</button>
      </div>
    `);

    const policyFormEl = policyForm.firstElementChild;
    policyFormEl.querySelector('#save-policy').addEventListener('click', async () => {
      const body = {
        api_role: policyFormEl.querySelector('#p-role').value.trim(),
        operation: policyFormEl.querySelector('#p-op').value,
        allowed: policyFormEl.querySelector('#p-allowed').checked,
        constraint_sql: policyFormEl.querySelector('#p-constraint').value.trim() || null,
      };
      try {
        await Api.put(`/v1/projects/${projectId}/tables/${tableName}/policies`, body);
        renderPoliciesTab(projectId, tableName);
      } catch (e) { console.error('[SupaBein] Save policy failed', e); alert(e.message); }
    });

    const policyTable = policies.length
      ? el('table', { class: 'data-table' },
          el('thead', {}, el('tr', {}, el('th', {}, 'Role'), el('th', {}, 'Op'), el('th', {}, 'Allowed'), el('th', {}, 'Constraint'))),
          el('tbody', {}, ...policies.map(p =>
            el('tr', {},
              el('td', {}, p.api_role),
              el('td', {}, p.operation),
              el('td', {}, p.allowed ? el('span', {class:'badge badge-green'},'Yes') : el('span', {class:'badge badge-red'},'No')),
              el('td', { class: 'text-muted text-sm' }, p.constraint_sql || '—')
            )
          ))
        )
      : el('div', { class: 'text-muted' }, 'No policies defined. All access is denied by default.');

    content.innerHTML = '';
    content.appendChild(policyFormEl);
    content.appendChild(el('div', { class: 'card mt-3' },
      el('div', { class: 'card-title' }, 'Current Policies'),
      policyTable
    ));
  } catch (e) {
    content.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

// Sites
async function renderSites({ id }) {
  if (!requireAuth()) return;

  // Show a minimal loading state while we check
  renderLayout(id, 'sites', [
    el('div', { class: 'page-header' }, el('h1', { class: 'page-title' }, 'Site')),
    el('div', { id: 'site-list' }, 'Loading...')
  ]);

  try {
    const sites = await Api.get(`/v1/projects/${id}/sites`);

    // Any existing site → go straight to the manager
    if (sites.length >= 1) {
      Router.navigate(`/projects/${id}/sites/${sites[0].id}`);
      return;
    }

    // No site yet — show create form
    renderLayout(id, 'sites', [
      el('div', { class: 'page-header' }, el('h1', { class: 'page-title' }, 'Site')),
      el('div', { class: 'card' },
        el('div', { class: 'card-title' }, 'Create your site'),
        el('div', { class: 'form-group' },
          el('label', {}, 'Subdomain'),
          el('input', { type: 'text', id: 'subdomain', placeholder: 'my-app' })
        ),
        el('div', { class: 'form-group', style: 'display:flex;align-items:center;gap:8px' },
          el('input', { type: 'checkbox', id: 'spa-mode', checked: true }),
          el('label', { for: 'spa-mode' }, 'SPA Mode (React/Vue/etc — rewrites all paths to index.html)')
        ),
        el('button', { class: 'btn btn-primary', id: 'create-site' }, 'Create Site')
      )
    ]);

    document.getElementById('create-site').addEventListener('click', async () => {
      const subdomain = document.getElementById('subdomain').value.trim();
      const spa_mode  = document.getElementById('spa-mode').checked;
      if (!subdomain) return;
      try {
        const site = await Api.post(`/v1/projects/${id}/sites`, { subdomain, spa_mode });
        Router.navigate(`/projects/${id}/sites/${site.id}`);
      } catch (e) { alert(e.message); }
    });
  } catch (e) {
    document.getElementById('site-list').innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

function showNewSiteModal(projectId) {
  const overlay = h(`
    <div class="modal-overlay">
      <div class="modal">
        <div class="modal-title">New Site</div>
        <div class="form-group"><label>Subdomain</label><input type="text" id="subdomain" placeholder="my-app"></div>
        <div class="form-group" style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" id="spa-mode" checked>
          <label for="spa-mode">SPA Mode (React/Vue/etc — rewrites all paths to index.html)</label>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" id="cancel">Cancel</button>
          <button class="btn btn-primary" id="create">Create</button>
        </div>
      </div>
    </div>
  `);
  const modalElS = overlay.firstElementChild;
  modalElS.querySelector('#cancel').addEventListener('click', () => modalElS.remove());
  modalElS.querySelector('#create').addEventListener('click', async () => {
    const subdomain = modalElS.querySelector('#subdomain').value.trim();
    const spa_mode  = modalElS.querySelector('#spa-mode').checked;
    if (!subdomain) return;
    try {
      await Api.post(`/v1/projects/${projectId}/sites`, { subdomain, spa_mode });
      modalElS.remove();
      renderSites({ id: projectId });
    } catch (e) { console.error('[SupaBein] Create site failed', e); alert(e.message); }
  });
  document.body.appendChild(modalElS);
}

// Site manager — deploy history + upload
async function renderSiteManager({ id, site_id }) {
  if (!requireAuth()) return;

  const menuBtn = el('button', { class: 'btn btn-secondary btn-sm', id: 'site-menu-btn', style: 'position:relative' }, '•••');
  const dropdown = el('div', { class: 'dropdown hidden', id: 'site-menu-dropdown' },
    el('button', { class: 'dropdown-item dropdown-item-danger', id: 'delete-site-btn' }, 'Delete Site')
  );
  const menuWrap = el('div', { style: 'position:relative' }, menuBtn, dropdown);

  renderLayout(id, 'sites', [
    el('div', { class: 'page-header' },
      el('a', { class: 'btn btn-secondary btn-sm', href: `#/projects/${id}` }, '← Project'),
      el('h1', { class: 'page-title' }, 'Site'),
      menuWrap
    ),
    el('div', { id: 'deploy-content' }, 'Loading...')
  ]);

  menuBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    dropdown.classList.toggle('hidden');
  });
  document.addEventListener('click', () => dropdown.classList.add('hidden'), { capture: false });

  dropdown.querySelector('#delete-site-btn').addEventListener('click', async () => {
    dropdown.classList.add('hidden');
    if (!confirm('Delete this site and all its deploys? This cannot be undone.')) return;
    try {
      await Api.delete(`/v1/projects/${id}/sites/${site_id}`);
      Router.navigate(`/projects/${id}/sites`);
    } catch (e) { alert(e.message); }
  });

  await loadDeployContent(id, site_id);
}

async function loadDeployContent(projectId, siteId) {
  const content = document.getElementById('deploy-content');

  try {
    const [site, deploys] = await Promise.all([
      Api.get(`/v1/projects/${projectId}/sites/${siteId}`),
      Api.get(`/v1/projects/${projectId}/sites/${siteId}/deploys`)
    ]);

    const uploadForm = h(`
      <div class="card">
        <div class="card-title">Upload Deploy (zip)</div>
        <div class="form-group">
          <label class="label">Version label (optional)</label>
          <input type="text" id="deploy-label" class="input" placeholder="e.g. v1.0.0 or hotfix-login">
        </div>
        <div class="form-group">
          <label class="label">Zip file</label>
          <input type="file" id="zip-file" accept=".zip">
        </div>
        <button class="btn btn-primary" id="deploy-btn">Deploy to Staging</button>
        <div class="progress-wrap hidden" id="progress-wrap">
          <div class="progress-bar" id="progress-bar"></div>
        </div>
        <div id="upload-status" class="mt-2 text-sm"></div>
      </div>
    `);

    const uploadFormEl = uploadForm.firstElementChild;
    uploadFormEl.querySelector('#deploy-btn').addEventListener('click', async () => {
      const file = uploadFormEl.querySelector('#zip-file').files[0];
      if (!file) { alert('Select a zip file first'); return; }

      const labelVal = uploadFormEl.querySelector('#deploy-label').value.trim();
      const fd = new FormData();
      fd.append('zipfile', file);
      if (labelVal) fd.append('label', labelVal);

      const progressWrap = uploadFormEl.querySelector('#progress-wrap');
      const progressBar  = uploadFormEl.querySelector('#progress-bar');
      const status       = uploadFormEl.querySelector('#upload-status');
      progressWrap.classList.remove('hidden');
      status.textContent = 'Uploading...';

      try {
        await new Promise((resolve, reject) => {
          const xhr = new XMLHttpRequest();
          xhr.open('POST', `/api/v1/projects/${projectId}/sites/${siteId}/deploys`);
          const token = Auth.getToken();
          if (token) xhr.setRequestHeader('Authorization', 'Bearer ' + token);

          xhr.upload.onprogress = e => {
            if (e.lengthComputable) {
              progressBar.style.width = Math.round(e.loaded / e.total * 100) + '%';
            }
          };

          xhr.onload = () => {
            if (xhr.status === 201 || xhr.status === 200) resolve(JSON.parse(xhr.responseText));
            else reject(new Error(JSON.parse(xhr.responseText).error || 'Upload failed'));
          };
          xhr.onerror = () => reject(new Error('Network error'));
          xhr.send(fd);
        });

        status.textContent = 'Staged! Click "Publish to Live" to go live.';
        status.style.color = 'var(--accent)';
        await loadDeployContent(projectId, siteId);
      } catch (e) {
        status.textContent = 'Error: ' + e.message;
        status.style.color = 'var(--danger)';
        progressWrap.classList.add('hidden');
      }
    });

    const currentDeploy = deploys.find(d => d.id === site.current_deploy_id);
    const statusBadge = d => {
      const cls = d.status === 'ready' ? 'green' : d.status === 'failed' ? 'red' : 'yellow';
      return `<span class="badge badge-${cls}">${d.status}</span>`;
    };

    const deployTable = deploys.length
      ? el('table', { class: 'data-table' },
          el('thead', {}, el('tr', {},
            el('th', {}, 'Version'), el('th', {}, 'Status'), el('th', {}, 'Size'), el('th', {}, 'Uploaded'), el('th', {}, '')
          )),
          el('tbody', {}, ...deploys.map((d, idx) => {
            const isCurrent = d.id === site.current_deploy_id;
            const nextReady = deploys.slice(idx + 1).find(x => x.status === 'ready');
            const isStaging = d.id === site.staging_deploy_id;
            const labelEl = el('span', {}, d.version_label || '—');
            if (isCurrent) {
              const chip = el('span', { class: 'badge badge-green', style: 'margin-left:6px;font-size:0.7rem' }, 'live');
              labelEl.appendChild(chip);
            } else if (isStaging) {
              const chip = el('span', { class: 'badge badge-yellow', style: 'margin-left:6px;font-size:0.7rem' }, 'staging');
              labelEl.appendChild(chip);
            } else if (d.status === 'pending') {
              const chip = el('span', { class: 'badge badge-yellow', style: 'margin-left:6px;font-size:0.7rem' }, 'pending');
              labelEl.appendChild(chip);
            }
            const actions = el('td', { style: 'white-space:nowrap' });
            if (isStaging && d.status === 'ready') {
              actions.appendChild(el('button', {
                class: 'btn btn-sm btn-primary',
                style: 'margin-right:6px',
                onClick: async () => {
                  if (!confirm('Publish this staging deploy to live?')) return;
                  try {
                    await Api.post(`/v1/projects/${projectId}/sites/${siteId}/deploys/${d.id}/publish`);
                    loadDeployContent(projectId, siteId);
                  } catch (e) { alert(e.message); }
                }
              }, 'Publish to Live'));
            }
            if (!isCurrent && !isStaging && d.status === 'ready') {
              actions.appendChild(el('button', {
                class: 'btn btn-sm btn-secondary',
                style: 'margin-right:6px',
                onClick: async () => {
                  if (!confirm('Roll back to this deploy?')) return;
                  try {
                    await Api.post(`/v1/projects/${projectId}/sites/${siteId}/deploys/${d.id}/rollback`);
                    loadDeployContent(projectId, siteId);
                  } catch (e) { alert(e.message); }
                }
              }, 'Rollback'));
            }
            if (nextReady && d.status === 'ready') {
              actions.appendChild(el('button', {
                class: 'btn btn-sm btn-ghost',
                onClick: async () => {
                  try {
                    const diff = await Api.get(`/v1/projects/${projectId}/sites/${siteId}/deploys/${d.id}/diff?vs=${nextReady.id}`);
                    const lines = [
                      `Diff: deploy ${d.id} vs ${nextReady.id}`,
                      '',
                      `Added (${diff.added.length}):    ${diff.added.join(', ') || 'none'}`,
                      `Removed (${diff.removed.length}): ${diff.removed.join(', ') || 'none'}`,
                      `Modified (${diff.modified.length}): ${diff.modified.join(', ') || 'none'}`,
                      `Unchanged: ${diff.unchanged}`,
                    ];
                    alert(lines.join('\n'));
                  } catch (e) { alert(e.message); }
                }
              }, 'Diff'));
            }
            if (d.status === 'ready') {
              actions.appendChild(el('button', {
                class: 'btn btn-sm btn-ghost',
                onClick: async (e) => {
                  e.target.disabled = true; e.target.textContent = '…';
                  try {
                    const res = await fetch(`/api/v1/projects/${projectId}/sites/${siteId}/deploys/${d.id}/download`, {
                      headers: { Authorization: 'Bearer ' + (Auth.getToken() || '') }
                    });
                    if (!res.ok) throw new Error('Download failed');
                    const cd = res.headers.get('Content-Disposition') || '';
                    const fnMatch = cd.match(/filename="([^"]+)"/);
                    const filename = fnMatch ? fnMatch[1] : `deploy-${d.id}.zip`;
                    const blob = await res.blob();
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url; a.download = filename; a.click();
                    URL.revokeObjectURL(url);
                  } catch (err) { alert(err.message); }
                  finally { e.target.disabled = false; e.target.textContent = '↓ ZIP'; }
                }
              }, '↓ ZIP'));
            }
            return el('tr', {},
              el('td', {}, labelEl),
              el('td', {}, ...h(`<span>${statusBadge(d)}</span>`).children),
              el('td', { class: 'text-muted text-sm' }, d.size_bytes ? Math.round(d.size_bytes / 1024) + ' KB' : '—'),
              el('td', { class: 'text-muted text-sm' }, fmtDate(d.uploaded_at)),
              actions
            );
          }))
        )
      : el('div', { class: 'text-muted' }, 'No deploys yet. Upload a zip above.');

    const tabDeploy = el('div', { class: 'tab active', id: 'tab-deploy' }, 'Deploy');
    const tabFiles  = el('div', { class: 'tab', id: 'tab-files' }, 'Files');
    const paneDeployEl = el('div', { id: 'pane-deploy' });
    const paneFilesEl  = el('div', { id: 'pane-files', class: 'hidden' });

    paneDeployEl.appendChild(uploadFormEl);
    paneDeployEl.appendChild(el('div', { class: 'card mt-3' },
      el('div', { class: 'card-title' }, 'Deploy History'),
      deployTable
    ));

    tabDeploy.addEventListener('click', () => {
      tabDeploy.classList.add('active'); tabFiles.classList.remove('active');
      paneDeployEl.classList.remove('hidden'); paneFilesEl.classList.add('hidden');
    });
    tabFiles.addEventListener('click', () => {
      tabFiles.classList.add('active'); tabDeploy.classList.remove('active');
      paneFilesEl.classList.remove('hidden'); paneDeployEl.classList.add('hidden');
      if (!paneFilesEl.dataset.loaded) {
        paneFilesEl.dataset.loaded = '1';
        loadFileBrowser(projectId, siteId, paneFilesEl, '');
      }
    });

    content.innerHTML = '';
    content.appendChild(el('div', { class: 'tabs' }, tabDeploy, tabFiles));
    content.appendChild(paneDeployEl);
    content.appendChild(paneFilesEl);
  } catch (e) {
    content.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

async function loadFileBrowser(projectId, siteId, container, path) {
  container.innerHTML = '<div class="text-muted" style="padding:12px">Loading...</div>';
  try {
    const data = await Api.get(`/v1/projects/${projectId}/sites/${siteId}/browse?path=${encodeURIComponent(path)}`);

    if (data.type === 'dir') {
      // Breadcrumb
      const parts = path ? path.split('/').filter(Boolean) : [];
      const crumbs = [el('span', { class: 'fb-crumb', style: 'cursor:pointer', onClick: () => loadFileBrowser(projectId, siteId, container, '') }, '/')];
      parts.forEach((p, i) => {
        crumbs.push(el('span', { class: 'text-muted' }, ' / '));
        const crumbPath = parts.slice(0, i + 1).join('/');
        crumbs.push(el('span', { class: 'fb-crumb', style: 'cursor:pointer', onClick: () => loadFileBrowser(projectId, siteId, container, crumbPath) }, p));
      });

      const rows = data.items.map(item => {
        const icon = item.type === 'dir' ? '📁' : '📄';
        const itemPath = path ? path + '/' + item.name : item.name;
        return el('tr', { class: 'fb-row', style: 'cursor:pointer', onClick: () => loadFileBrowser(projectId, siteId, container, itemPath) },
          el('td', { style: 'width:24px' }, icon),
          el('td', {}, item.name),
          el('td', { class: 'text-muted text-sm', style: 'text-align:right;width:80px' }, item.size != null ? Math.round(item.size / 1024 * 10) / 10 + ' KB' : '')
        );
      });

      // Back row
      if (path) {
        const parentPath = path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '';
        rows.unshift(el('tr', { class: 'fb-row', style: 'cursor:pointer', onClick: () => loadFileBrowser(projectId, siteId, container, parentPath) },
          el('td', {}, '⬆'), el('td', {}, '..'), el('td', {})
        ));
      }

      container.innerHTML = '';
      container.appendChild(el('div', { class: 'card' },
        el('div', { style: 'display:flex;align-items:center;gap:4px;margin-bottom:12px;flex-wrap:wrap' }, ...crumbs),
        data.items.length
          ? el('table', { class: 'data-table' }, el('tbody', {}, ...rows))
          : el('div', { class: 'text-muted' }, 'Empty directory')
      ));
    } else {
      // File viewer
      const parentPath = path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '';
      const fileName = path.split('/').pop();
      container.innerHTML = '';
      container.appendChild(el('div', { class: 'card' },
        el('div', { style: 'display:flex;align-items:center;gap:8px;margin-bottom:12px' },
          el('span', { class: 'fb-crumb', style: 'cursor:pointer', onClick: () => loadFileBrowser(projectId, siteId, container, parentPath) }, '← Back'),
          el('span', { class: 'text-muted' }, fileName),
          el('span', { class: 'text-muted text-sm' }, data.size != null ? '(' + Math.round(data.size / 1024 * 10) / 10 + ' KB)' : '')
        ),
        data.truncated
          ? el('div', { class: 'alert alert-error' }, 'File too large to display (> 512 KB)')
          : (() => {
              const EXT_LANG = {
                html: 'html', htm: 'html', css: 'css',
                js: 'javascript', mjs: 'javascript', ts: 'typescript',
                json: 'json', php: 'php', md: 'markdown',
                xml: 'xml', svg: 'xml', sh: 'bash', bash: 'bash',
                py: 'python', rb: 'ruby', sql: 'sql', yaml: 'yaml', yml: 'yaml',
              };
              const ext = fileName.split('.').pop().toLowerCase();
              const lang = EXT_LANG[ext] || 'plaintext';
              const pre = document.createElement('pre');
              pre.className = 'fb-content';
              const code = document.createElement('code');
              code.className = `language-${lang}`;
              code.textContent = data.content ?? '';
              pre.appendChild(code);
              if (window.hljs) hljs.highlightElement(code);
              return pre;
            })()
      ));
    }
  } catch (e) {
    container.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

function render404() {
  setApp('<div style="padding:48px;text-align:center"><h1 style="font-size:48px;color:var(--muted)">404</h1><p class="text-muted">Page not found</p><a href="#/projects">Go home</a></div>');
}

// ─── Account ──────────────────────────────────────────────────────────────────

async function renderAccount() {
  if (!requireAuth()) return;
  renderLayout(null, 'account', [el('p', { class: 'text-muted' }, 'Loading…')]);

  let user = null, pats = [];
  try {
    [user, pats] = await Promise.all([
      Api.get('/v1/auth/me'),
      Api.get('/v1/auth/tokens'),
    ]);
  } catch (e) {
    renderLayout(null, 'account', [el('div', { class: 'alert alert-error' }, e.message)]);
    return;
  }

  // ── User info card ──
  const infoCard = el('div', { class: 'api-table-card' },
    el('div', { class: 'api-table-title' }, 'Your Account'),
    el('div', { style: 'margin-top:12px;display:flex;flex-direction:column;gap:8px' },
      el('div', { style: 'display:flex;gap:12px;font-size:0.85rem' },
        el('span', { style: 'color:var(--muted);width:80px;flex-shrink:0' }, 'Email'),
        el('span', {}, user.email)
      ),
      el('div', { style: 'display:flex;gap:12px;font-size:0.85rem' },
        el('span', { style: 'color:var(--muted);width:80px;flex-shrink:0' }, 'Role'),
        el('span', {}, user.role)
      ),
      el('div', { style: 'display:flex;gap:12px;font-size:0.85rem' },
        el('span', { style: 'color:var(--muted);width:80px;flex-shrink:0' }, 'Member since'),
        el('span', {}, new Date(user.created_at).toLocaleDateString())
      )
    )
  );

  // ── PAT card ──
  function copyCode(btn, text) {
    navigator.clipboard.writeText(text).then(() => {
      const orig = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(() => { btn.textContent = orig; }, 1500);
    });
  }

  let patList = [...pats];
  const listEl = el('div', { class: 'pat-list' });

  function renderPatList() {
    listEl.innerHTML = '';
    if (patList.length === 0) {
      listEl.appendChild(el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin:8px 0' }, 'No tokens yet.'));
      return;
    }
    patList.forEach(pat => {
      const revokeBtn = el('button', { class: 'btn btn-sm btn-danger' }, 'Revoke');
      revokeBtn.onclick = async () => {
        if (!confirm(`Revoke token "${pat.name}"?`)) return;
        try {
          await Api.delete(`/v1/auth/tokens/${pat.id}`);
          patList = patList.filter(p => p.id !== pat.id);
          renderPatList();
        } catch (e) { alert(e.message); }
      };
      const lastUsed = pat.last_used_at ? new Date(pat.last_used_at).toLocaleDateString() : 'Never';
      listEl.appendChild(
        el('div', { class: 'pat-row' },
          el('span', { class: 'pat-name' }, pat.name),
          el('span', { class: 'text-muted pat-meta' }, `Created ${new Date(pat.created_at).toLocaleDateString()} · Last used: ${lastUsed}`),
          revokeBtn
        )
      );
    });
  }
  renderPatList();

  const nameInput    = el('input', { type: 'text', class: 'form-control', placeholder: 'Token name (e.g. "CI deploy")…', style: 'flex:1' });
  const createBtn    = el('button', { class: 'btn btn-primary' }, '+ New Token');
  const tokenDisplay = el('div', { style: 'display:none;margin-top:12px' });

  createBtn.onclick = async () => {
    const name = nameInput.value.trim();
    if (!name) { nameInput.focus(); return; }
    try {
      createBtn.disabled = true;
      const res = await Api.post('/v1/auth/tokens', { name });
      nameInput.value = '';
      const tokenPre = el('pre', { class: 'api-code-block' }, res.token);
      const copyBtn2 = el('button', { class: 'copy-btn btn btn-sm' }, 'Copy');
      copyBtn2.onclick = () => copyCode(copyBtn2, res.token);
      tokenDisplay.style.display = '';
      tokenDisplay.innerHTML = '';
      tokenDisplay.appendChild(
        el('div', {},
          el('p', { style: 'font-size:0.8rem;color:#f59e0b;margin-bottom:6px' }, '⚠ Copy this token now — it will not be shown again.'),
          el('div', { style: 'position:relative' }, tokenPre, copyBtn2)
        )
      );
      const freshPats = await Api.get('/v1/auth/tokens');
      patList = freshPats;
      renderPatList();
    } catch (e) { alert(e.message); }
    finally { createBtn.disabled = false; }
  };

  const patCard = el('div', { class: 'api-table-card' },
    el('div', { class: 'api-table-title' }, 'Personal Access Tokens'),
    el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin:6px 0 14px' },
      'PATs authenticate as you across all projects — use them in scripts or CI/CD instead of your password. Token values are shown only once at creation.'
    ),
    listEl,
    el('div', { style: 'display:flex;gap:8px;margin-top:14px' }, nameInput, createBtn),
    tokenDisplay
  );

  // ── CLAUDE.md Builder card ──
  let projects = [];
  try { projects = await Api.get('/v1/projects'); } catch (_) {}

  const baseUrl   = window.location.origin;
  const previewEl = el('textarea', {
    readonly: 'readonly',
    style: 'width:100%;height:320px;font-family:monospace;font-size:0.75rem;background:#0a0d14;color:#e2e8f0;border:1px solid var(--border);border-radius:6px;padding:12px;resize:vertical;white-space:pre',
  });
  previewEl.value = generateClaudeMd(baseUrl, null, null);

  const projSelect = el('select', { class: 'form-control', style: 'max-width:320px' },
    el('option', { value: '' }, '— No project (generic template) —'),
    ...projects.map(p => el('option', { value: p.id }, `${p.name} (id: ${p.id})`))
  );

  const patInput = el('input', {
    type: 'text',
    class: 'form-control',
    placeholder: 'Paste your PAT here (sb_pat_…)',
    style: 'max-width:480px;font-family:monospace;font-size:0.82rem',
  });

  let currentProjId = null, currentServiceKey = null;

  function refreshPreview() {
    const pat = patInput.value.trim() || null;
    previewEl.value = generateClaudeMd(baseUrl, currentProjId, currentServiceKey, pat);
  }

  projSelect.addEventListener('change', async () => {
    const pid = projSelect.value;
    if (!pid) {
      currentProjId = null; currentServiceKey = null;
      refreshPreview();
      return;
    }
    try {
      const proj = await Api.get(`/v1/projects/${pid}`);
      currentProjId  = proj.id;
      currentServiceKey = proj.service_key;
      refreshPreview();
    } catch (e) { alert(e.message); }
  });

  patInput.addEventListener('input', refreshPreview);

  const copyMdBtn = el('button', { class: 'btn btn-secondary btn-sm' }, 'Copy');
  copyMdBtn.onclick = () => {
    navigator.clipboard.writeText(previewEl.value).then(() => {
      const orig = copyMdBtn.textContent;
      copyMdBtn.textContent = 'Copied!';
      setTimeout(() => { copyMdBtn.textContent = orig; }, 1500);
    });
  };

  const dlBtn = el('button', { class: 'btn btn-primary btn-sm' }, 'Download CLAUDE.md');
  dlBtn.onclick = () => downloadText('CLAUDE.md', previewEl.value);

  const builderCard = el('div', { class: 'api-table-card' },
    el('div', { class: 'api-table-title' }, 'CLAUDE.md Builder'),
    el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin:6px 0 14px' },
      'Generate a ready-to-use CLAUDE.md for any new project repo. Drop it at the repo root — Claude Code will read it automatically and know how to use SupaBein as the backend.'
    ),
    el('div', { style: 'margin-bottom:12px' },
      el('label', { class: 'label', style: 'margin-bottom:4px;display:block' }, 'Project (optional)'),
      projSelect
    ),
    el('div', { style: 'margin-bottom:12px' },
      el('label', { class: 'label', style: 'margin-bottom:4px;display:block' }, 'Personal Access Token — create one above, then paste it here'),
      patInput
    ),
    previewEl,
    el('div', { style: 'display:flex;gap:8px;margin-top:10px' }, copyMdBtn, dlBtn)
  );

  // ── Change password card ──
  const pwFields = {
    current: el('input', { type: 'password', class: 'form-control', placeholder: 'Current password' }),
    next:    el('input', { type: 'password', class: 'form-control', placeholder: 'New password (min 8 chars)' }),
    msg:     el('div', { style: 'font-size:0.82rem;min-height:18px' }),
  };
  const pwBtn = el('button', { class: 'btn btn-sm btn-primary' }, 'Change Password');
  pwBtn.addEventListener('click', async () => {
    pwFields.msg.textContent = '';
    pwFields.msg.style.color = '';
    pwBtn.disabled = true; pwBtn.textContent = 'Saving…';
    try {
      await Api.patch('/v1/auth/password', {
        current_password: pwFields.current.value,
        new_password: pwFields.next.value,
      });
      pwFields.current.value = ''; pwFields.next.value = '';
      pwFields.msg.textContent = 'Password changed successfully.';
      pwFields.msg.style.color = 'var(--success, #16a34a)';
    } catch (e) {
      pwFields.msg.textContent = e.message;
      pwFields.msg.style.color = 'var(--danger, #dc2626)';
    }
    pwBtn.disabled = false; pwBtn.textContent = 'Change Password';
  });

  const pwCard = el('div', { class: 'api-table-card' },
    el('div', { class: 'api-table-title' }, 'Change Password'),
    el('div', { style: 'display:flex;flex-direction:column;gap:10px;margin-top:12px' },
      el('div', { class: 'form-group', style: 'margin:0' }, pwFields.current),
      el('div', { class: 'form-group', style: 'margin:0' }, pwFields.next),
      el('div', { style: 'display:flex;align-items:center;gap:12px' }, pwBtn, pwFields.msg)
    )
  );

  makeCollapsible(pwCard);
  makeCollapsible(patCard);
  makeCollapsible(builderCard);

  renderLayout(null, 'account', [
    el('div', { class: 'page-header' },
      el('h1', { class: 'page-title' }, 'Account'),
      el('a', { href: '/docs', target: '_blank', rel: 'noopener', class: 'btn btn-sm' }, 'API Docs ↗')
    ),
    infoCard,
    pwCard,
    patCard,
    builderCard,
  ]);
}

// ─── Routes ──────────────────────────────────────────────────────────────────

Router.add('', () => {
  if (Auth.isLoggedIn()) Router.navigate('/projects');
  else Router.navigate('/login');
});

Router.add('login',  renderLogin);
Router.add('signup', renderSignup);
Router.add('forgot', renderForgot);
Router.add('reset',  renderReset);

Router.add('logout', () => {
  Auth.clear();
  Router.navigate('/login');
});

Router.add('projects',                              renderProjects);
Router.add('projects/:id',                          renderProject);
Router.add('projects/:id/tables',                   renderTables);
Router.add('projects/:id/tables/:name',             renderTableEditor);
Router.add('projects/:id/sites',                    renderSites);
Router.add('projects/:id/sites/:site_id',           renderSiteManager);
Router.add('projects/:id/api',                      renderApi);
Router.add('account',                               renderAccount);

// ─── Boot ─────────────────────────────────────────────────────────────────────

document.addEventListener('click', () => {
  document.querySelectorAll('.proj-menu-drop:not(.hidden)').forEach(d => d.classList.add('hidden'));
});

document.addEventListener('DOMContentLoaded', () => { Router.init(); initAiFab(); AiPanel.checkForActiveJobs(); });
