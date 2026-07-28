<?php

declare(strict_types=1);

// These paths are force-injected with fixed canonical content at deploy
// time (see ai_deploy.php) regardless of what the model writes to them —
// read-only in effect. Shared here so ai_run_edit_agent_tool()'s read
// override and ai_smoke_test_extract_failing_location()'s file-pointer
// logic can't drift apart into two different ideas of "which paths are
// actually editable."
const AI_PLATFORM_CANONICAL_PATHS = ['core/router.js', 'core/api.js', 'core/errors.js', 'features/auth/auth.js'];


// sb_log() (bootstrap.php) writes through PHP's own error_log ini setting,
// which resolves a bare "error_log" value relative to the CURRENT WORKING
// DIRECTORY at the time of the call. Fine for anything invoked from a web
// request (cwd is the docroot), but the AI job worker is spawned via a bare
// exec($phpBin ... &) with no explicit cd (see ai_spawn_job_worker()), so its
// actual cwd is whatever the parent web request happened to have, not
// guaranteed to be the docroot. Live-confirmed on a real job: this produced
// zero matching log lines despite an identical manual CLI test (run with an
// explicit cd first) working fine. Writes to an explicit absolute path
// instead, so it lands in the same place regardless of how or from where the
// process was launched. Diagnostic-only, own file, no byte cap the way
// ai_jobs.progress/result have, and — critically — never read by anything
// user-facing (see ai_log_agent_write_content()'s doc comment for why that
// separation matters).
function ai_pipeline_debug_log(string $context, string $message, array $data = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $context . '] ' . $message
          . ($data ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '') . "\n";
    @file_put_contents(SUPABEIN_ROOT . '/storage/ai_pipeline_debug.log', $line, FILE_APPEND | LOCK_EX);
}

// ─── AI output helpers ───────────────────────────────────────────────────────

/**
 * Lenient JSON extraction for models that wrap output in ```json fences, add
 * prose, or otherwise produce something JSON-*shaped* rather than valid JSON.
 * Every AI provider client falls back to this after a plain json_decode()
 * fails, so it's the single place that determines how often "the model's
 * output didn't parse" turns into a real retry versus a recovered result.
 *
 * Handles, in order: a fenced code block anywhere in the text (not just at
 * the very start); a bare top-level array as well as an object; and, if a
 * clean parse of the extracted candidate still fails, a string-aware repair
 * pass (see ai_json_repair()) for the specific defects models actually
 * produce -- then finally, if the response was truncated (ran out of
 * max_tokens mid-structure), a best-effort salvage that drops only the
 * incomplete trailing element instead of failing the whole response.
 */
function ai_lenient_json(string $raw): ?array
{
    $s = trim($raw);
    if ($s === '') return null;

    // Prefer a fenced code block if one exists anywhere in the text -- a
    // model asked for raw JSON can still preface it with prose ("Here's the
    // JSON:\n```json\n{...}\n```") or wrap it in a fence out of habit.
    if (preg_match('/```(?:json|js)?\s*\r?\n(.*?)```/is', $s, $m)) {
        $fenced = trim($m[1]);
        if ($fenced !== '') $s = $fenced;
    }

    // Candidate root is whichever of { or [ appears first -- a plain strpos
    // search for only { would silently miss a bare top-level JSON array.
    $braceStart   = strpos($s, '{');
    $bracketStart = strpos($s, '[');
    if ($braceStart === false && $bracketStart === false) return null;
    $start = ($braceStart === false) ? $bracketStart
           : (($bracketStart === false) ? $braceStart : min($braceStart, $bracketStart));

    // Scan forward tracking nesting + string state so a { or [ inside a
    // string literal can't skew depth. Each stack frame also remembers the
    // position of the last top-level-for-that-frame comma seen (its own
    // "safe rollback point") -- used only if the response turns out to be
    // truncated, to drop an incomplete trailing element instead of trying
    // to keep corrupted partial data.
    $depth = 0; $inStr = false; $esc = false; $end = null;
    $stack = [];
    for ($i = $start, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($inStr) {
            if ($esc)            { $esc = false; }
            elseif ($c === '\\') { $esc = true; }
            elseif ($c === '"')  { $inStr = false; }
            continue;
        }
        if ($c === '"') { $inStr = true; continue; }
        if ($c === '{' || $c === '[') { $stack[] = ['open' => $c, 'safePos' => null]; $depth++; continue; }
        if ($c === ',' && $stack) { $stack[count($stack) - 1]['safePos'] = $i; continue; }
        if ($c === '}' || $c === ']') {
            if ($stack) array_pop($stack);
            $depth--;
            if ($depth === 0) { $end = $i; break; }
        }
    }

    $candidates = [];
    if ($end !== null) {
        $candidates[] = substr($s, $start, $end - $start + 1);
    } elseif ($inStr) {
        // Truncated mid-string -- an unambiguous "ran out of tokens here"
        // signal. Roll back to the innermost still-open frame's own last
        // complete sibling; if that frame never completed even its first
        // element, keep popping outward until one that has, dropping every
        // wholly-incomplete frame in between. Recovers as much of the
        // response as was actually finished, instead of either keeping
        // corrupted partial content or discarding the whole response.
        $cutPos = null; $keepDepth = 0;
        for ($k = count($stack) - 1; $k >= 0; $k--) {
            if ($stack[$k]['safePos'] !== null) {
                $cutPos = $stack[$k]['safePos'];
                $keepDepth = $k + 1;
                break;
            }
        }
        if ($cutPos !== null) {
            $tail = substr($s, $start, $cutPos - $start);
            for ($k = $keepDepth - 1; $k >= 0; $k--) {
                $tail .= $stack[$k]['open'] === '{' ? '}' : ']';
            }
            $candidates[] = $tail;
        }
    } else {
        // Truncated, but not mid-string -- the trailing token (a number,
        // true/false/null, or a nested structure's own closing bracket) may
        // already be complete; don't second-guess it, just close what's
        // still open. json_decode is the real arbiter of whether it holds up.
        $tail = substr($s, $start);
        $tail = preg_replace('/,\s*$/', '', $tail);
        for ($k = count($stack) - 1; $k >= 0; $k--) {
            $tail .= $stack[$k]['open'] === '{' ? '}' : ']';
        }
        $candidates[] = $tail;
    }

    foreach ($candidates as $candidate) {
        $data = json_decode($candidate, true);
        if (is_array($data)) return $data;

        $repaired = ai_json_repair($candidate);
        if ($repaired !== null) {
            $data = json_decode($repaired, true);
            if (is_array($data)) return $data;
        }
    }

    return null;
}

