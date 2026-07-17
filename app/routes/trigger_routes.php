<?php

declare(strict_types=1);

// Registers outbound Triggers: "when a row is inserted into this table, call
// this Integration with this templated request" -- the reverse of Signed
// Webhooks, reusing the exact same Integration (secret + proxy) machinery.
// Firing happens from app/core/crud.php's handleInsert()/handleBatchInsert()
// via Catalog::fireTriggers(), not from a route here.
function register_trigger_routes(\SupaBein\Router $router): void
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
    $validSpec = fn($spec): bool => is_array($spec) && (array_key_exists('literal', $spec) || array_key_exists('template', $spec) || array_key_exists('path', $spec));

    // POST /v1/projects/:id/triggers
    // { "name": "notify-new-lead", "table": "leads", "event": "insert", "integration": "resend",
    //   "request": { "method": "POST", "path": "emails", "body": {
    //     "to": {"literal": "owner@example.com"}, "subject": {"template": "New lead: {{row.name}}"} } } }
    $router->post('/v1/projects/:id/triggers', function (array $req) use ($catalog, $ownProject, $validName, $validSpec): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);

        $name            = strtolower(trim((string)($req['body']['name'] ?? '')));
        $tableName       = trim((string)($req['body']['table'] ?? ''));
        $event           = strtolower(trim((string)($req['body']['event'] ?? 'insert')));
        $integrationName = trim((string)($req['body']['integration'] ?? ''));
        $request         = $req['body']['request'] ?? null;

        if (!$validName($name)) {
            abort(422, 'name must be lowercase letters, numbers, "-", "_" (max 63 chars).');
        }
        if (!$catalog->getTable($project['id'], $tableName)) {
            abort(422, "table \"$tableName\" not found");
        }
        if ($event !== 'insert') {
            abort(422, 'event must be "insert" (the only supported trigger event today)');
        }
        if ($integrationName === '' || !$catalog->getIntegration($project['id'], $integrationName)) {
            abort(422, "No integration named \"$integrationName\" is registered on this project");
        }
        if (!is_array($request) || trim((string)($request['path'] ?? '')) === '') {
            abort(422, 'request.path is required');
        }
        $bodySpec = $request['body'] ?? [];
        if (!is_array($bodySpec)) {
            abort(422, 'request.body must be an object of column => value-spec');
        }
        foreach ($bodySpec as $key => $spec) {
            if (!$validSpec($spec)) {
                abort(422, "request.body.$key must be {\"literal\": ...}, {\"path\": \"row.x\"}, or {\"template\": \"...{{row.x}}...\"}");
            }
        }

        $trigger = $catalog->createTrigger($project['id'], $name, $tableName, $event, $integrationName, $request);
        json_out($trigger, 201);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/triggers
    $router->get('/v1/projects/:id/triggers', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);
        json_out($catalog->listTriggers($project['id']));
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/triggers/:name
    $router->delete('/v1/projects/:id/triggers/:name', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);
        $catalog->deleteTrigger($project['id'], strtolower($req['params']['name']));
        json_out(['deleted' => true]);
    }, ['auth_middleware']);
}
