<?php

declare(strict_types=1);

// Treasura's transactional business logic, ported onto its migrated SupaBein
// project tables (see the migration this session ran for project id 70).
// Plain reads/writes (login, register via the PASSWORD-column auto-hash,
// forgot/reset password) already work for free through the platform's own
// generic /v1/data/:project_id/:table_name endpoints and project-table auth
// (POST .../login, .../forgot, .../reset) -- nothing here duplicates those.
// What's here is everything that needs atomicity or a business rule beyond
// "write this row": the request approval state machine (which moves money
// between treasuries/debts/the unallocated balance), allocation rules,
// treasurer relationships (invite/accept/resign), and the treasurer-assisted
// account reset (snapshot + wipe). Ported 1:1 from Treasura's own
// api/features/*.php, adapted from PHP-session auth + generate_id() CHAR(32)
// ids to project_user JWTs + SupaBein's auto-increment INT ids.
function register_treasura_routes(\SupaBein\Router $router): void
{
    $catalog = \SupaBein\Catalog::getInstance();

    // ── Auth/scope helper ───────────────────────────────────────────────
    // Every route below requires an authenticated project_user of the
    // Treasura project's own `users` table -- returns that row's int id.
    $requireTreasuraUser = function (array $req) use ($catalog): int {
        $urlProjectId = (int)$req['params']['id'];
        $auth = $req['auth'];
        if (($auth['role'] ?? '') !== 'project_user' || (int)($auth['project_id'] ?? 0) !== $urlProjectId) {
            abort(401, 'Unauthorized');
        }
        return (int)$auth['user_id'];
    };

    $pTable = fn(int $projectId, string $logical): string => $catalog->getTable($projectId, $logical)['physical_name'];

    // ── Notifications helper (mirrors Treasura's notify(), best-effort) ──
    $notify = function (\PDO $pdo, string $notifTable, int $userId, string $type, string $title, ?string $body = null, ?string $link = null) {
        if (!$userId) return;
        try {
            $pdo->prepare('INSERT INTO `' . $notifTable . '` (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)')
                ->execute([$userId, $type, $title, $body, $link]);
        } catch (\Throwable $e) { /* non-critical */ }
    };

    $fmtAmount = fn(int $kobo): string => number_format($kobo / 100, 2);
    $typeLabel = fn(string $type): string => [
        'DEPOSIT' => 'Deposit', 'DEPOSIT_UNALLOCATED' => 'Unallocated deposit', 'ALLOCATION' => 'Allocation',
        'WITHDRAWAL' => 'Withdrawal', 'WITHDRAWAL_AS_DEBT' => 'Withdrawal (debt)',
        'DEBT_REPAYMENT' => 'Debt repayment', 'TRANSFER' => 'Transfer',
    ][$type] ?? $type;

    // ── Treasuries ─────────────────────────────────────────────────────

    // GET /v1/projects/:id/treasura/treasuries
    $router->get('/v1/projects/:id/treasura/treasuries', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id'];
        $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db');
        $treasuries = $pTable($projectId, 'treasuries');
        $debts = $pTable($projectId, 'debts');
        $requests = $pTable($projectId, 'requests');
        $users = $pTable($projectId, 'users');

        $stmt = $pdo->prepare("
            SELECT t.*, COALESCE((
                SELECT SUM(d.original_amount - d.amount_repaid) FROM `$debts` d
                WHERE d.treasury_id = t.id AND d.status != 'CLEARED'
            ), 0) AS total_debt
            FROM `$treasuries` t WHERE t.client_id = ? AND t.is_archived = 0 ORDER BY t.created_at DESC
        ");
        $stmt->execute([$userId]);
        $list = $stmt->fetchAll();

        $totalBalance = array_sum(array_column($list, 'balance'));
        $totalDebt = array_sum(array_column($list, 'total_debt'));

        // Pending outflows (mirrors Treasura's get_pending_outflows()).
        $pStmt = $pdo->prepare("
            SELECT r.type, r.treasury_id, r.target_treasury_id, r.allocation_group_id, SUM(r.amount) AS s
            FROM `$requests` r
            WHERE r.initiator_id = ?
              AND ( (r.type IN ('WITHDRAWAL','WITHDRAWAL_AS_DEBT') AND r.status IN ('PENDING','TREASURER_CONFIRMED'))
                 OR (r.type IN ('TRANSFER','ALLOCATION','DEBT_REPAYMENT') AND r.status = 'PENDING') )
            GROUP BY r.type, r.treasury_id, r.target_treasury_id, r.allocation_group_id
        ");
        $pStmt->execute([$userId]);
        $perTreasury = []; $unallocatedPending = 0; $totalPending = 0;
        foreach ($pStmt->fetchAll() as $row) {
            $sum = (int)$row['s'];
            switch ($row['type']) {
                case 'WITHDRAWAL': case 'WITHDRAWAL_AS_DEBT':
                    $perTreasury[$row['treasury_id']] = ($perTreasury[$row['treasury_id']] ?? 0) + $sum;
                    $totalPending += $sum;
                    break;
                case 'TRANSFER':
                    $perTreasury[$row['treasury_id']] = ($perTreasury[$row['treasury_id']] ?? 0) + $sum;
                    break;
                case 'ALLOCATION':
                    $unallocatedPending += $sum;
                    break;
                case 'DEBT_REPAYMENT':
                    if (!empty($row['target_treasury_id'])) {
                        $perTreasury[$row['target_treasury_id']] = ($perTreasury[$row['target_treasury_id']] ?? 0) + $sum;
                        $totalPending += $sum;
                    } elseif (($row['allocation_group_id'] ?? '') === 'UNALLOCATED') {
                        $unallocatedPending += $sum;
                        $totalPending += $sum;
                    }
                    break;
            }
        }
        foreach ($list as &$t) { $t['pending_out'] = $perTreasury[$t['id']] ?? 0; }
        unset($t);

        $meStmt = $pdo->prepare("SELECT unallocated_balance FROM `$users` WHERE id = ?");
        $meStmt->execute([$userId]);
        $me = $meStmt->fetch();

        json_out([
            'treasuries' => $list, 'total_balance' => (int)$totalBalance, 'total_debt' => (int)$totalDebt,
            'total_pending' => $totalPending, 'unallocated_balance' => (int)($me['unallocated_balance'] ?? 0),
            'unallocated_pending' => $unallocatedPending,
        ]);
    }, ['auth_middleware']);

    // POST /v1/projects/:id/treasura/treasuries
    $router->post('/v1/projects/:id/treasura/treasuries', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id'];
        $userId = $requireTreasuraUser($req);
        $name = trim((string)($req['body']['name'] ?? ''));
        if (strlen($name) < 2) abort(422, 'Treasury name must be at least 2 characters');
        $pdo = \App::get('db');
        $treasuries = $pTable($projectId, 'treasuries');
        $stmt = $pdo->prepare("INSERT INTO `$treasuries` (client_id, name, description) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $name, $req['body']['description'] ?? null]);
        json_out(['message' => 'Treasury created', 'id' => (int)$pdo->lastInsertId()], 201);
    }, ['auth_middleware']);

    // GET /v1/projects/:id/treasura/treasuries/:tid
    $router->get('/v1/projects/:id/treasura/treasuries/:tid', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id'];
        $userId = $requireTreasuraUser($req);
        $tid = (int)$req['params']['tid'];
        $pdo = \App::get('db');
        $treasuries = $pTable($projectId, 'treasuries');
        $debts = $pTable($projectId, 'debts');
        $requests = $pTable($projectId, 'requests');

        $tStmt = $pdo->prepare("SELECT * FROM `$treasuries` WHERE id = ?");
        $tStmt->execute([$tid]);
        $treasury = $tStmt->fetch();
        if (!$treasury || (int)$treasury['client_id'] !== $userId) abort(404, 'Not found');

        $dStmt = $pdo->prepare("
            SELECT d.*, r.note AS request_note, r.created_at AS incurred_at
            FROM `$debts` d JOIN `$requests` r ON r.id = d.source_request_id
            WHERE d.treasury_id = ? ORDER BY d.created_at DESC
        ");
        $dStmt->execute([$tid]);

        $rStmt = $pdo->prepare("
            SELECT r.*, tt.name AS target_treasury_name FROM `$requests` r
            LEFT JOIN `$treasuries` tt ON tt.id = r.target_treasury_id
            WHERE r.treasury_id = ? ORDER BY r.created_at DESC LIMIT 50
        ");
        $rStmt->execute([$tid]);

        json_out(['treasury' => $treasury, 'debts' => $dStmt->fetchAll(), 'requests' => $rStmt->fetchAll()]);
    }, ['auth_middleware']);

    // PATCH /v1/projects/:id/treasura/treasuries/:tid
    $router->patch('/v1/projects/:id/treasura/treasuries/:tid', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id'];
        $userId = $requireTreasuraUser($req);
        $tid = (int)$req['params']['tid'];
        $pdo = \App::get('db');
        $treasuries = $pTable($projectId, 'treasuries');

        $tStmt = $pdo->prepare("SELECT * FROM `$treasuries` WHERE id = ?");
        $tStmt->execute([$tid]);
        $treasury = $tStmt->fetch();
        if (!$treasury || (int)$treasury['client_id'] !== $userId) abort(404, 'Not found');

        if (!empty($req['body']['archive'])) {
            $pdo->prepare("UPDATE `$treasuries` SET is_archived = 1 WHERE id = ?")->execute([$tid]);
            json_out(['message' => 'Treasury archived']);
        }
        $name = trim((string)($req['body']['name'] ?? ''));
        if (strlen($name) < 2) abort(422, 'Treasury name must be at least 2 characters');
        $pdo->prepare("UPDATE `$treasuries` SET name = ?, description = ? WHERE id = ?")
            ->execute([$name, $req['body']['description'] ?? $treasury['description'], $tid]);
        json_out(['message' => 'Treasury updated']);
    }, ['auth_middleware']);

    // ── Allocation rules ───────────────────────────────────────────────

    // GET /v1/projects/:id/treasura/allocations
    $router->get('/v1/projects/:id/treasura/allocations', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id'];
        $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db');
        $rules = $pTable($projectId, 'allocation_rules');
        $items = $pTable($projectId, 'allocation_rule_items');
        $treasuries = $pTable($projectId, 'treasuries');

        $rStmt = $pdo->prepare("SELECT * FROM `$rules` WHERE client_id = ? ORDER BY is_default DESC, created_at DESC");
        $rStmt->execute([$userId]);
        $ruleList = $rStmt->fetchAll();
        foreach ($ruleList as &$rule) {
            $iStmt = $pdo->prepare("SELECT i.*, t.name AS treasury_name FROM `$items` i JOIN `$treasuries` t ON t.id = i.treasury_id WHERE i.rule_id = ?");
            $iStmt->execute([$rule['id']]);
            $rule['items'] = $iStmt->fetchAll();
        }
        unset($rule);
        json_out(['rules' => $ruleList]);
    }, ['auth_middleware']);

    $validateAllocationItems = function (array $data): ?array {
        $errors = [];
        if (empty($data['name'])) $errors['name'] = 'Rule name is required';
        if (empty($data['items']) || !is_array($data['items']) || count($data['items']) < 2) {
            $errors['items'] = 'At least 2 treasuries are required';
        } else {
            $total = array_sum(array_column($data['items'], 'percentage'));
            if (abs($total - 100) > 0.01) $errors['items'] = 'Percentages must sum to 100. Current total: ' . round($total, 2);
        }
        return $errors ?: null;
    };

    // POST /v1/projects/:id/treasura/allocations
    $router->post('/v1/projects/:id/treasura/allocations', function (array $req) use ($requireTreasuraUser, $pTable, $validateAllocationItems): void {
        $projectId = (int)$req['params']['id'];
        $userId = $requireTreasuraUser($req);
        $data = $req['body'];
        $errors = $validateAllocationItems($data);
        if ($errors) json_out(['errors' => $errors], 422);

        $pdo = \App::get('db');
        $rules = $pTable($projectId, 'allocation_rules');
        $items = $pTable($projectId, 'allocation_rule_items');
        $isDefault = !empty($data['is_default']);
        if ($isDefault) $pdo->prepare("UPDATE `$rules` SET is_default = 0 WHERE client_id = ?")->execute([$userId]);
        $pdo->prepare("INSERT INTO `$rules` (client_id, name, is_default) VALUES (?, ?, ?)")
            ->execute([$userId, trim($data['name']), $isDefault ? 1 : 0]);
        $ruleId = (int)$pdo->lastInsertId();
        $iStmt = $pdo->prepare("INSERT INTO `$items` (rule_id, treasury_id, percentage) VALUES (?, ?, ?)");
        foreach ($data['items'] as $item) { $iStmt->execute([$ruleId, (int)$item['treasury_id'], (float)$item['percentage']]); }
        json_out(['message' => 'Allocation rule created', 'id' => $ruleId], 201);
    }, ['auth_middleware']);

    // PATCH /v1/projects/:id/treasura/allocations/:rid
    $router->patch('/v1/projects/:id/treasura/allocations/:rid', function (array $req) use ($requireTreasuraUser, $pTable, $validateAllocationItems): void {
        $projectId = (int)$req['params']['id'];
        $userId = $requireTreasuraUser($req);
        $rid = (int)$req['params']['rid'];
        $pdo = \App::get('db');
        $rules = $pTable($projectId, 'allocation_rules');
        $items = $pTable($projectId, 'allocation_rule_items');

        $rStmt = $pdo->prepare("SELECT * FROM `$rules` WHERE id = ?");
        $rStmt->execute([$rid]);
        $rule = $rStmt->fetch();
        if (!$rule || (int)$rule['client_id'] !== $userId) abort(404, 'Not found');

        $data = $req['body'];
        $errors = $validateAllocationItems($data);
        if ($errors) json_out(['errors' => $errors], 422);

        $isDefault = !empty($data['is_default']);
        if ($isDefault) $pdo->prepare("UPDATE `$rules` SET is_default = 0 WHERE client_id = ?")->execute([$userId]);
        $pdo->prepare("UPDATE `$rules` SET name = ?, is_default = ? WHERE id = ? AND client_id = ?")
            ->execute([trim($data['name']), $isDefault ? 1 : 0, $rid, $userId]);
        $pdo->prepare("DELETE FROM `$items` WHERE rule_id = ?")->execute([$rid]);
        $iStmt = $pdo->prepare("INSERT INTO `$items` (rule_id, treasury_id, percentage) VALUES (?, ?, ?)");
        foreach ($data['items'] as $item) { $iStmt->execute([$rid, (int)$item['treasury_id'], (float)$item['percentage']]); }
        json_out(['message' => 'Rule updated']);
    }, ['auth_middleware']);

    // DELETE /v1/projects/:id/treasura/allocations/:rid
    $router->delete('/v1/projects/:id/treasura/allocations/:rid', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id'];
        $userId = $requireTreasuraUser($req);
        $rid = (int)$req['params']['rid'];
        $pdo = \App::get('db');
        $rules = $pTable($projectId, 'allocation_rules');
        $pdo->prepare("DELETE FROM `$rules` WHERE id = ? AND client_id = ?")->execute([$rid, $userId]);
        json_out(['message' => 'Rule deleted']);
    }, ['auth_middleware']);

    register_treasura_requests_routes($router, $requireTreasuraUser, $pTable, $notify, $fmtAmount, $typeLabel);
    register_treasura_relationships_routes($router, $requireTreasuraUser, $pTable, $notify);
}
