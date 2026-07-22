<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Resolve any Bearer token to an auth array (or null for anon/invalid).
 * Handles: user JWTs, project anon_key (type:anon), project service_key
 * (type:service), and Personal Access Tokens (sb_pat_ prefix).
 */
function resolve_token(string $token): ?array
{
    // 1. Personal Access Token
    if (str_starts_with($token, 'sb_pat_')) {
        $hash = hash('sha256', $token);
        $pat  = \SupaBein\Catalog::getInstance()->findPatByHash($hash);
        if (!$pat) {
            return null;
        }
        return [
            'user_id'    => (int)$pat['user_id'],
            'role'       => 'owner',
            'email'      => '',
            // NULL = account-wide PAT (unchanged legacy behavior). Set = a
            // project-scoped PAT, confined by enforce_pat_project_scope()
            // below to the routes/project it was minted for.
            'project_id' => $pat['project_id'] ?? null,
        ];
    }

    // 2. JWT (user login, anon_key, or service_key)
    $config = App::get('config');
    try {
        $decoded = JWT::decode($token, new Key($config['JWT_SECRET'], $config['JWT_ALGO']));
    } catch (Throwable) {
        return null;
    }

    $type = $decoded->type ?? 'user';

    if ($type === 'service') {
        $pid = isset($decoded->pid) ? (int)$decoded->pid : null;
        if (!$pid) return null;
        // Verify against stored key so rotation invalidates old tokens immediately
        $project = \SupaBein\Catalog::getInstance()->getProjectByIdInternal($pid);
        if (!$project || ($project['service_key'] ?? '') !== $token) return null;
        return [
            'user_id'    => 0,
            'role'       => 'service_role',
            'email'      => '',
            'project_id' => $pid,
        ];
    }

    if ($type === 'project_user') {
        // JWT issued by the data /login endpoint — scoped to a project's own users table
        return [
            'user_id'    => (int)$decoded->sub,
            'role'       => 'project_user',
            'email'      => '',
            'project_id' => isset($decoded->pid) ? (int)$decoded->pid : null,
            // Which table this identity logged in through (e.g. "users") --
            // needed alongside user_id to identify a specific end-user for
            // per-registrant checks (see Catalog::registerHostname), since a
            // bare row id alone could collide across two different
            // auth-capable tables in the same project.
            'table'      => $decoded->table ?? null,
        ];
    }

    // Platform user JWT (dashboard login, PATs already handled above)
    return [
        'user_id'    => (int)$decoded->sub,
        'role'       => $decoded->role ?? 'owner',
        'email'      => $decoded->email ?? '',
        'project_id' => null,
    ];
}

// Control-plane routes a project-scoped PAT may call — deny-by-default so a
// route added later that forgets to consider PAT scoping fails closed (403)
// rather than silently granting a scoped token access it was never meant to
// have. The data-plane (/v1/data/...) needs no entry here: Crud::resolve()
// already enforces project_id match generically for every auth type that
// carries one. AI build/edit/plan/apply routes are deliberately excluded —
// they take project_id from the request body, not the URL, so a project-
// scoped PAT can't safely be matched against them yet; it 403s instead of
// silently ignoring the scope.
const PAT_PROJECT_SCOPED_ROUTES = [
    '/v1/projects/:id',
    '/v1/projects/:id/overview',
    '/v1/projects/:id/cleanup',
    '/v1/projects/:id/rotate-service-key',
    '/v1/projects/:id/seed/clear',
    '/v1/projects/:id/tables',
    '/v1/projects/:id/tables/:name',
    '/v1/projects/:id/tables/:name/columns',
    '/v1/projects/:id/tables/:name/columns/:col',
    '/v1/projects/:id/tables/:name/policies',
    '/v1/projects/:id/sites',
    '/v1/projects/:id/sites/:site_id',
    '/v1/projects/:project_id/sites/:site_id/deploys',
    '/v1/projects/:project_id/sites/:site_id/deploys/open',
    '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/files',
    '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/finalize',
    '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/publish',
    '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/rollback',
    '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/diff',
    '/v1/projects/:project_id/sites/:site_id/deploys/:deploy_id/download',
    '/v1/projects/:project_id/sites/:site_id/browse',
    '/v1/projects/:project_id/sites/:site_id/debug',
    '/v1/projects/:project_id/storage/:bucket',
    '/v1/projects/:project_id/storage/:bucket/:filename',
    '/v1/projects/:project_id/storage/:bucket/policy',
    '/v1/projects/:id/hostnames',
    '/v1/projects/:id/hostnames/:hostname',
    '/v1/projects/:id/integrations',
    '/v1/projects/:id/integrations/:name',
    '/v1/projects/:id/integrations/:name/proxy',
    '/v1/projects/:id/webhooks',
    '/v1/projects/:id/webhooks/:name',
    '/v1/projects/:id/auth-email-provider',
    '/v1/projects/:id/triggers',
    '/v1/projects/:id/triggers/:name',
    '/v1/projects/:id/meta-resolvers',
    '/v1/projects/:id/meta-resolvers/:name',
    '/v1/projects/:id/ai-assistants',
    '/v1/projects/:id/ai-assistants/:name',
    '/v1/projects/:id/ai-assistants/:name/chat',
    '/v1/projects/:id/tools/generate-icon',
    '/v1/projects/:id/errors',
    '/v1/projects/:id/errors/download',
    '/v1/projects/:id/requirements',
    '/v1/projects/:id/test-accounts',
    '/v1/projects/:id/test-status',
];

