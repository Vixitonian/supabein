# CLAUDE.md — SupaBein Project

This project uses **SupaBein** as its backend (database, REST API, and site hosting).
Read this file before writing any backend code — all data and hosting goes through SupaBein.

---

## Configuration

```
SUPABEIN_URL=http://supabein.dxinnovationhub.com
SUPABEIN_TOKEN=sb_pat_YOUR_TOKEN_HERE
SUPABEIN_PROJECT_ID=YOUR_PROJECT_ID
SUPABEIN_ANON_KEY=YOUR_ANON_KEY
SUPABEIN_SITE_ID=YOUR_SITE_ID       # Fill in after creating a site (from Deploy tab)
```

> The PAT authenticates as the project owner and can create tables, set policies, and deploy.
> The anon key is safe to use in frontend code — it respects table policies.
> Never put the PAT or service key in frontend bundles.

---

## First-time Setup (run once)

### 1. Create the project
```bash
PROJECT=$(curl -s -X POST "$SUPABEIN_URL/api/v1/projects" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"my-app"}')

PROJECT_ID=$(echo $PROJECT | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")
ANON_KEY=$(echo $PROJECT | python3 -c "import sys,json; print(json.load(sys.stdin)['anon_key'])")

echo "Project ID: $PROJECT_ID"
echo "Anon key:   $ANON_KEY"
```

### 2. Create a site (for frontend hosting)
```bash
curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$PROJECT_ID/sites" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"subdomain":"my-app","spa_mode":true}'
```
Save the returned `id` as `SUPABEIN_SITE_ID`.

> **spa_mode** — when `true`, every URL path serves `index.html` so your client-side router
> (React Router, Vue Router, etc.) handles routing. Set `false` for plain static sites.

---

## Tables

### Create a table with columns
```bash
curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/tables" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "posts",
    "columns": [
      { "name": "title",      "type": "VARCHAR(255)", "nullable": false },
      { "name": "body",       "type": "TEXT",         "nullable": true  },
      { "name": "user_id",    "type": "INT",          "nullable": false },
      { "name": "published",  "type": "BOOLEAN",      "nullable": false },
      { "name": "created_at", "type": "DATETIME",     "nullable": false }
    ]
  }'
```

### Add a column to an existing table
```bash
curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/tables/posts/columns" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "views", "type": "INT", "nullable": true}'
```

### Delete a column
```bash
curl -s -X DELETE "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/tables/posts/columns/views" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
```

### List / delete tables
```bash
curl -s "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/tables" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"

curl -s -X DELETE "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/tables/posts" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
```

### Allowed column types
`INT` `BIGINT` `SMALLINT` `TINYINT` `VARCHAR(255)` `VARCHAR(128)` `VARCHAR(64)` `TEXT`
`MEDIUMTEXT` `BOOLEAN` `DECIMAL(10,2)` `FLOAT` `DOUBLE` `DATETIME` `DATE` `TIMESTAMP` `JSON`

---

## Row-Level Policies

Every table defaults to **deny all**. Always set policies before querying as anon or authenticated.

```bash
# Batch upsert — recommended, send an array
curl -s -X PUT "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/tables/posts/policies" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '[
    { "api_role": "anon",          "operation": "SELECT", "allowed": true  },
    { "api_role": "anon",          "operation": "INSERT", "allowed": false },
    { "api_role": "anon",          "operation": "UPDATE", "allowed": false },
    { "api_role": "anon",          "operation": "DELETE", "allowed": false },
    { "api_role": "authenticated", "operation": "SELECT", "allowed": true  },
    { "api_role": "authenticated", "operation": "INSERT", "allowed": true  },
    { "api_role": "authenticated", "operation": "UPDATE", "allowed": true  },
    { "api_role": "authenticated", "operation": "DELETE", "allowed": true  }
  ]'
```

### Row-level constraint (users can only edit their own rows)
```bash
curl -s -X PUT "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/tables/posts/policies" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '[
    { "api_role": "authenticated", "operation": "UPDATE", "allowed": true,
      "constraint_sql": "user_id = :current_user_id" },
    { "api_role": "authenticated", "operation": "DELETE", "allowed": true,
      "constraint_sql": "user_id = :current_user_id" }
  ]'
```

