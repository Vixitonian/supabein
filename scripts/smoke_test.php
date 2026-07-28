<?php

declare(strict_types=1);

/**
 * End-to-end smoke test against the REAL AI build pipeline -- not a unit
 * test. Signs into a fixed, dedicated throwaway account (creating it once
 * if it doesn't exist yet), submits a trivial fixed prompt through the
 * actual POST /v1/ai/build/job endpoint (review off, so schema -> design ->
 * frontend -> deploy -> auto-test all run inside one job, exactly like a
 * real "watch only" build), waits for it to finish, and confirms the job
 * itself succeeded and something actually got deployed. Deletes the
 * project and session it created afterward either way, so the same
 * account starts clean next run.
 *
 * This exists because the only thing that caught this session's real
 * pipeline breakages (a missing require_once, a truncated-JSON parse
 * failure) was a human noticing a stuck build in production. Run this
 * after any change to app/routes/ai_routes.php or app/workers/ai_worker.php
 * -- a regression that breaks the pipeline shows up here as a failed job
 * within minutes instead of via a real user hours or days later.
 *
 * Deliberately does NOT grade the generated app's own test pass/fail count
 * as a hard failure -- that's the browser-test-agent's job, and AI output
 * quality varies run to run for reasons that have nothing to do with
 * whether the platform itself is broken. The hard gate here is narrower
 * and more mechanical: did the job run to completion without erroring,
 * and did it actually produce a deployed project.
 *
 * Usage:
 *   php scripts/smoke_test.php [base_url]
 *   SMOKE_BASE_URL, SMOKE_EMAIL, SMOKE_PASSWORD env vars override the
 *   defaults below.
 *
 * Exit code 0 on success, 1 on any failure (including timeout).
 */

$baseUrl  = rtrim($argv[1] ?? getenv('SMOKE_BASE_URL') ?: 'https://supabein.dxinnovationhub.com/api', '/');
$email    = getenv('SMOKE_EMAIL') ?: 'smoke-test-internal@supabein.local';
$password = getenv('SMOKE_PASSWORD') ?: 'SmokeTestInternalOnly!2026';
$prompt   = 'A simple counter app with increment and decrement buttons';
// Live-observed on this platform: schema alone can take several minutes with
// GLM's reasoning overhead, frontend generation regularly retries on invalid
// JSON before succeeding, and auto-test then runs several more Playwright-
// driven stories on top of that. A tight timeout here produces a false FAIL
// on a pipeline that's genuinely still working, not one that's actually
// broken -- 1800s gives real (if slow) runs room to finish while still
// catching a truly stuck job.
$maxWaitSeconds  = 1800;
$pollIntervalSec = 5;

function log_line(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
}

/**
 * A poll running for 10 minutes is going to hit at least one transient
 * network blip somewhere along the way (a connection reset, a momentary
 * DNS hiccup) -- treating that as a hard failure crashed an earlier
 * version of this script mid-run, well before the real job it was
 * watching had even finished, and skipped cleanup entirely. Retries a
 * handful of times with a short backoff before finally giving up.
 *
 * @return array{0:int,1:array} [http_status, decoded_json]
 */
function api_call(string $baseUrl, string $method, string $path, ?array $body = null, ?string $token = null, int $retries = 3): array
{
    $lastErr = null;
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $ch = curl_init($baseUrl . $path);
        $headers = ['Content-Type: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $response = curl_exec($ch);
        if ($response !== false) {
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $decoded = json_decode($response, true);
            return [$status, is_array($decoded) ? $decoded : []];
        }
        $lastErr = curl_error($ch);
        curl_close($ch);
        if ($attempt < $retries) {
            log_line("  (transient network error calling {$method} {$path}: {$lastErr} -- retrying)");
            sleep(2 * $attempt);
        }
    }
    throw new \RuntimeException("Network error calling {$method} {$path} after {$retries} attempts: {$lastErr}");
}

function fail(string $msg): never
{
    log_line('FAIL: ' . $msg);
    exit(1);
}

$token = null;
$sessionId = null;
$projectId = null;

// Cleanup must run no matter how the run below ends -- a successful pass, a
// mechanical FAIL, or an unexpected exception -- so a crashed smoke test
// run never leaves the fixed account cluttered with orphaned projects and
// sessions for the next run to trip over.
register_shutdown_function(function () use (&$baseUrl, &$token, &$sessionId, &$projectId): void {
    if (!$token) return;
    if ($projectId) {
        log_line("Cleaning up project {$projectId}...");
        try { api_call($baseUrl, 'DELETE', "/v1/projects/{$projectId}", null, $token, 1); } catch (\Throwable $e) { /* best-effort */ }
    }
    if ($sessionId) {
        log_line("Cleaning up session {$sessionId}...");
        try { api_call($baseUrl, 'DELETE', "/v1/ai/sessions/{$sessionId}", null, $token, 1); } catch (\Throwable $e) { /* best-effort */ }
    }
});