/**
 * String-aware light repair pass for the class of "obviously meant to be
 * JSON, one small thing wrong" replies models actually produce: a raw
 * (unescaped) newline/tab inside a string value -- the single most common
 * failure for this app specifically, since file contents get embedded as
 * JSON string values -- a trailing comma before a closing bracket, a // or
 * /* * / comment, or a bare NaN/Infinity/undefined/Python-style literal.
 * Every repair below only ever fires OUTSIDE a string literal (except the
 * control-character escape, which only ever fires INSIDE one) via the same
 * single string-aware scan, so real string content -- a URL containing
 * "//", the literal word "undefined" typed into a text field -- is never
 * touched by anything except the one repair that's specifically about it.
 * Returns null (meaning "nothing to repair") rather than an unchanged copy,
 * so the caller can skip a redundant identical decode attempt.
 */
function ai_json_repair(string $s): ?string
{
    $out = '';
    $inStr = false; $esc = false;
    $n = strlen($s);
    $changed = false;
    $literals = [
        ['Infinity', 'null'], ['NaN', 'null'], ['undefined', 'null'],
        ['None', 'null'], ['True', 'true'], ['False', 'false'],
    ];

    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($inStr) {
            if ($esc)            { $out .= $c; $esc = false; continue; }
            if ($c === '\\')     { $out .= $c; $esc = true; continue; }
            if ($c === '"')      { $out .= $c; $inStr = false; continue; }
            if ($c === "\n")     { $out .= '\\n'; $changed = true; continue; }
            if ($c === "\r")     { $out .= '\\r'; $changed = true; continue; }
            if ($c === "\t")     { $out .= '\\t'; $changed = true; continue; }
            $out .= $c;
            continue;
        }

        if ($c === '"') { $out .= $c; $inStr = true; continue; }

        if ($c === '/' && ($s[$i + 1] ?? '') === '/') {
            $nl = strpos($s, "\n", $i);
            $i = ($nl === false) ? $n - 1 : $nl - 1;
            $changed = true;
            continue;
        }
        if ($c === '/' && ($s[$i + 1] ?? '') === '*') {
            $endC = strpos($s, '*/', $i + 2);
            $i = ($endC === false) ? $n - 1 : $endC + 1;
            $changed = true;
            continue;
        }

        if ($c === ',') {
            $j = $i + 1;
            while ($j < $n && ($s[$j] === ' ' || $s[$j] === "\n" || $s[$j] === "\r" || $s[$j] === "\t")) $j++;
            if ($j < $n && ($s[$j] === '}' || $s[$j] === ']')) {
                $changed = true;
                continue; // drop the trailing comma
            }
        }

        $matched = false;
        foreach ($literals as [$needle, $replacement]) {
            $len = strlen($needle);
            if (substr($s, $i, $len) === $needle
                && !ctype_alnum($s[$i - 1] ?? ' ')
                && !ctype_alnum($s[$i + $len] ?? ' ')) {
                $out .= $replacement;
                $i += $len - 1;
                $changed = true;
                $matched = true;
                break;
            }
        }
        if ($matched) continue;

        $out .= $c;
    }

    return $changed ? $out : null;
}

