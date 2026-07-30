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
// A feature module file's own folder/file name doesn't always match the JS
// identifier it actually declares — e.g. features/home/home.js commonly
// declares `const homeModule = {...}` (a "Module" suffix the file path
// itself doesn't carry), because index.html's route registrations
// (`defineRoute('/', homeModule.renderHome)`) read more naturally that way.
// The browser resolves that reference by the GLOBAL VARIABLE NAME alone —
// it has never heard of the file's path — so matching route handlers by
// path-derived name instead of the file's real declared identifier is a
// false-positive machine: a working, deployed route can get flagged
// "was never generated" forever, since no edit can fix a check that's
// looking for a name the file was never going to have.
function ai_validator_detect_module_identifier(string $js): ?string
{
    if (preg_match('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*[\{\(]/', $js, $m)) {
        return $m[1];
    }
    return null;
}

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

// ─── Hard-invariant checks (safe to run incrementally, per write) ───────────
// Unlike route/nav/script-src wiring (legitimately incomplete mid-build --
// e.g. index.html lists a script tag for a feature file not written yet, or
// a nav link precedes the route it will point to), these are NEVER
// transiently true: a `this` reference, an inline onX="", a bare auth.*
// reference with no PASSWORD column, and an api.* call against a nonexistent
// table are wrong the instant they're written, because the schema is fixed
// before frontend generation ever starts and a file's own syntax doesn't
// become more or less correct as sibling files arrive. Extracted here so
// ai_validator_check_project() (the full, end-of-generation/explicit-call
// pass) and the incremental per-write check below share one implementation
// instead of two regexes that could drift out of sync.
// this-ban + inline onX="" ban -- vanilla-stack-only (react has no shared-
// scope bare-reference trap and never write index.html/template-string
// markup this pattern applies to). Kept as one small function so
// ai_validator_check_project()'s existing two loops and the incremental
// per-write check below share one implementation.
function ai_validator_check_this_and_onx_ban(string $path, string $content, string $frontendStack): array
{
    if ($frontendStack === 'react') return [];
    $findings = [];
    $isCanonical = in_array($path, AI_PLATFORM_CANONICAL_PATHS, true);
    if (str_ends_with($path, '.js') && !$isCanonical && preg_match('/\bthis\b/', $content)) {
        $findings[] = ai_validator_finding('error', 'script',
            "{$path} contains the word \"this\"",
            'RULE 2B bans `this` outright in vanilla-stack code — every handler here is invoked as a bare function reference (route dispatch, addEventListener callbacks), never as module.method(), so `this` is never reliably bound to anything and using it crashes the instant that code path actually runs. Reference the module by its own top-level const name instead.');
    }
    if (!str_ends_with($path, '.jsx') && !$isCanonical && preg_match('/(?<=[\s"\'])on[a-z]+\s*=\s*["\']/i', $content, $m)) {
        $findings[] = ai_validator_finding('error', 'script',
            "{$path} contains an inline HTML event-handler attribute ({$m[0]})",
            'Inline onX="..." attributes run in the global object\'s scope, which cannot see a module\'s top-level const/let bindings — clicking/submitting/etc. will silently throw "X is not defined" and do nothing, in whatever file built this markup (static index.html or a template string a feature module assigned to innerHTML). Use addEventListener() instead, attached once the element exists in the DOM.');
    }
    return $findings;
}

// Bare `auth.*` reference with no PASSWORD column in the schema -- see
// ai_validator_check_project()'s own matching block for the live-caught
// incident this covers. Vanilla-only, same reasoning as the check above.
function ai_validator_check_bare_auth_reference(string $path, string $content, bool $hasAuthTable): array
{
    if ($hasAuthTable || in_array($path, AI_PLATFORM_CANONICAL_PATHS, true)) return [];
    $authMethodPattern = '/\bauth\.(' . implode('|', AI_VALIDATOR_AUTH_EXPORTS) . ')\b/';
    if (!preg_match($authMethodPattern, $content, $am)) return [];
    return [ai_validator_finding('error', 'script',
        "{$path} references \"auth.{$am[1]}\", but this schema has no PASSWORD column so features/auth/auth.js was never loaded",
        'There is no `auth` global anywhere on this page — this throws "auth is not defined" the moment the script runs and blanks the whole page. Remove every auth-gated nav element (nav-login, nav-logout, nav-authed-only) and every reference to auth.* from this file; this project has no login system.')];
}

