<?php

declare(strict_types=1);



function ai_sanitize_plan(array $plan): array
{
    // ── Column type normalization ─────────────────────────────────────────────
    static $TYPE_MAP = [
        'string'             => 'VARCHAR(255)',
        'varchar'            => 'VARCHAR(255)',
        'character varying'  => 'VARCHAR(255)',
        'char'               => 'VARCHAR(255)',
        'integer'            => 'INT',
        'int unsigned'       => 'INT',
        'int(11)'            => 'INT',
        'serial'             => 'INT',
        'bigserial'          => 'BIGINT',
        'bool'               => 'BOOLEAN',
        'number'             => 'DECIMAL(10,2)',
        'numeric'            => 'DECIMAL(10,2)',
        'decimal'            => 'DECIMAL(10,2)',
        'money'              => 'DECIMAL(10,2)',
        'uuid'               => 'VARCHAR(36)',
        'real'               => 'FLOAT',
        'long text'          => 'LONGTEXT',
        'medium text'        => 'MEDIUMTEXT',
    ];

    // ── SQL reserved words that models use as table/column names ─────────────
    static $SQL_RESERVED = [
        'order','group','key','index','select','insert','update','delete',
        'table','from','where','join','left','right','inner','outer','on',
        'by','as','in','is','not','null','and','or','like','limit','offset',
        'having','union','all','distinct','case','when','then','else','end',
        'create','drop','alter','add','column','primary','foreign','references',
        'default','check','unique','constraint','auto_increment','values','set',
        'into','exists','between','any','some','user','value','read','write',
        'status','rank','role','type','name','date','time','year','month',
    ];

    // ── api_role normalization ────────────────────────────────────────────────
    static $ROLE_MAP = [
        'user'       => 'authenticated',
        'users'      => 'authenticated',
        'public'     => 'anon',
        'guest'      => 'anon',
        'admin'      => 'authenticated',
        'private'    => 'authenticated',
        'logged_in'  => 'authenticated',
        'loggedin'   => 'authenticated',
        'member'     => 'authenticated',
    ];

    // ── operation normalization ───────────────────────────────────────────────
    static $OP_MAP = [
        'read'    => 'SELECT',
        'get'     => 'SELECT',
        'list'    => 'SELECT',
        'fetch'   => 'SELECT',
        'write'   => 'INSERT',
        'create'  => 'INSERT',
        'add'     => 'INSERT',
        'post'    => 'INSERT',
        'edit'    => 'UPDATE',
        'modify'  => 'UPDATE',
        'patch'   => 'UPDATE',
        'put'     => 'UPDATE',
        'change'  => 'UPDATE',
        'remove'  => 'DELETE',
        'destroy' => 'DELETE',
        'erase'   => 'DELETE',
    ];

    // ── SQL function defaults that must be nulled ─────────────────────────────
    static $FN_DEFAULTS = [
        'now()', 'current_timestamp', 'current_date', 'current_time',
        'sysdate()', 'getdate()', 'uuid_generate_v4()', 'gen_random_uuid()',
        'newid()', 'uuid()',
    ];

    // ── 1. Subdomain ──────────────────────────────────────────────────────────
    if (isset($plan['subdomain'])) {
        $sub = strtolower((string)$plan['subdomain']);
        $sub = preg_replace('/[^a-z0-9]+/', '-', $sub);   // non-alphanum → hyphen
        $sub = preg_replace('/-+/', '-', $sub);             // collapse hyphens
        $sub = trim($sub, '-');
        $sub = substr($sub, 0, 30);
        $sub = trim($sub, '-');
        if (strlen($sub) < 3) $sub = 'app-' . $sub;
        $plan['subdomain'] = $sub;
    }

    // ── 2. Tables ─────────────────────────────────────────────────────────────
    foreach (($plan['tables'] ?? []) as &$table) {

        // Rename reserved table names
        if (in_array(strtolower($table['name'] ?? ''), $SQL_RESERVED, true)) {
            $table['name'] = $table['name'] . '_data';
        }

        // Strip auto-generated columns
        $table['columns'] = array_values(array_filter(
            $table['columns'] ?? [],
            fn($col) => !in_array(strtolower($col['name'] ?? ''), ['id', 'created_at'], true)
        ));

        foreach ($table['columns'] as &$col) {
            // Rename reserved column names
            if (in_array(strtolower($col['name'] ?? ''), $SQL_RESERVED, true)) {
                $col['name'] = $col['name'] . '_value';
            }

            // Normalize type
            $typeLower = strtolower(trim($col['type'] ?? ''));
            if (isset($TYPE_MAP[$typeLower])) {
                $col['type'] = $TYPE_MAP[$typeLower];
            }

            // Null out SQL function defaults
            if (isset($col['default'])) {
                $defLower = strtolower(trim((string)$col['default']));
                if (in_array($defLower, $FN_DEFAULTS, true)) {
                    $col['default'] = null;
                }
            }
        }
        unset($col);

        foreach ($table['policies'] ?? [] as &$policy) {
            // Normalize api_role
            $role = strtolower(trim($policy['api_role'] ?? ''));
            $policy['api_role'] = $ROLE_MAP[$role] ?? $policy['api_role'];

            // Normalize operation
            $op = strtolower(trim($policy['operation'] ?? ''));
            $policy['operation'] = strtoupper($OP_MAP[$op] ?? $policy['operation']);

            // Replace auth.uid() / uid() with :current_user_id in constraint_sql
            if (isset($policy['constraint_sql']) && is_string($policy['constraint_sql'])) {
                $policy['constraint_sql'] = preg_replace(
                    '/\bauth\.uid\s*\(\s*\)|\buid\s*\(\s*\)/i',
                    ':current_user_id',
                    $policy['constraint_sql']
                );
            }
        }
        unset($policy);
    }
    unset($table);

    // ── 3. Frontend file paths — strip leading slashes / ./ ──────────────────
    foreach ($plan['frontend']['files'] ?? [] as &$file) {
        $file['path'] = ltrim(preg_replace('#^\./+#', '', $file['path'] ?? ''), '/');
    }
    unset($file);

    return $plan;
}

