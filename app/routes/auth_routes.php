<?php

declare(strict_types=1);

use Firebase\JWT\JWT;

require_once SUPABEIN_ROOT . '/app/middleware/auth.php';

function register_auth_routes(\SupaBein\Router $router): void
{
    // POST /v1/auth/signup
    $router->post('/v1/auth/signup', function (array $req): void {
        $email    = trim($req['body']['email'] ?? '');
        $password = $req['body']['password'] ?? '';

        sb_log('signup', 'Attempt', ['email' => $email ?: '(empty)']);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sb_log('signup', 'FAIL invalid email', ['email' => $email]);
            abort(422, 'Invalid email address');
        }
        if (strlen($password) < 8) {
            sb_log('signup', 'FAIL password too short', ['email' => $email, 'len' => strlen($password)]);
            abort(422, 'Password must be at least 8 characters');
        }

        sb_log('signup', 'Validation passed, checking DB', ['email' => $email]);

        try {
            $pdo = App::get('db');
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                sb_log('signup', 'FAIL email already registered', ['email' => $email]);
                abort(409, 'Email already registered');
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
            $stmt->execute([$email, $hash]);
            $userId = (int)$pdo->lastInsertId();

            sb_log('signup', 'OK user created', ['email' => $email, 'user_id' => $userId]);
            json_out(['token' => issue_jwt($userId, $email, 'owner')], 201);

        } catch (\PDOException $e) {
            sb_log('signup', 'DB ERROR ' . $e->getMessage(), ['email' => $email, 'code' => $e->getCode()]);
            abort(500, 'Database error during signup');
        }
    });

    // POST /v1/auth/login
    $router->post('/v1/auth/login', function (array $req): void {
        $email    = trim($req['body']['email'] ?? '');
        $password = $req['body']['password'] ?? '';

        sb_log('login', 'Attempt', ['email' => $email ?: '(empty)']);

        if (!$email || !$password) {
            sb_log('login', 'FAIL missing email or password');
            abort(422, 'Email and password are required');
        }

        try {
            $pdo = App::get('db');
            $stmt = $pdo->prepare('SELECT id, password_hash, role FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                sb_log('login', 'FAIL user not found', ['email' => $email]);
                abort(401, 'Invalid credentials');
            }
            if (!password_verify($password, $user['password_hash'])) {
                sb_log('login', 'FAIL wrong password', ['email' => $email]);
                abort(401, 'Invalid credentials');
            }

            sb_log('login', 'OK', ['email' => $email, 'user_id' => $user['id'], 'role' => $user['role']]);
            json_out(['token' => issue_jwt((int)$user['id'], $email, $user['role'])]);

        } catch (\PDOException $e) {
            sb_log('login', 'DB ERROR ' . $e->getMessage(), ['email' => $email, 'code' => $e->getCode()]);
            abort(500, 'Database error during login');
        }
    });

    // GET /v1/auth/me
    $router->get('/v1/auth/me', function (array $req): void {
        $pdo = App::get('db');
        $stmt = $pdo->prepare('SELECT id, email, role, created_at FROM users WHERE id = ?');
        $stmt->execute([$req['auth']['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            abort(404);
        }
        json_out($user);
    }, ['auth_middleware']);

    // ── Personal Access Tokens ───────────────────────────────────────────────

    // GET /v1/auth/tokens — list PATs (no token values returned)
    $router->get('/v1/auth/tokens', function (array $req): void {
        $catalog = \SupaBein\Catalog::getInstance();
        json_out($catalog->listPats($req['auth']['user_id']));
    }, ['auth_middleware']);

    // POST /v1/auth/tokens — create PAT, returns raw token once
    $router->post('/v1/auth/tokens', function (array $req): void {
        $name = trim($req['body']['name'] ?? '');
        if ($name === '') {
            abort(422, 'Token name is required');
        }
        $catalog = \SupaBein\Catalog::getInstance();
        $raw     = $catalog->createPat($req['auth']['user_id'], $name);
        json_out(['token' => $raw, 'name' => $name], 201);
    }, ['auth_middleware']);

    // DELETE /v1/auth/tokens/:id — revoke PAT
    $router->delete('/v1/auth/tokens/:id', function (array $req): void {
        $catalog = \SupaBein\Catalog::getInstance();
        $deleted = $catalog->deletePat($req['auth']['user_id'], (int)$req['params']['id']);
        if (!$deleted) {
            abort(404, 'Token not found');
        }
        json_out(['deleted' => true]);
    }, ['auth_middleware']);
}

function issue_jwt(int $userId, string $email, string $role): string
{
    $config = App::get('config');
    $now    = time();
    $payload = [
        'iss'   => 'supabein',
        'sub'   => (string)$userId,
        'email' => $email,
        'role'  => $role,
        'iat'   => $now,
        'exp'   => $now + $config['JWT_TTL'],
    ];
    return JWT::encode($payload, $config['JWT_SECRET'], $config['JWT_ALGO']);
}
