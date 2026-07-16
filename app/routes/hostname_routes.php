<?php

declare(strict_types=1);

// Registers hostnames into the neutral `site_registry` table that the
// wildcard vhost's standalone router (site-server/router.php) reads from.
// Deliberately never accepts a docroot from the caller -- it's always
// derived server-side from the caller's own project, so a project can only
// ever register hostnames pointing at its own files.
function register_hostname_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    // Resolves the URL's :id to a project the caller may act on. Two auth
    // shapes get here: an owner (platform JWT or PAT -- account-wide or
    // project-scoped, all resolve to a real SupaBein account user_id, so the
    // existing ownership lookup covers every one of them with no extra
    // branching), or a project_user (an end-user JWT issued by one of this
    // *project's own* tables' /login endpoint -- e.g. a Zera business owner
    // logging into their own account). A project_user token's project_id is
    // baked in at login time by the platform itself, not client-supplied, so
    // it's trusted directly rather than re-checked against an owner_user_id
    // that has no meaning for that identity space -- this is what lets an
    // app like Zera register a hostname automatically, right at signup, using
    // the business owner's own session instead of a separately-issued PAT.
    $resolveProject = function (int $urlProjectId, array $auth) use ($catalog): array {
        if (($auth['role'] ?? '') === 'project_user') {
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
        return $project;
    };

    $ownSite = function (int $projectId) use ($catalog): array {
        $sites = $catalog->listSites($projectId);
        if (empty($sites)) {
            abort(404, 'This project has no site yet. Create one via POST /v1/projects/:id/sites first.');
        }
        return $sites[0];
    };

    // null/null for an owner/PAT call (represents the project itself); the
    // JWT's own table+user_id for a project_user call (one specific end-user).
    $registrant = function (array $auth): array {
        if (($auth['role'] ?? '') === 'project_user') {
            return [$auth['table'] ?? null, (int)$auth['user_id']];
        }
        return [null, null];
    };

    // POST /v1/projects/:id/hostnames  { "hostname": "joes-store.dxinnovationhub.com" }
    $router->post('/v1/projects/:id/hostnames', function (array $req) use ($catalog, $resolveProject, $ownSite, $registrant): void {
        $project  = $resolveProject((int)$req['params']['id'], $req['auth']);
        $site     = $ownSite($project['id']);
        $hostname = strtolower(trim($req['body']['hostname'] ?? ''));

        if (!preg_match('/^(?=.{1,255}$)[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/', $hostname)) {
            abort(422, 'Invalid hostname. Send {"hostname":"sub.example.com"}.');
        }

        // Reserved-word check only applies under the platform's own domain --
        // an unrelated external custom domain someone actually owns isn't
        // squatting on anything by using a word from this list.
        $baseDomain = platform_base_domain();
        if (str_ends_with($hostname, '.' . $baseDomain)) {
            $label = explode('.', substr($hostname, 0, -strlen('.' . $baseDomain)))[0];
            if (\SupaBein\Catalog::isReservedSubdomain($label)) {
                abort(403, "\"$label\" is a reserved subdomain and can't be claimed.");
            }
        }

        $config   = \App::get('config');
        $docroot  = rtrim($config['SITES_PATH'], '/') . '/s' . $site['id'] . '/current';
        [$regTable, $regUserId] = $registrant($req['auth']);

        try {
            $catalog->registerHostname($hostname, $docroot, (bool)$site['spa_mode'], $project['id'], $regTable, $regUserId);
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        json_out($catalog->getHostnameRegistration($hostname), 201);
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/hostnames/:hostname
    $router->delete('/v1/projects/:id/hostnames/:hostname', function (array $req) use ($catalog, $resolveProject, $registrant): void {
        $project  = $resolveProject((int)$req['params']['id'], $req['auth']);
        $hostname = strtolower($req['params']['hostname']);

        $existing = $catalog->getHostnameRegistration($hostname);
        if (!$existing || (int)$existing['project_id'] !== $project['id']) {
            abort(404, 'Hostname not found for this project');
        }

        [$regTable, $regUserId] = $registrant($req['auth']);
        if ($regTable !== null) {
            $ownedByCaller = $existing['registered_by_user_id'] !== null
                && $existing['registered_by_table'] === $regTable
                && (int)$existing['registered_by_user_id'] === $regUserId;
            if (!$ownedByCaller) {
                abort(403, 'This hostname was registered by someone else and can only be removed by them or the project owner.');
            }
        }

        $catalog->deleteHostname($hostname, $project['id'], $regTable, $regUserId);
        json_out(['deleted' => true]);
    }, ['auth_middleware']);
}
