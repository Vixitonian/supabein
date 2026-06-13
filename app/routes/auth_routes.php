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

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'Invalid email address');
        }
        if (strlen($password) < 8) {
            abort(422, 'Password must be at least 8 characters');
        }

        $pdo = App::get('db');
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            abort(409, 'Email already registered');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        $stmt->execute([$email, $hash]);
        $userId = (int)$pdo->lastInsertId();

        json_out(['token' => issue_jwt($userId, $email, 'owner')], 201);
    });

    // POST /v1/auth/login
    $router->post('/v1/auth/login', function (array $req): void {
        $email    = trim($req['body']['email'] ?? '');
        $password = $req['body']['password'] ?? '';

        if (!$email || !$password) {
            abort(422, 'Email and password are required');
        }

        $pdo = App::get('db');
        $stmt = $pdo->prepare('SELECT id, password_hash, role FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            abort(401, 'Invalid credentials');
        }

        json_out(['token' => issue_jwt((int)$user['id'], $email, $user['role'])]);
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
