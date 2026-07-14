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

    // Resolves who's calling and what storage scope they get:
    //   - owner JWT/PAT, or service_key for this project -> full bucket access (scope=null)
    //   - project_user (an end-user logged in via a table's /login endpoint)
    //     -> confined to files prefixed with their own row id, computed here
    //        from their JWT, never from anything the client sends
    // Anything else (anon, wrong project) is rejected.
    $resolveStorageAccess = function (int $projectId, array $auth) use ($catalog): array {
        $role = $auth['role'] ?? '';

        if ($role === 'owner' && $catalog->getProjectById($projectId, (int)$auth['user_id'])) {
            return ['scope' => null, 'is_end_user' => false];
        }
        if ($role === 'service_role' && (int)($auth['project_id'] ?? 0) === $projectId) {
            return ['scope' => null, 'is_end_user' => false];
        }
        if ($role === 'project_user' && (int)($auth['project_id'] ?? 0) === $projectId) {
            if (!$catalog->getProjectByIdInternal($projectId)) {
                abort(404, 'Project not found');
            }
            return ['scope' => 'u' . (int)$auth['user_id'], 'is_end_user' => true];
        }

        abort(403, "This token isn't authorized for this project's storage.");
    };

    // Aborts unless the project owner has explicitly enabled end-user
    // uploads for this bucket -- same default-deny posture as an
    // unpolicied table: adding this feature must not silently make every
    // existing bucket in every project writable by any signed-up visitor.
    $requireBucketOptIn = function (int $projectId, string $bucket) use ($catalog): void {
        $policy = $catalog->getStorageBucketPolicy($projectId, $bucket);
        if (!$policy || !$policy['allow_authenticated_upload']) {
            abort(403, "Bucket '$bucket' does not accept end-user uploads yet — the project owner must enable it first (PUT .../storage/$bucket/policy).");
        }
    };

    // POST /v1/projects/:project_id/storage/:bucket — upload a file
    $router->post('/v1/projects/:project_id/storage/:bucket',
        function (array $req) use ($resolveStorageAccess, $requireBucketOptIn): void {
            $projectId = (int)$req['params']['project_id'];
            $bucket    = (string)$req['params']['bucket'];
            $access    = $resolveStorageAccess($projectId, $req['auth']);
            if ($access['is_end_user']) {
                $requireBucketOptIn($projectId, $bucket);
                \SupaBein\RateLimit::checkProjectStorage($projectId);
            }
            \SupaBein\Storage::upload($req, $access['scope']);
        },
        ['auth_middleware']
    );

    // GET /v1/projects/:project_id/storage/:bucket — list files in bucket
    $router->get('/v1/projects/:project_id/storage/:bucket',
        function (array $req) use ($resolveStorageAccess): void {
            $projectId = (int)$req['params']['project_id'];
            $access    = $resolveStorageAccess($projectId, $req['auth']);
            \SupaBein\Storage::listFiles($req, $access['scope']);
        },
        ['auth_middleware']
    );

    // DELETE /v1/projects/:project_id/storage/:bucket/:filename — delete a file
    $router->delete('/v1/projects/:project_id/storage/:bucket/:filename',
        function (array $req) use ($resolveStorageAccess): void {
            $projectId = (int)$req['params']['project_id'];
            $access    = $resolveStorageAccess($projectId, $req['auth']);
            if ($access['is_end_user']) {
                \SupaBein\RateLimit::checkProjectStorage($projectId);
            }
            \SupaBein\Storage::deleteFile($req, $access['scope']);
        },
        ['auth_middleware']
    );

    // GET /v1/storage/:project_id/:bucket/:filename — public file serve (no auth)
    $router->get('/v1/storage/:project_id/:bucket/:filename',
        function (array $req): void {
            \SupaBein\Storage::serve($req);
        }
    );

    // PUT /v1/projects/:project_id/storage/:bucket/policy — owner-only.
    // Explicitly opt a bucket in (or back out of) accepting end-user
    // (project_user) uploads. Everything defaults to owner-only until
    // this is called.
    $router->put('/v1/projects/:project_id/storage/:bucket/policy',
        function (array $req) use ($catalog, $ownProject): void {
            $projectId = (int)$req['params']['project_id'];
            $ownProject($projectId, $req['auth']['user_id']);
            $bucket = \SupaBein\Storage::validateBucketName((string)$req['params']['bucket']);
            $allow  = (bool)($req['body']['authenticated_upload'] ?? false);
            json_out($catalog->setStorageBucketPolicy($projectId, $bucket, $allow));
        },
        ['auth_middleware']
    );

    // GET /v1/projects/:project_id/storage/:bucket/policy — owner-only.
    $router->get('/v1/projects/:project_id/storage/:bucket/policy',
        function (array $req) use ($catalog, $ownProject): void {
            $projectId = (int)$req['params']['project_id'];
            $ownProject($projectId, $req['auth']['user_id']);
            $bucket = \SupaBein\Storage::validateBucketName((string)$req['params']['bucket']);
            $policy = $catalog->getStorageBucketPolicy($projectId, $bucket);
            json_out($policy ?? ['project_id' => $projectId, 'bucket' => $bucket, 'allow_authenticated_upload' => false]);
        },
        ['auth_middleware']
    );
}