function ai_validate_plan(array $plan): ?string
{
    if (empty($plan['project_name']) || strlen($plan['project_name']) > 80) {
        return 'project_name missing or too long';
    }
    if (!isset($plan['subdomain']) || !preg_match('/^[a-z0-9][a-z0-9\-]{1,28}[a-z0-9]$/', $plan['subdomain'])) {
        return 'subdomain must be 3-30 lowercase alphanumeric + hyphens';
    }
    if (empty($plan['tables']) || !is_array($plan['tables'])) {
        return 'tables array is required';
    }
    foreach ($plan['tables'] as $i => $t) {
        try {
            \SupaBein\Schema::validateIdentifier($t['name'] ?? '');
        } catch (\InvalidArgumentException $e) {
            return "tables[$i].name: " . $e->getMessage();
        }
        foreach ($t['columns'] ?? [] as $j => $col) {
            try {
                $colName = \SupaBein\Schema::validateIdentifier($col['name'] ?? '');
            } catch (\InvalidArgumentException $e) {
                return "tables[$i].columns[$j].name: " . $e->getMessage();
            }

            try {
                \SupaBein\Schema::validateDataType($col['type'] ?? '');
            } catch (\InvalidArgumentException $e) {
                return "tables[$i].columns[$j].type: " . $e->getMessage();
            }
        }
        foreach ($t['policies'] ?? [] as $k => $p) {
            if (!in_array($p['api_role'] ?? '', ['anon', 'authenticated'], true)) {
                return "tables[$i].policies[$k].api_role must be 'anon' or 'authenticated'";
            }
            if (!in_array(strtoupper($p['operation'] ?? ''), ['SELECT','INSERT','UPDATE','DELETE'], true)) {
                return "tables[$i].policies[$k].operation must be SELECT, INSERT, UPDATE, or DELETE";
            }
        }
    }

    // ── Cross-pass auth coherence: :current_user_id requires a PASSWORD column ──
    // Without a PASSWORD column the platform cannot issue a login token, so any
    // owner-scoped policy is permanently unsatisfiable and the app is dead on arrival.
    $hasPassword     = false;
    $usesCurrentUser = false;
    foreach ($plan['tables'] as $t) {
        foreach ($t['columns'] ?? [] as $c) {
            if (strtoupper(trim((string)($c['type'] ?? ''))) === 'PASSWORD') $hasPassword = true;
        }
        foreach ($t['policies'] ?? [] as $p) {
            if (!empty($p['constraint_sql']) && str_contains((string)$p['constraint_sql'], ':current_user_id')) {
                $usesCurrentUser = true;
            }
        }
    }
    if ($usesCurrentUser && !$hasPassword) {
        return 'policies use :current_user_id but no table has a PASSWORD column, so login is '
             . 'impossible and every owner-scoped table is unreachable. Add a users table with a '
             . 'PASSWORD column (e.g. email VARCHAR(255), password PASSWORD) and reference it via user_id.';
    }

    // ── Cross-pass reachability: a table with UPDATE allowed but INSERT allowed
    // for no role, and no seeded rows, can never have a row to update at all —
    // not a style guess, a hard fact about the plan: there is no path through
    // the real API that could ever create one. Live-observed root cause of a
    // build whose frontend correctly wrote code to read/update a "counter"
    // table, deployed clean, then failed every story because the table was
    // permanently empty and nothing (including the frontend's own defensive
    // create-if-missing fallback) was ever allowed to seed it.
    foreach ($plan['tables'] as $t) {
        $tableName = (string)($t['name'] ?? '?');
        $hasSeed = !empty($plan['seed_data'][$tableName]);
        if ($hasSeed) continue;
        $updateAllowed = false;
        $insertAllowedAnywhere = false;
        foreach ($t['policies'] ?? [] as $p) {
            $op = strtoupper((string)($p['operation'] ?? ''));
            if ($op === 'UPDATE' && !empty($p['allowed'])) $updateAllowed = true;
            if ($op === 'INSERT' && !empty($p['allowed'])) $insertAllowedAnywhere = true;
        }
        if ($updateAllowed && !$insertAllowedAnywhere) {
            return "table '{$tableName}' allows UPDATE but no role is allowed to INSERT into it, and it has "
                 . 'no seed_data — this table can never have a row for anyone to update. Either seed at least '
                 . "one starting row for '{$tableName}' in seed_data, or grant INSERT to whichever role should "
                 . 'be able to create its rows.';
        }
    }

    return null;
}