/**
 * Collect top-level (global-scope) const/let/var names from a JS string.
 * Strips strings, templates, and comments so their braces don't skew depth.
 */
function ai_collect_top_level_decls(string $js): array
{
    $clean = preg_replace('#/\*.*?\*/#s', '', $js);
    $clean = preg_replace('#//[^\n]*#', '', (string)$clean);
    $clean = preg_replace('#"(?:\\\\.|[^"\\\\])*"#s', '""', (string)$clean);
    $clean = preg_replace("#'(?:\\\\.|[^'\\\\])*'#s", "''", (string)$clean);
    $clean = preg_replace('#`(?:\\\\.|[^`\\\\])*`#s', '``', (string)$clean);

    $names = [];
    $depth = 0;
    $len   = strlen((string)$clean);
    for ($i = 0; $i < $len; $i++) {
        $ch = $clean[$i];
        if ($ch === '{' || $ch === '(' || $ch === '[') { $depth++; continue; }
        if ($ch === '}' || $ch === ')' || $ch === ']') { $depth = max(0, $depth - 1); continue; }
        if ($depth === 0 && ($ch === 'c' || $ch === 'l' || $ch === 'v')) {
            if (preg_match('/(const|let|var)\s+([A-Za-z_$][\w$]*)/A', $clean, $m, 0, $i)) {
                $names[] = $m[2];
                $i += strlen($m[0]) - 1;
            }
        }
    }
    return $names;
}

/**
 * Static pre-publish smoke check on the assembled deploy directory.
 * Returns an error string (deploy should be rejected) or null if it passes.
 */
function ai_smoke_check_dir(string $dir): ?string
{
    $indexPath = $dir . '/index.html';
    if (!is_file($indexPath)) return 'index.html is missing';
    $html = (string)file_get_contents($indexPath);

    // 1. No absolute script paths (break on subdomain hosting).
    if (preg_match('#<script[^>]+src\s*=\s*["\']/[^"\']#i', $html)) {
        return 'index.html loads a script with an absolute path (src="/..."); use relative paths';
    }

    // 2. Every referenced local script must exist in the assembled deploy.
    if (preg_match_all('#<script[^>]+src\s*=\s*["\']([^"\']+)["\']#i', $html, $m)) {
        foreach ($m[1] as $src) {
            if (preg_match('#^https?://#i', $src)) continue;
            $rel = ltrim(preg_replace('#^\./+#', '', $src), '/');
            if (!is_file($dir . '/' . $rel)) {
                return "index.html references a missing script: {$src}";
            }
        }
    }

    // 3. Duplicate top-level const/let across all classic scripts → fatal SyntaxError.
    $counts = [];
    $record = function (string $js) use (&$counts) {
        foreach (ai_collect_top_level_decls($js) as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
    };

    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'js') {
            $record((string)file_get_contents($f->getPathname()));
        }
    }
    // inline <script> blocks in index.html (those WITHOUT a src attribute)
    if (preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $html, $sm)) {
        foreach ($sm[1] as $block) {
            $record($block);
        }
    }

    $dupes = array_keys(array_filter($counts, fn($c) => $c > 1));
    if ($dupes) {
        return 'duplicate top-level declaration(s) — fatal "already declared" SyntaxError: '
             . implode(', ', $dupes)
             . '. Each module/global must be declared exactly once.';
    }

    return null;
}

// ─── Frontend file reader ────────────────────────────────────────────────────

// Review-off ("watch only") builds deploy to staging first and only promote to
// the "current" (live/published) slot on an explicit Publish click — so a
// project can sit in staging-only for its entire test-and-fix loop. Anything
// that needs to read "what's actually deployed right now" (edit-mode context,
// validation merges) must prefer staging over current, exactly like
// ai_run_project_tests() already does when picking what to test against —
// otherwise it silently reads an empty/nonexistent "current" deploy on any
// project that hasn't been published yet.
function ai_effective_deploy_target(array $site): string
{
    if ($site['staging_deploy_id'] ?? null) return 'staging';
    return 'current';
}

