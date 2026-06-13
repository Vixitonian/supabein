<?php

declare(strict_types=1);

function register_project_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    // GET /v1/projects
    $router->get('/v1/projects', function (array $req) use ($catalog): void {
        $projects = $catalog->listProjects($req['auth']['user_id']);
        json_out($projects);
    }, ['auth_middleware']);

    // POST /v1/projects
    $router->post('/v1/projects', function (array $req) use ($catalog): void {
        $name = trim($req['body']['name'] ?? '');
        if (!$name || strlen($name) > 128) {
            abort(422, 'Project name is required (max 128 chars)');
        }

        try {
            $project = $catalog->createProject($req['auth']['user_id'], $name);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                abort(409, 'A project with this name already exists');
            }
            throw $e;
        }

        json_out($project, 201);
    }, ['auth_middleware']);

    // GET /v1/projects/:id
    $router->get('/v1/projects/:id', function (array $req) use ($catalog): void {
        $project = $catalog->getProjectById((int)$req['params']['id'], $req['auth']['user_id']);
        if (!$project) {
            abort(404);
        }
        json_out($project);
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id
    $router->delete('/v1/projects/:id', function (array $req) use ($catalog): void {
        $deleted = $catalog->deleteProject((int)$req['params']['id'], $req['auth']['user_id']);
        if (!$deleted) {
            abort(404);
        }
        json_out(['deleted' => true]);
    }, ['auth_middleware']);
}
