<?php

declare(strict_types=1);

// Backs /sb-admin — a small, separate static panel (not part of the main
// dashboard) for the platform operator to see accounts and grant hosted-AI
// credit. Every route here is gated by platform_admin_middleware, which is
// deliberately stricter than the normal auth_middleware (see its own
// docblock in app/middleware/auth.php) — this surface can see every user on
// the platform and hand out free AI usage, so it never accepts a PAT and
// always re-checks users.is_platform_admin fresh against the DB.
function register_admin_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    // GET /v1/admin/users
    $router->get('/v1/admin/users', function (array $req) use ($catalog): void {
        json_out($catalog->listUsersForAdmin());
    }, ['platform_admin_middleware']);

    // PATCH /v1/admin/users/:id/ai-credit
    // { "unlimited": true }  or  { "balance": 5000000 }  (micro-USD) — either
    // field alone, or both together; omitted fields are left unchanged.
    $router->patch('/v1/admin/users/:id/ai-credit', function (array $req) use ($catalog): void {
        $userId = (int)$req['params']['id'];

        $body = $req['body'] ?? [];
        if (!array_key_exists('unlimited', $body) && !array_key_exists('balance', $body)) {
            abort(422, 'Provide "unlimited" (bool) and/or "balance" (integer, micro-USD)');
        }
        $unlimited = array_key_exists('unlimited', $body) ? (bool)$body['unlimited'] : null;
        $balance   = array_key_exists('balance', $body) ? (int)$body['balance'] : null;

        json_out($catalog->setAiCreditGrant($userId, $unlimited, $balance));
    }, ['platform_admin_middleware']);
}