function ai_classify_error(string $msg): string
{
    $lower = strtolower($msg);
    if (str_contains($lower, '429') || str_contains($lower, 'rate limit') || str_contains($lower, 'too many requests')) return 'rate_limit';
    if (str_contains($lower, '401') || str_contains($lower, 'invalid key') || str_contains($lower, 'api key')) return 'api_key';
    if (str_contains($lower, '403') || str_contains($lower, 'permission denied')) return 'permission';
    if (str_contains($lower, 'no content') || str_contains($lower, 'content filter') || str_contains($lower, 'safety') || str_contains($lower, 'blocked')) return 'content_filter';
    if (str_contains($lower, 'not valid json') || str_contains($lower, 'invalid json') || str_contains($lower, 'unexpected response')) return 'invalid_json';
    if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) return 'timeout';
    if (str_contains($lower, 'network') || str_contains($lower, 'curl') || str_contains($lower, 'connection')) return 'network';
    if (str_contains($lower, '500') || str_contains($lower, '503') || str_contains($lower, 'internal server error')) return 'provider_error';
    return 'unknown';
}

function ai_abort_error(string $stage, string $msg): never
{
    if (str_contains(strtolower($msg), 'credits') || str_contains(strtolower($msg), 'quota')) {
        abort(402, $msg, ['stage' => $stage, 'code' => 'rate_limit', 'raw' => $msg]);
    }
    abort(502, 'AI error', ['stage' => $stage, 'code' => ai_classify_error($msg), 'raw' => $msg]);
}

// A hard, permanent provider failure (a daily free-tier quota exhausted, out
// of credits, an invalid key) fails identically no matter how many more turns
// are spent retrying it -- unlike a truncated or malformed JSON response,
// which retrying can genuinely fix. Every agent loop below used to treat
// every \Throwable from generateJsonWithHistory() the same way ("recoverable,
// give it another turn"), which for one of these silently burned the entire
// turn budget doing nothing but repeat the exact same doomed call, then
// force-finished with an empty delta that looks like a normal (if unusually
// small) result -- an edit that changed nothing sails straight through
// validate+deploy+test looking successful, with the user's actual request
// never having been attempted and no visible failure anywhere.
function ai_is_unrecoverable_provider_error(string $msg): bool
{
    $msg = strtolower($msg);
    if (str_contains($msg, 'rate limit') && (str_contains($msg, 'per-day') || str_contains($msg, 'per day') || str_contains($msg, 'daily'))) return true;
    foreach (['insufficient credit', 'insufficient balance', 'add credit', 'add 10 credits', 'credit balance is too low', 'quota exceeded', 'exceeded your current quota', 'invalid api key', 'unauthorized'] as $needle) {
        if (str_contains($msg, $needle)) return true;
    }
    // Live-caught: attaching an image to a build/edit request can land on a
    // text-only model deep in the OpenRouter/NVIDIA fallback chain (not
    // every model of the several dozen routed through OPENROUTER's tiers
    // supports vision), which rejects the request outright instead of just
    // ignoring the image — e.g. OpenRouter's "No endpoints found that
    // support image input". Without this, the whole job hard-fails instead
    // of moving on to the next candidate the same way a rate limit does.
    foreach (['support image input', 'support images', 'does not support vision', 'multimodal messages are not supported', 'image_url is not supported'] as $needle) {
        if (str_contains($msg, $needle)) return true;
    }
    // A request that times out (curl's own "Operation timed out..."/"Connection
    // timed out" wording, or DNS/connect-level failures) never reached the
    // model at all -- retrying the exact same provider is no more likely to
    // succeed than the first attempt was, whereas the next candidate in the
    // chain is live right now. Treated as unrecoverable so it falls forward
    // instead of surfacing the timeout straight to the caller.
    foreach (['timed out', 'timeout', 'could not resolve host', 'couldn\'t resolve host', 'connection refused', 'connection reset', 'empty reply from server', 'failed to connect'] as $needle) {
        if (str_contains($msg, $needle)) return true;
    }
    // Groq's free tier enforces a small per-model tokens-per-minute budget
    // that covers prompt + max_tokens together ("Request too large ... on
    // tokens per minute (TPM)", code rate_limit_exceeded) -- live-measured
    // at 6000-12000 tokens depending on model, small enough that a large
    // system prompt alone can permanently exceed a given model's whole
    // budget no matter how MaxTokensProbe shrinks max_tokens. Falling
    // forward to the next candidate is the only way such a request ever
    // completes, same reasoning as a daily rate limit.
    if (str_contains($msg, 'tokens per minute') || str_contains($msg, 'rate_limit_exceeded')) return true;
    // OpenRouter's free-tier (":free"-suffixed) models come and go -- a
    // slug can stop routing entirely ("No endpoints found for X"), get
    // pulled from the free tier ("This model is unavailable for free..."),
    // or its backing provider can flake on a given request ("Provider
    // returned error"). All three are about the CANDIDATE being
    // unreachable/unusable right now, not about this specific request or
    // response quality -- unlike a malformed-JSON response (deliberately
    // NOT included here: that's the caller's own retry loop's job, since a
    // same-model retry often just works, and jumping providers over what's
    // frequently a one-off glitch would undermine that for the strong
    // providers ahead of this in the chain).
    foreach (['no endpoints found', 'unavailable for free', 'provider returned error'] as $needle) {
        if (str_contains($msg, $needle)) return true;
    }
    return false;
}

