<?php

declare(strict_types=1);

// Registers "Integrations": a project-scoped secret + a hard-locked base_url
// that SupaBein calls on the project's behalf, injecting the secret
// server-side per auth_style. The secret is write-only from every client's
// perspective -- no GET on this resource ever returns it, the same trust
// model the PASSWORD column type already uses. See
// Catalog::createIntegration()/callIntegration() for the registration-time
// and call-time SSRF guards.
function register_integration_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    // Registering/listing/deleting an integration is owner/PAT-only -- it's
    // what puts a real secret in the DB or removes one. Calling the proxy is
    // handled separately below since a project_user may be allowed to do
    // that without being allowed to manage the integration itself.
    $ownProject = function (int $urlProjectId, array $auth) use ($catalog): array {
        $project = $catalog->getProjectById($urlProjectId, (int)$auth['user_id']);
        if (!$project) {
            abort(404, 'Project not found');
        }
        $project['id'] = (int)$project['id'];
        return $project;
    };

    $validName = fn(string $name): bool => (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $name);

    // POST /v1/projects/:id/integrations
    // { "name": "paystack", "base_url": "https://api.paystack.co/", "secret": "sk_live_...",
    //   "auth_style": "bearer", "allowed_project_user_paths": ["bank/resolve"] }
    $router->post('/v1/projects/:id/integrations', function (array $req) use ($catalog, $ownProject, $validName): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);

        $name         = strtolower(trim((string)($req['body']['name'] ?? '')));
        $baseUrl      = trim((string)($req['body']['base_url'] ?? ''));
        $secret       = (string)($req['body']['secret'] ?? '');
        $authStyle    = trim((string)($req['body']['auth_style'] ?? ''));
        $allowedPaths = $req['body']['allowed_project_user_paths'] ?? null;

        if (!$validName($name)) {
            abort(422, 'name must be lowercase letters, numbers, "-", "_" (max 63 chars).');
        }
        if ($secret === '') {
            abort(422, 'secret is required');
        }
        if (!\SupaBein\Catalog::isValidAuthStyle($authStyle)) {
            abort(422, 'auth_style must be "bearer", "header:<Name>", or "query:<param>"');
        }
        if ($allowedPaths !== null && (!is_array($allowedPaths) || !array_is_list($allowedPaths))) {
            abort(422, 'allowed_project_user_paths must be a JSON array of strings, or omitted');
        }

        try {
            $integration = $catalog->createIntegration($project['id'], $name, $baseUrl, $secret, $authStyle, $allowedPaths);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        json_out($integration, 201);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/integrations -- never includes secret.
    $router->get('/v1/projects/:id/integrations', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);
        json_out($catalog->listIntegrations($project['id']));
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/integrations/:name
    $router->delete('/v1/projects/:id/integrations/:name', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);
        $catalog->deleteIntegration($project['id'], strtolower($req['params']['name']));
        json_out(['deleted' => true]);
    }, ['auth_middleware']);

    // POST /v1/projects/:id/integrations/:name/proxy
    // { "method": "POST", "path": "subaccount", "body": {...} }
    //
    // Callable by the project's own owner/PAT, or by an authenticated
    // project_user IF this integration's allowed_project_user_paths opts the
    // requested path in -- deny-by-default otherwise (see
    // Catalog::integrationPathAllowedForProjectUser()).
    $router->post('/v1/projects/:id/integrations/:name/proxy', function (array $req) use ($catalog): void {
        $urlProjectId  = (int)$req['params']['id'];
        $auth          = $req['auth'];
        $isProjectUser = ($auth['role'] ?? '') === 'project_user';

        if ($isProjectUser) {
            if ((int)($auth['project_id'] ?? 0) !== $urlProjectId) {
                abort(403, 'This token belongs to a different project.');
            }
            $project = $catalog->getProjectByIdInternal($urlProjectId);
        } else {
            $project = $catalog->getProjectById($urlProjectId, (int)$auth['user_id']);
        }
        if (!$project) {
            abort(404, 'Project not found');
        }
        $project['id'] = (int)$project['id'];

        $name        = strtolower($req['params']['name']);
        $integration = $catalog->getIntegration($project['id'], $name);
        if (!$integration) {
            abort(404, 'Integration not found');
        }

        $method = strtoupper((string)($req['body']['method'] ?? 'GET'));
        if (!in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            abort(422, 'method must be one of GET, POST, PATCH, PUT, DELETE');
        }
        $path = ltrim((string)($req['body']['path'] ?? ''), '/');
        if ($path === '') {
            abort(422, 'path is required');
        }
        $body = $req['body']['body'] ?? null;

        if ($isProjectUser && !\SupaBein\Catalog::integrationPathAllowedForProjectUser($integration['allowed_project_user_paths'], $path)) {
            abort(403, 'This integration does not allow end users to call this path.');
        }

        try {
            $result = $catalog->callIntegration($project['id'], $name, $method, $path, $body);
        } catch (\RuntimeException $e) {
            abort(502, $e->getMessage());
        }

        json_out($result);
    }, ['auth_middleware']);
}
