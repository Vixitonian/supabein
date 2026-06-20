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
        return ['user_id' => (int)$pat['user_id'], 'role' => 'owner', 'email' => ''];
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

/**
 * Strict auth middleware — aborts 401 if no valid user-level token.
 * Accepts user JWTs, PATs, and service_key. Rejects anon_key.
 */
function auth_middleware(array $req, callable $next): void
{
    $header = $req['headers']['Authorization'] ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        abort(401, 'Missing or malformed Authorization header');
    }

    $auth = resolve_token($m[1]);
    if ($auth === null) {
        abort(401, 'Invalid or expired token');
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
