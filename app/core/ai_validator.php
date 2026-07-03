<?php

declare(strict_types=1);

// ─── SupaBein Build Validator ────────────────────────────────────────────────
// Deterministic, regex/heuristic static analysis over a generated app's schema
// + frontend files, run as a stage in ai_run_build_generation/ai_run_edit_generation
// (app/routes/ai_routes.php). Catches the class of bug where the frontend and
// schema/seed data silently disagree — a query filters on a literal no seed
// row has, a nav link points at a route that was never registered, a table
// name is misspelled in an api.* call — the kind of thing that produces a
// working-looking app with a permanently empty section, a dead link, or a
// silent failure, with no error shown anywhere.
//
// Deliberately NOT an AI feature for detection: every check here is a plain
// string/regex match over already-known-shape code (every SupaBein-generated
// app follows the same handful of patterns — `const x = (() => {...; return
// {...}; })()`, `router.defineRoute(path, handler)`, `api.list('table')`), so
// a rule answers these questions faster and more reliably than a model would.
// AI is only ever used, best-effort, to EXPLAIN a finding a rule already made
// (ai_validator_explain_findings) — never to detect one.
//
// Fully removable as a feature: unhook the ai_validator_check_project(...)
// call sites in ai_run_build_generation/ai_run_edit_generation and this file
// is dead code. Individually toggleable per request via payload.validate
// (see /v1/ai/build/job and /v1/ai/edit/job), default on.

function ai_validator_finding(string $severity, string $category, string $message, ?string $detail = null): array
{
    return ['severity' => $severity, 'category' => $category, 'message' => $message, 'detail' => $detail];
}

function ai_validator_severity_rank(string $severity): int
{
    return match ($severity) { 'error' => 3, 'warning' => 2, 'info' => 1, default => 0 };
}

// const NAME = (() => { ...; return { a, b: c, ... }; })();  →  ['a', 'b']
function ai_validator_extract_exports(string $js): array
{
    if (!preg_match('/return\s*\{([^}]*)\}\s*;\s*\}\s*\)\s*\(\s*\)\s*;?\s*$/s', trim($js), $m)) {
        return [];
    }
    $names = [];
    foreach (explode(',', $m[1]) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $name = trim(explode(':', $part)[0]); // shorthand "a" or renamed "a: b" — exported name is the left side
        if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name)) $names[] = $name;
    }
    return $names;
}

function ai_validator_extract_routes(string $js): array
{
    preg_match_all('/router\.defineRoute\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([A-Za-z0-9_.$]+)\s*\)/', $js, $m, PREG_SET_ORDER);
    $routes = [];
    foreach ($m as $mm) $routes[] = ['path' => $mm[1], 'handler' => $mm[2]];
    return $routes;
}

function ai_validator_extract_nav_hrefs(string $html): array
{
    preg_match_all('/href=[\'"]#(\/[^\'"#]*)[\'"]/', $html, $m);
    return array_values(array_unique($m[1]));
}

function ai_validator_extract_navigate_calls(string $js): array
{
    preg_match_all('/router\.navigate\(\s*[\'"`](\/[^\'"`]*)/', $js, $m);
    return array_values(array_unique($m[1]));
}

// const NAME = 'value'; / let NAME = "value";  →  ['NAME' => 'value']
// SupaBein-generated apps routinely alias a table name to a local constant
// (const BUDGETS_TABLE = 'budgets'; ... api.create(BUDGETS_TABLE, ...)) —
// api-call extraction below resolves through this so that idiom doesn't look
// like a call with no recognizable table argument.
function ai_validator_extract_string_constants(string $js): array
{
    preg_match_all('/(?:const|let)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*;/', $js, $m, PREG_SET_ORDER);
    $consts = [];
    foreach ($m as $mm) $consts[$mm[1]] = $mm[2];
    return $consts;
}

function ai_validator_extract_api_calls(string $js): array
{
    $consts = ai_validator_extract_string_constants($js);
    preg_match_all('/api\.(list|get|create|update|remove)\(\s*(?:[\'"]([A-Za-z0-9_]+)[\'"]|([A-Za-z_$][A-Za-z0-9_$]*))/', $js, $m, PREG_SET_ORDER);
    $calls = [];
    foreach ($m as $mm) {
        $table = $mm[2] !== '' ? $mm[2] : ($consts[$mm[3]] ?? null);
        if ($table === null) continue; // dynamic/unresolvable table argument — not this check's concern
        $calls[] = ['op' => $mm[1], 'table' => $table];
    }
    return $calls;
}

function ai_validator_extract_literal_equalities(string $js, string $column): array
{
    preg_match_all('/\.\s*' . preg_quote($column, '/') . '\s*===?\s*[\'"]([^\'"]+)[\'"]/', $js, $m);
    return array_values(array_unique($m[1]));
}

/**
 * Run all deterministic checks. $schema is ['tables' => [...], 'seed_data' => [...]]
 * (a build's sanitized plan has seed_data; ai_schema_from_db() for an edit does
 * not, since seed content lives in the live DB, not schema metadata — the
 * seed-data check is a no-op in that case, everything else still runs).
 * $frontendFiles is [{path, content}, ...].
 */
