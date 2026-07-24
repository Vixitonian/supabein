<?php

declare(strict_types=1);

// Treasurer relationships (invite/accept/resign/revoke), the treasurer-
// assisted account reset (snapshot + wipe), and registration's one bit of
// custom behavior (auto-activating a pending invite addressed to the new
// email). Ported from Treasura's relationships.*/reset.handler.php.
function register_treasura_relationships_routes(\SupaBein\Router $router, \Closure $requireTreasuraUser, \Closure $pTable, \Closure $notify): void
{
    // ── POST /v1/projects/:id/treasura/register ─────────────────────────
    // The generic POST /v1/data/:id/users endpoint already creates the row
    // and auto-hashes password_hash (it's a PASSWORD-typed column) -- this
    // wraps that with Treasura's one real side effect: activating any
    // treasurer invite already sitting PENDING for this email, and logging
    // the new user straight in (mirrors Treasura's own register response).
    $router->post('/v1/projects/:id/treasura/register', function (array $req) use ($pTable): void {
        $projectId = (int)$req['params']['id'];
        $name = trim((string)($req['body']['name'] ?? ''));
        $email = strtolower(trim((string)($req['body']['email'] ?? '')));
        $password = (string)($req['body']['password'] ?? '');
        $errors = [];
        if (strlen($name) < 2) $errors['name'] = 'Name must be at least 2 characters';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'A valid email address is required';
        if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters';
        if ($errors) json_out(['errors' => $errors], 422);

        $pdo = \App::get('db');
        $users = $pTable($projectId, 'users');
        $chk = $pdo->prepare("SELECT id FROM `$users` WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) json_out(['errors' => ['email' => 'This email is already registered']], 422);

        $pdo->prepare("INSERT INTO `$users` (name, email, password_hash, currency) VALUES (?, ?, ?, ?)")
            ->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), $req['body']['currency'] ?? 'NGN']);
        $userId = (int)$pdo->lastInsertId();

        $rel = $pTable($projectId, 'treasurer_relationships');
        $pdo->prepare("UPDATE `$rel` SET treasurer_id = ?, status = 'ACTIVE' WHERE invite_email = ? AND status = 'PENDING'")
            ->execute([$userId, $email]);

        $config = \App::get('config');
        $now = time();
        $token = \Firebase\JWT\JWT::encode(
            ['sub' => $userId, 'pid' => $projectId, 'table' => 'users', 'type' => 'project_user', 'iat' => $now, 'exp' => $now + (int)($config['PROJECT_USER_JWT_TTL'] ?? 2592000)],
            $config['JWT_SECRET'], $config['JWT_ALGO']
        );
        json_out(['message' => 'Registered successfully', 'token' => $token, 'user' => ['id' => $userId, 'name' => $name, 'email' => $email]], 201);
    });

    // ── Relationships ────────────────────────────────────────────────
    $router->get('/v1/projects/:id/treasura/relationships/mine', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $users = $pTable($projectId, 'users');
        $stmt = $pdo->prepare("
            SELECT r.*, u.name AS treasurer_name, u.email AS treasurer_email FROM `$rel` r
            LEFT JOIN `$users` u ON u.id = r.treasurer_id
            WHERE r.client_id = ? AND r.status != 'REVOKED' ORDER BY r.created_at DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        json_out(['relationship' => $stmt->fetch() ?: null]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/relationships/incoming', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $email = $req['auth']['email'] ?? '';
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $users = $pTable($projectId, 'users');
        // The project_user JWT doesn't carry email, so look it up.
        $eStmt = $pdo->prepare("SELECT email FROM `$users` WHERE id = ?");
        $eStmt->execute([$userId]);
        $email = (string)$eStmt->fetchColumn();
        $stmt = $pdo->prepare("
            SELECT r.*, c.name AS client_name, c.email AS client_email FROM `$rel` r JOIN `$users` c ON c.id = r.client_id
            WHERE r.status = 'PENDING' AND (r.treasurer_id = ? OR LOWER(r.invite_email) = LOWER(?)) ORDER BY r.created_at DESC
        ");
        $stmt->execute([$userId, $email]);
        json_out(['invites' => $stmt->fetchAll()]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/relationships/pending-count', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $requests = $pTable($projectId, 'requests'); $treasuries = $pTable($projectId, 'treasuries'); $rel = $pTable($projectId, 'treasurer_relationships');
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM `$requests` r LEFT JOIN `$treasuries` t ON t.id = r.treasury_id
            JOIN `$rel` rr ON rr.client_id = COALESCE(t.client_id, r.initiator_id) AND rr.treasurer_id = ? AND rr.status = 'ACTIVE'
            WHERE r.status = 'PENDING'
        ");
        $stmt->execute([$userId]);
        json_out(['count' => (int)$stmt->fetchColumn()]);
    }, ['auth_middleware']);

    $router->post('/v1/projects/:id/treasura/relationships/:rid/respond', function (array $req) use ($requireTreasuraUser, $pTable, $notify): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $rid = (int)$req['params']['rid'];
        $accept = (bool)($req['body']['accept'] ?? false);
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $users = $pTable($projectId, 'users'); $notifTable = $pTable($projectId, 'notifications');

        $rStmt = $pdo->prepare("SELECT r.*, c.name AS my_name FROM `$rel` r JOIN `$users` c ON c.id = r.client_id WHERE r.id = ?");
        $rStmt->execute([$rid]);
        $relRow = $rStmt->fetch();
        $meStmt = $pdo->prepare("SELECT name, email FROM `$users` WHERE id = ?");
        $meStmt->execute([$userId]);
        $me = $meStmt->fetch();
        if (!$relRow || $relRow['status'] !== 'PENDING') abort(404, 'Invite not found or already handled');
        if ((int)($relRow['treasurer_id'] ?? 0) !== $userId && strtolower($relRow['invite_email']) !== strtolower($me['email'] ?? '')) {
            abort(403, 'This invite is not addressed to you');
        }
        if ($accept) {
            $pdo->prepare("UPDATE `$rel` SET treasurer_id = ?, status = 'ACTIVE' WHERE id = ?")->execute([$userId, $rid]);
            $notify($pdo, $notifTable, (int)$relRow['client_id'], 'TREASURER_ACCEPTED', $me['name'] . ' is now your Treasurer', $me['name'] . ' accepted your invitation.', '#/my-treasurer');
            json_out(['message' => 'Invitation accepted']);
        } else {
            $pdo->prepare("UPDATE `$rel` SET status = 'REVOKED' WHERE id = ?")->execute([$rid]);
            $notify($pdo, $notifTable, (int)$relRow['client_id'], 'TREASURER_DECLINED', $me['name'] . ' declined your Treasurer invite', 'You can invite someone else from your Treasurer page.', '#/my-treasurer');
            json_out(['message' => 'Invitation declined']);
        }
    }, ['auth_middleware']);

    $router->post('/v1/projects/:id/treasura/relationships/:rid/resign', function (array $req) use ($requireTreasuraUser, $pTable, $notify): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $rid = (int)$req['params']['rid'];
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $users = $pTable($projectId, 'users'); $notifTable = $pTable($projectId, 'notifications');
        $rStmt = $pdo->prepare("SELECT * FROM `$rel` WHERE id = ?");
        $rStmt->execute([$rid]);
        $relRow = $rStmt->fetch();
        if (!$relRow || (int)$relRow['treasurer_id'] !== $userId || $relRow['status'] !== 'ACTIVE') abort(404, 'Relationship not found');
        $pdo->prepare("UPDATE `$rel` SET status = 'REVOKED' WHERE id = ?")->execute([$rid]);
        $meStmt = $pdo->prepare("SELECT name FROM `$users` WHERE id = ?"); $meStmt->execute([$userId]); $meName = $meStmt->fetchColumn();
        $notify($pdo, $notifTable, (int)$relRow['client_id'], 'TREASURER_RESIGNED', $meName . ' resigned as your Treasurer', 'You can invite a new Treasurer from your Treasurer page.', '#/my-treasurer');
        json_out(['message' => 'You have resigned as treasurer']);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/relationships/my-clients', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $users = $pTable($projectId, 'users'); $treasuries = $pTable($projectId, 'treasuries'); $requests = $pTable($projectId, 'requests');
        $stmt = $pdo->prepare("
            SELECT r.*, u.name AS client_name, u.email AS client_email, u.unallocated_balance FROM `$rel` r
            JOIN `$users` u ON u.id = r.client_id WHERE r.treasurer_id = ? AND r.status = 'ACTIVE'
        ");
        $stmt->execute([$userId]);
        $clients = $stmt->fetchAll();
        foreach ($clients as &$client) {
            $bStmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM `$treasuries` WHERE client_id = ? AND is_archived = 0");
            $bStmt->execute([$client['client_id']]);
            $client['total_balance'] = (int)$bStmt->fetchColumn();
            $pStmt = $pdo->prepare("
                SELECT COUNT(*) FROM `$requests` r LEFT JOIN `$treasuries` t ON t.id = r.treasury_id
                WHERE COALESCE(t.client_id, r.initiator_id) = ? AND r.status IN ('PENDING','TREASURER_CONFIRMED')
            ");
            $pStmt->execute([$client['client_id']]);
            $client['pending_count'] = (int)$pStmt->fetchColumn();
        }
        unset($client);
        json_out(['clients' => $clients]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/relationships/my-clients/:cid/treasuries', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $cid = (int)$req['params']['cid'];
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $treasuries = $pTable($projectId, 'treasuries'); $debts = $pTable($projectId, 'debts');
        $chk = $pdo->prepare("SELECT id FROM `$rel` WHERE treasurer_id = ? AND client_id = ? AND status = 'ACTIVE'");
        $chk->execute([$userId, $cid]);
        if (!$chk->fetch()) abort(404, 'Not found');
        $stmt = $pdo->prepare("
            SELECT t.id, t.name, t.description, t.balance,
                   COALESCE((SELECT SUM(original_amount - amount_repaid) FROM `$debts` WHERE treasury_id = t.id AND status != 'CLEARED'), 0) AS total_debt
            FROM `$treasuries` t WHERE t.client_id = ? AND t.is_archived = 0 ORDER BY t.name ASC
        ");
        $stmt->execute([$cid]);
        json_out(['treasuries' => $stmt->fetchAll()]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/relationships/my-clients/:cid/treasuries/:tid', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $cid = (int)$req['params']['cid']; $tid = (int)$req['params']['tid'];
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $treasuries = $pTable($projectId, 'treasuries'); $debts = $pTable($projectId, 'debts'); $requests = $pTable($projectId, 'requests');
        $chk = $pdo->prepare("SELECT id FROM `$rel` WHERE treasurer_id = ? AND client_id = ? AND status = 'ACTIVE'");
        $chk->execute([$userId, $cid]);
        if (!$chk->fetch()) abort(404, 'Not found');
        $tStmt = $pdo->prepare("SELECT * FROM `$treasuries` WHERE id = ? AND client_id = ? AND is_archived = 0");
        $tStmt->execute([$tid, $cid]);
        $treasury = $tStmt->fetch();
        if (!$treasury) abort(404, 'Not found');
        $dStmt = $pdo->prepare("SELECT d.*, r.note AS request_note FROM `$debts` d JOIN `$requests` r ON r.id = d.source_request_id WHERE d.treasury_id = ? AND d.status != 'CLEARED' ORDER BY d.created_at DESC");
        $dStmt->execute([$tid]);
        $rStmt = $pdo->prepare("SELECT r.*, tt.name AS target_treasury_name FROM `$requests` r LEFT JOIN `$treasuries` tt ON tt.id = r.target_treasury_id WHERE r.treasury_id = ? ORDER BY r.created_at DESC LIMIT 50");
        $rStmt->execute([$tid]);
        json_out(['treasury' => $treasury, 'debts' => $dStmt->fetchAll(), 'requests' => $rStmt->fetchAll()]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/relationships/invite/:token', function (array $req) use ($pTable): void {
        $projectId = (int)$req['params']['id']; $token = (string)$req['params']['token'];
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $users = $pTable($projectId, 'users');
        $stmt = $pdo->prepare("SELECT r.*, u.name AS client_name, u.email AS client_email FROM `$rel` r JOIN `$users` u ON u.id = r.client_id WHERE r.invite_token = ?");
        $stmt->execute([$token]);
        $relRow = $stmt->fetch();
        if (!$relRow) abort(404, 'Invalid or expired invite link');
        json_out(['relationship' => $relRow]);
    });

    $router->post('/v1/projects/:id/treasura/relationships/invite/:token/accept', function (array $req) use ($requireTreasuraUser, $pTable, $notify): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $token = (string)$req['params']['token'];
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $users = $pTable($projectId, 'users'); $notifTable = $pTable($projectId, 'notifications');
        $stmt = $pdo->prepare("SELECT r.*, u.name AS client_name FROM `$rel` r JOIN `$users` u ON u.id = r.client_id WHERE r.invite_token = ?");
        $stmt->execute([$token]);
        $relRow = $stmt->fetch();
        if (!$relRow) abort(404, 'Invalid invite link');
        if ($relRow['status'] !== 'PENDING') abort(400, 'This invite has already been used');
        $meStmt = $pdo->prepare("SELECT name, email FROM `$users` WHERE id = ?"); $meStmt->execute([$userId]); $me = $meStmt->fetch();
        if (strtolower($relRow['invite_email']) !== strtolower($me['email'] ?? '')) abort(403, 'This invite was sent to a different email address');
        $pdo->prepare("UPDATE `$rel` SET treasurer_id = ?, status = 'ACTIVE' WHERE invite_token = ? AND status = 'PENDING'")->execute([$userId, $token]);
        $notify($pdo, $notifTable, (int)$relRow['client_id'], 'TREASURER_ACCEPTED', $me['name'] . ' is now your Treasurer', $me['name'] . ' accepted your invitation.', '#/my-treasurer');
        json_out(['message' => 'You are now the Treasurer for ' . $relRow['client_name']]);
    }, ['auth_middleware']);

    $router->post('/v1/projects/:id/treasura/relationships', function (array $req) use ($requireTreasuraUser, $pTable, $notify): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $email = strtolower(trim((string)($req['body']['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['errors' => ['email' => 'A valid email is required']], 422);

        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $users = $pTable($projectId, 'users'); $notifTable = $pTable($projectId, 'notifications');
        $exStmt = $pdo->prepare("SELECT * FROM `$rel` WHERE client_id = ? AND status != 'REVOKED' ORDER BY created_at DESC LIMIT 1");
        $exStmt->execute([$userId]);
        $existing = $exStmt->fetch();
        if ($existing && $existing['status'] === 'ACTIVE') abort(400, 'You already have an active Treasurer. Revoke it first.');

        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO `$rel` (client_id, invite_email, invite_token, status) VALUES (?, ?, ?, 'PENDING')")->execute([$userId, $email, $token]);
        $id = (int)$pdo->lastInsertId();

        $euStmt = $pdo->prepare("SELECT id FROM `$users` WHERE email = ?");
        $euStmt->execute([$email]);
        $existingUserId = $euStmt->fetchColumn();
        if ($existingUserId) {
            $pdo->prepare("UPDATE `$rel` SET treasurer_id = ? WHERE id = ?")->execute([(int)$existingUserId, $id]);
            $meStmt = $pdo->prepare("SELECT name FROM `$users` WHERE id = ?"); $meStmt->execute([$userId]); $meName = $meStmt->fetchColumn();
            $notify($pdo, $notifTable, (int)$existingUserId, 'INVITE', $meName . ' invited you to be their Treasurer', 'Review and accept the invitation to start managing their treasuries.', '#/treasuring-for');
        }
        json_out(['message' => 'Invite created', 'token' => $token, 'id' => $id], 201);
    }, ['auth_middleware']);

    $router->delete('/v1/projects/:id/treasura/relationships/:rid', function (array $req) use ($requireTreasuraUser, $pTable, $notify): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $rid = (int)$req['params']['rid'];
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $users = $pTable($projectId, 'users'); $notifTable = $pTable($projectId, 'notifications');
        $rStmt = $pdo->prepare("SELECT * FROM `$rel` WHERE id = ?");
        $rStmt->execute([$rid]);
        $relRow = $rStmt->fetch();
        $pdo->prepare("UPDATE `$rel` SET status = 'REVOKED' WHERE id = ? AND client_id = ?")->execute([$rid, $userId]);
        if ($relRow && $relRow['treasurer_id'] && (int)$relRow['client_id'] === $userId) {
            $meStmt = $pdo->prepare("SELECT name FROM `$users` WHERE id = ?"); $meStmt->execute([$userId]); $meName = $meStmt->fetchColumn();
            $notify($pdo, $notifTable, (int)$relRow['treasurer_id'], 'RELATIONSHIP_REVOKED', $meName . ' removed you as their Treasurer', null, '#/treasuring-for');
        }
        json_out(['message' => 'Treasurer relationship revoked']);
    }, ['auth_middleware']);

    // ── Treasurer-assisted account reset ────────────────────────────────
    $router->post('/v1/projects/:id/treasura/reset/request', function (array $req) use ($requireTreasuraUser, $pTable, $notify): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $rel = $pTable($projectId, 'treasurer_relationships'); $reset = $pTable($projectId, 'reset_requests'); $notifTable = $pTable($projectId, 'notifications'); $users = $pTable($projectId, 'users');
        $tStmt = $pdo->prepare("SELECT treasurer_id FROM `$rel` WHERE client_id = ? AND status = 'ACTIVE' LIMIT 1");
        $tStmt->execute([$userId]);
        $treasurerId = $tStmt->fetchColumn();
        if (!$treasurerId) abort(400, 'You need an active treasurer to reset your account.');

        $pdo->prepare("UPDATE `$reset` SET status = 'CANCELLED' WHERE client_id = ? AND status = 'PENDING'")->execute([$userId]);
        $code = strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare("INSERT INTO `$reset` (client_id, treasurer_id, code_hash, status) VALUES (?, ?, ?, 'PENDING')")
            ->execute([$userId, (int)$treasurerId, password_hash($code, PASSWORD_BCRYPT)]);
        $id = (int)$pdo->lastInsertId();

        $meStmt = $pdo->prepare("SELECT name FROM `$users` WHERE id = ?"); $meStmt->execute([$userId]); $meName = $meStmt->fetchColumn();
        $notify($pdo, $notifTable, (int)$treasurerId, 'RESET_REQUESTED', $meName . ' requested an account reset',
            'They will give you a code to approve. Granting wipes their history and balances (treasuries are kept).', '#/client?clientId=' . $userId);
        json_out(['message' => 'Reset requested', 'id' => $id, 'code' => $code], 201);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/reset/mine', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $reset = $pTable($projectId, 'reset_requests');
        $stmt = $pdo->prepare("SELECT id, status, created_at FROM `$reset` WHERE client_id = ? AND status = 'PENDING' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$userId]);
        json_out(['reset' => $stmt->fetch() ?: null]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/reset/incoming', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $reset = $pTable($projectId, 'reset_requests'); $users = $pTable($projectId, 'users');
        $stmt = $pdo->prepare("
            SELECT rr.id, rr.client_id, rr.created_at, u.name AS client_name FROM `$reset` rr JOIN `$users` u ON u.id = rr.client_id
            WHERE rr.treasurer_id = ? AND rr.status = 'PENDING' ORDER BY rr.created_at DESC
        ");
        $stmt->execute([$userId]);
        json_out(['resets' => $stmt->fetchAll()]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/reset/archives', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $pdo = \App::get('db'); $archives = $pTable($projectId, 'archives');
        $stmt = $pdo->prepare("SELECT id, label, created_at FROM `$archives` WHERE client_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        json_out(['archives' => $stmt->fetchAll()]);
    }, ['auth_middleware']);

    $router->get('/v1/projects/:id/treasura/reset/archives/:aid/download', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $aid = (int)$req['params']['aid'];
        $pdo = \App::get('db'); $archives = $pTable($projectId, 'archives');
        $stmt = $pdo->prepare("SELECT label, data, created_at FROM `$archives` WHERE id = ? AND client_id = ?");
        $stmt->execute([$aid, $userId]);
        $row = $stmt->fetch();
        if (!$row) abort(404, 'Archive not found');
        json_out(['label' => $row['label'], 'created_at' => $row['created_at'], 'data' => json_decode($row['data'], true)]);
    }, ['auth_middleware']);

    $router->post('/v1/projects/:id/treasura/reset/:resid/cancel', function (array $req) use ($requireTreasuraUser, $pTable): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $resid = (int)$req['params']['resid'];
        $pdo = \App::get('db'); $reset = $pTable($projectId, 'reset_requests');
        $pdo->prepare("UPDATE `$reset` SET status = 'CANCELLED' WHERE id = ? AND client_id = ? AND status = 'PENDING'")->execute([$resid, $userId]);
        json_out(['message' => 'Reset cancelled']);
    }, ['auth_middleware']);

    $router->post('/v1/projects/:id/treasura/reset/:resid/grant', function (array $req) use ($requireTreasuraUser, $pTable, $notify): void {
        $projectId = (int)$req['params']['id']; $userId = $requireTreasuraUser($req);
        $resid = (int)$req['params']['resid'];
        $pdo = \App::get('db');
        $reset = $pTable($projectId, 'reset_requests'); $users = $pTable($projectId, 'users'); $treasuries = $pTable($projectId, 'treasuries');
        $requests = $pTable($projectId, 'requests'); $debts = $pTable($projectId, 'debts'); $archives = $pTable($projectId, 'archives'); $notifTable = $pTable($projectId, 'notifications');

        $stmt = $pdo->prepare("SELECT rr.*, u.name AS client_name FROM `$reset` rr JOIN `$users` u ON u.id = rr.client_id WHERE rr.id = ?");
        $stmt->execute([$resid]);
        $rr = $stmt->fetch();
        if (!$rr || $rr['status'] !== 'PENDING') abort(404, 'Reset request not found');
        if ((int)$rr['treasurer_id'] !== $userId) abort(403, 'This reset is not yours to grant');
        $code = strtoupper(trim((string)($req['body']['code'] ?? '')));
        if ($code === '' || !password_verify($code, $rr['code_hash'])) abort(403, 'Incorrect reset code');

        $clientId = (int)$rr['client_id'];
        $pdo->beginTransaction();
        try {
            $tr = $pdo->prepare("SELECT id, name, description, balance, currency, is_archived, created_at FROM `$treasuries` WHERE client_id = ?");
            $tr->execute([$clientId]);
            $treasuriesSnap = $tr->fetchAll();
            $rq = $pdo->prepare("SELECT * FROM `$requests` WHERE initiator_id = ? ORDER BY created_at ASC");
            $rq->execute([$clientId]);
            $requestsSnap = $rq->fetchAll();
            $db = $pdo->prepare("SELECT d.* FROM `$debts` d JOIN `$treasuries` t ON t.id = d.treasury_id WHERE t.client_id = ?");
            $db->execute([$clientId]);
            $debtsSnap = $db->fetchAll();
            $ub = $pdo->prepare("SELECT unallocated_balance FROM `$users` WHERE id = ?");
            $ub->execute([$clientId]);
            $unallocated = (int)$ub->fetchColumn();

            $snapshot = ['generated_at' => date('c'), 'client' => ['id' => $clientId, 'name' => $rr['client_name']],
                'unallocated_balance' => $unallocated, 'treasuries' => $treasuriesSnap, 'requests' => $requestsSnap, 'debts' => $debtsSnap];
            $pdo->prepare("INSERT INTO `$archives` (client_id, label, data) VALUES (?, ?, ?)")
                ->execute([$clientId, 'Reset ' . date('Y-m-d H:i'), json_encode($snapshot, JSON_UNESCAPED_SLASHES)]);

            $pdo->prepare("UPDATE `$requests` SET debt_id = NULL WHERE initiator_id = ?")->execute([$clientId]);
            $pdo->prepare("DELETE FROM `$debts` WHERE treasury_id IN (SELECT id FROM `$treasuries` WHERE client_id = ?)")->execute([$clientId]);
            $pdo->prepare("DELETE FROM `$requests` WHERE initiator_id = ?")->execute([$clientId]);
            $pdo->prepare("UPDATE `$treasuries` SET balance = 0 WHERE client_id = ?")->execute([$clientId]);
            $pdo->prepare("UPDATE `$users` SET unallocated_balance = 0 WHERE id = ?")->execute([$clientId]);
            $pdo->prepare("UPDATE `$reset` SET status = 'COMPLETED', completed_at = NOW() WHERE id = ?")->execute([$resid]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $notify($pdo, $notifTable, $clientId, 'RESET_COMPLETED', 'Your account was reset',
            'History and balances were cleared; your treasuries were kept. A downloadable archive is in Account.', '#/settings');
        $notify($pdo, $notifTable, $userId, 'RESET_COMPLETED', 'Reset completed for ' . $rr['client_name'], null, '#/treasuring-for');
        json_out(['message' => 'Account reset complete']);
    }, ['auth_middleware']);
}
