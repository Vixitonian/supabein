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

// The full callable surface of the platform-injected features/auth/auth.js —
// both the real implementation (AI_CANONICAL_AUTH_JS) and the auth-less stub
// (AI_CANONICAL_AUTH_STUB_JS) in ai_routes.php export exactly these names.
// Kept here, next to the check that uses it, because the validator must know
// the real contract to reject hallucinated method names like renderAuthForms.
const AI_VALIDATOR_AUTH_EXPORTS = ['ready', 'getCurrentUser', 'login', 'logout', 'signup', 'renderLogin', 'renderSignup'];

function ai_validator_finding(string $severity, string $category, string $message, ?string $detail = null): array
{
    return ['severity' => $severity, 'category' => $category, 'message' => $message, 'detail' => $detail];
}

function ai_validator_severity_rank(string $severity): int
{
    return match ($severity) { 'error' => 3, 'warning' => 2, 'info' => 1, default => 0 };
}

// Heuristic distinguishing a regex literal's opening '/' from a division
// operator, by looking backward from $pos (exclusive) at the last non-
// whitespace character already scanned. Not a full JS tokenizer, but
// sufficient for the common case: a '/' right after an identifier/number/
// closing bracket is division; anywhere else (start of expression, after an
// operator/punctuation/keyword, or at the very start) it opens a regex.
function ai_validator_regex_starts_at(string $js, int $pos): bool
{
    $j = $pos - 1;
    while ($j >= 0 && ctype_space($js[$j])) $j--;
    if ($j < 0) return true;
    $last = $js[$j];
    if (ctype_alnum($last) || $last === '_' || $last === '$' || $last === ')' || $last === ']') {
        // "return /x/" is the one common false-negative this trips on —
        // 'return' ends in an alnum char but is not itself a value. Walk
        // back over the trailing identifier and compare it to the keyword.
        $k = $j;
        while ($k >= 0 && (ctype_alnum($js[$k]) || $js[$k] === '_' || $js[$k] === '$')) $k--;
        return substr($js, $k + 1, $j - $k) === 'return';
    }
    return true;
}

// Advances $i past a regex literal (opening '/' through its closing '/', not
// counting escaped '\/' or a '/' inside a [...] character class) starting at
// $js[$i]. Without this, a literal quote character inside a regex — e.g.
// .replace(/"/g, '&quot;') to HTML-escape a string, a routine pattern in
// generated frontend code — is misread as opening a real string, which
// desyncs brace-depth tracking for the rest of the file and makes every
// member after it invisible to ai_validator_extract_exports.
function ai_validator_skip_regex_literal(string $js, int &$i): void
{
    $len = strlen($js);
    $i++; // opening '/'
    $inClass = false;
    while ($i < $len) {
        $c = $js[$i];
        if ($c === '\\' && $i + 1 < $len) { $i += 2; continue; }
        if ($c === '[') { $inClass = true; $i++; continue; }
        if ($c === ']') { $inClass = false; $i++; continue; }
        if ($c === '/' && !$inClass) { $i++; return; }
        if ($c === "\n") { return; } // unterminated — bail rather than run away
        $i++;
    }
}

// Splits the inner content of an object literal into its top-level members,
// respecting nested {}/()/[] and string/template literals so a comma inside a
// method body or argument list isn't mistaken for a member separator.
function ai_validator_split_top_level(string $body): array
{
    $parts = [];
    $depth = 0;
    $state = 'code'; // code | ' | " | `
    $buf   = '';
    $len   = strlen($body);
    $i     = 0;
    while ($i < $len) {
        $ch = $body[$i];
        if ($state === 'code') {
            if ($ch === '/' && $i + 1 < $len && $body[$i + 1] === '/') {
                $nl = strpos($body, "\n", $i);
                $i  = $nl === false ? $len : $nl + 1;
                continue;
            }
            if ($ch === '/' && $i + 1 < $len && $body[$i + 1] === '*') {
                $end = strpos($body, '*/', $i + 2);
                $i   = $end === false ? $len : $end + 2;
                continue;
            }
            if ($ch === '/' && ai_validator_regex_starts_at($body, $i)) {
                $start = $i;
                ai_validator_skip_regex_literal($body, $i);
                $buf .= substr($body, $start, $i - $start);
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $state = $ch; $buf .= $ch; $i++; continue; }
            if ($ch === '{' || $ch === '(' || $ch === '[') { $depth++; $buf .= $ch; $i++; continue; }
            if ($ch === '}' || $ch === ')' || $ch === ']') { $depth--; $buf .= $ch; $i++; continue; }
            if ($ch === ',' && $depth === 0) { $parts[] = $buf; $buf = ''; $i++; continue; }
            $buf .= $ch; $i++; continue;
        }
        // Inside a string/template literal: copy verbatim until the matching
        // unescaped quote. Template `${...}` interpolation is treated as
        // opaque text here (not re-entered as code) — a heuristic, not a full
        // parser, but sufficient to find member boundaries correctly.
        $buf .= $ch;
        if ($ch === '\\' && $i + 1 < $len) { $buf .= $body[$i + 1]; $i += 2; continue; }
        if ($ch === $state) { $state = 'code'; }
        $i++;
    }
    if (trim($buf) !== '') $parts[] = $buf;
    return $parts;
}

