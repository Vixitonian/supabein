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
}
