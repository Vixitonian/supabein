<?php

declare(strict_types=1);

// Requests (the approval state machine), debts, and notifications -- split
// out of treasura_routes.php purely for file size. Ported from Treasura's
// requests.service.php/requests.handler.php/debts.*/notifications.handler.php.
function register_treasura_requests_routes(\SupaBein\Router $router, \Closure $requireTreasuraUser, \Closure $pTable, \Closure $notify, \Closure $fmtAmount, \Closure $typeLabel): void
{
    $activeTreasurerId = function (\PDO $pdo, string $rel, int $clientId): ?int {
        $stmt = $pdo->prepare("SELECT treasurer_id FROM `$rel` WHERE client_id = ? AND status = 'ACTIVE' LIMIT 1");
        $stmt->execute([$clientId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    };

    // Needs treasurer approval before it takes effect? See Treasura's own
    // comment (preserved): deposits always need confirming receipt; outflows
    // (withdrawal/transfer/repayment funded externally) need confirming the
    // debit; a pure internal reallocation (allocation, or a repayment funded
    // from the unallocated balance) completes instantly.
    $needsApproval = function (string $type, array $data): bool {
        return match ($type) {
            'DEPOSIT', 'DEPOSIT_UNALLOCATED' => true,
            'DEBT_REPAYMENT' => ($data['allocation_group_id'] ?? '') !== 'UNALLOCATED',
            default => true,
        };
    };

    // Applies a request's balance/debt side effects -- shared by the
    // treasurer-approval path and the instant-complete path. Must run inside
    // an open transaction (caller's responsibility).
    $applyEffects = function (\PDO $pdo, string $treasuries, string $users, string $debts, array $request, string $type, int $amount) {
        switch ($type) {
            case 'DEPOSIT':
                $pdo->prepare("UPDATE `$treasuries` SET balance = balance + ? WHERE id = ?")->execute([$amount, $request['treasury_id']]);
                break;
            case 'DEPOSIT_UNALLOCATED':
                $pdo->prepare("UPDATE `$users` SET unallocated_balance = unallocated_balance + ? WHERE id = ?")->execute([$amount, $request['initiator_id']]);
                break;
            case 'ALLOCATION':
                $pdo->prepare("UPDATE `$treasuries` SET balance = balance + ? WHERE id = ?")->execute([$amount, $request['treasury_id']]);
                $pdo->prepare("UPDATE `$users` SET unallocated_balance = unallocated_balance - ? WHERE id = ?")->execute([$amount, $request['initiator_id']]);
                break;
            case 'TRANSFER':
                $pdo->prepare("UPDATE `$treasuries` SET balance = balance - ? WHERE id = ?")->execute([$amount, $request['treasury_id']]);
                $pdo->prepare("UPDATE `$treasuries` SET balance = balance + ? WHERE id = ?")->execute([$amount, $request['target_treasury_id']]);
                break;
            case 'DEBT_REPAYMENT':
                $pdo->prepare("UPDATE `$treasuries` SET balance = balance + ? WHERE id = ?")->execute([$amount, $request['treasury_id']]);
                if (!empty($request['target_treasury_id'])) {
                    $pdo->prepare("UPDATE `$treasuries` SET balance = balance - ? WHERE id = ?")->execute([$amount, $request['target_treasury_id']]);
                } elseif (trim((string)($request['allocation_group_id'] ?? '')) === 'UNALLOCATED') {
                    $pdo->prepare("UPDATE `$users` SET unallocated_balance = unallocated_balance - ? WHERE id = ?")->execute([$amount, $request['initiator_id']]);
                }
                if (!empty($request['debt_id'])) {
                    $dStmt = $pdo->prepare("SELECT * FROM `$debts` WHERE id = ?");
                    $dStmt->execute([$request['debt_id']]);
                    $d = $dStmt->fetch();
                    if ($d) {
                        $newRepaid = (int)$d['amount_repaid'] + $amount;
                        $newStatus = $newRepaid >= (int)$d['original_amount'] ? 'CLEARED' : 'PARTIALLY_REPAID';
                        $pdo->prepare("UPDATE `$debts` SET amount_repaid = ?, status = ? WHERE id = ?")->execute([$newRepaid, $newStatus, $d['id']]);
                    }
                } else {
                    // Treasury-level repayment: apply across open debts oldest-first (FIFO).
                    $open = $pdo->prepare("SELECT * FROM `$debts` WHERE treasury_id = ? AND status != 'CLEARED' ORDER BY created_at ASC, id ASC");
                    $open->execute([$request['treasury_id']]);
                    $left = $amount;
                    foreach ($open->fetchAll() as $d) {
                        if ($left <= 0) break;
                        $remaining = (int)$d['original_amount'] - (int)$d['amount_repaid'];
                        $apply = min($left, $remaining);
                        $newRepaid = (int)$d['amount_repaid'] + $apply;
                        $newStatus = $newRepaid >= (int)$d['original_amount'] ? 'CLEARED' : 'PARTIALLY_REPAID';
                        $pdo->prepare("UPDATE `$debts` SET amount_repaid = ?, status = ? WHERE id = ?")->execute([$newRepaid, $newStatus, $d['id']]);
                        $left -= $apply;
                    }
                }
                break;
        }
    };

    $getRequestById = function (\PDO $pdo, string $requests, string $treasuries, int $id): ?array {
        $stmt = $pdo->prepare("
            SELECT r.*, COALESCE(t.client_id, r.initiator_id) AS client_id, t.name AS treasury_name, tt.name AS target_treasury_name
            FROM `$requests` r
            LEFT JOIN `$treasuries` t ON t.id = r.treasury_id
            LEFT JOIN `$treasuries` tt ON tt.id = r.target_treasury_id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    };

    // ── POST /v1/projects/:id/treasura/requests -- create a single request ──
    $router->post('/v1/projects/:id/treasura/requests', function (array $req) use ($requireTreasuraUser, $pTable, $needsApproval, $applyEffects, $getRequestById, $activeTreasurerId, $notify, $fmtAmount, $typeLabel): void {
        $projectId = (int)$req['params']['id'];
        $userId = $requireTreasuraUser($req);
        $data = $req['body'];
        $validTypes = ['DEPOSIT','DEPOSIT_UNALLOCATED','ALLOCATION','WITHDRAWAL','WITHDRAWAL_AS_DEBT','DEBT_REPAYMENT','TRANSFER'];
        $errors = [];
        if (empty($data['type']) || !in_array($data['type'], $validTypes, true)) $errors['type'] = 'Valid request type is required';
        if (empty($data['amount']) || (float)$data['amount'] <= 0) $errors['amount'] = 'Amount must be a positive number';
        if ($errors) json_out(['errors' => $errors], 422);

        $pdo = \App::get('db');
        $treasuries = $pTable($projectId, 'treasuries');
        $users = $pTable($projectId, 'users');
        $debts = $pTable($projectId, 'debts');
        $requests = $pTable($projectId, 'requests');
        $type = $data['type'];
        $amountKobo = (int)round((float)$data['amount'] * 100);

        if ($type !== 'DEPOSIT_UNALLOCATED') {
            if (empty($data['treasury_id'])) json_out(['errors' => ['treasury_id' => 'Treasury is required']], 422);
            $tStmt = $pdo->prepare("SELECT * FROM `$treasuries` WHERE id = ?");
            $tStmt->execute([(int)$data['treasury_id']]);
            $treasury = $tStmt->fetch();
            if (!$treasury || (int)$treasury['client_id'] !== $userId) abort(404, 'Treasury not found');

            if (in_array($type, ['WITHDRAWAL', 'WITHDRAWAL_AS_DEBT'], true) && (int)$treasury['balance'] < $amountKobo) {
                abort(400, 'Insufficient treasury balance');
            }
            if ($type === 'TRANSFER') {
                if (empty($data['target_treasury_id'])) json_out(['errors' => ['target_treasury_id' => 'Target treasury is required']], 422);
                $tgStmt = $pdo->prepare("SELECT * FROM `$treasuries` WHERE id = ?");
                $tgStmt->execute([(int)$data['target_treasury_id']]);
                $target = $tgStmt->fetch();
                if (!$target || (int)$target['client_id'] !== $userId) abort(404, 'Target treasury not found');
            }
            if ($type === 'DEBT_REPAYMENT' && empty($data['debt_id'])) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM `$debts` WHERE treasury_id = ? AND status != 'CLEARED'");
                $chk->execute([(int)$data['treasury_id']]);
                if ((int)$chk->fetchColumn() === 0) abort(400, 'This treasury has no open debts to repay');
            }
            if ($type === 'DEBT_REPAYMENT' && !empty($data['target_treasury_id'])) {
                $sStmt = $pdo->prepare("SELECT * FROM `$treasuries` WHERE id = ?");
                $sStmt->execute([(int)$data['target_treasury_id']]);
                $source = $sStmt->fetch();
                if (!$source || (int)$source['client_id'] !== $userId) abort(404, 'Funding treasury not found');
                if ((int)$source['balance'] < $amountKobo) abort(400, 'Insufficient balance in the funding treasury');
            }
            if ($type === 'DEBT_REPAYMENT' && ($data['pay_from'] ?? '') === 'UNALLOCATED') {
                $uStmt = $pdo->prepare("SELECT unallocated_balance FROM `$users` WHERE id = ?");
                $uStmt->execute([$userId]);
                if ((int)$uStmt->fetchColumn() < $amountKobo) abort(400, 'Insufficient unallocated balance');
                $data['allocation_group_id'] = 'UNALLOCATED';
            }
        }

        $autoComplete = !$needsApproval($type, $data);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO `$requests` (type, initiator_id, treasury_id, target_treasury_id, debt_id, allocation_group_id, amount, note, status, treasurer_acted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $type, $userId, isset($data['treasury_id']) ? (int)$data['treasury_id'] : null,
                isset($data['target_treasury_id']) ? (int)$data['target_treasury_id'] : null,
                isset($data['debt_id']) ? (int)$data['debt_id'] : null, $data['allocation_group_id'] ?? null,
                $amountKobo, $data['note'] ?? null, $autoComplete ? 'COMPLETED' : 'PENDING',
                $autoComplete ? date('Y-m-d H:i:s') : null,
            ]);
            $id = (int)$pdo->lastInsertId();
            if ($autoComplete) {
                $applyEffects($pdo, $treasuries, $users, $debts, $getRequestById($pdo, $requests, $treasuries, $id), $type, $amountKobo);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $treasurerId = $activeTreasurerId($pdo, $pTable($projectId, 'treasurer_relationships'), $userId);
        if ($treasurerId) {
            $notifTable = $pTable($projectId, 'notifications');
            if ($autoComplete) {
                $notify($pdo, $notifTable, $treasurerId, 'REQUEST_CONFIRMED', $typeLabel($type) . ' recorded',
                    $fmtAmount($amountKobo) . ' was recorded automatically -- no approval needed.', '#/client?clientId=' . $userId);
            } else {
                $notify($pdo, $notifTable, $treasurerId, 'NEW_REQUEST', 'New ' . strtolower($typeLabel($type)) . ' request',
                    'A request of ' . $fmtAmount($amountKobo) . ' is awaiting your confirmation.', '#/client?clientId=' . $userId);
            }
        }
        json_out(['message' => $autoComplete ? 'Request completed' : 'Request created', 'id' => $id, 'auto_completed' => $autoComplete], 201);
    }, ['auth_middleware']);

    // ── POST /v1/projects/:id/treasura/requests/split -- allocate unallocated balance ──
    $router->post('/v1/projects/:id/treasura/requests/split', function (array $req) use ($requireTreasuraUser, $pTable, $applyEffects, $activeTreasurerId, $notify, $fmtAmount): void {
        $projectId = (int)$req['params']['id'];
        $userId = $requireTreasuraUser($req);
        $data = $req['body'];
        $errors = [];
        if (empty($data['amount']) || (float)$data['amount'] <= 0) $errors['amount'] = 'Amount is required';
        if (empty($data['allocations']) || !is_array($data['allocations']) || count($data['allocations']) < 1) {
            $errors['allocations'] = 'At least one allocation is required';
        } else {
            $total = array_sum(array_column($data['allocations'], 'percentage'));
            if (abs($total - 100) > 0.01) $errors['allocations'] = 'Percentages must sum to 100';
        }
        if ($errors) json_out(['errors' => $errors], 422);

        $pdo = \App::get('db');
        $users = $pTable($projectId, 'users');
        $treasuries = $pTable($projectId, 'treasuries');
        $debts = $pTable($projectId, 'debts');
        $requests = $pTable($projectId, 'requests');

        $totalKobo = (int)round((float)$data['amount'] * 100);
        $meStmt = $pdo->prepare("SELECT unallocated_balance FROM `$users` WHERE id = ?");
        $meStmt->execute([$userId]);
        if ((int)$meStmt->fetchColumn() < $totalKobo) abort(400, 'Insufficient unallocated balance');

        $groupId = bin2hex(random_bytes(8));
        $ids = [];
        $pdo->beginTransaction();
        try {
            foreach ($data['allocations'] as $alloc) {
                $allocKobo = (int)round($totalKobo * (float)$alloc['percentage'] / 100);
                $pdo->prepare("
                    INSERT INTO `$requests` (type, initiator_id, treasury_id, allocation_group_id, amount, note, status, treasurer_acted_at)
                    VALUES ('ALLOCATION', ?, ?, ?, ?, ?, 'COMPLETED', NOW())
                ")->execute([$userId, (int)$alloc['treasury_id'], $groupId, $allocKobo, $alloc['note'] ?? null]);
                $ids[] = (int)$pdo->lastInsertId();
                $applyEffects($pdo, $treasuries, $users, $debts, ['treasury_id' => (int)$alloc['treasury_id'], 'initiator_id' => $userId], 'ALLOCATION', $allocKobo);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $treasurerId = $activeTreasurerId($pdo, $pTable($projectId, 'treasurer_relationships'), $userId);
        if ($treasurerId) {
            $notify($pdo, $pTable($projectId, 'notifications'), $treasurerId, 'REQUEST_CONFIRMED', 'Allocation recorded',
                count($ids) . ' allocation(s) totalling ' . $fmtAmount($totalKobo) . ' were recorded automatically.', '#/client?clientId=' . $userId);
        }
        json_out(['message' => 'Allocation completed', 'ids' => $ids, 'auto_completed' => true], 201);
    }, ['auth_middleware']);

    // ── List endpoints ────────────────────────────────────────────────
    $router->get('/v1/projects/:id/treasura/requests/mine', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $requests = $pTable($projectId, 'requests'); $treasuries = $pTable($projectId, 'treasuries');
        $stmt = $pdo->prepare("
            SELECT r.*, COALESCE(t.name, 'Unallocated') AS treasury_name, tt.name AS target_treasury_name
            FROM `$requests` r LEFT JOIN `$treasuries` t ON t.id = r.treasury_id LEFT JOIN `$treasuries` tt ON tt.id = r.target_treasury_id
            WHERE r.initiator_id = ? ORDER BY r.created_at DESC LIMIT 100
        ");
        $stmt->execute([$userId]);
        json_out(['requests' => $stmt->fetchAll()]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/requests/mine-pending', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $requests = $pTable($projectId, 'requests'); $treasuries = $pTable($projectId, 'treasuries');
        $stmt = $pdo->prepare("
            SELECT r.*, COALESCE(t.name, 'Unallocated') AS treasury_name, tt.name AS target_treasury_name
            FROM `$requests` r LEFT JOIN `$treasuries` t ON t.id = r.treasury_id LEFT JOIN `$treasuries` tt ON tt.id = r.target_treasury_id
            WHERE r.initiator_id = ? AND r.status = 'PENDING' ORDER BY r.created_at DESC
        ");
        $stmt->execute([$userId]);
        json_out(['requests' => $stmt->fetchAll()]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/requests/pending-mine', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $requests = $pTable($projectId, 'requests'); $treasuries = $pTable($projectId, 'treasuries');
        $stmt = $pdo->prepare("
            SELECT r.*, t.name AS treasury_name FROM `$requests` r JOIN `$treasuries` t ON t.id = r.treasury_id
            WHERE t.client_id = ? AND r.status = 'TREASURER_CONFIRMED' ORDER BY r.created_at DESC
        ");
        $stmt->execute([$userId]);
        json_out(['requests' => $stmt->fetchAll()]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/requests/pending-treasurer', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $clientId = $req['query']['clientId'] ?? null;
        $pdo = \App::get('db');
        $requests = $pTable($projectId, 'requests'); $treasuries = $pTable($projectId, 'treasuries');
        $users = $pTable($projectId, 'users'); $rel = $pTable($projectId, 'treasurer_relationships');
        $sql = "
            SELECT r.*, COALESCE(t.name, 'Unallocated') AS treasury_name, COALESCE(t.client_id, r.initiator_id) AS client_id,
                   u.name AS initiator_name, tt.name AS target_treasury_name
            FROM `$requests` r
            JOIN `$users` u ON u.id = r.initiator_id
            LEFT JOIN `$treasuries` t ON t.id = r.treasury_id
            LEFT JOIN `$treasuries` tt ON tt.id = r.target_treasury_id
            JOIN `$rel` rr ON rr.client_id = COALESCE(t.client_id, r.initiator_id) AND rr.treasurer_id = ? AND rr.status = 'ACTIVE'
            WHERE r.status IN ('PENDING','TREASURER_CONFIRMED')
        ";
        $params = [$userId];
        if ($clientId) { $sql .= ' AND COALESCE(t.client_id, r.initiator_id) = ?'; $params[] = (int)$clientId; }
        $sql .= ' ORDER BY r.created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_out(['requests' => $stmt->fetchAll()]);
    }, ['auth_middleware']);

    // ── PATCH /v1/projects/:id/treasura/requests/:rid -- perform an action ──
    $router->patch('/v1/projects/:id/treasura/requests/:rid', function (array $req) use ($requireTreasuraUser, $pTable, $getRequestById, $applyEffects, $activeTreasurerId, $notify, $fmtAmount, $typeLabel): void {
        $projectId = (int)$req['params']['id'];
        $userId = $requireTreasuraUser($req);
        $rid = (int)$req['params']['rid'];
        $action = strtoupper(trim((string)($req['body']['action'] ?? '')));
        $note = $req['body']['note'] ?? null;
        if (!$action) abort(400, 'Action is required');

        $pdo = \App::get('db');
        $requests = $pTable($projectId, 'requests');
        $treasuries = $pTable($projectId, 'treasuries');
        $users = $pTable($projectId, 'users');
        $debts = $pTable($projectId, 'debts');
        $rel = $pTable($projectId, 'treasurer_relationships');
        $notifTable = $pTable($projectId, 'notifications');

        $request = $getRequestById($pdo, $requests, $treasuries, $rid);
        if (!$request) abort(404, 'Request not found');

        $isClient = ((int)$request['client_id'] === $userId);
        $relStmt = $pdo->prepare("SELECT id FROM `$rel` WHERE client_id = ? AND treasurer_id = ? AND status = 'ACTIVE'");
        $relStmt->execute([$request['client_id'], $userId]);
        $isTreasurer = (bool)$relStmt->fetch();
        if (!$isClient && !$isTreasurer) abort(403, 'Unauthorized');

        $status = $request['status'];
        $type = $request['type'];
        $amount = (int)$request['amount'];

        $pdo->beginTransaction();
        try {
            $handled = true;
            if ($action === 'TREASURER_CONFIRM' && $status === 'PENDING' && $isTreasurer
                && in_array($type, ['DEPOSIT', 'DEPOSIT_UNALLOCATED', 'ALLOCATION', 'TRANSFER', 'DEBT_REPAYMENT'], true)) {
                $pdo->prepare("UPDATE `$requests` SET status = 'COMPLETED', treasurer_acted_at = NOW(), treasurer_note = ? WHERE id = ?")->execute([$note, $rid]);
                $applyEffects($pdo, $treasuries, $users, $debts, $request, $type, $amount);
                $notify($pdo, $notifTable, (int)$request['initiator_id'], 'REQUEST_CONFIRMED', $typeLabel($type) . ' confirmed',
                    'Your ' . strtolower($typeLabel($type)) . ' of ' . $fmtAmount($amount) . ' was confirmed by your treasurer.',
                    $request['treasury_id'] ? '#/treasury?id=' . $request['treasury_id'] : '#/requests');
            } elseif ($action === 'TREASURER_SEND' && $status === 'PENDING' && $isTreasurer && in_array($type, ['WITHDRAWAL', 'WITHDRAWAL_AS_DEBT'], true)) {
                $pdo->prepare("UPDATE `$requests` SET status = 'TREASURER_CONFIRMED', treasurer_acted_at = NOW(), treasurer_note = ? WHERE id = ?")->execute([$note, $rid]);
                $notify($pdo, $notifTable, (int)$request['initiator_id'], 'WITHDRAWAL_SENT', 'Withdrawal sent',
                    'Your treasurer sent ' . $fmtAmount($amount) . '. Confirm receipt to complete it.', '#/pending');
            } elseif ($action === 'CLIENT_CONFIRM' && $status === 'TREASURER_CONFIRMED' && $isClient) {
                $pdo->prepare("UPDATE `$requests` SET status = 'COMPLETED', client_confirmed_at = NOW() WHERE id = ?")->execute([$rid]);
                $pdo->prepare("UPDATE `$treasuries` SET balance = balance - ? WHERE id = ?")->execute([$amount, $request['treasury_id']]);
                if ($type === 'WITHDRAWAL_AS_DEBT') {
                    $pdo->prepare("INSERT INTO `$debts` (treasury_id, source_request_id, original_amount) VALUES (?, ?, ?)")
                        ->execute([$request['treasury_id'], $rid, $amount]);
                }
                $tid = null; $tStmt = $pdo->prepare("SELECT treasurer_id FROM `$rel` WHERE client_id = ? AND status = 'ACTIVE' LIMIT 1");
                $tStmt->execute([$request['client_id']]); $tid = $tStmt->fetchColumn();
                if ($tid) {
                    $notify($pdo, $notifTable, (int)$tid, 'RECEIPT_CONFIRMED', 'Client confirmed receipt',
                        'Receipt of ' . $fmtAmount($amount) . ' was confirmed by the client.', '#/client?clientId=' . $request['client_id']);
                }
            } elseif ($action === 'CANCEL' && $status === 'PENDING') {
                $pdo->prepare("UPDATE `$requests` SET status = 'CANCELLED' WHERE id = ?")->execute([$rid]);
                if ($isClient) {
                    $tid = null; $tStmt = $pdo->prepare("SELECT treasurer_id FROM `$rel` WHERE client_id = ? AND status = 'ACTIVE' LIMIT 1");
                    $tStmt->execute([$request['client_id']]); $tid = $tStmt->fetchColumn();
                    if ($tid) {
                        $notify($pdo, $notifTable, (int)$tid, 'REQUEST_CANCELLED', 'Request cancelled',
                            'A ' . strtolower($typeLabel($type)) . ' of ' . $fmtAmount($amount) . ' was cancelled by the client.', '#/client?clientId=' . $request['client_id']);
                    }
                }
            } elseif ($action === 'DECLINE' && $status === 'PENDING' && $isTreasurer) {
                $pdo->prepare("UPDATE `$requests` SET status = 'DECLINED', treasurer_acted_at = NOW(), treasurer_note = ? WHERE id = ?")->execute([$note, $rid]);
                $notify($pdo, $notifTable, (int)$request['initiator_id'], 'REQUEST_DECLINED', $typeLabel($type) . ' declined',
                    'Your ' . strtolower($typeLabel($type)) . ' of ' . $fmtAmount($amount) . ' was declined' . ($note ? ': ' . $note : '.'),
                    $request['treasury_id'] ? '#/treasury?id=' . $request['treasury_id'] : '#/requests');
            } else {
                $handled = false;
            }

            if (!$handled) {
                $pdo->rollBack();
                abort(400, "Action '{$action}' is not valid for a {$type} with status {$status}");
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        json_out(['message' => 'Request updated']);
    }, ['auth_middleware']);

    // ── Debts ─────────────────────────────────────────────────────────
    $router->get('/v1/projects/:id/treasura/debts/mine', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $debts = $pTable($projectId, 'debts'); $treasuries = $pTable($projectId, 'treasuries'); $requests = $pTable($projectId, 'requests');
        $stmt = $pdo->prepare("
            SELECT d.*, t.name AS treasury_name, r.note AS request_note FROM `$debts` d
            JOIN `$treasuries` t ON t.id = d.treasury_id JOIN `$requests` r ON r.id = d.source_request_id
            WHERE t.client_id = ? AND d.status != 'CLEARED' ORDER BY d.created_at DESC
        ");
        $stmt->execute([$userId]);
        json_out(['debts' => $stmt->fetchAll()]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/debts/client/:cid', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $cid = (int)$req['params']['cid'];
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships');
        $chk = $pdo->prepare("SELECT id FROM `$rel` WHERE treasurer_id = ? AND client_id = ? AND status = 'ACTIVE'");
        $chk->execute([$userId, $cid]);
        if (!$chk->fetch()) abort(404, 'Not found');
        $debts = $pTable($projectId, 'debts'); $treasuries = $pTable($projectId, 'treasuries'); $requests = $pTable($projectId, 'requests');
        $stmt = $pdo->prepare("
            SELECT d.*, t.name AS treasury_name, r.note AS request_note FROM `$debts` d
            JOIN `$treasuries` t ON t.id = d.treasury_id JOIN `$requests` r ON r.id = d.source_request_id
            WHERE t.client_id = ? AND d.status != 'CLEARED' ORDER BY d.created_at DESC
        ");
        $stmt->execute([$cid]);
        json_out(['debts' => $stmt->fetchAll()]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/debts/treasury/:tid', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $requireTreasuraUser($req);
        $tid = (int)$req['params']['tid'];
        $pdo = \App::get('db'); $debts = $pTable($projectId, 'debts'); $requests = $pTable($projectId, 'requests');
        $stmt = $pdo->prepare("
            SELECT d.*, r.note AS request_note, r.created_at AS incurred_at FROM `$debts` d JOIN `$requests` r ON r.id = d.source_request_id
            WHERE d.treasury_id = ? ORDER BY d.created_at DESC
        ");
        $stmt->execute([$tid]);
        json_out(['debts' => $stmt->fetchAll()]);
    }, ['auth_middleware']);

    // ── Notifications ─────────────────────────────────────────────────
    $router->get('/v1/projects/:id/treasura/notifications', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $notifTable = $pTable($projectId, 'notifications');
        $stmt = $pdo->prepare("SELECT * FROM `$notifTable` WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([$userId]);
        $items = $stmt->fetchAll();
        $u = $pdo->prepare("SELECT COUNT(*) FROM `$notifTable` WHERE user_id = ? AND is_read = 0");
        $u->execute([$userId]);
        json_out(['notifications' => $items, 'unread_count' => (int)$u->fetchColumn()]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/notifications/unread-count', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $notifTable = $pTable($projectId, 'notifications');
        $u = $pdo->prepare("SELECT COUNT(*) FROM `$notifTable` WHERE user_id = ? AND is_read = 0");
        $u->execute([$userId]);
        json_out(['unread_count' => (int)$u->fetchColumn()]);
    }, ['auth_middleware']);

    $router->post('/v1/projects/:id/treasura/notifications/read-all', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $notifTable = $pTable($projectId, 'notifications');
        $pdo->prepare("UPDATE `$notifTable` SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$userId]);
        json_out(['message' => 'All marked read']);
    }, ['auth_middleware']);

    $router->patch('/v1/projects/:id/treasura/notifications/:nid', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $nid = (int)$req['params']['nid'];
        $pdo = \App::get('db'); $notifTable = $pTable($projectId, 'notifications');
        $pdo->prepare("UPDATE `$notifTable` SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$nid, $userId]);
        json_out(['message' => 'Marked read']);
    }, ['auth_middleware']);
}
