<?php

declare(strict_types=1);

// BLogic: tenant-authored business logic attached to a table, executed in a
// sandboxed subprocess (see app/core/blogic.php). This file has two halves:
// management (define/list/edit/remove entries -- owner/PAT only, same shape
// as table_routes.php's columns/policies routes) and invocation (the one
// route end users actually hit, gated by the existing Policy engine exactly
// like a generic UPDATE would be -- BLogic never bypasses Policies, it
// composes with them).
function register_blogic_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();
    $blogic  = new \SupaBein\Blogic();

    $ownProject = function (int $projectId, int $userId) use ($catalog): array {
        $project = $catalog->getProjectById($projectId, $userId);
        if (!$project) abort(404, 'Project not found');
        $project['id'] = (int)$project['id'];
        return $project;
    };

    $ownTable = function (int $projectId, string $tableName) use ($catalog): array {
        $table = $catalog->getTable($projectId, $tableName);
        if (!$table) abort(404, 'Table not found');
        $table['id']         = (int)$table['id'];
        $table['project_id'] = (int)$table['project_id'];
        return $table;
    };

    $validTriggers = ['action', 'before_insert', 'after_insert', 'before_update', 'after_update', 'before_delete', 'after_delete'];

    // Compiles $source inside a never-executed branch so a syntax error is
    // caught here, at definition time, instead of the first time someone
    // actually triggers it. `if (0) { ... }` still has to parse -- PHP
    // compiles the whole eval'd string upfront regardless of which branches
    // run -- but it guarantees the source itself never executes.
    $validateSourceSyntax = function (string $source): void {
        try {
            eval('if (0) {' . $source . '}');
        } catch (\ParseError $e) {
            abort(422, 'source does not compile as a PHP function body: ' . $e->getMessage());
        }
    };

    // ── Management (owner/PAT) ───────────────────────────────────────────

    // GET /v1/projects/:id/tables/:name/blogic
    $router->get('/v1/projects/:id/tables/:name/blogic', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable($project['id'], $req['params']['name']);
        json_out($catalog->listBlogic($table['id']));
    }, ['auth_middleware']);

    // POST /v1/projects/:id/tables/:name/blogic
    // { "trigger_type", "action_name"?, "name", "description"?, "source", "context_spec"? }
    $router->post('/v1/projects/:id/tables/:name/blogic', function (array $req) use ($catalog, $ownProject, $ownTable, $validTriggers, $validateSourceSyntax): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable($project['id'], $req['params']['name']);
        $body    = $req['body'];

        $triggerType = (string)($body['trigger_type'] ?? '');
        $actionName  = $body['action_name'] ?? null;
        $name        = trim((string)($body['name'] ?? ''));
        $source      = (string)($body['source'] ?? '');

        if (!in_array($triggerType, $validTriggers, true)) abort(422, 'Invalid trigger_type');
        if ($triggerType === 'action' && (!$actionName || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $actionName))) {
            abort(422, 'action trigger requires action_name (uppercase letters/digits/underscore)');
        }
        if ($triggerType !== 'action') $actionName = null;
        if (strlen($name) < 2) abort(422, 'name must be at least 2 characters');
        if (trim($source) === '') abort(422, 'source is required');

        $validateSourceSyntax($source);

        $contextSpec = is_array($body['context_spec'] ?? null) ? $body['context_spec'] : null;
        json_out($catalog->createBlogic($table['id'], $triggerType, $actionName, $name, $body['description'] ?? null, $source, $contextSpec), 201);
    }, ['auth_middleware']);

    // PATCH /v1/projects/:id/tables/:name/blogic/:blogic_id
    $router->patch('/v1/projects/:id/tables/:name/blogic/:blogic_id', function (array $req) use ($catalog, $ownProject, $ownTable, $validateSourceSyntax): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable($project['id'], $req['params']['name']);
        $entry   = $catalog->getBlogicById((int)$req['params']['blogic_id']);
        if (!$entry || $entry['project_table_id'] !== $table['id']) abort(404, 'BLogic entry not found');

        $body   = $req['body'];
        $name   = array_key_exists('name', $body) ? trim((string)$body['name']) : $entry['name'];
        $source = array_key_exists('source', $body) ? (string)$body['source'] : $entry['source'];
        if (strlen($name) < 2) abort(422, 'name must be at least 2 characters');
        if (trim($source) === '') abort(422, 'source is required');
        $validateSourceSyntax($source);

        $description = array_key_exists('description', $body) ? $body['description'] : $entry['description'];
        $contextSpec = array_key_exists('context_spec', $body)
            ? (is_array($body['context_spec']) ? $body['context_spec'] : null)
            : $entry['context_spec'];
        $isActive = array_key_exists('is_active', $body) ? (bool)$body['is_active'] : (bool)$entry['is_active'];

        json_out($catalog->updateBlogic($entry['id'], $name, $description, $source, $contextSpec, $isActive));
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/tables/:name/blogic/:blogic_id
    $router->delete('/v1/projects/:id/tables/:name/blogic/:blogic_id', function (array $req) use ($catalog, $ownProject, $ownTable): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']['user_id']);
        $table   = $ownTable($project['id'], $req['params']['name']);
        $entry   = $catalog->getBlogicById((int)$req['params']['blogic_id']);
        if (!$entry || $entry['project_table_id'] !== $table['id']) abort(404, 'BLogic entry not found');
        $catalog->deleteBlogic($entry['id']);
        json_out(['deleted' => true]);
    }, ['auth_middleware']);

    // ── Invocation ────────────────────────────────────────────────────────

    // POST /v1/data/:project_id/:table_name/:id/actions/:action_name
    // Gated by the same Policy engine a generic UPDATE would be -- BLogic
    // never runs unless Policies already allow this actor to act on this
    // row. That's the whole "Policies decide who, BLogic decides what
    // happens" split: this route re-checks Policy itself rather than
    // trusting the caller already passed some other check.
    $router->post('/v1/data/:project_id/:table_name/:id/actions/:action_name', function (array $req) use ($catalog, $blogic): void {
        $projectId  = (int)$req['params']['project_id'];
        $tableName  = $req['params']['table_name'];
        $rowId      = (int)$req['params']['id'];
        $actionName = $req['params']['action_name'];
        $pdo        = \App::get('db');

        $table = $catalog->getTable($projectId, $tableName);
        if (!$table) abort(404, 'Table not found');
        $project = $catalog->getProjectByIdInternal($projectId);
        if (!$project) abort(404, 'Project not found');

        if ($req['auth'] !== null && isset($req['auth']['project_id']) && $req['auth']['project_id'] !== null
            && $req['auth']['project_id'] !== $projectId) {
            abort(403, 'Token is not valid for this project');
        }

        \SupaBein\RateLimit::checkProject($projectId);

        $policy = \SupaBein\Policy::check($pdo, (int)$table['id'], $req['auth'], (int)$project['owner_user_id'], 'UPDATE');
        if (!$policy->allowed) abort(403, 'Policy denies this operation');

        $entry = $catalog->findActiveBlogic((int)$table['id'], 'action', $actionName);
        if (!$entry) abort(404, 'No such action on this table');

        $sql = 'SELECT * FROM `' . $table['physical_name'] . '` WHERE id = ?' . ($policy->constraint ? ' AND (' . $policy->constraint . ')' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rowId]);
        $row = $stmt->fetch();
        if (!$row) abort(404, 'Row not found or policy constraint not satisfied');

        $context = $blogic->resolveContext($pdo, $projectId, $row, $entry['context_spec']);
        $context['actor'] = [
            'user_id' => $req['auth']['user_id'] ?? null,
            'role'    => $req['auth']['role'] ?? 'anon',
        ];

        $allowedTables = [$table['physical_name']];
        foreach ($entry['context_spec'] ?? [] as $lookup) {
            if (!empty($lookup['table'])) {
                $t = $catalog->getTable($projectId, (string)$lookup['table']);
                if ($t) $allowedTables[] = $t['physical_name'];
            }
        }

        try {
            $effects = $blogic->invoke($entry['source'], $context);
            $blogic->applyEffects($pdo, $effects, array_unique($allowedTables));
        } catch (\SupaBein\BlogicExecutionException $e) {
            abort(422, $e->getMessage());
        } catch (\RuntimeException $e) {
            sb_log('blogic', 'invoke failed', ['project_id' => $projectId, 'table' => $tableName, 'action' => $actionName, 'error' => $e->getMessage()]);
            abort(500, 'BLogic execution failed');
        }

        $stmt2 = $pdo->prepare('SELECT * FROM `' . $table['physical_name'] . '` WHERE id = ?');
        $stmt2->execute([$rowId]);
        json_out(['row' => $stmt2->fetch() ?: null]);
    }, ['optional_auth_middleware']);
}