> `:current_user_id` is substituted with the JWT user's ID at query time.
> Roles: `anon` (no token) · `authenticated` (user JWT) · `service_role` (bypasses all policies)

### View current policies
```bash
curl -s "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/tables/posts/policies" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
```

---

## Data API

Use the **anon key** for frontend calls. Use the **PAT or service key** for trusted server calls.

> **ID types**: `id` fields in responses are numbers (integers), not strings.

### Query parameters for list endpoints

| Param | Effect |
|-------|--------|
| `?limit=N` | Max rows (1–1000, default 20) |
| `?offset=N` | Skip N rows for pagination |
| `?col=value` | Exact-match filter (shorthand for `eq`) |
| `?col=op.value` | Filter with operator: `eq` `neq` `gt` `gte` `lt` `lte` `like` |
| `?order=col.dir` | Sort: `?order=name.asc` or `?order=age.desc,name.asc` |

Filter examples: `?age=gte.18` `?name=like.Alice%25` `?status=neq.archived` `?published=true`

```bash
# List with filters + sort + pagination
curl -s "$SUPABEIN_URL/api/v1/data/$SUPABEIN_PROJECT_ID/posts?limit=20&offset=0&published=true&order=created_at.desc" \
  -H "Authorization: Bearer $SUPABEIN_ANON_KEY"

# Insert
curl -s -X POST "$SUPABEIN_URL/api/v1/data/$SUPABEIN_PROJECT_ID/posts" \
  -H "Authorization: Bearer $SUPABEIN_ANON_KEY" \
  -H "Content-Type: application/json" \
  -d '{"title":"Hello","body":"World","user_id":1,"published":false}'

# Get single row
curl -s "$SUPABEIN_URL/api/v1/data/$SUPABEIN_PROJECT_ID/posts/1" \
  -H "Authorization: Bearer $SUPABEIN_ANON_KEY"

# Update (partial)
curl -s -X PATCH "$SUPABEIN_URL/api/v1/data/$SUPABEIN_PROJECT_ID/posts/1" \
  -H "Authorization: Bearer $SUPABEIN_ANON_KEY" \
  -H "Content-Type: application/json" \
  -d '{"title":"Updated title","published":true}'

# Delete
curl -s -X DELETE "$SUPABEIN_URL/api/v1/data/$SUPABEIN_PROJECT_ID/posts/1" \
  -H "Authorization: Bearer $SUPABEIN_ANON_KEY"
```

---

## User Auth (project-scoped, for your app's end-users)

Each project has its own user table. Tokens are scoped to the project (`pid` claim)
and will be rejected by other projects' data endpoints.

```bash
# Sign up → returns { token: "eyJ..." }
curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/auth/signup" \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"secure-password"}'

# Log in → returns { token: "eyJ..." }
curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"secure-password"}'

# Get current user
curl -s "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/auth/me" \
  -H "Authorization: Bearer $USER_TOKEN"

# Refresh token (returns new token with fresh expiry)
curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/auth/refresh" \
  -H "Authorization: Bearer $USER_TOKEN"

# Password reset — you deliver the token to the user (email, SMS, etc.)
curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/auth/forgot" \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com"}'
# returns { token: "abc123...", expires_in: 3600 }

curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/auth/reset" \
  -H "Content-Type: application/json" \
  -d '{"token":"abc123...","password":"new-password"}'

# List / delete end-users (owner auth only)
curl -s "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/users" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"

curl -s -X DELETE "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/users/42" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
```

---

## File Storage

```bash
# Upload (multipart, field name: file)
curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/storage/avatars" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \
  -F "file=@photo.jpg"
# returns { name, bucket, size, url }

# List files in a bucket
curl -s "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/storage/avatars" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"

# Delete a file
curl -s -X DELETE "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/storage/avatars/photo.jpg" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
```

**Public URL** (use in `<img>` tags, no auth):
`$SUPABEIN_URL/api/v1/storage/$SUPABEIN_PROJECT_ID/avatars/photo.jpg`

> Bucket names: 1–63 chars, lowercase/numbers/hyphens/underscores.
> Max file size: 50 MB. Blocked extensions: `.php` `.py` `.sh` and other executables.

---

## Rate Limiting

The data API allows **600 requests per minute per project**. Excess requests return `429` with `Retry-After: 60`.
Build exponential backoff into any polling loop.