// Given one top-level object member's raw source, return its key name for
// all three legal forms: `name: value`, method-shorthand `name(...) {...}`
// (optionally `async`/generator `*`), and property-shorthand `name`.
function ai_validator_key_from_member(string $part): ?string
{
    $part = trim($part);
    if ($part === '') return null;
    if (preg_match('/^(?:async\s+|\*\s*)?([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $part, $m)) return $m[1];
    if (preg_match('/^([A-Za-z_$][A-Za-z0-9_$]*)\s*:/', $part, $m)) return $m[1];
    if (preg_match('/^([A-Za-z_$][A-Za-z0-9_$]*)$/', $part, $m)) return $m[1];
    return null;
}

// Scans forward from an opening `{` at $braceStart to its matching `}`
// (respecting nested braces and string/template literals) and returns the
// content strictly between them, or null if unbalanced.
function ai_validator_extract_balanced_body(string $js, int $braceStart): ?string
{
    $len = strlen($js);
    if ($braceStart >= $len || $js[$braceStart] !== '{') return null;
    $depth = 0;
    $state = 'code';
    $i     = $braceStart;
    while ($i < $len) {
        $ch = $js[$i];
        if ($state === 'code') {
            if ($ch === '/' && $i + 1 < $len && $js[$i + 1] === '/') {
                $nl = strpos($js, "\n", $i);
                $i  = $nl === false ? $len : $nl + 1;
                continue;
            }
            if ($ch === '/' && $i + 1 < $len && $js[$i + 1] === '*') {
                $end = strpos($js, '*/', $i + 2);
                $i   = $end === false ? $len : $end + 2;
                continue;
            }
            if ($ch === '/' && ai_validator_regex_starts_at($js, $i)) {
                ai_validator_skip_regex_literal($js, $i);
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $state = $ch; $i++; continue; }
            if ($ch === '{') { $depth++; $i++; continue; }
            if ($ch === '}') {
                $depth--;
                if ($depth === 0) return substr($js, $braceStart + 1, $i - $braceStart - 1);
                $i++; continue;
            }
            $i++; continue;
        }
        if ($ch === '\\' && $i + 1 < $len) { $i += 2; continue; }
        if ($ch === $state) { $state = 'code'; }
        $i++;
    }
    return null; // unbalanced
}

// A feature module is legitimately written in either of two shapes:
//   (a) const NAME = (() => { ...; return { a, b: c }; })();  — IIFE, exports via return
//   (b) const NAME = { a: fn, b: fn2 };                        — plain object literal
// Both are common model output; only recognizing (a) makes every module
// written as (b) look like it "exports nothing" no matter what it actually
// contains, which then false-positives every route pointing at it forever
// (no edit can fix a check that can't see the file's real exports).
function ai_validator_extract_exports(string $js, string $moduleName): array
{
    if (preg_match('/return\s*\{([^}]*)\}\s*;\s*\}\s*\)\s*\(\s*\)\s*;?\s*$/s', trim($js), $m)) {
        $names = [];
        foreach (ai_validator_split_top_level($m[1]) as $part) {
            $name = ai_validator_key_from_member($part);
            if ($name !== null) $names[] = $name;
        }
        return $names;
    }

    if (!preg_match('/(?:const|let|var)\s+' . preg_quote($moduleName, '/') . '\s*=\s*\{/', $js, $m2, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $braceStart = $m2[0][1] + strlen($m2[0][0]) - 1;
    $body       = ai_validator_extract_balanced_body($js, $braceStart);
    if ($body === null) return [];

    $names = [];
    foreach (ai_validator_split_top_level($body) as $part) {
        $name = ai_validator_key_from_member($part);
        if ($name !== null) $names[] = $name;
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

// defineRoute calls whose second argument is NOT a plain function reference —
// an object literal ({feature: 'home', render: 'renderView'}) or a string
// ('home.renderView'). The platform router calls handler(params) directly, so
// any of these crash (or 404 into a custom dispatch scheme) on every visit.
// Live-caught: a generated app registered ALL of its routes descriptor-style
// against a home-rolled window[feature] dispatcher, every page died with
// "Module X not found", and the validator — whose route regex only matches
// identifier handlers — saw zero routes and reported nothing at all.
function ai_validator_extract_non_function_routes(string $js): array
{
    preg_match_all('/router\.defineRoute\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([{\'"])/', $js, $m, PREG_SET_ORDER);
    $routes = [];
    foreach ($m as $mm) {
        $routes[] = ['path' => $mm[1], 'kind' => $mm[2] === '{' ? 'object' : 'string'];
    }
    return $routes;
}

// <script src="..."> paths from an HTML file, normalised (leading ./ and /
// stripped) so they compare directly against the files array's paths.
function ai_validator_extract_script_srcs(string $html): array
{
    preg_match_all('/<script\s[^>]*src=[\'"]([^\'"]+)[\'"]/i', $html, $m);
    return array_map(fn($s) => ltrim($s, './'), $m[1]);
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
        $exportsByModule[$m[1]] = ai_validator_extract_exports($content, $m[1]);
    }

    $routeDefs     = [];
    $badRouteDefs  = [];
    $navHrefs      = [];
    $navigateCalls = [];
    $scriptSrcs    = [];
    foreach ($byPath as $path => $content) {
        $routeDefs    = array_merge($routeDefs, ai_validator_extract_routes($content));
        $badRouteDefs = array_merge($badRouteDefs, ai_validator_extract_non_function_routes($content));
        if (str_ends_with($path, '.html')) {
            $navHrefs   = array_merge($navHrefs, ai_validator_extract_nav_hrefs($content));
            $scriptSrcs = array_merge($scriptSrcs, ai_validator_extract_script_srcs($content));
        }
        $navigateCalls = array_merge($navigateCalls, ai_validator_extract_navigate_calls($content));
    }

    // ── Route ↔ handler existence, duplicate routes ────────────────────────
    foreach ($badRouteDefs as $brd) {
        $findings[] = ai_validator_finding('error', 'route',
            "Route \"{$brd['path']}\" is registered with " . ($brd['kind'] === 'object' ? 'an object literal' : 'a string') . ' instead of a direct function reference',
            'The platform router calls handler(params) directly — defineRoute(path, module.renderFn) is the only working form. '
            . 'Descriptor/string handlers only work against a home-rolled dispatch scheme the platform discards at deploy time, so every visit to this route fails.');
    }

    $seenPaths = [];
    foreach ($routeDefs as $rd) {
        // A handler reference is only callable if the module's script is actually
        // loaded by index.html — the file existing in the deploy isn't enough.
        // Live-caught: a /profile route whose features/profile/profile.js was
        // written to disk but never given a <script src> tag, so visiting it threw
        // "profile is not defined" while every static check on the file passed.
        if (preg_match('/^([a-zA-Z0-9_]+)\./', $rd['handler'], $sm) && $sm[1] !== 'auth') {
            $modPath = "features/{$sm[1]}/{$sm[1]}.js";
            if (isset($byPath[$modPath]) && $scriptSrcs && !in_array($modPath, $scriptSrcs, true)) {
                $findings[] = ai_validator_finding('error', 'route',
                    "Route \"{$rd['path']}\" uses {$rd['handler']}, but index.html never loads {$modPath} via <script src>",
                    'This route will throw "' . $sm[1] . ' is not defined" the moment it is visited — add the script tag.');
            }
        }
        if (isset($seenPaths[$rd['path']])) {
            $findings[] = ai_validator_finding('warning', 'route',
                "Route \"{$rd['path']}\" is registered more than once",
                'Only the last registration wins — the earlier one is dead code.');
        }
        $seenPaths[$rd['path']] = true;

        if (preg_match('/^([a-zA-Z0-9_]+)\.([a-zA-Z0-9_$]+)$/', $rd['handler'], $hm)) {
            [, $module, $fn] = $hm;
            if ($module === 'auth') {
                // Platform-injected features/auth/auth.js always exposes exactly this
                // surface (see AI_CANONICAL_AUTH_JS / AI_CANONICAL_AUTH_STUB_JS in
                // ai_routes.php) — the AI still has to call the real method names, so
                // this can't be a blanket skip the way it used to be. A live-caught bug:
                // the model wrote auth.renderAuthForms() (a plausible-sounding name that
                // doesn't exist), the validator waved it through, and every route into
                // auth crashed with "is not a function" at deploy time.
                if (!in_array($fn, AI_VALIDATOR_AUTH_EXPORTS, true)) {
                    $findings[] = ai_validator_finding('error', 'route',
                        "Route \"{$rd['path']}\" points to {$rd['handler']}, but features/auth/auth.js does not export \"{$fn}\" (only " . implode(', ', AI_VALIDATOR_AUTH_EXPORTS) . ')',
                        'This route will throw "is not a function" the moment it is visited.');
                }
                continue;
            }
            if (isset($exportsByModule[$module])) {
                if (!in_array($fn, $exportsByModule[$module], true)) {
                    $findings[] = ai_validator_finding('error', 'route',
                        "Route \"{$rd['path']}\" points to {$rd['handler']}, but features/{$module}/{$module}.js does not export \"{$fn}\"",
                        'This route will throw "is not a function" the moment it is visited.');
                }
            } else {
                // The handler references a module for which no features/{module}/{module}.js
                // file exists at all — not just a missing export. Previously this fell
                // through both branches silently: a route wired to a feature the model
                // never actually wrote produced no finding, so a whole-app breakage (every
                // route throwing "X is not defined") shipped past validation undetected.
                $findings[] = ai_validator_finding('error', 'route',
                    "Route \"{$rd['path']}\" points to {$rd['handler']}, but features/{$module}/{$module}.js was never generated",
                    'This route will throw "' . $module . ' is not defined" the moment it is visited.');
            }
        }
    }

    // ── Auth routes must exist when the schema has auth ────────────────────
    // The canonical features/auth/auth.js cross-links #/login ↔ #/signup, the
    // api client redirects to #/login on 401, and the generated test suite
    // navigates straight to both — so with a PASSWORD column in the schema,
    // an app that fails to register either route has broken auth by
    // construction, regardless of what the rest of its code looks like.
    $definedPaths = array_column($routeDefs, 'path');
    $hasAuthTable = false;
    foreach ($schema['tables'] ?? [] as $t) {
        foreach ($t['columns'] ?? [] as $c) {
            if (strtoupper((string)($c['type'] ?? '')) === 'PASSWORD') { $hasAuthTable = true; break 2; }
        }
    }
    if ($hasAuthTable && ($routeDefs || $badRouteDefs)) {
        foreach (['/login' => 'auth.renderLogin', '/signup' => 'auth.renderSignup'] as $authPath => $authHandler) {
            if (!in_array($authPath, $definedPaths, true)) {
                $findings[] = ai_validator_finding('error', 'route',
                    "Schema has a login system but no \"{$authPath}\" route is registered",
                    "Add router.defineRoute('{$authPath}', {$authHandler}) to the bootstrap — the platform auth pages, 401 redirects, and tests all depend on it.");
            }
        }
    }

    // ── Nav ↔ route consistency (dead links, unreachable routes) ──────────
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

    // ── Anon/authenticated policy gap ──────────────────────────────────────
    // Missing policy row = deny by default (Policy::check() in policy.php), so
    // if anon can do an operation on a table but authenticated has no matching
    // allowed policy, every logged-in request for it 403s. That's not just a
    // missing feature — the canonical api.js client (AI_CANONICAL_API_JS)
    // treats a 403 while a token is present as an invalid session and logs
    // the user out. Live-caught: a generated app's content_blocks table had
    // an anon SELECT policy but no authenticated one; content_blocks is read
    // by the home view immediately after every login, so every login was
    // followed by an immediate silent logout — looking exactly like a broken
    // token/reload bug when the actual cause was this policy gap.
    foreach ($tables as $tname => $t) {
        $allowedByRoleOp = [];
        foreach ($t['policies'] ?? [] as $p) {
            if (!($p['allowed'] ?? false)) continue;
            $allowedByRoleOp[$p['api_role'] ?? ''][strtoupper((string)($p['operation'] ?? ''))] = true;
        }
        foreach ($allowedByRoleOp['anon'] ?? [] as $op => $_) {
            if (empty($allowedByRoleOp['authenticated'][$op])) {
                $findings[] = ai_validator_finding('error', 'policy',
                    "\"{$tname}\" allows anon {$op} but has no matching authenticated {$op} policy",
                    "A logged-in user gets LESS access than an anonymous visitor here — every authenticated {$op} request 403s, since a missing policy row denies by default. "
                    . 'If any page reads this table right after login (e.g. the home view), this looks exactly like "logging in logs you out." Add a matching authenticated policy unless this table is deliberately anon-only.');
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