/**
 * Validate an EDIT delta against the existing schema so a one-shot retry has something
 * concrete to feed back. Catches the silent edit-killers: invalid column types, re-adding
 * a table/column that already exists (DDL duplicate → non-fatal skip → the user's change
 * just never appears), and targeting a table that doesn't exist. Pure-frontend deltas
 * (empty add_* arrays) are valid. Returns an error string or null.
 */
function ai_validate_delta(array $delta, array $existingSchema): ?string
{
    // Index existing tables/columns (lowercased) for collision checks.
    $existing = [];
    foreach ($existingSchema['tables'] ?? [] as $t) {
        $tn = strtolower((string)($t['name'] ?? ''));
        if ($tn === '') continue;
        $existing[$tn] = [];
        foreach ($t['columns'] ?? [] as $c) {
            $existing[$tn][strtolower((string)($c['name'] ?? ''))] = true;
        }
    }

    // Tables being added in THIS delta (so add_columns may target them).
    $newTables = [];
    foreach ($delta['add_tables'] ?? [] as $i => $t) {
        $name = (string)($t['name'] ?? '');
        try { \SupaBein\Schema::validateIdentifier($name); }
        catch (\InvalidArgumentException $e) { return "add_tables[$i].name: " . $e->getMessage(); }
        if (isset($existing[strtolower($name)])) {
            return "add_tables[$i] \"$name\" already exists — do not re-add existing tables; "
                 . "use add_columns or update_policies for changes to it.";
        }
        $newTables[strtolower($name)] = true;
        foreach ($t['columns'] ?? [] as $j => $c) {
            try { \SupaBein\Schema::validateDataType((string)($c['type'] ?? '')); }
            catch (\InvalidArgumentException $e) { return "add_tables[$i].columns[$j].type: " . $e->getMessage(); }
        }
    }

    foreach ($delta['add_columns'] ?? [] as $i => $entry) {
        $tn = strtolower((string)($entry['table'] ?? ''));
        if ($tn === '') return "add_columns[$i].table is required";
        $tableIsNew = isset($newTables[$tn]);
        if (!$tableIsNew && !isset($existing[$tn])) {
            return "add_columns[$i] targets unknown table \"{$entry['table']}\" — it is neither in "
                 . "the current schema nor in add_tables.";
        }
        foreach ($entry['columns'] ?? [] as $j => $c) {
            $cn = strtolower((string)($c['name'] ?? ''));
            try { \SupaBein\Schema::validateIdentifier((string)($c['name'] ?? '')); }
            catch (\InvalidArgumentException $e) { return "add_columns[$i].columns[$j].name: " . $e->getMessage(); }
            if (in_array($cn, ['id', 'created_at'], true)) {
                return "add_columns[$i] column \"{$entry['table']}.{$c['name']}\" is auto-managed by "
                     . "SupaBein — never add id or created_at.";
            }
            try { \SupaBein\Schema::validateDataType((string)($c['type'] ?? '')); }
            catch (\InvalidArgumentException $e) { return "add_columns[$i].columns[$j].type: " . $e->getMessage(); }
            if (!$tableIsNew && isset($existing[$tn][$cn])) {
                return "add_columns[$i] column \"{$entry['table']}.{$c['name']}\" already exists — "
                     . "remove it from the delta (additions only, no duplicates).";
            }
        }
    }

    foreach ($delta['update_policies'] ?? [] as $i => $p) {
        $tn = strtolower((string)($p['table'] ?? ''));
        if ($tn === '') return "update_policies[$i].table is required";
        if (!isset($existing[$tn]) && !isset($newTables[$tn])) {
            return "update_policies[$i] targets unknown table \"{$p['table']}\".";
        }
    }

    foreach ($delta['seed_data'] ?? [] as $seedTable => $rows) {
        $tn = strtolower((string)$seedTable);
        if (!isset($existing[$tn]) && !isset($newTables[$tn])) {
            return "seed_data targets unknown table \"{$seedTable}\".";
        }
        if (!is_array($rows)) {
            return "seed_data.\"{$seedTable}\" must be an array of row objects.";
        }
    }

    return null;
}

