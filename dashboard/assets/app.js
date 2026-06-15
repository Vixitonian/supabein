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
      const err = new ApiError(json.error || 'Request failed', res.status);
      console.error('[SupaBein] API error', { method, path, status: res.status, message: err.message });
      throw err;
    }

    return json;
  }

  class ApiError extends Error {
    constructor(msg, status) {
      super(msg);
      this.status = status;
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

function generateClaudeMd(baseUrl, projectId, anonKey, pat) {
  const pid  = projectId || 'YOUR_PROJECT_ID';
  const anon = anonKey   || 'YOUR_ANON_KEY';
  const token = pat      || 'sb_pat_xxxx          # create one above in Personal Access Tokens';
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
SUPABEIN_ANON_KEY=${anon}
SUPABEIN_SITE_ID=YOUR_SITE_ID       # Fill in after creating a site (from Deploy tab)
\`\`\`

> The PAT authenticates as the project owner and can create tables, set policies, and deploy.
> The anon key is safe to use in frontend code — it respects table policies.

---

## First-time Setup (run once)

### 1. Create the project
\`\`\`bash
PROJECT=$(curl -s -X POST "${siteUrl}/api/v1/projects" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{"name":"my-app"}')

PROJECT_ID=$(echo $PROJECT | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")
ANON_KEY=$(echo $PROJECT | python3 -c "import sys,json; print(json.load(sys.stdin)['anon_key'])")

echo "Project ID: $PROJECT_ID"
echo "Anon key:   $ANON_KEY"
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
\`INT\` \`BIGINT\` \`VARCHAR(255)\` \`TEXT\` \`BOOLEAN\` \`DATETIME\` \`DECIMAL(10,2)\` \`FLOAT\`

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

Roles: \`anon\` (no token), \`authenticated\` (valid user JWT), \`service_role\` (service key, bypasses all).
Operations: \`SELECT\` \`INSERT\` \`UPDATE\` \`DELETE\`

---

## Data API

Use the **anon key** for frontend calls. Use the **PAT or service key** for trusted server calls.

> **ID types**: \`id\` fields in responses are numbers (integers), not strings.
> Use \`===\` comparisons safely.

### Query parameters for list endpoints

| Param | Effect |
|-------|--------|
| \`?limit=N\` | Max rows to return (1–1000, default 20) |
| \`?offset=N\` | Skip N rows for pagination |
| \`?colname=value\` | Exact-match filter on any column (e.g. \`?status=active\`) |

Rows are always returned newest-first (\`id DESC\`). Custom ordering is not yet supported.

\`\`\`bash
# List rows (with filter + pagination)
curl -s "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/posts?limit=20&offset=0&status=active" \\
  -H "Authorization: Bearer $SUPABEIN_ANON_KEY"

# Insert
curl -s -X POST "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/posts" \\
  -H "Authorization: Bearer $SUPABEIN_ANON_KEY" \\
  -H "Content-Type: application/json" \\
  -d '{"title":"Hello","body":"World","user_id":1}'

# Get / Update / Delete
curl -s "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/posts/1" -H "Authorization: Bearer $SUPABEIN_ANON_KEY"
curl -s -X PATCH "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/posts/1" -H "Authorization: Bearer $SUPABEIN_ANON_KEY" -H "Content-Type: application/json" -d '{"title":"Updated"}'
curl -s -X DELETE "${siteUrl}/api/v1/data/$SUPABEIN_PROJECT_ID/posts/1" -H "Authorization: Bearer $SUPABEIN_ANON_KEY"
\`\`\`

---

## User Auth (project-scoped, for your app's end-users)

Each project has its own user table. Tokens are scoped to the project (pid claim)
and will be rejected by other projects' data endpoints.

\`\`\`bash
# Sign up  → returns { token: "eyJ..." }
curl -s -X POST "${siteUrl}/api/v1/projects/${pid}/auth/signup" \\
  -H "Content-Type: application/json" \\
  -d '{"email":"user@example.com","password":"secret123"}'

# Log in  → returns { token: "eyJ..." }
curl -s -X POST "${siteUrl}/api/v1/projects/${pid}/auth/login" \\
  -H "Content-Type: application/json" \\
  -d '{"email":"user@example.com","password":"secret123"}'

# Get current user
curl -s "${siteUrl}/api/v1/projects/${pid}/auth/me" \\
  -H "Authorization: Bearer $USER_TOKEN"

# Refresh token (returns new token with fresh expiry)
curl -s -X POST "${siteUrl}/api/v1/projects/${pid}/auth/refresh" \\
  -H "Authorization: Bearer $USER_TOKEN"
\`\`\`

---

## Deploying the Frontend

### Option A — Zip upload
> **Zip structure**: files must be at the **root** of the zip, not inside a subfolder.
> ✓ correct: \`cd dist && zip -r ../deploy.zip .\`
> ✗ wrong: \`zip -r deploy.zip dist/\` — creates a \`dist/\` subfolder inside the zip and the site will 404.

\`\`\`bash
cd dist && zip -r ../deploy.zip . && cd ..
curl -s -X POST "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN" -F "zipfile=@./deploy.zip" -F "label=v1.0.0"
\`\`\`

### Option B — File by file (CI/CD)
\`\`\`bash
DID=$(curl -sX POST "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/open" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN" -H "Content-Type: application/json" \\
  -d '{"label":"v1.0.0"}' | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")

find dist -type f | while read f; do
  REL="\${f#dist/}"
  curl -sX PUT "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/$DID/files?path=$REL" \\
    -H "Authorization: Bearer $SUPABEIN_TOKEN" --data-binary "@$f"
done

curl -sX POST "${siteUrl}/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/$DID/finalize" \\
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
\`\`\`

Live site: \`${siteUrl}/sites/s$SUPABEIN_SITE_ID/current/\`

---

## Using the anon key in frontend JS

\`\`\`js
const SB_URL = '${siteUrl}/api/v1';
const SB_KEY = '${anon}';
const SB_PID = ${pid};

async function sbQuery(table, params = {}) {
  const qs  = new URLSearchParams(params).toString();
  const res = await fetch(\`\${SB_URL}/data/\${SB_PID}/\${table}?\${qs}\`, {
    headers: { Authorization: \`Bearer \${SB_KEY}\` }
  });
  if (!res.ok) throw new Error(await res.text());
  return res.json();
}

async function sbInsert(table, data) {
  const res = await fetch(\`\${SB_URL}/data/\${SB_PID}/\${table}\`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: \`Bearer \${SB_KEY}\` },
    body: JSON.stringify(data)
  });
  if (!res.ok) throw new Error(await res.text());
  return res.json();
}
\`\`\`

---

## Rules for Claude

- Never use a separate database — all data goes through the SupaBein data API.
- Never hardcode project ID or tokens in source files — read from environment variables.
- Always create tables before inserting data.
- Always set policies on new tables — the default is deny all.
- Prefer the file-by-file deploy (Option B) for CI/CD; use zip upload for one-off deploys.
- The anon key is safe for frontend bundles. The PAT and service key must never be in frontend code.
- Do not invent API endpoints — the full reference is at ${siteUrl}/docs.
- **Two auth tiers**: use \`/v1/projects/:id/auth/*\` for your app's end-users (project-scoped);
  use \`/v1/auth/*\` only for SupaBein platform management (operators/CI), not for app users.
- Project-user JWTs are scoped to their project — do not share them across projects.
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
      el('a', { href: `#/projects/${projectId}/users`, class: 'sb-link' + isOn('users') },
        el('span', { class: 'sb-icon' }, '◎'),
        'Users'
      )
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
function renderLogin() {
  const form = h(`
    <div class="auth-wrap">
      <div class="auth-box card">
        <div class="auth-logo">SupaBein</div>
        <div class="auth-sub">Sign in to your account</div>
        <div class="form-group"><label>Email</label><input type="email" id="email" placeholder="you@example.com"></div>
        <div class="form-group"><label>Password</label><input type="password" id="password"></div>
        <button class="btn btn-primary w-full" id="submit">Sign In</button>
        <div class="mt-3 text-sm text-muted" style="text-align:center">
          No account? <a href="#/signup">Sign up</a>
        </div>
      </div>
    </div>
  `);

  form.querySelector('#submit').addEventListener('click', async () => {
    const email    = form.querySelector('#email').value;
    const password = form.querySelector('#password').value;
    try {
      const res = await Api.post('/v1/auth/login', { email, password });
      Auth.setToken(res.token);
      Router.navigate('/projects');
    } catch (e) {
      showAlert(form.querySelector('.auth-box'), e.message);
    }
  });

  setApp(form);
}

// Signup
function renderSignup() {
  const form = h(`
    <div class="auth-wrap">
      <div class="auth-box card">
        <div class="auth-logo">SupaBein</div>
        <div class="auth-sub">Create your account</div>
        <div class="form-group"><label>Email</label><input type="email" id="email" placeholder="you@example.com"></div>
        <div class="form-group"><label>Password</label><input type="password" id="password" placeholder="At least 8 characters"></div>
        <button class="btn btn-primary w-full" id="submit">Create Account</button>
        <div class="mt-3 text-sm text-muted" style="text-align:center">
          Have an account? <a href="#/login">Sign in</a>
        </div>
      </div>
    </div>
  `);

  form.querySelector('#submit').addEventListener('click', async () => {
    const email    = form.querySelector('#email').value;
    const password = form.querySelector('#password').value;

    console.log('[SupaBein] Signup button clicked', { email, passwordLen: password.length });
    console.log('[SupaBein] POST URL:', Api.BASE + '/v1/auth/signup');

    try {
      console.log('[SupaBein] Sending signup request...');
      const res = await Api.post('/v1/auth/signup', { email, password });
      console.log('[SupaBein] Signup success', res);
      Auth.setToken(res.token);
      Router.navigate('/projects');
    } catch (e) {
      console.error('[SupaBein] Signup failed', { status: e.status, message: e.message });
      showAlert(form.querySelector('.auth-box'), e.message);
    }
  });

  setApp(form);
}

// Projects list
async function renderProjects() {
  if (!requireAuth()) return;

  renderLayout(null, '', [el('div', { class: 'page-header' },
    el('h1', { class: 'page-title' }, 'Projects'),
    el('button', { class: 'btn btn-primary', id: 'new-project' }, '+ New Project')
  ), el('div', { id: 'project-list' }, 'Loading...')]);

  document.getElementById('new-project').addEventListener('click', () => showNewProjectModal());

  try {
    const projects = await Api.get('/v1/projects');
    const list = document.getElementById('project-list');

    if (!projects.length) {
      list.innerHTML = '<div class="text-muted">No projects yet. Create one to get started.</div>';
      return;
    }

    const table = el('table', { class: 'data-table' },
      el('thead', {}, el('tr', {},
        el('th', {}, 'Name'), el('th', {}, 'Created'), el('th', {}, '')
      )),
      el('tbody', {}, ...projects.map(p =>
        el('tr', {},
          el('td', {}, el('a', { href: `#/projects/${p.id}` }, p.name)),
          el('td', { class: 'text-muted text-sm' }, fmtDate(p.created_at)),
          el('td', {},
            el('button', {
              class: 'btn btn-sm btn-danger',
              onClick: async () => {
                if (!confirm(`Delete project "${p.name}"? This cannot be undone.`)) return;
                await Api.delete(`/v1/projects/${p.id}`);
                renderProjects();
              }
            }, 'Delete')
          )
        )
      ))
    );

    list.innerHTML = '';
    list.appendChild(table);
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

  try {
    const project = await Api.get(`/v1/projects/${id}`);
    renderLayout(id, '', [
      el('div', { class: 'page-header' },
        el('h1', { class: 'page-title' }, project.name)
      ),
      el('div', { class: 'flex gap-2' },
        el('a', { class: 'btn btn-primary', href: `#/projects/${id}/tables` }, 'Tables'),
        el('a', { class: 'btn btn-secondary', href: `#/projects/${id}/sites` }, 'Deploy')
      ),
      el('div', { class: 'card mt-3' },
        el('div', { class: 'card-title' }, 'Project Info'),
        el('div', { class: 'text-sm text-muted' }, `ID: ${project.id}`),
        el('div', { class: 'text-sm text-muted mt-1' }, `Created: ${fmtDate(project.created_at)}`)
      )
    ], { projectName: project.name });
  } catch (e) {
    setApp(`<div class="alert alert-error">${e.message}</div>`);
  }
}

// Tables list
async function renderTables({ id }) {
  if (!requireAuth()) return;

  renderLayout(id, 'tables', [
    el('div', { class: 'page-header' },
      el('h1', { class: 'page-title' }, 'Tables'),
      el('button', { class: 'btn btn-primary', id: 'new-table' }, '+ New Table')
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
      }, '+ Insert Row')
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
          <div class="form-group" style="width:180px"><label>API Role</label><input type="text" id="p-role" placeholder="anon / authenticated"></div>
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

    const header = document.querySelector('.page-header');
    const existingViewBtn = header && header.querySelector('#view-site-btn');
    if (existingViewBtn) existingViewBtn.remove();
    if (header && site.current_deploy_id) {
      header.appendChild(
        el('a', { id: 'view-site-btn', class: 'btn btn-primary btn-sm', href: `/sites/s${siteId}/current/`, target: '_blank', rel: 'noopener' }, 'View Site →')
      );
    }

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
        <button class="btn btn-primary" id="deploy-btn">Deploy</button>
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

        status.textContent = 'Deployed!';
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
            const labelEl = el('span', {}, d.version_label || '—');
            if (isCurrent) {
              const chip = el('span', { class: 'badge badge-green', style: 'margin-left:6px;font-size:0.7rem' }, 'live');
              labelEl.appendChild(chip);
            } else if (d.status === 'pending') {
              const chip = el('span', { class: 'badge badge-yellow', style: 'margin-left:6px;font-size:0.7rem' }, 'pending');
              labelEl.appendChild(chip);
            }
            const actions = el('td', { style: 'white-space:nowrap' });
            if (!isCurrent && d.status === 'ready') {
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

// ─── Project Users ───────────────────────────────────────────────────────────

async function renderProjectUsers({ id }) {
  if (!requireAuth()) return;
  renderLayout(id, 'users', [el('p', { class: 'text-muted' }, 'Loading users…')]);

  let users = [];
  try {
    const result = await Api.get(`/v1/projects/${id}/users`);
    users = result.users || [];
  } catch (e) {
    renderLayout(id, 'users', [el('div', { class: 'alert alert-danger' }, e.message)]);
    return;
  }

  async function deleteUser(uid) {
    if (!confirm('Delete this user? This cannot be undone.')) return;
    try {
      await Api.delete(`/v1/projects/${id}/users/${uid}`);
      renderProjectUsers({ id });
    } catch (e) {
      alert('Failed to delete user: ' + e.message);
    }
  }

  const rows = users.length === 0
    ? [el('tr', {}, el('td', { colspan: '3', style: 'text-align:center;color:var(--text-muted);padding:32px' }, 'No end-users yet. Users appear here after they sign up via your app.'))]
    : users.map(u =>
        el('tr', {},
          el('td', {}, u.email),
          el('td', {}, u.id),
          el('td', {}, u.created_at),
          el('td', {},
            el('button', { class: 'btn btn-sm btn-danger', onclick: () => deleteUser(u.id) }, 'Delete')
          )
        )
      );

  const content = [
    el('div', { class: 'page-header' },
      el('div', {},
        el('h2', {}, 'Project Users'),
        el('p', { class: 'text-muted' }, `${users.length} end-user${users.length !== 1 ? 's' : ''} registered in this project`)
      )
    ),
    el('div', { class: 'card' },
      el('div', { class: 'table-responsive' },
        el('table', { class: 'table' },
          el('thead', {},
            el('tr', {},
              el('th', {}, 'Email'),
              el('th', {}, 'ID'),
              el('th', {}, 'Joined'),
              el('th', {}, '')
            )
          ),
          el('tbody', {}, ...rows)
        )
      )
    ),
    el('div', { class: 'card' },
      el('h3', { style: 'margin-top:0;font-size:0.95rem' }, 'Project User Auth API'),
      el('p', { class: 'text-muted', style: 'font-size:0.85rem;margin-bottom:0' },
        'Your app\'s end-users sign up and log in via the project auth endpoints. See the ',
        el('a', { href: '#/projects/' + id + '/api' }, 'API tab'),
        ' for curl examples.'
      )
    )
  ];

  renderLayout(id, 'users', content);
}

// ─── API Reference ────────────────────────────────────────────────────────────

async function renderApi({ id }) {
  if (!requireAuth()) return;
  renderLayout(id, 'api', [el('p', { class: 'text-muted' }, 'Loading API reference…')]);

  const baseUrl = window.location.origin + '/api/v1';
  const projectId = parseInt(id);

  let tables = [], project = null;
  try {
    [project, tables] = await Promise.all([
      Api.get(`/v1/projects/${id}`),
      Api.get(`/v1/projects/${id}/tables`),
    ]);
  } catch (e) {
    renderLayout(id, 'api', [el('div', { class: 'alert alert-error' }, e.message)]);
    return;
  }

  const anonKey    = project?.anon_key    ?? 'NOT_AVAILABLE';
  const serviceKey = project?.service_key ?? 'NOT_AVAILABLE';

  function copyCode(btn, text) {
    navigator.clipboard.writeText(text).then(() => {
      const orig = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(() => { btn.textContent = orig; }, 1500);
    });
  }

  function codeBlock(code) {
    const pre = el('pre', { class: 'api-code-block' }, code);
    const btn = el('button', { class: 'copy-btn btn btn-sm' }, 'Copy');
    btn.onclick = () => copyCode(btn, code);
    const wrap = el('div', { style: 'position:relative' }, pre, btn);
    return wrap;
  }

  function exampleTabs(curlCode, jsCode) {
    let active = 'curl';
    const curlBlock = codeBlock(curlCode);
    const jsBlock   = codeBlock(jsCode);
    jsBlock.style.display = 'none';

    const curlBtn = el('button', { class: 'api-tab-btn active' }, 'curl');
    const jsBtn   = el('button', { class: 'api-tab-btn' }, 'JavaScript');

    curlBtn.onclick = () => {
      if (active === 'curl') return;
      active = 'curl';
      curlBlock.style.display = ''; jsBlock.style.display = 'none';
      curlBtn.classList.add('active'); jsBtn.classList.remove('active');
    };
    jsBtn.onclick = () => {
      if (active === 'js') return;
      active = 'js';
      curlBlock.style.display = 'none'; jsBlock.style.display = '';
      jsBtn.classList.add('active'); curlBtn.classList.remove('active');
    };

    return el('div', {},
      el('div', { class: 'api-tab-row' }, curlBtn, jsBtn),
      curlBlock, jsBlock
    );
  }

  function methodBadge(method) {
    return el('span', { class: `method-badge method-${method.toLowerCase()}` }, method);
  }

  function endpointRow(method, path, desc, curlCode, jsCode) {
    const details = el('div', { class: 'api-endpoint-detail' }, exampleTabs(curlCode, jsCode));
    details.style.display = 'none';
    let open = false;
    const row = el('div', { class: 'endpoint-row' },
      methodBadge(method),
      el('span', { class: 'endpoint-path' }, path),
      el('span', { class: 'endpoint-desc' }, desc),
      el('button', { class: 'btn btn-sm', style: 'margin-left:auto;flex-shrink:0' }, '▾')
    );
    row.querySelector('button').onclick = () => {
      open = !open;
      details.style.display = open ? '' : 'none';
      row.querySelector('button').textContent = open ? '▴' : '▾';
    };
    return el('div', {}, row, details);
  }

  function tableCard(table) {
    const tname = table.table_name;
    const pname = table.physical_name;
    const listPath   = `/v1/data/${projectId}/${tname}`;
    const singlePath = `/v1/data/${projectId}/${tname}/:id`;

    const curlList = `curl -X GET "${baseUrl}/data/${projectId}/${tname}?limit=50" \\
  -H "Authorization: Bearer ${anonKey}"`;
    const jsList = `const res = await fetch('${baseUrl}/data/${projectId}/${tname}?limit=50', {
  headers: { 'Authorization': 'Bearer ${anonKey}' }
});
const rows = await res.json();`;

    const curlCreate = `curl -X POST "${baseUrl}/data/${projectId}/${tname}" \\
  -H "Authorization: Bearer ${anonKey}" \\
  -H "Content-Type: application/json" \\
  -d '{"column": "value"}'`;
    const jsCreate = `const res = await fetch('${baseUrl}/data/${projectId}/${tname}', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ${anonKey}',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({ column: 'value' })
});
const row = await res.json();`;

    const curlGet = `curl -X GET "${baseUrl}/data/${projectId}/${tname}/1" \\
  -H "Authorization: Bearer ${anonKey}"`;
    const jsGet = `const res = await fetch('${baseUrl}/data/${projectId}/${tname}/1', {
  headers: { 'Authorization': 'Bearer ${anonKey}' }
});
const row = await res.json();`;

    const curlPatch = `curl -X PATCH "${baseUrl}/data/${projectId}/${tname}/1" \\
  -H "Authorization: Bearer ${anonKey}" \\
  -H "Content-Type: application/json" \\
  -d '{"column": "new_value"}'`;
    const jsPatch = `const res = await fetch('${baseUrl}/data/${projectId}/${tname}/1', {
  method: 'PATCH',
  headers: {
    'Authorization': 'Bearer ${anonKey}',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({ column: 'new_value' })
});
const row = await res.json();`;

    const curlDelete = `curl -X DELETE "${baseUrl}/data/${projectId}/${tname}/1" \\
  -H "Authorization: Bearer ${anonKey}"`;
    const jsDelete = `const res = await fetch('${baseUrl}/data/${projectId}/${tname}/1', {
  method: 'DELETE',
  headers: { 'Authorization': 'Bearer ${anonKey}' }
});`;

    return el('div', { class: 'api-table-card' },
      el('div', { class: 'api-table-title' }, tname),
      el('div', { class: 'api-table-physical' }, pname),
      el('div', { style: 'margin-top:12px' },
        endpointRow('GET',    listPath,   'List rows',   curlList,   jsList),
        endpointRow('POST',   listPath,   'Insert row',  curlCreate, jsCreate),
        endpointRow('GET',    singlePath, 'Get row',     curlGet,    jsGet),
        endpointRow('PATCH',  singlePath, 'Update row',  curlPatch,  jsPatch),
        endpointRow('DELETE', singlePath, 'Delete row',  curlDelete, jsDelete),
      )
    );
  }

  // Project Keys card
  function maskedKey(val) {
    return val.length > 20 ? val.slice(0, 10) + '••••••••••••' + val.slice(-6) : val;
  }

  function keyRow(label, val, warning) {
    let shown = false;
    const display = el('code', { class: 'api-key-value' }, maskedKey(val));
    const showBtn = el('button', { class: 'btn btn-sm api-tab-btn' }, 'Show');
    showBtn.onclick = () => {
      shown = !shown;
      display.textContent = shown ? val : maskedKey(val);
      showBtn.textContent = shown ? 'Hide' : 'Show';
    };
    const copyBtn = el('button', { class: 'btn btn-sm' }, 'Copy');
    copyBtn.onclick = () => copyCode(copyBtn, val);
    const row = el('div', { class: 'api-key-row' },
      el('span', { class: 'api-key-label' }, label),
      display,
      el('div', { class: 'api-key-actions' }, showBtn, copyBtn)
    );
    if (warning) {
      return el('div', {},
        row,
        el('p', { class: 'text-muted', style: 'font-size:0.75rem;margin:2px 0 8px 0' }, warning)
      );
    }
    return row;
  }

  const keysCard = el('div', { class: 'api-table-card' },
    el('div', { class: 'api-table-title' }, 'Project Keys'),
    el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin:6px 0 14px' },
      'Use the ', el('strong', {}, 'anon key'), ' in your frontend — requests are subject to your table policies. Use the ',
      el('strong', {}, 'service key'), ' from a trusted server only — it bypasses all policies.'
    ),
    keyRow('anon key', anonKey, null),
    keyRow('service key', serviceKey, '⚠ Never expose this key in client-side code.')
  );

  // Auth section
  const emptyState = tables.length === 0
    ? el('div', { class: 'api-table-card', style: 'text-align:center;padding:40px' },
        el('p', { class: 'text-muted' }, 'No tables yet — create one to see your auto-generated API endpoints.'),
        el('a', { href: `#/projects/${id}/tables`, class: 'btn btn-primary', style: 'display:inline-block;margin-top:12px' }, 'Create a Table')
      )
    : null;

  const content = [
    el('div', { class: 'page-header' },
      el('div', {},
        el('h1', { class: 'page-title' }, 'API Reference'),
        el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin-top:2px' }, 'Project keys and auto-generated endpoints')
      ),
      el('a', { href: '/docs', target: '_blank', rel: 'noopener', class: 'btn btn-sm' }, 'Full Docs ↗')
    ),
    keysCard,
    ...(emptyState ? [emptyState] : tables.map(tableCard)),
  ];

  renderLayout(id, 'api', content);
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

  let currentProjId = null, currentAnonKey = null;

  function refreshPreview() {
    const pat = patInput.value.trim() || null;
    previewEl.value = generateClaudeMd(baseUrl, currentProjId, currentAnonKey, pat);
  }

  projSelect.addEventListener('change', async () => {
    const pid = projSelect.value;
    if (!pid) {
      currentProjId = null; currentAnonKey = null;
      refreshPreview();
      return;
    }
    try {
      const proj = await Api.get(`/v1/projects/${pid}`);
      currentProjId  = proj.id;
      currentAnonKey = proj.anon_key;
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

  renderLayout(null, 'account', [
    el('div', { class: 'page-header' },
      el('h1', { class: 'page-title' }, 'Account'),
      el('a', { href: '/docs', target: '_blank', rel: 'noopener', class: 'btn btn-sm' }, 'API Docs ↗')
    ),
    infoCard,
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
Router.add('projects/:id/users',                     renderProjectUsers);
Router.add('projects/:id/api',                      renderApi);
Router.add('account',                               renderAccount);

// ─── Boot ─────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => Router.init());
