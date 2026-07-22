<?php

declare(strict_types=1);

// Registers a project-scoped tool for generating a single icon-style PNG
// asset on demand -- Pollinations.ai for the image, GD flood-fill for the
// background cutout (see \SupaBein\IconGenerator). No per-project secret,
// no Python/ML runtime; callable by the project owner (PAT) or an
// authenticated project_user, same posture as AI Assistants' chat route.
function register_image_tool_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    // POST /v1/projects/:id/tools/generate-icon
    // { "subject": "rocket ship" }
    // -> { "png_base64": "..." }
    $router->post('/v1/projects/:id/tools/generate-icon', function (array $req) use ($catalog): void {
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

        // Reuses the existing end-user (project_user) storage-write bucket --
        // this endpoint has the same trust profile (reachable by anonymous
        // guest tokens, proxies an external call + does real CPU work), and
        // doesn't warrant its own dedicated counter table yet.
        \SupaBein\RateLimit::checkProjectStorage((int)$project['id']);

        $subject = trim((string)($req['body']['subject'] ?? ''));
        if ($subject === '') {
            abort(422, 'subject is required, e.g. "rocket ship"');
        }

        try {
            $png = \SupaBein\IconGenerator::generate($subject);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(502, $e->getMessage());
        }

        json_out(['png_base64' => base64_encode($png)]);
    }, ['auth_middleware']);
}