/**
 * Apply-time only (POST /v1/ai/apply, mode=edit) — NOT used during generation.
 * ai_execute_edit() applies add_tables/add_columns one item at a time with no
 * transaction, each independently try/caught; a plan that fails partway
 * through (a deploy error, a bad policy reference later in the same delta)
 * can leave some tables/columns already created even though the overall
 * request came back as a failure. The dashboard's "Retry apply" then resends
 * that SAME plan — which ai_validate_delta() would permanently reject with
 * "already exists", since it can't tell "the AI is confused" apart from "this
 * specific retry already finished this specific piece". Strip anything the
 * delta wants to add that the live schema already has before validating, so
 * a retry finishes whatever didn't land instead of being blocked outright by
 * the part that already did. Only strips exact matches against schema fetched
 * fresh at apply time — a genuinely stale/wrong table or type still gets
 * caught by ai_validate_delta() on whatever's left.
 */
function ai_reconcile_delta_for_apply(array $delta, array $existingSchema, int $projectId): array
{
    $existing = [];
    foreach ($existingSchema['tables'] ?? [] as $t) {
        $tn = strtolower((string)($t['name'] ?? ''));
        if ($tn === '') continue;
        $existing[$tn] = [];
        foreach ($t['columns'] ?? [] as $c) {
            $existing[$tn][strtolower((string)($c['name'] ?? ''))] = true;
        }
    }

    if (!empty($delta['add_tables'])) {
        $kept = [];
        foreach ($delta['add_tables'] as $t) {
            $tn = strtolower((string)($t['name'] ?? ''));
            if ($tn !== '' && isset($existing[$tn])) {
                sb_log('ai_edit', 'apply retry: skipping already-created table', ['project_id' => $projectId, 'table' => $t['name'] ?? '']);
                continue;
            }
            $kept[] = $t;
        }
        $delta['add_tables'] = $kept;
    }

    if (!empty($delta['add_columns'])) {
        $reconciled = [];
        foreach ($delta['add_columns'] as $entry) {
            $tn = strtolower((string)($entry['table'] ?? ''));
            $cols = [];
            foreach ($entry['columns'] ?? [] as $c) {
                $cn = strtolower((string)($c['name'] ?? ''));
                if ($cn !== '' && isset($existing[$tn][$cn])) {
                    sb_log('ai_edit', 'apply retry: skipping already-added column', ['project_id' => $projectId, 'column' => ($entry['table'] ?? '') . '.' . ($c['name'] ?? '')]);
                    continue;
                }
                $cols[] = $c;
            }
            if ($cols) { $entry['columns'] = $cols; $reconciled[] = $entry; }
        }
        $delta['add_columns'] = $reconciled;
    }

    return $delta;
}