// Routes with no project-id URL param at all, safe for any scoped PAT to
// call unconditionally — they only ever describe the token/account calling
// them, never another project's data. This is how a scoped PAT discovers
// which project it belongs to (GET /v1/auth/me) without brute-forcing IDs.
const PAT_SELF_DESCRIBING_ROUTES = [
    '/v1/auth/me',
];

/**
 * Confines a project-scoped PAT ($auth['project_id'] !== null, role owner) to
 * the allowlisted routes above and to the one project it was minted for.
 * No-op for everything else (plain JWTs, account-wide PATs, service_role,
 * project_user) — those already carry their own, separate access rules.
 */
function enforce_pat_project_scope(array $req, array $auth): void
{
    if (($auth['role'] ?? '') !== 'owner' || ($auth['project_id'] ?? null) === null) {
        return;
    }

    $pattern = $req['route_pattern'] ?? '';
    if (in_array($pattern, PAT_SELF_DESCRIBING_ROUTES, true)) {
        return;
    }
    // The hint below is deliberately part of the error text, not just the
    // docs — an agent that never read docs.html still hits this 403 on its
    // first natural move (e.g. GET /v1/projects) and needs to learn its own
    // project id from the response it's already looking at.
    if (!in_array($pattern, PAT_PROJECT_SCOPED_ROUTES, true)) {
        abort(403, 'This token is scoped to one project and cannot be used for this endpoint. Call GET /v1/auth/me to see which project this token belongs to.');
    }

    $urlProjectId = $req['params']['id'] ?? $req['params']['project_id'] ?? null;
    if ($urlProjectId === null || (int)$urlProjectId !== (int)$auth['project_id']) {
        abort(403, 'This token is not valid for this project. Call GET /v1/auth/me to see which project this token belongs to.');
    }
}

/**
 * Strict auth middleware — aborts 401 if no valid user-level token.
 * Accepts user JWTs, PATs, and service_key. Rejects anon_key.
 * Performs sliding renewal: if a platform-user JWT expires within 15 minutes,
 * issues a fresh token and sends it back as X-Refresh-Token.
 */
function auth_middleware(array $req, callable $next): void
{
    $header = $req['headers']['Authorization'] ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        abort(401, 'Missing or malformed Authorization header');
    }

    $rawToken = $m[1];
    $auth = resolve_token($rawToken);
    if ($auth === null) {
        abort(401, 'Invalid or expired token');
    }

    enforce_pat_project_scope($req, $auth);

    // Sliding renewal for platform-user JWTs — renew on every request so the session
    // never expires while the user is active (no logout until explicit sign-out).
    if (
        !str_starts_with($rawToken, 'sb_pat_')
        && ($auth['role'] ?? '') === 'owner'
        && ($auth['project_id'] ?? null) === null
    ) {
        try {
            $config  = App::get('config');
            $decoded = \Firebase\JWT\JWT::decode($rawToken, new \Firebase\JWT\Key($config['JWT_SECRET'], $config['JWT_ALGO']));
            $now     = time();
            $payload = [
                'iss'   => 'supabein',
                'sub'   => (string)$decoded->sub,
                'email' => $decoded->email ?? '',
                'role'  => $decoded->role ?? 'owner',
                'iat'   => $now,
                'exp'   => $now + $config['JWT_TTL'],
            ];
            $fresh = \Firebase\JWT\JWT::encode($payload, $config['JWT_SECRET'], $config['JWT_ALGO']);
            header('X-Refresh-Token: ' . $fresh);
        } catch (\Throwable) {
            // resolve_token already validated it; ignore decode errors here
        }
    }

    $req['auth'] = $auth;
    $next($req);
}

/**
 * Gates /sb-admin's API (see admin_routes.php) to the platform operator
 * only. Deliberately stricter than auth_middleware: rejects a PAT outright
 * (a leaked automation token should never be able to grant AI credit or see
 * every user on the platform) and requires a fresh DB check of
 * `users.is_platform_admin` on every request rather than trusting a JWT
 * claim — that flag is only ever set directly against the DB (see its own
 * schema comment), so a stale cached claim in an old token could otherwise
 * outlive a revoked grant.
 */
function platform_admin_middleware(array $req, callable $next): void
{
    $header = $req['headers']['Authorization'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        abort(401, 'Missing or malformed Authorization header');
    }
    $rawToken = $m[1];
    if (str_starts_with($rawToken, 'sb_pat_')) {
        abort(403, 'Personal Access Tokens cannot access the admin API.');
    }

    $auth = resolve_token($rawToken);
    if ($auth === null || ($auth['role'] ?? '') !== 'owner' || ($auth['project_id'] ?? null) !== null) {
        abort(401, 'Invalid or expired token');
    }

    if (!\SupaBein\Catalog::getInstance()->isPlatformAdmin((int)$auth['user_id'])) {
        abort(403, 'This account is not a platform admin.');
    }

    $req['auth'] = $auth;
    $next($req);
}

/**
 * Optional auth middleware — populates $req['auth'] if a valid token is present.
 * Does NOT abort on missing/invalid token; anon_key sets auth to null (anon role).
 */
function optional_auth_middleware(array $req, callable $next): void
{
    $req['auth'] = null;

    $header = $req['headers']['Authorization'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        $req['auth'] = resolve_token($m[1]);
    }

    $next($req);
}