---

## Deploying the Frontend

Deploys follow a **two-step staging flow** — every upload lands in staging first.
Explicitly promote to live when ready.

### Step 1 — Upload to staging

> **Zip structure**: files must be at the **root** of the zip, not inside a subfolder.
> ✓ correct: `cd dist && zip -r ../deploy.zip .`
> ✗ wrong: `zip -r deploy.zip dist/` — creates a `dist/` subfolder and the site will 404.

```bash
cd dist && zip -r ../deploy.zip . && cd ..

curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \
  -F "zipfile=@./deploy.zip" \
  -F "label=v1.0.0"
# Returns deploy object with id — save it as DEPLOY_ID
```

Staging preview: `$SUPABEIN_URL/sites/s$SUPABEIN_SITE_ID/staging/`

### Step 2 — Publish to live

```bash
curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/$DEPLOY_ID/publish" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
```

Live site: `$SUPABEIN_URL/sites/s$SUPABEIN_SITE_ID/current/`

### Rollback to a previous deploy

```bash
curl -s -X POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/$DEPLOY_ID/rollback" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
```

### List deploy history

```bash
curl -s "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
```

### Option B — File-by-file deploy (CI/CD pipelines)

```bash
# Open a deploy
DID=$(curl -sX POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/open" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"label":"v1.0.0"}' | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")

# Upload files one by one
find dist -type f | while read f; do
  REL="${f#dist/}"
  curl -sX PUT "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/$DID/files?path=$REL" \
    -H "Authorization: Bearer $SUPABEIN_TOKEN" \
    --data-binary "@$f"
done

# Finalize (moves to staging)
curl -sX POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/$DID/finalize" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"

# Then publish when ready
curl -sX POST "$SUPABEIN_URL/api/v1/projects/$SUPABEIN_PROJECT_ID/sites/$SUPABEIN_SITE_ID/deploys/$DID/publish" \
  -H "Authorization: Bearer $SUPABEIN_TOKEN"
```

---

## JavaScript SDK Helpers

Copy these into your frontend. Replace the constants at the top.

```js
const SB_URL = 'http://supabein.dxinnovationhub.com/api/v1';
const SB_KEY = 'YOUR_ANON_KEY';   // safe to expose in frontend
const SB_PID = YOUR_PROJECT_ID;   // integer

// ── Auth ─────────────────────────────────────────────────────────────────────

const auth = {
  _token: localStorage.getItem('sb_token'),

  async signup(email, password) {
    const res = await sbFetch(`/projects/${SB_PID}/auth/signup`, {
      method: 'POST', body: JSON.stringify({ email, password })
    }, SB_KEY);
    this._token = res.token;
    localStorage.setItem('sb_token', res.token);
    return res;
  },

  async login(email, password) {
    const res = await sbFetch(`/projects/${SB_PID}/auth/login`, {
      method: 'POST', body: JSON.stringify({ email, password })
    }, SB_KEY);
    this._token = res.token;
    localStorage.setItem('sb_token', res.token);
    return res;
  },

  async me() {
    return sbFetch(`/projects/${SB_PID}/auth/me`, {}, this._token);
  },

  async refresh() {
    const res = await sbFetch(`/projects/${SB_PID}/auth/refresh`, {
      method: 'POST'
    }, this._token);
    this._token = res.token;
    localStorage.setItem('sb_token', res.token);
    return res;
  },

  logout() {
    this._token = null;
    localStorage.removeItem('sb_token');
  },

  token() { return this._token; },
  isLoggedIn() { return !!this._token; },
};

// ── Core fetch ────────────────────────────────────────────────────────────────

async function sbFetch(path, options = {}, token = SB_KEY) {
  const res = await fetch(`${SB_URL}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
      ...(options.headers || {}),
    },
  });
  if (res.status === 401) { auth.logout(); throw new Error('Unauthorized'); }
  if (res.status === 429) {
    const wait = parseInt(res.headers.get('Retry-After') || '60', 10);
    await new Promise(r => setTimeout(r, wait * 1000));
    return sbFetch(path, options, token); // retry once
  }
  if (!res.ok) {
    const err = await res.json().catch(() => ({ error: res.statusText }));
    throw new Error(err.error || res.statusText);
  }
  return res.json();
}

