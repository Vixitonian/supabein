# SupaBein

A self-hosted Backend-as-a-Service + static frontend host built on PHP and MySQL. Create projects, define tables through a dashboard, get an auto-generated policy-protected REST API, and deploy static frontends — all on ordinary cPanel shared hosting.

---

## Requirements

- cPanel shared hosting (or any Apache + PHP 8.0+ + MySQL/MariaDB server)
- PHP 8.0 or higher with extensions: `pdo_mysql`, `zip`, `fileinfo`
- MySQL 5.7+ or MariaDB 10.3+
- Composer (for installing PHP dependencies)
- Apache with `mod_rewrite` enabled and `AllowOverride All`

---

## Installation

### 1. Clone the repository

SSH into your server and clone into your home directory (or wherever your subdomain points):

```bash
git clone https://github.com/Vixitonian/supabein.git /home/youruser/supabein
cd /home/youruser/supabein
```

### 2. Install PHP dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Create the database

In cPanel → MySQL Databases (or via the MySQL CLI):

```sql
CREATE DATABASE supabein CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'supabein_user'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON supabein.* TO 'supabein_user'@'localhost';
FLUSH PRIVILEGES;
```

Then apply the control plane schema:

```bash
mysql -u supabein_user -p supabein < app/catalog/catalog_schema.sql
```

### 4. Configure secrets

Copy the config template and fill in your values:

```bash
cp config/secrets.php config/secrets.php
```

Edit `config/secrets.php`:

```php
return [
    'DB_DSN'  => 'mysql:host=localhost;dbname=supabein;charset=utf8mb4',
    'DB_USER' => 'supabein_user',
    'DB_PASS' => 'strong_password_here',

    'JWT_SECRET' => 'paste-a-long-random-string-here',  // generate with: openssl rand -hex 32
    'JWT_ALGO'   => 'HS256',
    'JWT_TTL'    => 3600,

    'STORAGE_PATH' => '/home/youruser/supabein/storage',
    'SITES_PATH'   => '/home/youruser/supabein/sites',

    'MAX_DEPLOY_BYTES' => 52428800,  // 50 MB
    'API_BASE_URL'     => 'https://supabein.yourdomain.com/api',
    'CORS_ORIGIN'      => '*',
];
```

Generate a secure JWT secret:

```bash
openssl rand -hex 32
```

### 5. Set directory permissions

```bash
chmod 750 storage sites
chmod 640 config/secrets.php
```

---

## Subdomain Setup (cPanel)

SupaBein is designed to run on a subdomain (e.g. `supabein.yourdomain.com`).

1. In cPanel → **Subdomains**, create a new subdomain (e.g. `supabein`).
2. Set the **Document Root** to the repository root: `/home/youruser/supabein`
   *(not the `public_html/` subdirectory — the root `.htaccess` handles routing into it)*
3. Wait for DNS to propagate, then visit `https://supabein.yourdomain.com`.

The root `.htaccess` automatically:
- Redirects `/` to `/dashboard/`
- Routes `/api/*` requests to the PHP front controller
- Serves `/sites/*` static frontend files directly
- Blocks access to `app/`, `config/`, `storage/`, `vendor/`

### URL map

| URL | What it serves |
|-----|---------------|
| `supabein.yourdomain.com/` | Redirects to dashboard |
| `supabein.yourdomain.com/dashboard/` | Dashboard SPA |
| `supabein.yourdomain.com/api/v1/...` | REST API |
| `supabein.yourdomain.com/sites/p{id}/current/` | Hosted static frontend |

---

## First Use

1. Open `https://supabein.yourdomain.com` in your browser.
2. Click **Sign up** and create your account.
3. Create a **Project**.
4. Inside the project, go to **Tables** → create a table and add columns.
5. Go to **Policies** on that table to control who can read/write rows.
6. Use the **Data** tab to insert and browse rows.
7. Go to **Sites** → create a site (pick a subdomain slug, enable SPA mode if needed).
8. Build your frontend locally, zip the output folder, and upload it via the deploy UI.

---

## REST API

All API endpoints live under `/api/v1/`. JSON in, JSON out.

### Authentication

```
POST /api/v1/auth/signup   { email, password }  →  { token }
POST /api/v1/auth/login    { email, password }  →  { token }
GET  /api/v1/auth/me                            →  { id, email, role }
```

Pass the token on every authenticated request:

```
Authorization: Bearer <token>
```

### Projects