// ─── Schema serializer for two-pass generation ───────────────────────────────

/**
 * Cap intent to hard limits. Handles both the legacy flat format
 * {actors:string[], stories:string[]} and the new nested format
 * {actors:[{name, stories:[{title, journeys, requirements}]}], non_functional_requirements:[]}.
 */
function ai_cap_intent(array $intent): array
{
    $cleanStrings = static function (array $list, int $max): array {
        $out = [];
        foreach ($list as $v) {
            if (!is_string($v)) continue;
            $v = trim($v);
            if ($v === '') continue;
            $out[] = $v;
            if (count($out) >= $max) break;
        }
        return $out;
    };

    // Legacy flat format — convert to nested
    if (!empty($intent['actors']) && is_string($intent['actors'][0] ?? null)) {
        $actorNames = $cleanStrings($intent['actors'], 5);
        $stories    = $cleanStrings($intent['stories'] ?? [], 7);
        return [
            'actors' => array_map(fn($name) => ['name' => $name, 'stories' => array_map(
                fn($s) => ['title' => $s, 'journeys' => [], 'requirements' => []], $stories
            )], $actorNames),
            'non_functional_requirements' => [],
        ];
    }

    // New nested format
    $actors = [];
    foreach (array_slice((array)($intent['actors'] ?? []), 0, 5) as $actor) {
        if (!is_array($actor) || empty($actor['name'])) continue;
        $stories = [];
        foreach (array_slice((array)($actor['stories'] ?? []), 0, 4) as $story) {
            if (!is_array($story) || empty($story['title'])) continue;
            $stories[] = [
                'title'        => (string)$story['title'],
                'journeys'     => $cleanStrings((array)($story['journeys']     ?? []), 2),
                'requirements' => $cleanStrings((array)($story['requirements'] ?? []), 4),
            ];
        }
        if (empty($stories)) continue;
        $actors[] = ['name' => (string)$actor['name'], 'stories' => $stories];
    }

    return [
        'actors'                      => $actors,
        'non_functional_requirements' => $cleanStrings((array)($intent['non_functional_requirements'] ?? []), 6),
    ];
}

/**
 * Structural validation of the new nested intent format.
 */
function ai_validate_intent(array $intent): ?string
{
    if (empty($intent['actors']) || !is_array($intent['actors']))
        return 'intent.actors must be a non-empty array';
    foreach ($intent['actors'] as $actor) {
        if (!is_array($actor) || empty($actor['name']))
            return 'each actor must be an object with a name';
        if (empty($actor['stories']) || !is_array($actor['stories']))
            return 'each actor must have a non-empty stories array';
        foreach ($actor['stories'] as $story) {
            if (!is_array($story) || empty($story['title']))
                return 'each story must have a title';
        }
    }
    return null;
}

/**
 * Run the intent pass (pass 0) with one self-correcting retry, then cap deterministically.
 */
/**
 * @param array $refs Reference material from uploaded attachments, shaped
 *   ['attachments' => [{media_type, data_base64}, ...], 'context' => string]
 *   — see ai_prepare_attachments_for_ai(). Defaults to none.
 */
