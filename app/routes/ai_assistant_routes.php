<?php

declare(strict_types=1);

// Registers "AI Assistants": a project-scoped, hosted AI chat capability.
// Unlike Integrations, this holds no per-project secret at all -- it reuses
// SupaBein's own AI provider keys (the exact same make_ai_client() the app-
// builder itself calls), metered against the *owning account's* ai_credits
// balance rather than a per-project budget. See Catalog::callAiAssistant()
// for the credit check + provider call + debit sequence.
function register_ai_assistant_routes(\SupaBein\Router $router): void
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

    $validName = fn(string $name): bool => (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $name);

    // POST /v1/projects/:id/ai-assistants
    // { "name": "support-bot", "system_prompt": "You are Lois Stores' support assistant...",
    //   "allow_project_user": true }
    $router->post('/v1/projects/:id/ai-assistants', function (array $req) use ($catalog, $ownProject, $validName): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);

        $name              = strtolower(trim((string)($req['body']['name'] ?? '')));
        $systemPrompt      = isset($req['body']['system_prompt']) ? (string)$req['body']['system_prompt'] : null;
        $allowProjectUser  = (bool)($req['body']['allow_project_user'] ?? false);

        if (!$validName($name)) {
            abort(422, 'name must be lowercase letters, numbers, "-", "_" (max 63 chars).');
        }

        $assistant = $catalog->createAiAssistant($project['id'], $name, $systemPrompt, $allowProjectUser);
        json_out($assistant, 201);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/ai-assistants
    $router->get('/v1/projects/:id/ai-assistants', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);
        json_out($catalog->listAiAssistants($project['id']));
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/ai-assistants/:name
    $router->delete('/v1/projects/:id/ai-assistants/:name', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);
        $catalog->deleteAiAssistant($project['id'], strtolower($req['params']['name']));
        json_out(['deleted' => true]);
    }, ['auth_middleware']);

    // POST /v1/projects/:id/ai-assistants/:name/chat
    // { "messages": [{"role": "user", "content": "Hi, what are your hours?"}] }
    //
    // Callable by the project's own owner/PAT, or by an authenticated
    // project_user IF this assistant's allow_project_user is set --
    // deny-by-default otherwise, same posture as Integrations' proxy.
    $router->post('/v1/projects/:id/ai-assistants/:name/chat', function (array $req) use ($catalog): void {
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
        $projectId = (int)$project['id'];

        $name      = strtolower($req['params']['name']);
        $assistant = $catalog->getAiAssistant($projectId, $name);
        if (!$assistant) {
            abort(404, 'AI assistant not found');
        }
        if ($isProjectUser && !$assistant['allow_project_user']) {
            abort(403, 'This assistant does not allow end users to call it.');
        }

        $messages = $req['body']['messages'] ?? null;
        if (!is_array($messages) || empty($messages)) {
            abort(422, 'messages is required — an array of {"role": "user"|"assistant", "content": "..."}');
        }

        try {
            $result = $catalog->callAiAssistant($projectId, $name, $messages);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INSUFFICIENT_CREDIT') {
                abort(402, 'This project\'s account is out of AI credit.');
            }
            abort(502, $e->getMessage());
        }

        json_out($result);
    }, ['auth_middleware']);
}