// api.* call against a table the schema doesn't have -- stack-agnostic
// (react's canonical core/api.js exposes the same call shape), so this runs
// regardless of file extension, same as ai_validator_check_project()'s own
// unrestricted loop for it.
function ai_validator_check_api_table_mismatch(string $content, array $schema): array
{
    $tables = [];
    foreach ($schema['tables'] ?? [] as $t) $tables[$t['name']] = true;
    $findings = [];
    foreach (ai_validator_extract_api_calls($content) as $call) {
        if (!isset($tables[$call['table']])) {
            $findings[] = ai_validator_finding('error', 'schema',
                "Frontend calls api.{$call['op']}('{$call['table']}'), but no \"{$call['table']}\" table exists",
                'This call will 404 at runtime — likely a typo or a renamed/removed table.');
        }
    }
    return $findings;
}

// The getElementById check needs ids from every file, not just this one -- a
// feature file querying an id index.html defines (or vice versa) is the
// normal, correct pattern this must not flag. $allFiles is {path: content}
// for the FULL current set (everything already written, this write merged
// in) -- see ai_validator_check_project()'s own matching block for why
// "known ids" is collected from literal id="X" occurrences across every
// file rather than just index.html.
function ai_validator_check_getelementbyid_for_file(string $path, string $content, array $allFiles): array
{
    if (!str_ends_with($path, '.js') || in_array($path, AI_PLATFORM_CANONICAL_PATHS, true)) return [];
    $knownIds = [];
    foreach ($allFiles as $c) {
        if (preg_match_all('/\bid=["\']([\w-]+)["\']/', (string)$c, $m)) {
            foreach ($m[1] as $id) $knownIds[$id] = true;
        }
    }
    $findings = [];
    if (preg_match_all('/getElementById\(\s*["\']([\w-]+)["\']\s*\)/', $content, $m2)) {
        foreach (array_unique($m2[1]) as $id) {
            if (isset($knownIds[$id])) continue;
            $findings[] = ai_validator_finding('error', 'script',
                "{$path} calls document.getElementById('{$id}'), but no element with id=\"{$id}\" exists anywhere",
                'getElementById() returns null for a nonexistent id — the next line (almost always .innerHTML = ... or .addEventListener(...)) throws "Cannot set properties of null" the instant this code runs.');
        }
    }
    return $findings;
}

// Runs just the hard-invariant subset against ONE just-written file, for
// callers that want a decision (reject the write / let it through) on the
// same turn a mismatch is introduced instead of waiting for the explicit
// validate_frontend call or the end of generation -- see
// ai_agent_check_hard_invariants_for_write()'s call sites in the frontend/
// edit agent loops. Returns only 'error'-severity findings (nothing here is
// ever a 'warning'/'info').
function ai_validator_check_incremental_for_write(string $path, string $content, array $schema, string $frontendStack, array $allFiles): array
{
    $hasAuthTable = false;
    foreach ($schema['tables'] ?? [] as $t) {
        foreach ($t['columns'] ?? [] as $c) {
            if (strtoupper((string)($c['type'] ?? '')) === 'PASSWORD') { $hasAuthTable = true; break 2; }
        }
    }
    // getElementById is a vanilla-only concept (react has no index.html for
    // the agent to write ids into) -- $frontendStack gate here, same
    // reasoning as the other vanilla-only checks composed below.
    return array_merge(
        ai_validator_check_this_and_onx_ban($path, $content, $frontendStack),
        $frontendStack === 'react' ? [] : ai_validator_check_bare_auth_reference($path, $content, $hasAuthTable),
        ai_validator_check_api_table_mismatch($content, $schema),
        $frontendStack === 'react' ? [] : ai_validator_check_getelementbyid_for_file($path, $content, $allFiles)
    );
}

/**
 * Run all deterministic checks. $schema is ['tables' => [...], 'seed_data' => [...]]
 * (a build's sanitized plan has seed_data; ai_schema_from_db() for an edit does
 * not, since seed content lives in the live DB, not schema metadata — the
 * seed-data check is a no-op in that case, everything else still runs).
 * $frontendFiles is [{path, content}, ...].
 */