// A rate-limited request never reached the model at all -- there is no
// "response" to have been malformed. Live-caught by profiling a real test
// run: of 21 turns that showed "Response was invalid, retrying…" on
// screen, 20 were plain HTTP 429s from the provider, not the model
// producing bad JSON. Every one of the three agent turn loops below caught
// ALL \Throwable the same way and labeled every one of them identically,
// which is what made a pure rate-limiting problem look like a model/schema
// quality problem in the UI and in any trace pulled afterward for analysis.
function ai_agent_is_rate_limited(string $errorMsg): bool
{
    return stripos($errorMsg, '429') !== false
        || stripos($errorMsg, 'too many requests') !== false
        || stripos($errorMsg, 'rate limit') !== false;
}

// General stuck-loop detector shared by every agent turn loop below. Two
// live-caught bugs this session — an edit agent re-reading the same
// unchanged file ~9 turns in a row, and a test agent re-attempting login
// over and over off one wrong hypothesis — turned out to be the same
// underlying gap wearing different clothes: nothing noticed "the last few
// tool calls are byte-identical and nothing is changing." Rather than patch
// each new instance of this pattern with its own one-off prompt rule
// forever, this catches the whole class: if the exact same (tool, args)
// repeats $threshold times in a row, the call is skipped (no point actually
// re-running something that will just return what it already did) and the
// caller gets a forcing nudge back instead, telling the model plainly that
// repeating this exact action isn't working and to do something else.
// $recentCalls is the loop's own rolling window, passed by reference so it
// persists turn to turn; resets naturally the moment a different action
// breaks the streak.
function ai_agent_detect_stuck_repeat(array &$recentCalls, string $tool, array $args, int $threshold = 3): bool
{
    $signature = $tool . ':' . json_encode($args);
    $recentCalls[] = $signature;
    if (count($recentCalls) > $threshold) {
        array_shift($recentCalls);
    }
    if (count($recentCalls) < $threshold) {
        return false;
    }
    $stuck = count(array_unique($recentCalls)) === 1;
    if ($stuck) {
        $recentCalls = []; // give the next, different action a clean window
    }
    return $stuck;
}

// A model that fails to produce valid JSON for one action rarely recovers by
// just being told "try again" — left alone, the retry catch blocks below
// repeat that identical soft nudge every time, and a model that's stuck
// tends to send back the SAME broken response verbatim turn after turn.
// Confirmed live: one build failed the same write_file action 4 times in a
// row, ~11 minutes of retries with zero forward progress, before finally
// succeeding on the 5th attempt. Escalate to a much more forceful
// instruction once a few consecutive failures pile up, mirroring how
// ai_agent_detect_stuck_repeat() above already escalates for a *successful*
// action repeated pointlessly.
function ai_agent_note_parse_failure(int &$consecutiveFailures, string $errorMsg, int $threshold = 2): string
{
    $consecutiveFailures++;
    if ($consecutiveFailures < $threshold) {
        return "\n\n(Your previous response to this could not be parsed as valid JSON — it may have been cut "
             . "off: {$errorMsg}. Respond again with a single valid JSON action; if you were writing a large "
             . 'file, keep the content more concise.)';
    }
    return "\n\n(You have now failed to produce valid JSON {$consecutiveFailures} times in a row for this exact "
         . "action: {$errorMsg}. Repeating the same attempt again will not work either. Do ONE of: write a "
         . 'substantially shorter/simpler version of whatever you were writing, split it into a smaller piece, '
         . 'switch to a completely different action, or call finish with whatever is already staged.)';
}

// finish() has its own rejection branch in both agent loops, separate from
// the generic stuck-repeat check, so a rejected finish() was never covered
// by any escalation at all — live-observed: one build called finish() 14
// times in a row, each rejected for the same unresolved smoke_test failure,
// repeating an identical fabricated "all done" justification every time
// until the turn budget forced an exit. Mirrors
// ai_agent_note_parse_failure()'s shape: the same static rejection reason
// for the first couple of attempts, then a much more forceful, specific
// instruction once it's clearly not being acted on.
function ai_agent_note_finish_rejected(int &$consecutiveRejections, string $baseReason, int $threshold = 2): string
{
    $consecutiveRejections++;
    if ($consecutiveRejections < $threshold) {
        return $baseReason;
    }
    return "You have now called finish() {$consecutiveRejections} times in a row without resolving this. "
         . 'Calling finish() again unchanged will keep being rejected. Call smoke_test right now, read what '
         . 'it actually reports, and fix that specific problem — do not call finish again until you have.';
}