// ── Data CRUD ─────────────────────────────────────────────────────────────────

function sbToken() { return auth.token() || SB_KEY; }

async function sbList(table, params = {}) {
  const qs = new URLSearchParams(params).toString();
  return sbFetch(`/data/${SB_PID}/${table}${qs ? '?' + qs : ''}`, {}, sbToken());
}

async function sbGet(table, id) {
  return sbFetch(`/data/${SB_PID}/${table}/${id}`, {}, sbToken());
}

async function sbInsert(table, data) {
  return sbFetch(`/data/${SB_PID}/${table}`, {
    method: 'POST', body: JSON.stringify(data)
  }, sbToken());
}

async function sbUpdate(table, id, data) {
  return sbFetch(`/data/${SB_PID}/${table}/${id}`, {
    method: 'PATCH', body: JSON.stringify(data)
  }, sbToken());
}

async function sbDelete(table, id) {
  return sbFetch(`/data/${SB_PID}/${table}/${id}`, { method: 'DELETE' }, sbToken());
}

// Paginate through all rows automatically
async function sbListAll(table, params = {}, pageSize = 100) {
  let offset = 0, all = [];
  while (true) {
    const rows = await sbList(table, { ...params, limit: pageSize, offset });
    all = all.concat(rows);
    if (rows.length < pageSize) break;
    offset += pageSize;
  }
  return all;
}

// ── File storage ──────────────────────────────────────────────────────────────

async function sbUpload(bucket, file) {
  const form = new FormData();
  form.append('file', file);
  const res = await fetch(`${SB_URL}/projects/${SB_PID}/storage/${bucket}`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${sbToken()}` },
    body: form,
  });
  if (!res.ok) throw new Error(await res.text());
  return res.json(); // { name, bucket, size, url }
}

function sbPublicUrl(bucket, filename) {
  return `${SB_URL}/storage/${SB_PID}/${bucket}/${filename}`;
}
```

### Usage examples

```js
// Auth
await auth.signup('user@example.com', 'password');
await auth.login('user@example.com', 'password');
const me = await auth.me();

// Data
const posts = await sbList('posts', { published: true, order: 'created_at.desc', limit: 10 });
const post  = await sbGet('posts', 42);
const newPost = await sbInsert('posts', { title: 'Hello', body: 'World', user_id: me.id });
await sbUpdate('posts', 42, { title: 'Updated' });
await sbDelete('posts', 42);

// Paginate everything
const all = await sbListAll('posts', { published: true });

// File upload
const input = document.querySelector('input[type=file]');
const { url } = await sbUpload('avatars', input.files[0]);
```

---

## Error Handling Reference

| Status | Meaning | Action |
|--------|---------|--------|
| `400` | Bad request / validation error | Fix the request payload |
| `401` | Invalid or expired token | Re-login; refresh the token |
| `403` | Policy denied or wrong project | Check table policies |
| `404` | Row / resource not found | Check ID |
| `409` | Conflict (duplicate unique value) | Handle in UI |
| `422` | Unprocessable — missing required field | Fix the payload |
| `429` | Rate limit hit | Retry after `Retry-After` seconds |
| `500` | Server error | Check server logs |

---

## Rules for Claude

- Never use a separate database — all data goes through the SupaBein data API.
- Never hardcode project ID or tokens in source files — read from environment variables or config.
- Always create tables before inserting data.
- Always set policies on new tables — the default is deny all.
- Prefer the file-by-file deploy (Option B) for CI/CD; use zip upload for one-off deploys.
- The anon key is safe for frontend bundles. The PAT and service key must never be in frontend code.
- Do not invent API endpoints — the full reference is at http://supabein.dxinnovationhub.com/docs.
- **Two auth tiers**: use `/v1/projects/:id/auth/*` for your app's end-users (project-scoped);
  use `/v1/auth/*` only for SupaBein platform management (operators/CI), not for app users.
- Project-user JWTs are scoped to their project — do not share them across projects.
- **Staging deploy**: uploads land in staging, not live. Always call the `/publish` endpoint
  (or click "Publish to Live" in the dashboard) before assuming the site is updated.
- **Never run commands against the live server** (`supabein.dxinnovationhub.com`) unless
  the user explicitly instructs it in the current message. Always ask for confirmation first.
