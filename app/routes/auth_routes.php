<?php

declare(strict_types=1);

use Firebase\JWT\JWT;

require_once SUPABEIN_ROOT . '/app/middleware/auth.php';

// Sends one transactional email via Resend. Best-effort: failures are logged
// but never thrown — a broken mail provider must not turn into a 500 for the
// user (the "always return the same shape" enumeration guard already covers
// the caller-visible response either way).
function sb_send_email(array $config, string $to, string $subject, string $html): bool
{
    $apiKey = $config['RESEND_API_KEY'] ?? '';
    if (!$apiKey) { sb_log('mail', 'RESEND_API_KEY not configured — email not sent', ['to' => $to]); return false; }

    $from = $config['RESEND_FROM'] ?? 'SupaBein <onboarding@resend.dev>';
    $payload = json_encode(['from' => $from, 'to' => [$to], 'subject' => $subject, 'html' => $html], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 300) {
        sb_log('mail', 'Resend send failed', ['to' => $to, 'http' => $httpCode, 'error' => $curlErr ?: $response]);
        return false;
    }
    return true;
}

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

    // PATCH /v1/auth/password — change password while logged in
    $router->patch('/v1/auth/password', function (array $req): void {
        $current = $req['body']['current_password'] ?? '';
        $new     = $req['body']['new_password']     ?? '';

        if (!$current || !$new) {
            abort(422, 'current_password and new_password are required');
        }
        if (strlen($new) < 8) {
            abort(422, 'New password must be at least 8 characters');
        }

        $pdo  = App::get('db');
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = ?');
        $stmt->execute([$req['auth']['user_id']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current, $user['password_hash'])) {
            abort(401, 'Current password is incorrect');
        }

        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);

        json_out(['message' => 'Password changed successfully']);
    }, ['auth_middleware']);

    // POST /v1/auth/forgot — generate password-reset token for a platform operator
    $router->post('/v1/auth/forgot', function (array $req): void {
        $email = trim($req['body']['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'Invalid email address');
        }

        $pdo  = App::get('db');
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always return the same shape to prevent enumeration — never reveal
        // whether the email is registered, and never hand the token back in
        // the response (it now only ever reaches the user via their inbox).
        $genericResponse = ['message' => 'If that email is registered, a password reset link has been sent to it.'];
        if (!$user) {
            json_out($genericResponse);
            return;
        }

        $raw     = bin2hex(random_bytes(32));
        $hash    = hash('sha256', $raw);
        $expires = date('Y-m-d H:i:s', time() + 3600);

        $pdo->prepare('DELETE FROM user_reset_tokens WHERE user_id = ?')->execute([$user['id']]);
        $pdo->prepare('INSERT INTO user_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)')->execute([$user['id'], $hash, $expires]);

        $config   = App::get('config');
        $resetUrl = rtrim($config['API_BASE_URL'] ?? '', '/') . '/#/reset/' . $raw;
        sb_send_email($config, $email, 'Reset your SupaBein password', <<<HTML
            <p>Someone requested a password reset for your SupaBein account.</p>
            <p><a href="{$resetUrl}">Click here to reset your password</a> (expires in 1 hour).</p>
            <p>If you didn't request this, you can ignore this email.</p>
            HTML
        );

        json_out($genericResponse);
    });

    // POST /v1/auth/reset — exchange token for new password
    $router->post('/v1/auth/reset', function (array $req): void {
        $token    = $req['body']['token']    ?? '';
        $password = $req['body']['password'] ?? '';

        if (!$token || !$password) {
            abort(422, 'token and password are required');
        }
        if (strlen($password) < 8) {
            abort(422, 'Password must be at least 8 characters');
        }

        $hash = hash('sha256', $token);
        $pdo  = App::get('db');

        $stmt = $pdo->prepare(
            'SELECT t.id, t.user_id, u.email
             FROM user_reset_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        if (!$row) {
            abort(401, 'Invalid or expired reset token');
        }

        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $row['user_id']]);
        $pdo->prepare('UPDATE user_reset_tokens SET used_at = NOW() WHERE id = ?')
            ->execute([$row['id']]);

        json_out(['message' => 'Password updated successfully.', 'token' => issue_jwt((int)$row['user_id'], $row['email'], 'owner')]);
    });
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

/**
 * Issue a project-scoped JWT for an end-user of an app built on SupaBein.
 * The pid claim ties the token to a specific project so cross-project access is blocked.
 */
function issue_project_jwt(int $projectUserId, string $email, int $projectId): string
{
    $config = App::get('config');
    $now    = time();
    $payload = [
        'iss'   => 'supabein',
        'sub'   => (string)$projectUserId,
        'email' => $email,
        'role'  => 'authenticated',
        'type'  => 'project_user',
        'pid'   => $projectId,
        'iat'   => $now,
        'exp'   => $now + $config['JWT_TTL'],
    ];
    return JWT::encode($payload, $config['JWT_SECRET'], $config['JWT_ALGO']);
}