// How many write_file/write_files/patch_file calls are allowed in a row
// before a smoke_test is forced. Live-observed in job 210: 55+ writes to one
// file against only 6 smoke_test checks across a ~13-minute phase, with
// console_errors byte-identical for 10 straight minutes — the agent kept
// writing blind between checks for far longer than any check-driven loop
// would, letting drift compound before anything caught it. Nothing forced a
// check; it was fully agent-discretionary. This is a mechanical cap, not a
// prompt request, for the same reason the finish() gate below is mechanical:
// an instruction the model can choose to skip isn't a fix, a fact it can't
// route around is.
const AI_AGENT_MAX_UNVERIFIED_WRITES = 4;

function ai_agent_note_unverified_writes_blocked(int $writesSinceCheck): string
{
    return "You've made {$writesSinceCheck} file writes in a row without running smoke_test to check any of "
         . 'them. Errors compound the longer they go unchecked, and a check now is far cheaper than untangling '
         . 'several stacked changes later. Call smoke_test now before writing any more files.';
}

// Job 210's first auto-fix attempt, after several smoke_test checks came
// back with the exact same error, didn't narrow its diagnosis — it rewrote
// counter.js from scratch, abandoned the canonical api.* wrapper for raw
// fetch(), and introduced two NEW bugs in the process (a `response.()`
// syntax error and a truncated Content-Type header) on top of the original,
// still-unfixed one. A full rewrite under repeated-failure pressure is a
// worse failure mode than the bug itself: it discards a working 90% to
// gamble on a new 100%, and dropping api.* specifically breaks this
// platform's auth/error-handling contract (401 handling, the real error
// body surfaced by the api.js fix above) that every generated app relies on.
// Detects the abandonment mechanically — same content-fact approach as the
// finish() file-diff gate — rather than trusting the model to notice its own
// pattern.
function ai_agent_check_canonical_wrapper_abandonment(string $oldContent, string $newContent): bool
{
    $wrapperPattern = '/\bapi\.(list|get|create|update|remove)\s*\(/';
    $usedWrapper = (bool)preg_match($wrapperPattern, $oldContent);
    if (!$usedWrapper) return false;
    $stillUsesWrapper = (bool)preg_match($wrapperPattern, $newContent);
    $nowUsesRawFetch = (bool)preg_match('/\bfetch\s*\(/', $newContent);
    return !$stillUsesWrapper && $nowUsesRawFetch;
}

// How many times in a row smoke_test must return the exact same
// console_errors before a write to the implicated file is checked for
// canonical-wrapper abandonment. >=2 means this is at least the 3rd
// identical failure — enough repeats that "try a bigger rewrite" is a
// meaningfully different (and riskier) move than "keep narrowing," not a
// hair-trigger on the first retry.
const AI_AGENT_MAX_IDENTICAL_SMOKE_FAILURES = 2;

function ai_agent_note_repeated_failure_rewrite_blocked(string $file): string
{
    return "smoke_test has failed with the exact same error 3 times in a row on {$file} — repeating the same "
         . "small edits wasn't finding the real cause, but rewriting the file to drop the api.* wrapper for raw "
         . 'fetch() is not the fix either: it breaks this platform\'s auth/error-handling contract and risks '
         . "introducing new bugs on top of the one that's still unfixed. Keep using api.list/get/create/update/"
         . "remove. Instead, read_file {$file} again, read the EXACT error text closely (not just its status "
         . 'code or which line it points to), and make one small, targeted change addressing that specific '
         . 'error — do not rewrite the whole file.';
}

// The full aiTrace (every tool call, every raw smoke_test result) only
// reaches ai_jobs.result once the job finishes -- while it's still running,
// polling ai_jobs.progress (what $report() writes, live) only ever showed a
// static PRE-dispatch label like "Loading in a real browser to check for
// errors…", never the actual outcome. That made it genuinely impossible to
// tell, while a job was still running, why a given smoke_test attempt
// failed -- not a missing tool, a real gap in what got reported at all.
// Capped short and only called on a real failure, so it doesn't meaningfully
// grow the cost of appendJobProgress()'s already-O(n) rewrite-the-whole-array
// pattern the way echoing full file contents or the whole aiTrace would.
function ai_smoke_test_failure_progress_detail(array $smokeTestResult): string
{
    $errors = $smokeTestResult['console_errors'] ?? [];
    $summary = is_array($errors) && $errors
        ? implode(' | ', array_slice(array_map('strval', $errors), 0, 2))
        : (string)($smokeTestResult['error'] ?? 'no console errors captured');
    if (mb_strlen($summary) > 300) $summary = mb_substr($summary, 0, 300) . '…';
    return "smoke_test failed: {$summary}";
}