function ai_validator_check_project(array $schema, array $frontendFiles, string $frontendStack = 'vanilla'): array
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

    // Everything in this block is tied to the vanilla stack's own
    // conventions -- the inline onclick=/const-module pattern, the
    // features/<name>/<name>.js naming+export convention, router.defineRoute/
    // <script src>/nav-href-based routing. None of it applies to a react-
    // stack project: components are ES modules (no shared-scope onclick
    // trap possible), routing goes through core/router.js's useHashRoute()/
    // matchRoute()/navigate() instead of router.defineRoute()/<script src>,
    // and there is no index.html to check nav hrefs against (it's build-
    // generated, never agent-written). A failed esbuild build (see
    // ai_react_build_bundle()) is the stronger, more general gate that
    // replaces all of this for react — skip it entirely rather than run
    // regexes that were never designed to match JSX syntax and would only
    // ever produce false positives or false "all clear" here.
    if ($frontendStack !== 'react') {
    // Feature module exports, e.g. 'home' => ['renderView'], 'budgets' => ['renderBudgetsList', 'renderBudgetDetail']
    // Keyed by the JS identifier the file actually declares (see
    // ai_validator_detect_module_identifier()'s doc comment), falling back to
    // the folder name only when nothing better can be found in the file —
    // for the common case where they genuinely do match, this changes
    // nothing.
    $exportsByModule = [];
    $pathByModule    = []; // real file path for each detected identifier — for the script-src check below
    foreach ($byPath as $path => $content) {
        if (!preg_match('#^features/([a-zA-Z0-9_]+)/\1\.js$#', $path, $m)) continue;
        $ident = ai_validator_detect_module_identifier($content) ?? $m[1];
        $exportsByModule[$ident] = ai_validator_extract_exports($content, $ident);
        $pathByModule[$ident]    = $path;
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

    // ── RULE 2B, mechanically enforced: `this` and inline onX="" attributes ──
    // Two prior incidents (job 220: an inline onclick="todo.deleteTask(...)"
    // silently swallowed "todo is not defined"; job 221: a route handler
    // called as a bare reference lost its `this` receiver) looked like
    // unrelated one-off bugs and each got its own narrow regex here, matched
    // to that incident's exact shape (specifically: an inline onclick that
    // references a const/let-declared identifier by name). Both actually
    // share one root cause — code in this stack is constantly invoked as a
    // bare reference (route dispatch, addEventListener callbacks, inline
    // attributes), never as obj.method() — so neither `this` nor an inline
    // event attribute is EVER reliably bound to anything, regardless of what
    // identifier or property it happens to reference. Rather than write a
    // third narrow variant next time this surfaces differently, these two
    // checks ban both categories outright, matching RULE 2B's system prompt
    // wording exactly ("banned outright, no exceptions") — no per-incident
    // matching required, so a new variant can't slip through a gap the old
    // regexes didn't anticipate.
    // Live-caught (job 228): this used to run over EVERY .js path, including
    // platform-canonical ones (core/router.js, core/api.js, core/errors.js)
    // that are always force-injected with fixed content at deploy time
    // regardless of what's written here -- see AI_PLATFORM_CANONICAL_PATHS's
    // own doc comment. A "this" finding against one of those isn't a bug the
    // model can ever fix; it sent an autofix attempt into a dozen-turn loop
    // rereading and search_code'ing those exact files hunting for something
    // to change that was never going to be there. Both checks (and the
    // canonical-path exclusion) now live in ai_validator_check_this_and_onx_ban()
    // so the full validator and the incremental per-write check can't drift.
    foreach ($byPath as $path => $content) {
        $findings = array_merge($findings, ai_validator_check_this_and_onx_ban($path, $content, $frontendStack));
    }

    // ── getElementById() targeting an id that exists nowhere ────────────────
    // Live-caught (job 229): renderView() called document.getElementById('X')
    // where "X" was never present as an id="X" attribute anywhere the model
    // wrote -- getElementById() returns null for a nonexistent id, and the
    // very next line (almost always .innerHTML = ...) throws "Cannot set
    // properties of null" the instant that route renders. Root cause: RULE
    // 2's own worked example never actually shows a concrete <div id="app">
    // markup (only the nav/bootstrap script fragment), so nothing pins down
    // one canonical mount-point id, and the model's index.html and its
    // feature files can drift apart on what that id is called.
    // "Known ids" is built from literal id="X" occurrences across EVERY
    // file, not just index.html's real markup -- a feature file that both
    // creates an id inside its own innerHTML template string (e.g.
    // `id="add-task-form"`) and later queries it by that same literal id is
    // a common, entirely legitimate pattern that must not false-positive
    // here. Only a genuinely interpolated id (e.g. id="${task.id}") never
    // matches this literal-string regex in the first place, so dynamic
    // per-row ids are naturally excluded on both sides — never collected as
    // "known" and never checked as a getElementById() target, since a
    // dynamic id is looked up by class/attribute selector in idiomatic code,
    // not a literal getElementById() call. Shared with the incremental
    // per-write check via ai_validator_check_getelementbyid_for_file().
    foreach ($byPath as $path => $content) {
        $findings = array_merge($findings, ai_validator_check_getelementbyid_for_file($path, $content, $byPath));
    }

    // ── Dangling <script src> — index.html links a file that was never written ──
    // The opposite direction of the module/route check below (which catches a
    // written file that ISN'T loaded); this catches a LOADED path that was
    // never written at all. Live-caught (job 218, "Fun Facts App"): index.html
    // linked ./features/content/detail.js, which the frontend agent never got
    // around to writing — nothing detected this until deploy's own (separate,
    // more expensive) smoke check caught it and refused to publish, with no
    // corrective retry ever attempted. core/router.js, core/api.js,
    // core/errors.js and features/auth/auth.js are injected by the platform
    // at deploy time (see ai_inject_canonical_frontend_files()) so they never
    // appear in $frontendFiles here — excluded, not a bug.
    foreach (array_unique($scriptSrcs) as $src) {
        if (in_array($src, AI_PLATFORM_CANONICAL_PATHS, true)) continue;
        if (preg_match('#^(?:https?:)?//#i', $src)) continue; // external CDN script (e.g. Tailwind) — never local
        if (!isset($byPath[$src])) {
            $findings[] = ai_validator_finding('error', 'script',
                "index.html loads \"{$src}\" via <script src>, but that file was never written",
                'This will 404 in the browser the moment the page loads, and deploy\'s own smoke check will refuse to publish the build at all until this is fixed.');
        }
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
            $modPath = $pathByModule[$sm[1]] ?? "features/{$sm[1]}/{$sm[1]}.js";
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

    // ── Bare `auth.*` usage with no PASSWORD column ────────────────────────
    // The route-handler check above only catches auth.* referenced as a
    // router.defineRoute() target. Live-caught (job 227): a no-auth project's
    // bootstrap script called auth.getCurrentUser()/auth.ready/auth.logout()
    // directly (not as a route handler) despite correctly leaving out the
    // auth.js <script src> tag per RULE 3 — the `auth` global genuinely does
    // not exist anywhere on that page, and referencing it throws "auth is not
    // defined" the instant the bootstrap script runs, blanking the whole
    // page. That pattern is invisible to the route-handler check, so it needs
    // its own scan across every file's actual content, not just parsed route
    // definitions. Scoped to the known real auth methods (not a bare `\bauth\b`
    // match) so an unrelated identifier like `authForm` or a comment
    // mentioning "the auth.js file" can't false-positive.
    // Shared with the incremental per-write check via
    // ai_validator_check_bare_auth_reference() -- that function's own
    // $hasAuthTable-gate makes the loop a no-op when the schema has auth, so
    // no `if (!$hasAuthTable)` wrapper is needed here anymore.
    foreach ($byPath as $path => $content) {
        $findings = array_merge($findings, ai_validator_check_bare_auth_reference($path, $content, $hasAuthTable));
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

    } // end vanilla-only checks ($frontendStack !== 'react')

    // ── API table references ↔ schema, CRUD completeness ──────────────────
    // Stack-agnostic: react's canonical core/api.js exposes the exact same
    // api.list/get/create/update/remove('table', ...) call shape the agent
    // writes directly in JSX, so this regex-based check is just as valid
    // there as it is for vanilla.
    // Table-mismatch findings come from ai_validator_check_api_table_mismatch()
    // (shared with the incremental per-write check); $apiCallsByTable itself
    // still needs building here regardless, for the CRUD-completeness check
    // just below.
    $apiCallsByTable = [];
    foreach ($byPath as $content) {
        foreach (ai_validator_extract_api_calls($content) as $call) {
            $apiCallsByTable[$call['table']][] = $call['op'];
        }
        $findings = array_merge($findings, ai_validator_check_api_table_mismatch($content, $schema));
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