function ai_validator_check_project(array $schema, array $frontendFiles): array
{
    $findings = [];
    $tables   = [];
    foreach ($schema['tables'] ?? [] as $t) {
        $tables[$t['name']] = $t;
    }

    $byPath = [];
    foreach ($frontendFiles as $f) {
        if (!is_array($f) || !isset($f['path'])) continue;
        $byPath[$f['path']] = (string)($f['content'] ?? '');
    }
    if (!$byPath) return $findings; // nothing to check (schema-only change)

    // Feature module exports, e.g. 'home' => ['renderView'], 'budgets' => ['renderBudgetsList', 'renderBudgetDetail']
    $exportsByModule = [];
    foreach ($byPath as $path => $content) {
        if (!preg_match('#^features/([a-zA-Z0-9_]+)/\1\.js$#', $path, $m)) continue;
        $exportsByModule[$m[1]] = ai_validator_extract_exports($content);
    }

    $routeDefs     = [];
    $navHrefs      = [];
    $navigateCalls = [];
    foreach ($byPath as $path => $content) {
        $routeDefs = array_merge($routeDefs, ai_validator_extract_routes($content));
        if (str_ends_with($path, '.html')) {
            $navHrefs = array_merge($navHrefs, ai_validator_extract_nav_hrefs($content));
        }
        $navigateCalls = array_merge($navigateCalls, ai_validator_extract_navigate_calls($content));
    }

    // ── Route ↔ handler existence, duplicate routes ────────────────────────
    $seenPaths = [];
    foreach ($routeDefs as $rd) {
        if (isset($seenPaths[$rd['path']])) {
            $findings[] = ai_validator_finding('warning', 'route',
                "Route \"{$rd['path']}\" is registered more than once",
                'Only the last registration wins — the earlier one is dead code.');
        }
        $seenPaths[$rd['path']] = true;

        if (preg_match('/^([a-zA-Z0-9_]+)\.([a-zA-Z0-9_$]+)$/', $rd['handler'], $hm)) {
            [, $module, $fn] = $hm;
            if ($module === 'auth') continue; // platform-provided — always has renderLogin/renderSignup
            if (isset($exportsByModule[$module]) && !in_array($fn, $exportsByModule[$module], true)) {
                $findings[] = ai_validator_finding('error', 'route',
                    "Route \"{$rd['path']}\" points to {$rd['handler']}, but features/{$module}/{$module}.js does not export \"{$fn}\"",
                    'This route will throw "is not a function" the moment it is visited.');
            }
        }
    }

    // ── Nav ↔ route consistency (dead links, unreachable routes) ──────────
    $definedPaths = array_column($routeDefs, 'path');
    $matchesRoute = function (string $href) use ($definedPaths): bool {
        foreach ($definedPaths as $p) {
            if ($p === $href) return true;
            if (str_contains($p, ':')) {
                $pattern = '#^' . str_replace('\:[A-Za-z0-9_]+', ':[^/]+', preg_quote($p, '#')) . '$#';
                $pattern = preg_replace('/\\\\:[A-Za-z0-9_]+/', '[^/]+', $pattern);
                if (@preg_match($pattern, $href)) return true;
            }
        }
        return false;
    };
    foreach (array_unique($navHrefs) as $href) {
        if ($href === '' || $href === '/') continue;
        if (!$matchesRoute($href)) {
            $findings[] = ai_validator_finding('error', 'navigation',
                "Nav link \"#{$href}\" has no matching route", 'Clicking this link will show the 404 view.');
        }
    }

    $alwaysReachable = ['/', '/login', '/signup'];
    foreach (array_unique($definedPaths) as $path) {
        if (in_array($path, $alwaysReachable, true)) continue;
        $staticBase = rtrim(preg_replace('/:[A-Za-z0-9_]+.*$/', '', $path), '/');
        $linked = false;
        foreach ($navHrefs as $h) { if ($staticBase !== '' && str_starts_with($h, $staticBase)) { $linked = true; break; } }
        if (!$linked) {
            foreach ($navigateCalls as $nc) { if ($staticBase !== '' && str_starts_with($nc, $staticBase)) { $linked = true; break; } }
        }
        if (!$linked) {
            $findings[] = ai_validator_finding('warning', 'navigation',
                "Route \"{$path}\" is defined but nothing links to it",
                'No <a href> or router.navigate() call targets this path — it may be unreachable from the UI.');
        }
    }

    // ── API table references ↔ schema, CRUD completeness ──────────────────
    $apiCallsByTable = [];
    foreach ($byPath as $content) {
        foreach (ai_validator_extract_api_calls($content) as $call) {
            $apiCallsByTable[$call['table']][] = $call['op'];
            if (!isset($tables[$call['table']])) {
                $findings[] = ai_validator_finding('error', 'schema',
                    "Frontend calls api.{$call['op']}('{$call['table']}'), but no \"{$call['table']}\" table exists",
                    'This call will 404 at runtime — likely a typo or a renamed/removed table.');
            }
        }
    }

    $opMap = ['insert' => 'create', 'update' => 'update', 'delete' => 'remove'];
    foreach ($tables as $tname => $t) {
        $allowedOps = [];
        foreach ($t['policies'] ?? [] as $p) {
            if (($p['api_role'] ?? '') === 'authenticated' && ($p['allowed'] ?? false)) {
                $allowedOps[strtolower((string)($p['operation'] ?? ''))] = true;
            }
        }
        foreach ($opMap as $policyOp => $apiOp) {
            if (!empty($allowedOps[$policyOp]) && !in_array($apiOp, $apiCallsByTable[$tname] ?? [], true)) {
                $findings[] = ai_validator_finding('info', 'crud',
                    "\"{$tname}\" allows {$policyOp} but the frontend never calls api.{$apiOp}('{$tname}')",
                    'Either this is intentionally read-only in the UI, or a form/action is missing.');
            }
        }
    }

    // ── Seed data ↔ frontend literal-comparison consistency ────────────────
    // Heuristic: a column named *_key or *_type is treated as a "discriminator"
    // apps typically switch on with === comparisons (e.g. content_blocks.section_key).
    foreach ($tables as $tname => $t) {
        foreach ($t['columns'] ?? [] as $col) {
            $cname = $col['name'] ?? '';
            if (!preg_match('/_(key|type)$/', $cname)) continue;

            $seedValues = [];
            foreach (($schema['seed_data'][$tname] ?? []) as $row) {
                if (isset($row[$cname])) $seedValues[] = (string)$row[$cname];
            }
            if (!$seedValues) continue;

            $referencedValues = [];
            foreach ($byPath as $content) {
                $referencedValues = array_merge($referencedValues, ai_validator_extract_literal_equalities($content, $cname));
            }
            $referencedValues = array_values(array_unique($referencedValues));
            if (!$referencedValues) continue; // frontend never switches on this column — not this check's concern

            foreach ($referencedValues as $rv) {
                if (!in_array($rv, $seedValues, true)) {
                    $findings[] = ai_validator_finding('error', 'seed_data',
                        "Frontend checks {$tname}.{$cname} === '{$rv}', but no seeded row has that value",
                        'Seeded values are: ' . implode(', ', $seedValues) . '. This query/branch will always be empty.');
                }
            }
            foreach ($seedValues as $sv) {
                if (!in_array($sv, $referencedValues, true)) {
                    $findings[] = ai_validator_finding('warning', 'seed_data',
                        "Seeded {$tname}.{$cname} = '{$sv}' is never checked for anywhere in the frontend",
                        'This content will never be displayed.');
                }
            }
        }
    }

    // ── Foreign-key naming convention sanity check (low-confidence, info only) ──
    foreach ($tables as $tname => $t) {
        foreach ($t['columns'] ?? [] as $col) {
            $cname = $col['name'] ?? '';
            if (!preg_match('/^([a-z0-9_]+)_id$/', $cname, $m)) continue;
            $base = $m[1];
            if ($base === 'user' || $base === 'current_user') continue; // platform users table, not app schema
            $found = isset($tables[$base]) || isset($tables[$base . 's']) || isset($tables[rtrim($base, 's')]);
            if (!$found) {
                $findings[] = ai_validator_finding('info', 'foreign_key',
                    "\"{$tname}.{$cname}\" looks like a foreign key, but no table named \"{$base}\"/\"{$base}s\" exists",
                    'Naming-convention guess only — ignore if this column is not actually a relation.');
            }
        }
    }

    return $findings;
}