function ai_generate_intent(object $client, string $prompt, array $history = [], array $refs = []): array
{
    $attachments = $refs['attachments'] ?? [];
    $promptWithCtx = $prompt . (($refs['context'] ?? '') !== '' ? "\n\n" . $refs['context'] : '');
    $systemPrompt = AI_INTENT_PROMPT . ($attachments || !empty($refs['context']) ? ai_attachment_instruction_note() : '');

    $call = static function (string $user) use ($client, $history, $attachments, $systemPrompt) {
        return $history
            ? $client->generateJsonWithHistory($systemPrompt, $history, $user, $attachments)
            : $client->generateJson($systemPrompt, $user, $attachments);
    };

    $intent = $call($promptWithCtx);
    $err = ai_validate_intent($intent);
    if ($err) {
        $intent = $call($promptWithCtx . "\n\nYour previous response was rejected: " . $err . "\nReturn ONLY the JSON structure specified, obeying the hard limits.");
        $err = ai_validate_intent($intent);
        if ($err) {
            // Last-resort fallback
            $intent = ['actors' => [['name' => 'owner', 'stories' => [['title' => $prompt, 'journeys' => [], 'requirements' => []]]]], 'non_functional_requirements' => []];
        }
    }
    return ai_cap_intent($intent);
}

/**
 * Serialize an approved intent into a locked context block for the schema pass. Works with
 * both the new nested format and the legacy flat format.
 */
function ai_intent_to_context(array $intent, string $purpose = 'design the schema for'): string
{
    $intent = ai_cap_intent($intent);
    $lines  = "Locked product intent — {$purpose} EXACTLY these, add nothing and drop nothing.\nActors:\n";
    foreach ($intent['actors'] as $actor) {
        $lines .= '- ' . (is_array($actor) ? $actor['name'] : $actor) . "\n";
    }
    $lines .= "\nUser stories:\n";
    foreach ($intent['actors'] as $actor) {
        if (!is_array($actor)) continue;
        foreach ($actor['stories'] ?? [] as $story) {
            if (!is_array($story)) {
                $lines .= '- ' . $story . "\n";
                continue;
            }
            $lines .= '- ' . ($story['title'] ?? '') . "\n";
            foreach ($story['journeys'] ?? [] as $j) {
                $lines .= '  Journey: ' . $j . "\n";
            }
            foreach ($story['requirements'] ?? [] as $r) {
                $lines .= '  Requirement: ' . $r . "\n";
            }
        }
    }
    $nfrs = $intent['non_functional_requirements'] ?? [];
    if ($nfrs) {
        $lines .= "\nNon-functional requirements:\n";
        foreach ($nfrs as $r) {
            $lines .= '- ' . $r . "\n";
        }
    }
    return $lines;
}

/**
 * Detect the auth/users table in a plan (or DB-derived schema): the table holding a
 * PASSWORD column, plus its identifier field (email/username preferred). These fill the
 * __AUTH_TABLE__ / __AUTH_FIELD__ placeholders in the verbatim auth.js so weak models
 * never have to guess them. Returns ['table' => null, 'field' => null] when no auth.
 */
function ai_detect_auth(array $plan): array
{
    foreach ($plan['tables'] ?? [] as $t) {
        $hasPw = false;
        $field = null;
        foreach ($t['columns'] ?? [] as $c) {
            if (strtoupper(trim((string)($c['type'] ?? ''))) === 'PASSWORD') { $hasPw = true; continue; }
            if ($field === null) {
                $n = strtolower((string)($c['name'] ?? ''));
                if (in_array($n, ['email','username','user_name','login','handle','phone'], true)) {
                    $field = $c['name'];
                }
            }
        }
        if ($hasPw) {
            if ($field === null) {
                foreach ($t['columns'] ?? [] as $c) {
                    if (strtoupper(trim((string)($c['type'] ?? ''))) !== 'PASSWORD') { $field = $c['name']; break; }
                }
            }
            return ['table' => $t['name'], 'field' => $field ?? 'email'];
        }
    }
    return ['table' => null, 'field' => null];
}

/**
 * Bind __AUTH_TABLE__ / __AUTH_FIELD__ in a system prompt to the real schema values so the
 * model copies a ready-to-run auth.js instead of inventing one. Safe to call even with no
 * auth table (falls back to neutral defaults; the rules tell the model to omit auth.js then).
 */
function ai_bind_auth_placeholders(string $systemPrompt, array $plan): string
{
    $auth = ai_detect_auth($plan);
    return str_replace(
        ['__AUTH_TABLE__', '__AUTH_FIELD__'],
        [$auth['table'] ?? 'users', $auth['field'] ?? 'email'],
        $systemPrompt
    );
}

