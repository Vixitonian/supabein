<?php

declare(strict_types=1);

// Registers the one thing that lets a project's own /forgot (see
// data_routes.php) actually deliver an email: which registered Integration
// to send it through, and how to fill in the subject/text. Reuses the
// Integrations capability entirely -- no new secret storage, this table
// only holds routing + a declarative template.
function register_auth_email_provider_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    $ownProject = function (int $urlProjectId, array $auth) use ($catalog): array {
        $project = $catalog->getProjectById($urlProjectId, (int)$auth['user_id']);
        if (!$project) {
            abort(404, 'Project not found');
        }
        $project['id'] = (int)$project['id'];
        return $project;
    };

    $validSpec = fn($spec): bool => is_array($spec) && (array_key_exists('literal', $spec) || array_key_exists('template', $spec) || array_key_exists('path', $spec));

    // POST /v1/projects/:id/auth-email-provider
    // { "integration": "resend", "forgot_password": { "path": "emails", "from": "no-reply@...",
    //   "subject": {"literal": "Reset your password"}, "text": {"template": "...{{token}}..."} } }
    $router->post('/v1/projects/:id/auth-email-provider', function (array $req) use ($catalog, $ownProject, $validSpec): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);

        $integrationName = trim((string)($req['body']['integration'] ?? ''));
        $fp   = $req['body']['forgot_password'] ?? null;
        if ($integrationName === '') {
            abort(422, 'integration is required (the name of a already-registered Integration)');
        }
        if (!$catalog->getIntegration($project['id'], $integrationName)) {
            abort(422, "No integration named \"$integrationName\" is registered on this project");
        }
        if (!is_array($fp)) {
            abort(422, 'forgot_password is required');
        }
        $path = trim((string)($fp['path'] ?? ''));
        $from = isset($fp['from']) ? (string)$fp['from'] : null;
        $subject = $fp['subject'] ?? null;
        $text    = $fp['text'] ?? null;
        if ($path === '') {
            abort(422, 'forgot_password.path is required (the Integration path to POST the email to)');
        }
        if (!$validSpec($subject) || !$validSpec($text)) {
            abort(422, 'forgot_password.subject and forgot_password.text must each be {"literal": ...} or {"template": "...{{token}}..."}');
        }

        $provider = $catalog->createAuthEmailProvider($project['id'], $integrationName, $path, $from, $subject, $text);
        json_out($provider, 201);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/auth-email-provider
    $router->get('/v1/projects/:id/auth-email-provider', function (array $req) use ($catalog, $ownProject): void {
        $project  = $ownProject((int)$req['params']['id'], $req['auth']);
        $provider = $catalog->getAuthEmailProvider($project['id']);
        if (!$provider) {
            abort(404, 'No auth email provider registered for this project');
        }
        json_out($provider);
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/auth-email-provider
    $router->delete('/v1/projects/:id/auth-email-provider', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);
        $catalog->deleteAuthEmailProvider($project['id']);
        json_out(['deleted' => true]);
    }, ['auth_middleware']);
}
