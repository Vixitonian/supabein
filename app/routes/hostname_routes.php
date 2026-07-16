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

    $ownProject = function (int $projectId, int $userId) use ($catalog): array {
        $project = $catalog->getProjectById($projectId, $userId);
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

    // POST /v1/projects/:id/hostnames  { "hostname": "joes-store.dxinnovationhub.com" }
    $router->post('/v1/projects/:id/hostnames', function (array $req) use ($catalog, $ownProject, $ownSite): void {
        $project  = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
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

        try {
            $catalog->registerHostname($hostname, $docroot, (bool)$site['spa_mode'], $project['id']);
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        json_out($catalog->getHostnameRegistration($hostname), 201);
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/hostnames/:hostname
    $router->delete('/v1/projects/:id/hostnames/:hostname', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $deleted = $catalog->deleteHostname(strtolower($req['params']['hostname']), $project['id']);
        if (!$deleted) {
            abort(404, 'Hostname not found for this project');
        }
        json_out(['deleted' => true]);
    }, ['auth_middleware']);
}