/**
 * Best-effort AI pass: adds a plain-language "explanation" to the highest-
 * severity findings (capped, to keep this cheap — only called at all when
 * there's at least one 'error'-level finding worth explaining). Detection
 * above never depends on this — if it fails, findings are still returned,
 * just without an explanation.
 */
function ai_validator_explain_findings(array $findings, object $client, int $limit = 6): array
{
    if (!$findings) return $findings;
    usort($findings, fn($a, $b) => ai_validator_severity_rank($b['severity']) <=> ai_validator_severity_rank($a['severity']));
    $toExplain = array_slice($findings, 0, $limit);
    $rest      = array_slice($findings, $limit);

    $lines = [];
    foreach (array_values($toExplain) as $i => $f) {
        $lines[] = ($i + 1) . ". [{$f['category']}] {$f['message']}" . ($f['detail'] ? " ({$f['detail']})" : '');
    }
    $prompt = "For each numbered issue below (found by a static analyzer over a generated web app), "
            . "write ONE short sentence explaining the real-world symptom an end user would actually see. "
            . "Return ONLY JSON: {\"explanations\": [\"...\", ...]} in the same order, one string per issue.\n\n"
            . implode("\n", $lines);

    try {
        $res          = $client->generateJson('You are a terse QA engineer.', $prompt);
        $explanations = $res['explanations'] ?? [];
        foreach ($toExplain as $i => &$f) {
            if (!empty($explanations[$i])) $f['explanation'] = (string)$explanations[$i];
        }
        unset($f);
    } catch (\Throwable $e) {
        // Best-effort — findings are still useful without an explanation.
    }

    return array_merge($toExplain, $rest);
}
