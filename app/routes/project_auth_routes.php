<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once SUPABEIN_ROOT . '/app/middleware/auth.php';
require_once SUPABEIN_ROOT . '/app/routes/auth_routes.php';

/**
 * Project-scoped auth endpoints.
 *
 * These are for end-users of apps BUILT ON SupaBein, not for SupaBein platform operators.
 * Platform operators use /v1/auth/* (the global auth routes).
 *
 * User data lives in `project_users` (keyed by project_id + email), not in `users`.
 * Returned JWTs carry a `pid` claim binding the token to this project.
 */
function register_project_auth_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    // ── Helper: resolve project, abort 404 if not found ─────────────────────
    $resolveProject = function (int $projectId) use ($catalog): array {
        $project = $catalog->getProjectByIdInternal($projectId);
        if (!$project) {
            abort(404, 'Project not found');
        }
        return $project;
    };

    // POST /v1/projects/:pid/auth/signup
    $router->post('/v1/projects/:pid/auth/signup', function (array $req) use ($catalog, $resolveProject): void {
        $projectId = (int)$req['params']['pid'];
        $resolveProject($projectId);

        $email    = trim($req['body']['email'] ?? '');
        $password = $req['body']['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'Invalid email address');
        }
        if (strlen($password) < 8) {
            abort(422, 'Password must be at least 8 characters');
        }

        if ($catalog->findProjectUserByEmail($projectId, $email)) {
            abort(409, 'Email already registered in this project');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $user = $catalog->createProjectUser($projectId, $email, $hash);

        json_out(['token' => issue_project_jwt((int)$user['id'], $email, $projectId)], 201);
    });

    // POST /v1/projects/:pid/auth/login
    $router->post('/v1/projects/:pid/auth/login', function (array $req) use ($catalog, $resolveProject): void {
        $projectId = (int)$req['params']['pid'];
        $resolveProject($projectId);

        $email    = trim($req['body']['email'] ?? '');
        $password = $req['body']['password'] ?? '';

        if (!$email || !$password) {
            abort(422, 'Email and password are required');
        }

        $user = $catalog->findProjectUserByEmail($projectId, $email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            abort(401, 'Invalid credentials');
        }

        json_out(['token' => issue_project_jwt((int)$user['id'], $email, $projectId)]);
    });

    // GET /v1/projects/:pid/auth/me  [project-user auth required]
    $router->get('/v1/projects/:pid/auth/me', function (array $req) use ($catalog): void {
        $projectId = (int)$req['params']['pid'];
        $auth      = $req['auth'];

        // Must be a project-scoped token for this project
        if (!isset($auth['project_id']) || $auth['project_id'] !== $projectId) {
            abort(403, 'Token is not valid for this project');
        }

        $user = $catalog->getProjectUserById($projectId, (int)$auth['user_id']);
        if (!$user) {
            abort(404, 'User not found');
        }

        json_out($user);
    }, ['optional_auth_middleware']);

    // POST /v1/projects/:pid/auth/refresh  [project-user auth required]
    $router->post('/v1/projects/:pid/auth/refresh', function (array $req) use ($catalog, $resolveProject): void {
        $projectId = (int)$req['params']['pid'];
        $resolveProject($projectId);

        $auth = $req['auth'];

        if (!$auth || !isset($auth['project_id']) || $auth['project_id'] !== $projectId) {
            abort(401, 'Valid project-scoped token required');
        }

        $user = $catalog->getProjectUserById($projectId, (int)$auth['user_id']);
        if (!$user) {
            abort(404, 'User not found');
        }

        json_out(['token' => issue_project_jwt((int)$user['id'], $user['email'], $projectId)]);
    }, ['optional_auth_middleware']);

    // ── Password Reset ────────────────────────────────────────────────────────

    // POST /v1/projects/:pid/auth/forgot — generate a reset token
    $router->post('/v1/projects/:pid/auth/forgot', function (array $req) use ($catalog, $resolveProject): void {
        $projectId = (int)$req['params']['pid'];
        $resolveProject($projectId);

        $email = trim($req['body']['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'Invalid email address');
        }

        $user = $catalog->findProjectUserByEmail($projectId, $email);

        // Always return the same shape so enumeration isn't possible
        if (!$user) {
            json_out([
                'message'    => 'If that email is registered, a reset token has been generated.',
                'token'      => null,
                'expires_in' => 3600,
            ]);
            return;
        }

        $raw     = bin2hex(random_bytes(32));
        $hash    = hash('sha256', $raw);
        $expires = date('Y-m-d H:i:s', time() + 3600);

        $pdo = \App::get('db');
        // Invalidate any existing tokens for this user
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE project_user_id = ?')
            ->execute([$user['id']]);
        $pdo->prepare(
            'INSERT INTO password_reset_tokens (project_user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        )->execute([$user['id'], $hash, $expires]);

        json_out([
            'message'    => 'Reset token generated. Deliver this token to the user via your own email flow.',
            'token'      => $raw,
            'expires_in' => 3600,
        ]);
    });

    // POST /v1/projects/:pid/auth/reset — exchange token for a new password
    $router->post('/v1/projects/:pid/auth/reset', function (array $req) use ($catalog): void {
        $projectId = (int)$req['params']['pid'];
        $token     = $req['body']['token']    ?? '';
        $password  = $req['body']['password'] ?? '';

        if (!$token || !$password) {
            abort(422, 'token and password are required');
        }
        if (strlen($password) < 8) {
            abort(422, 'Password must be at least 8 characters');
        }

        $hash = hash('sha256', $token);
        $pdo  = \App::get('db');

        $stmt = $pdo->prepare(
            'SELECT t.id, t.project_user_id, u.email
             FROM password_reset_tokens t
             JOIN project_users u ON u.id = t.project_user_id
             WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()
               AND u.project_id = ?
             LIMIT 1'
        );
        $stmt->execute([$hash, $projectId]);
        $row = $stmt->fetch();

        if (!$row) {
            abort(401, 'Invalid or expired reset token');
        }

        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE project_users SET password_hash = ? WHERE id = ?')
            ->execute([$newHash, $row['project_user_id']]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')
            ->execute([$row['id']]);

        json_out([
            'message' => 'Password updated successfully.',
            'token'   => issue_project_jwt((int)$row['project_user_id'], $row['email'], $projectId),
        ]);
    });

    // ── Project User Management (owner only) ──────────────────────────────────

    // GET /v1/projects/:id/users — list all project users
    $router->get('/v1/projects/:id/users', function (array $req) use ($catalog): void {
        $projectId = (int)$req['params']['id'];

        $project = $catalog->getProjectById($projectId, (int)$req['auth']['user_id']);
        if (!$project) {
            abort(404, 'Project not found');
        }

        $pdo  = \App::get('db');
        $stmt = $pdo->prepare(
            'SELECT id, project_id, email, created_at FROM project_users
             WHERE project_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$projectId]);
        $users = array_map(function ($u) {
            $u['id']         = (int)$u['id'];
            $u['project_id'] = (int)$u['project_id'];
            return $u;
        }, $stmt->fetchAll());

        json_out(['users' => $users, 'count' => count($users)]);
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/users/:uid — delete a project user
    $router->delete('/v1/projects/:id/users/:uid', function (array $req) use ($catalog): void {
        $projectId = (int)$req['params']['id'];
        $userId    = (int)$req['params']['uid'];

        $project = $catalog->getProjectById($projectId, (int)$req['auth']['user_id']);
        if (!$project) {
            abort(404, 'Project not found');
        }

        $pdo  = \App::get('db');
        $stmt = $pdo->prepare('DELETE FROM project_users WHERE id = ? AND project_id = ?');
        $stmt->execute([$userId, $projectId]);

        if ($stmt->rowCount() === 0) {
            abort(404, 'User not found');
        }

        json_out(['deleted' => true]);
    }, ['auth_middleware']);
}