// The bare path in "Writing core/config.js…" answers WHAT changed, never
// WHAT IT ACTUALLY WROTE — watching a job live gave no way to see the real
// content, only find out after the fact from the finished job's aiTrace.
// This does NOT go through $report()/ai_jobs.progress: that field is
// rendered directly into the real dashboard's live AI panel
// (dashboard/assets/app.js's progress-detail div) for actual customers
// watching their app get built — dumping raw source into that UI is a real
// product-UX regression, not a diagnostics improvement. ai_pipeline_debug_log()
// instead: a server-log-only channel with zero UI exposure, so content is
// logged in full — the cap below is only a last-resort ceiling against one
// truly pathological generation, not a real limit for any realistic file.
function ai_log_agent_write_content(string $agentType, string $tool, array $args, ?int $projectId = null): void
{
    $cap = function (string $content, int $limit): string {
        return mb_strlen($content) > $limit ? mb_substr($content, 0, $limit) . '…(truncated — pathologically large)' : $content;
    };

    $body = null;
    if ($tool === 'write_file') {
        $path = (string)($args['path'] ?? '?');
        $body = "write_file {$path}:\n" . $cap((string)($args['content'] ?? ''), 100000);
    } elseif ($tool === 'write_files') {
        $files = is_array($args['files'] ?? null) ? $args['files'] : [];
        if (!$files) return;
        $parts = array_map(
            fn($f) => '--- ' . (string)($f['path'] ?? '?') . " ---\n" . $cap((string)($f['content'] ?? ''), 100000),
            $files
        );
        $body = "write_files (" . count($files) . " file(s)):\n" . implode("\n\n", $parts);
    } elseif ($tool === 'patch_file') {
        $path = (string)($args['path'] ?? '?');
        $body = "patch_file {$path}:\n- " . $cap((string)($args['find'] ?? ''), 100000)
              . "\n+ " . $cap((string)($args['replace'] ?? ''), 100000);
    }
    if ($body === null) return;

    ai_pipeline_debug_log('ai_write', $body, $projectId !== null ? ['project_id' => $projectId, 'agent' => $agentType] : ['agent' => $agentType]);
}

// Turn budgets across the three agent loops now run 60-120 turns (up from
// 12-60), and every turn appends two messages to $loopHistory with no cap —
// resent in full on every single subsequent call. Left unbounded, a long-
// running session's token cost (and latency) grows roughly with the square
// of its turn count. The model only ever needs recent context to keep making
// progress on the CURRENT step; nothing about turn 3 is still relevant by
// turn 50. Keeping the most recent messages and dropping the rest bounds
// this without meaningfully hurting the model's ability to continue.
const AI_AGENT_HISTORY_WINDOW_MESSAGES = 30; // ~15 turns of (user, model) pairs

// A write_file action's args.content carries the ENTIRE file being written —
// live-observed turn latency in the frontend build agent climbing from ~50s
// to 150s+ over one build's course pointed straight at this: replaying every
// past write_file's full content back to the model on every later turn (up
// to AI_AGENT_HISTORY_WINDOW_MESSAGES messages) forces it to re-transmit and
// re-attend to hundreds of lines of its own past output, compounding turn
// over turn. This costs nothing to trim -- write_file's own tool RESULT
// (already in history right after this) never echoes content back either,
// only {path, bytes, syntax_ok}; and if the model actually needs to see
// current content again, read_file returns it fresh, not a stale historical
// copy. Used wherever an agent loop appends its own action to $loopHistory,
// in place of a bare json_encode($action).
function ai_agent_history_action_json(array $action): string
{
    if (($action['tool'] ?? '') === 'write_file' && is_string($action['args']['content'] ?? null)) {
        $len = strlen($action['args']['content']);
        $action['args']['content'] = "[omitted from history — {$len} bytes already written; read_file to see current content]";
    }
    return json_encode($action);
}

// Compaction-lite: rather than silently dropping everything past the
// window (the model then has zero record of, say, already having written
// index.html, and can waste a turn re-deriving or re-checking something it
// already settled), fold the dropped turns' own tool calls into one cheap
// summary line prepended to what's kept. Built entirely from data already
// in $history — no extra AI call, just string/array work — so this doesn't
// add any latency or cost of its own.
function ai_agent_trim_history(array $history): array
{
    if (count($history) <= AI_AGENT_HISTORY_WINDOW_MESSAGES) return $history;

    $dropped = array_slice($history, 0, count($history) - AI_AGENT_HISTORY_WINDOW_MESSAGES);
    $kept    = array_slice($history, -AI_AGENT_HISTORY_WINDOW_MESSAGES);

    $actions = [];
    foreach ($dropped as $msg) {
        if (($msg['role'] ?? '') !== 'model') continue;
        $decoded = json_decode((string)($msg['text'] ?? ''), true);
        if (!is_array($decoded)) continue;
        $tool = (string)($decoded['tool'] ?? '');
        if ($tool === '') continue;
        $path = (string)($decoded['args']['path'] ?? '');
        $actions[] = $path !== '' ? "{$tool}({$path})" : $tool;
    }

    if ($actions) {
        $shown = array_slice($actions, 0, 20);
        $summary = ['role' => 'user', 'text' =>
            '(Summary of ' . count($dropped) . ' earlier messages, dropped from context to save space — actions '
            . "you already took: " . implode(', ', $shown) . (count($actions) > count($shown) ? ', …' : '')
            . '. Do not repeat these unless something is actually broken.)'];
        array_unshift($kept, $summary);
    }

    return $kept;
}

