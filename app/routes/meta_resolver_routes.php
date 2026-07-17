<?php

declare(strict_types=1);

// Registers bot-visible meta tag resolvers: "for a request path matching
// this pattern, from a known link-preview crawler, look up a row and inject
// <title>/<meta property="og:..."> tags into the HTML SupaBein serves."
// Registration only lives here -- the actual crawler-detection, path
// matching, and HTML injection happen in site-server/router.php, which
// deliberately never depends on the rest of this app (see that file's own
// docblock) and reads this table directly.
function register_meta_resolver_routes(\SupaBein\Router $router): void
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
    $validSpec = function ($spec) use (&$validSpec): bool {
        if (!is_array($spec) || (!array_key_exists('literal', $spec) && !array_key_exists('template', $spec) && !array_key_exists('path', $spec))) {
            return false;
        }
        if (isset($spec['fallback']) && !$validSpec($spec['fallback'])) {
            return false;
        }
        return true;
    };

    // POST /v1/projects/:id/meta-resolvers
    // { "name": "storefront", "path_pattern": "/store/:slug/*",
    //   "lookup": {"table": "businesses", "match_column": "slug", "match_value_path": "params.slug"},
    //   "meta": { "title": {"path": "row.name"},
    //             "og:description": {"path": "row.home_tagline", "fallback": {"path": "row.about_text"}} } }
    $router->post('/v1/projects/:id/meta-resolvers', function (array $req) use ($catalog, $ownProject, $validName, $validSpec): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);

        $name        = strtolower(trim((string)($req['body']['name'] ?? '')));
        $pathPattern = trim((string)($req['body']['path_pattern'] ?? ''));
        $lookup      = $req['body']['lookup'] ?? null;
        $meta        = $req['body']['meta'] ?? null;

        if (!$validName($name)) {
            abort(422, 'name must be lowercase letters, numbers, "-", "_" (max 63 chars).');
        }
        if ($pathPattern === '' || $pathPattern[0] !== '/') {
            abort(422, 'path_pattern must start with "/", e.g. "/store/:slug/*"');
        }
        if (!is_array($lookup)) {
            abort(422, 'lookup is required');
        }
        $lookupTable = (string)($lookup['table'] ?? '');
        $matchColumn = (string)($lookup['match_column'] ?? '');
        $matchPath   = (string)($lookup['match_value_path'] ?? '');
        $tableRow = $catalog->getTable($project['id'], $lookupTable);
        if (!$tableRow) {
            abort(422, "lookup.table \"$lookupTable\" not found");
        }
        $cols = array_column($catalog->listColumns((int)$tableRow['id']), 'col_name');
        if (!in_array($matchColumn, $cols, true)) {
            abort(422, "lookup.match_column \"$matchColumn\" not found on $lookupTable");
        }
        if (!str_starts_with($matchPath, 'params.')) {
            abort(422, 'lookup.match_value_path must reference a ":name" captured from path_pattern, e.g. "params.slug"');
        }
        if (!is_array($meta) || empty($meta)) {
            abort(422, 'meta must be a non-empty object, e.g. {"title": {"path": "row.name"}}');
        }
        foreach ($meta as $key => $spec) {
            if (!$validSpec($spec)) {
                abort(422, "meta.$key must be {\"literal\": ...}, {\"path\": \"row.x\"}, optionally with a \"fallback\" of the same shape");
            }
        }

        $resolver = $catalog->createMetaResolver($project['id'], $name, $pathPattern, $lookup, $meta);
        json_out($resolver, 201);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/meta-resolvers
    $router->get('/v1/projects/:id/meta-resolvers', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);
        json_out($catalog->listMetaResolvers($project['id']));
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/meta-resolvers/:name
    $router->delete('/v1/projects/:id/meta-resolvers/:name', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);
        $catalog->deleteMetaResolver($project['id'], strtolower($req['params']['name']));
        json_out(['deleted' => true]);
    }, ['auth_middleware']);
}