/**
 * Convert a validated plan's tables into a human-readable schema string
 * that is injected into the frontend prompt so the AI sees exact column names.
 */
function ai_schema_to_context(array $plan): string
{
    $lines = [];
    foreach ($plan['tables'] as $tbl) {
        $colParts = [];
        foreach ($tbl['columns'] as $col) {
            $part = $col['name'] . ' ' . $col['type'];
            $part .= ($col['nullable'] ?? true) ? ' NULL' : ' NOT NULL';
            if (($col['default'] ?? null) !== null) {
                $part .= ' DEFAULT ' . $col['default'];
            }
            $colParts[] = $part;
        }
        $lines[] = 'Table "' . $tbl['name'] . '": id (INT auto), '
                 . implode(', ', $colParts) . ', created_at (TIMESTAMP auto)';

        foreach ($tbl['policies'] ?? [] as $pol) {
            if ($pol['allowed']) {
                $constraint = $pol['constraint_sql'] ? ' WHERE ' . $pol['constraint_sql'] : '';
                $lines[] = '  policy: ' . $pol['api_role'] . ' ' . strtoupper($pol['operation']) . $constraint;
            }
        }
    }
    return implode("\n", $lines);
}

function ai_schema_from_db(int $projectId, \SupaBein\Catalog $catalog): array
{
    $tables = [];
    foreach ($catalog->listTables($projectId) as $tbl) {
        $cols = array_map(fn($c) => [
            'name'     => $c['name'],
            'type'     => $c['type'],
            'nullable' => (bool)$c['nullable'],
            'default'  => $c['default'] ?? null,
        ], $catalog->listColumns($tbl['id']));
        $tables[] = [
            'name'     => $tbl['logical_name'],
            'columns'  => $cols,
            'policies' => $catalog->listPolicies($tbl['id']),
        ];
    }
    return ['tables' => $tables];
}

/** @param array $refs See ai_generate_intent()'s doc comment for the shape. */
function ai_generate_design_brief(object $client, string $prompt, array $schemaPlan, array $refs = []): array
{
    $attachments = $refs['attachments'] ?? [];
    $schemaCtx = ai_schema_to_context($schemaPlan);
    $userMsg   = "App description: {$prompt}\n\nSchema:\n{$schemaCtx}"
               . (!empty($refs['context']) ? "\n\n" . $refs['context'] : '');
    $systemPrompt = AI_DESIGN_BRIEF_PROMPT . ($attachments || !empty($refs['context']) ? ai_attachment_instruction_note() : '');
    try {
        $brief = $client->generateJson($systemPrompt, $userMsg, $attachments);
    } catch (\Throwable) {
        return [];
    }
    return is_array($brief) ? $brief : [];
}

function ai_brief_to_context(array $brief): string
{
    if (empty($brief)) return '';
    $lines = ['Design brief — implement these choices exactly:'];
    $labels = [
        'personality'   => 'Brand personality',
        'accent_color'  => 'Accent color (Tailwind name)',
        'font_choice'   => 'Font',
        'card_style'    => 'Card style (Tailwind classes)',
        'layout'        => 'Layout pattern',
        'hero_style'    => 'Hero element',
        'unique_detail' => 'Unique UI detail',
    ];
    foreach ($labels as $k => $label) {
        if (!empty($brief[$k]) && is_string($brief[$k])) {
            $lines[] = "  {$label}: {$brief[$k]}";
        }
    }
    return implode("\n", $lines);
}