// Deterministic syntax check for a single agent-written file — used by the
// edit agent loop's write_file/syntax_check tools. A .js file is checked
// directly; an .html file has each inline (non-src) <script> block extracted
// and checked individually, since `node --check` only understands plain JS.
// Returns ['ok' => bool, 'error' => ?string] — never throws.
function ai_check_js_syntax(string $path, string $content, array $config): array
{
    $nodeBin = $config['NODE_BIN'] ?? '/opt/alt/alt-nodejs16/root/usr/bin/node';
    $ext     = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

    $blocks = [];
    if ($ext === 'js') {
        $blocks[] = $content;
    } elseif ($ext === 'html') {
        if (preg_match_all('#<script(?![^>]*\bsrc\s*=)[^>]*>(.*?)</script>#is', $content, $m)) {
            foreach ($m[1] as $inline) {
                if (trim($inline) !== '') $blocks[] = $inline;
            }
        }
    } else {
        return ['ok' => true, 'error' => null]; // nothing to check (e.g. .json, .css)
    }

    foreach ($blocks as $i => $block) {
        $tmp = sys_get_temp_dir() . '/sb_agentcheck_' . getmypid() . '_' . time() . '_' . $i . '.mjs';
        file_put_contents($tmp, $block);
        exec(escapeshellarg($nodeBin) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
        @unlink($tmp);
        if ($code !== 0) {
            return ['ok' => false, 'error' => trim(implode("\n", $out))];
        }
        $out = [];
    }
    return ['ok' => true, 'error' => null];
}

// Spawns a fully independent OS process to run one job. This — not a shared
// queue/consumer — is what lets every user's build/edit run in true parallel:
// each job gets its own process the moment it's created, so nobody waits on
// anybody else's job. (cPanel/LVE process limits could throttle this under
// extreme simultaneous load, but each worker is short-lived and normal usage
// never approaches that.)
function ai_spawn_job_worker(array $config, int $jobId): void
{
    $phpBin = $config['PHP_BIN'] ?? '/usr/local/bin/php';
    $worker = SUPABEIN_ROOT . '/app/workers/ai_worker.php';
    exec($phpBin . ' ' . escapeshellarg($worker) . ' ' . $jobId . ' > /dev/null 2>&1 &');
}

// A worker's shutdown handler marks its own job failed on a caught error, but
// nothing catches a SIGKILL from the host's process/resource limits (cPanel
// LVE, OOM, etc.) — that leaves the row stuck at status='running' forever,
// with the panel polling a job that will never resolve. Detect that: it's
// been quiet for a while AND the OS process it was claimed under is gone.
function ai_job_is_orphaned(array $job): bool
{
    if (($job['status'] ?? null) !== 'running' || empty($job['pid'])) return false;

    $updatedAt = strtotime((string)$job['updated_at'] . ' UTC');
    if ($updatedAt === false || (time() - $updatedAt) < 300) return false; // give slow stages room to breathe

    $pid = (int)$job['pid'];
    if (function_exists('posix_kill')) {
        return !@posix_kill($pid, 0); // signal 0: existence check only, sends nothing
    }
    return !is_dir('/proc/' . $pid);
}

// Every AI-call endpoint that accepts a free-text prompt enforces the same
// "required, under 2000 characters" rule -- centralized here instead of each
// of the 8 routes below repeating its own copy of this exact check, which is
// exactly what let the client-side prompt-cap logic (see RESOLVE_PROMPT_MAX
// in dashboard/assets/app.js) drift out of sync with the backend in the
// first place: a prompt built by concatenating an already-near-the-cap base
// prompt with more text (e.g. confirming an edit-review's selected changes)
// had no shared limit to check itself against client-side, so it 422'd here
// with no job ever created and no clear indication why. One place enforcing
// this means any future endpoint automatically gets it right too.
function ai_validate_prompt(array $body): string
{
    $prompt = trim((string)($body['prompt'] ?? ''));
    if ($prompt === '' || strlen($prompt) > 2000) {
        abort(422, 'prompt is required and must be under 2000 characters');
    }
    return $prompt;
}