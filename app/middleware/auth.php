<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Strict auth middleware — aborts 401 if no valid token.
 */
function auth_middleware(array $req, callable $next): void
{
    $config = App::get('config');
    $header = $req['headers']['Authorization'] ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        abort(401, 'Missing or malformed Authorization header');
    }

    try {
        $decoded = JWT::decode($m[1], new Key($config['JWT_SECRET'], $config['JWT_ALGO']));
    } catch (Throwable $e) {
        abort(401, 'Invalid or expired token');
    }

    $req['auth'] = [
        'user_id' => (int)$decoded->sub,
        'role'    => $decoded->role,
        'email'   => $decoded->email,
    ];

    $next($req);
}

/**
 * Optional auth middleware — populates $req['auth'] only if a valid token is present.
 * Does NOT abort on missing/invalid token.
 */
function optional_auth_middleware(array $req, callable $next): void
{
    $config = App::get('config');
    $header = $req['headers']['Authorization'] ?? '';

    $req['auth'] = null;

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        try {
            $decoded = JWT::decode($m[1], new Key($config['JWT_SECRET'], $config['JWT_ALGO']));
            $req['auth'] = [
                'user_id' => (int)$decoded->sub,
                'role'    => $decoded->role,
                'email'   => $decoded->email,
            ];
        } catch (Throwable) {
            // Token present but invalid — treat as anon
        }
    }

    $next($req);
}