// Stage 1+2 of a build: schema (with one self-correcting retry) and a
// best-effort visual design brief. Split out from frontend generation so the
// "Review" build flow can pause here and let the user confirm the schema and
// design before any frontend code gets written — the "watch only" (Review
// off) flow just calls this immediately followed by ai_run_build_frontend().
/** @param array $refs See ai_generate_intent()'s doc comment for the shape. */
function ai_run_build_schema_design(string $prompt, array $history, ?array $approvedIntent, object $client, callable $report, array $refs = []): array
{
    $aiTrace = [];
    $attachments = $refs['attachments'] ?? [];
    $hasRefs = $attachments || !empty($refs['context']);
    $schemaSystemPrompt = AI_BUILD_SCHEMA_PROMPT . ($hasRefs ? ai_attachment_instruction_note() : '');

    $lockedName = trim((string)($approvedIntent['project_name'] ?? ''));

    // ── Stage 1: schema ───────────────────────────────────────────────────
    $report(['stage' => 'schema', 'status' => 'start', 'label' => 'Designing database schema…']);
    $schemaUserMsg = $approvedIntent
        ? $prompt . "\n\n" . ai_intent_to_context($approvedIntent)
        : $prompt;
    if ($lockedName !== '') {
        $schemaUserMsg .= "\n\nLocked project name — use EXACTLY this as \"project_name\" in your JSON output: {$lockedName}";
    }
    if (!empty($refs['context'])) $schemaUserMsg .= "\n\n" . $refs['context'];
    $_t0 = microtime(true);
    $schemaPlan = $client->generateJsonWithHistory($schemaSystemPrompt, $history, $schemaUserMsg, $attachments);
    $aiTrace[] = ['stage' => 'schema_pass_1', 'system' => $schemaSystemPrompt, 'history' => $history, 'user_msg' => $schemaUserMsg, 'response' => $schemaPlan, 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];
    $schemaPlan['frontend'] = ['files' => []];
    $schemaPlan = ai_sanitize_plan($schemaPlan);
    if ($lockedName !== '') $schemaPlan['project_name'] = $lockedName;

    $validationError = ai_validate_plan($schemaPlan);
    if ($validationError) {
        $report(['stage' => 'schema', 'status' => 'retry', 'label' => 'Refining schema…', 'detail' => $validationError]);
        $retryPrompt = $schemaUserMsg
            . "\n\nYour previous schema was rejected for this reason:\n  " . $validationError
            . "\nReturn a corrected schema that fixes exactly this problem.";
        $_t0 = microtime(true);
        $schemaPlan = $client->generateJsonWithHistory($schemaSystemPrompt, $history, $retryPrompt, $attachments);
        $aiTrace[] = ['stage' => 'schema_retry', 'system' => $schemaSystemPrompt, 'history' => $history, 'user_msg' => $retryPrompt, 'response' => $schemaPlan, 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => true, 'error' => $validationError];
        $schemaPlan['frontend'] = ['files' => []];
        $schemaPlan = ai_sanitize_plan($schemaPlan);
        if ($lockedName !== '') $schemaPlan['project_name'] = $lockedName;
        $validationError = ai_validate_plan($schemaPlan);
        if ($validationError) throw new \RuntimeException('AI returned an invalid schema: ' . $validationError);
    }
    $tableNames = array_map(fn($t) => $t['name'], $schemaPlan['tables'] ?? []);
    $report(['stage' => 'schema', 'status' => 'done', 'label' => 'Database schema ready', 'detail' => count($tableNames) . ' table' . (count($tableNames) === 1 ? '' : 's') . ': ' . implode(', ', $tableNames)]);

    // ── Stage 2: design brief (best-effort) ───────────────────────────────
    $report(['stage' => 'design', 'status' => 'start', 'label' => 'Choosing a visual design…']);
    $_t0   = microtime(true);
    $brief = ai_generate_design_brief($client, $prompt, $schemaPlan, $refs);
    if (!empty($brief)) {
        $aiTrace[] = ['stage' => 'design_brief', 'system' => AI_DESIGN_BRIEF_PROMPT, 'history' => [], 'user_msg' => "App description: {$prompt}\n\nSchema:\n" . ai_schema_to_context($schemaPlan), 'response' => $brief, 'tokens' => $client->getLastUsage(), 'ms' => (int)((microtime(true) - $_t0) * 1000), 'retry' => false];
    }
    $report(['stage' => 'design', 'status' => 'done', 'label' => 'Visual design chosen', 'detail' => trim(($brief['personality'] ?? '') . (isset($brief['accent_color']) ? ' · ' . $brief['accent_color'] : '')) ?: 'default theme']);

    return ['schema' => $schemaPlan, 'design_brief' => $brief, 'aiTrace' => $aiTrace, 'usage' => $client->getLastUsage()];
}