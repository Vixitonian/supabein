<?php

declare(strict_types=1);

// Registers "Webhooks": verifies a signed inbound request from an external
// service, then applies a fixed, declarative column-update template to one
// row of one of the project's own tables. See Catalog::applyWebhookWrite()
// for why this stays "bounded but not arbitrary", the same reasoning that
// makes constraint_sql safe as an opaque-but-bounded string.
function register_webhook_routes(\SupaBein\Router $router): void
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

    // Validates `write` at registration time (not just at delivery time) so
    // a typo'd table/column name fails loudly for whoever registers the
    // webhook, instead of silently no-op-ing on every real delivery.
    $validateWrite = function (int $projectId, mixed $write) use ($catalog): string {
        if (!is_array($write)) {
            return 'write must be an object';
        }
        $table       = (string)($write['table'] ?? '');
        $matchColumn = (string)($write['match_column'] ?? '');
        $matchPath   = (string)($write['match_value_path'] ?? '');
        $set         = $write['set'] ?? null;
        if ($table === '' || $matchColumn === '' || $matchPath === '' || !is_array($set) || empty($set)) {
            return 'write must include table, match_column, match_value_path, and a non-empty set object';
        }
        $tableRow = $catalog->getTable($projectId, $table);
        if (!$tableRow) {
            return "write.table \"$table\" not found";
        }
        $cols = array_column($catalog->listColumns((int)$tableRow['id']), 'col_name');
        if (!in_array($matchColumn, $cols, true)) {
            return "write.match_column \"$matchColumn\" not found on $table";
        }
        foreach ($set as $col => $spec) {
            if (!in_array($col, $cols, true)) {
                return "write.set column \"$col\" not found on $table";
            }
            if (!is_array($spec) || (!array_key_exists('literal', $spec) && !array_key_exists('path', $spec))) {
                return "write.set.$col must be {\"literal\": ...} or {\"path\": \"a.b\"}";
            }
        }
        return '';
    };

    // POST /v1/projects/:id/webhooks
    $router->post('/v1/projects/:id/webhooks', function (array $req) use ($catalog, $ownProject, $validName, $validateWrite): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);

        $name      = strtolower(trim((string)($req['body']['name'] ?? '')));
        $sigHeader = trim((string)($req['body']['signature_header'] ?? ''));
        $sigAlgo   = trim((string)($req['body']['signature_algorithm'] ?? ''));
        $sigSecret = (string)($req['body']['signature_secret'] ?? '');
        $match     = $req['body']['match'] ?? null;
        $write     = $req['body']['write'] ?? null;

        if (!$validName($name)) {
            abort(422, 'name must be lowercase letters, numbers, "-", "_" (max 63 chars).');
        }
        if ($sigHeader === '') {
            abort(422, 'signature_header is required');
        }
        if (!\SupaBein\Catalog::isValidWebhookAlgorithm($sigAlgo)) {
            abort(422, 'signature_algorithm must be "hmac-sha256" or "hmac-sha512"');
        }
        if ($sigSecret === '') {
            abort(422, 'signature_secret is required');
        }
        if ($match !== null && !is_array($match)) {
            abort(422, 'match must be a JSON object, or omitted');
        }
        $writeError = $validateWrite($project['id'], $write);
        if ($writeError !== '') {
            abort(422, $writeError);
        }

        $webhook = $catalog->createWebhook($project['id'], $name, $sigHeader, $sigAlgo, $sigSecret, $match, $write);
        json_out($webhook, 201);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/webhooks -- never includes signature_secret.
    $router->get('/v1/projects/:id/webhooks', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);
        json_out($catalog->listWebhooks($project['id']));
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/webhooks/:name
    $router->delete('/v1/projects/:id/webhooks/:name', function (array $req) use ($catalog, $ownProject): void {
        $project = $ownProject((int)$req['params']['id'], $req['auth']);
        $catalog->deleteWebhook($project['id'], strtolower($req['params']['name']));
        json_out(['deleted' => true]);
    }, ['auth_middleware']);

    // POST /v1/projects/:id/webhooks/:name -- the public receiver. No auth
    // middleware: the external service (Paystack etc.) calls this directly
    // and proves itself via the signature, not a bearer token. Per the
    // spec, always returns 200 on anything short of a bad signature --
    // most senders (Paystack included) retry aggressively on non-2xx, and a
    // slow/erroring response causes duplicate-delivery storms, not safety.
    $router->post('/v1/projects/:id/webhooks/:name', function (array $req) use ($catalog): void {
        $projectId = (int)$req['params']['id'];
        $name      = strtolower($req['params']['name']);

        $webhook = $catalog->getWebhookForVerification($projectId, $name);
        if (!$webhook) {
            abort(404, 'Webhook not found');
        }

        $headerName = strtolower($webhook['signature_header']);
        $provided   = '';
        foreach ($req['headers'] as $k => $v) {
            if (strtolower((string)$k) === $headerName) {
                $provided = $v;
                break;
            }
        }
        $rawBody = $req['raw_body'] ?? '';
        if ($provided === '' || !\SupaBein\Catalog::verifyWebhookSignature(
            $rawBody, $provided, $webhook['signature_algorithm'], $webhook['signature_secret']
        )) {
            abort(401, 'Invalid signature');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            json_out(['received' => true]);
        }

        if (is_array($webhook['match_json'])) {
            foreach ($webhook['match_json'] as $key => $expected) {
                $actual = \SupaBein\Catalog::resolveDotPath($payload, (string)$key);
                if ((string)$actual !== (string)$expected) {
                    // Not the event type this webhook cares about -- 200 so
                    // the sender doesn't retry, not an error condition.
                    json_out(['received' => true, 'matched' => false]);
                }
            }
        }

        try {
            $result = $catalog->applyWebhookWrite($projectId, (array)$webhook['write_json'], $payload);
        } catch (\Throwable $e) {
            sb_log('webhook', 'write failed', ['project_id' => $projectId, 'name' => $name, 'error' => $e->getMessage()]);
            json_out(['received' => true, 'applied' => false]);
        }

        json_out(['received' => true, 'applied' => $result['applied'], 'reason' => $result['reason']]);
    });
}