// ── 1. Sign in, creating the fixed throwaway account on first run only ──────
log_line("Signing in as {$email}...");
[$status, $res] = api_call($baseUrl, 'POST', '/v1/auth/login', ['email' => $email, 'password' => $password]);
if ($status !== 200) {
    log_line('Login failed (' . $status . ') -- creating the account for the first time...');
    [$status, $res] = api_call($baseUrl, 'POST', '/v1/auth/signup', ['email' => $email, 'password' => $password, 'name' => 'Smoke Test']);
    if ($status !== 200 && $status !== 201) {
        fail("Could not sign in or sign up ({$status}): " . json_encode($res));
    }
}
$token = $res['token'] ?? null;
if (!$token) fail('No auth token in response: ' . json_encode($res));
log_line('Signed in.');

// ── 1b. Sweep any leftover projects from a previous run ─────────────────────
// This account should always be empty between runs -- the shutdown cleanup
// above deletes what it created. But a run that gets SIGKILLed (an external
// timeout, a killed process) skips shutdown functions entirely, and if the
// job had already finished server-side by then, its deployed project is
// orphaned with nothing local left to know its id. The next run then fails
// at the deploy step on a plain name collision instead of testing anything.
// Sweeping first makes the account self-healing regardless of why a
// previous run failed to clean up after itself.
[$status, $existingProjects] = api_call($baseUrl, 'GET', '/v1/projects', null, $token);
if ($status === 200 && is_array($existingProjects)) {
    foreach ($existingProjects as $p) {
        $leftoverId = $p['id'] ?? null;
        if (!$leftoverId) continue;
        log_line("Sweeping leftover project {$leftoverId} ({$p['name']}) from a previous run...");
        try { api_call($baseUrl, 'DELETE', "/v1/projects/{$leftoverId}", null, $token, 1); } catch (\Throwable $e) { /* best-effort */ }
    }
}

// ── 2. Create a session and submit the real build job ──────────────────────
[$status, $sessRes] = api_call($baseUrl, 'POST', '/v1/ai/sessions', ['name' => 'Smoke test'], $token);
if ($status !== 200 && $status !== 201) fail("Could not create session ({$status}): " . json_encode($sessRes));
$sessionId = $sessRes['id'] ?? null;

log_line('Submitting build job: "' . $prompt . '"...');
[$status, $jobRes] = api_call($baseUrl, 'POST', '/v1/ai/build/job', [
    'prompt'     => $prompt,
    'validate'   => true,
    'session_id' => $sessionId,
    // Pinned rather than left to the server's own no-preference default --
    // real dashboard users always send an explicit model (the client's own
    // fallback is Claude Opus, a paid model this free smoke test shouldn't
    // spend on), so omitting this entirely was accidentally exercising a
    // rarely-hit fallback path instead of anything a real user experiences.
    // glm-4.5-flash is free and live-verified fast/clean against this exact
    // pipeline -- see ai_build_fallback_chain()'s own comment on this slug.
    'provider'   => 'zhipu',
    'model'      => 'glm-4.5-flash',
], $token);
if ($status !== 202 && $status !== 200) fail("Could not create build job ({$status}): " . json_encode($jobRes));
$jobId = $jobRes['job_id'] ?? null;
if (!$jobId) fail('No job_id in response: ' . json_encode($jobRes));
log_line("Job {$jobId} created -- polling...");

// ── 3. Poll until done, failed, or timed out ────────────────────────────────
$deadline = time() + $maxWaitSeconds;
$result = null;
$finalStatus = null;
$since = 0; // only fetch events past this index each poll -- avoids re-printing the whole history every 5s
while (time() < $deadline) {
    sleep($pollIntervalSec);
    [$status, $jobStatus] = api_call($baseUrl, 'GET', "/v1/ai/jobs/{$jobId}?since={$since}", null, $token);
    if ($status !== 200) fail("Job status check failed ({$status}): " . json_encode($jobStatus));

    foreach ($jobStatus['events'] ?? [] as $ev) {
        $label = $ev['label'] ?? '?';
        $detail = !empty($ev['detail']) ? ' -- ' . $ev['detail'] : '';
        log_line('  ' . ($ev['stage'] ?? '?') . '/' . ($ev['status'] ?? '?') . ': ' . $label . $detail);
    }
    $since = (int)($jobStatus['event_count'] ?? $since);

    $finalStatus = $jobStatus['status'] ?? null;
    if ($finalStatus === 'done') {
        $result = $jobStatus['result'] ?? null;
        break;
    }
    if ($finalStatus === 'failed') {
        fail('Job failed: ' . ($jobStatus['error'] ?? 'unknown error'));
    }
}

if ($finalStatus !== 'done') {
    fail("Job never finished within {$maxWaitSeconds}s (last status: " . ($finalStatus ?? 'unknown') . ')');
}

$projectId = $result['apply']['project']['id'] ?? null;
if (!$projectId) {
    fail('Job finished but no project was deployed -- result: ' . json_encode($result));
}

$testResult = $result['test'] ?? null;
$passed = $testResult['passed'] ?? null;
$failed = $testResult['failed'] ?? null;
log_line("Job succeeded -- project {$projectId} deployed."
    . ($testResult !== null ? " Generated app's own tests: {$passed} passed, {$failed} failed (informational only)." : ' No test stage ran.'));

log_line('PASS: the build pipeline ran end-to-end successfully.');
exit(0);
