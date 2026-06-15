<?php

declare(strict_types=1);

function register_storage_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    $ownProject = function (int $projectId, int $userId) use ($catalog): array {
        $project = $catalog->getProjectById($projectId, $userId);
        if (!$project) {
            abort(404, 'Project not found');
        }
        return $project;
    };

    // POST /v1/projects/:project_id/storage/:bucket — upload a file
    $router->post('/v1/projects/:project_id/storage/:bucket',
        function (array $req) use ($ownProject): void {
            $ownProject((int)$req['params']['project_id'], $req['auth']['user_id']);
            \SupaBein\Storage::upload($req);
        },
        ['auth_middleware']
    );

    // GET /v1/projects/:project_id/storage/:bucket — list files in bucket
    $router->get('/v1/projects/:project_id/storage/:bucket',
        function (array $req) use ($ownProject): void {
            $ownProject((int)$req['params']['project_id'], $req['auth']['user_id']);
            \SupaBein\Storage::listFiles($req);
        },
        ['auth_middleware']
    );

    // DELETE /v1/projects/:project_id/storage/:bucket/:filename — delete a file
    $router->delete('/v1/projects/:project_id/storage/:bucket/:filename',
        function (array $req) use ($ownProject): void {
            $ownProject((int)$req['params']['project_id'], $req['auth']['user_id']);
            \SupaBein\Storage::deleteFile($req);
        },
        ['auth_middleware']
    );

    // GET /v1/storage/:project_id/:bucket/:filename — public file serve (no auth)
    $router->get('/v1/storage/:project_id/:bucket/:filename',
        function (array $req): void {
            \SupaBein\Storage::serve($req);
        }
    );
}