```
GET    /api/v1/projects
POST   /api/v1/projects          { name }
GET    /api/v1/projects/:id
DELETE /api/v1/projects/:id
```

### Tables & Schema

```
GET    /api/v1/projects/:id/tables
POST   /api/v1/projects/:id/tables           { name }
DELETE /api/v1/projects/:id/tables/:name

POST   /api/v1/projects/:id/tables/:name/columns   { name, type, nullable }
DELETE /api/v1/projects/:id/tables/:name/columns/:col

GET    /api/v1/projects/:id/tables/:name/policies
PUT    /api/v1/projects/:id/tables/:name/policies  { api_role, operation, allowed, constraint_sql }
```

**Supported column types:** `INT`, `BIGINT`, `SMALLINT`, `TINYINT`, `VARCHAR(255)`, `VARCHAR(128)`, `VARCHAR(64)`, `TEXT`, `MEDIUMTEXT`, `BOOLEAN`, `DECIMAL(10,2)`, `FLOAT`, `DOUBLE`, `DATETIME`, `DATE`, `TIMESTAMP`, `JSON`

### Data (CRUD)

```
GET    /api/v1/data/:project_id/:table   ?limit=20&offset=0&col=value
POST   /api/v1/data/:project_id/:table   { col: value, ... }
GET    /api/v1/data/:project_id/:table/:id
PATCH  /api/v1/data/:project_id/:table/:id  { col: value }
DELETE /api/v1/data/:project_id/:table/:id
```

Access is controlled by policies. Unauthenticated requests use the `anon` role; authenticated requests use `authenticated`.

### Policies

A policy entry is `(api_role, operation) → allowed + optional constraint`:

| Field | Example |
|-------|---------|
| `api_role` | `anon`, `authenticated` |
| `operation` | `SELECT`, `INSERT`, `UPDATE`, `DELETE` |
| `allowed` | `true` / `false` |
| `constraint_sql` | `user_id = :current_user_id` |

`:current_user_id` is substituted with the authenticated user's ID at query time.

### Sites & Deploys

```
GET  /api/v1/projects/:id/sites
POST /api/v1/projects/:id/sites          { subdomain, spa_mode }

POST /api/v1/projects/:id/sites/:sid/deploys        (multipart/form-data, field: zipfile)
GET  /api/v1/projects/:id/sites/:sid/deploys
POST /api/v1/projects/:id/sites/:sid/deploys/:did/rollback
```

---

## Deploying a Frontend

1. Build your app locally (e.g. `npm run build` → `dist/` folder).
2. Zip the contents of the output folder (not the folder itself):
   ```bash
   cd dist && zip -r ../deploy.zip .
   ```
3. Upload `deploy.zip` via the dashboard **Sites → Deploy** page.

SupaBein validates the zip, extracts it, writes a hardening `.htaccess` that disables PHP execution, and atomically swaps the live symlink. Rollback to any previous deploy is instant.

To call your project's API from the deployed frontend, use:
```
https://supabein.yourdomain.com/api/v1/data/<project_id>/<table_name>
```

---

## Directory Structure

```
/
├── .htaccess                  ← subdomain entry point (routes into public_html/)
├── composer.json
├── config/
│   └── secrets.php            ← DB credentials + JWT secret (never web-served)
├── public_html/
│   ├── api/
│   │   ├── .htaccess          ← rewrites /api/* to index.php
│   │   └── index.php          ← PHP front controller
│   └── dashboard/             ← dashboard SPA (vanilla JS)
├── app/
│   ├── bootstrap.php
│   ├── router.php
│   ├── catalog/               ← control plane read/write layer
│   ├── core/                  ← schema engine, CRUD, policy, deploy
│   ├── middleware/            ← JWT auth middleware
│   └── routes/                ← route registration files
├── sites/                     ← hosted frontends (PHP disabled, Apache serves directly)
│   └── p{id}/
│       ├── deploys/
│       └── current → deploys/<timestamp>  (symlink)
└── storage/                   ← raw zip uploads (web-blocked)
```

---

## Security Notes

- **`config/secrets.php`** is above the web root and blocked by `.htaccess`. Never commit real credentials — use environment-specific copies.
- **Table/column names** are validated against a strict regex and SQL reserved-word blocklist before any DDL is generated.
- **Uploaded zips** are scanned for path-traversal entries and blocked executable extensions (`.php`, `.py`, `.sh`, etc.) before extraction. A hardening `.htaccess` is written into every deployed site to prevent PHP execution even if a file slips through.
- **All data queries** go through the policy layer — no direct database access from frontend code.
