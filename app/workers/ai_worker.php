<?php

declare(strict_types=1);

$workerRoot = dirname(dirname(__DIR__));
require_once $workerRoot . '/app/bootstrap.php'; // defines SUPABEIN_ROOT
require_once SUPABEIN_ROOT . '/app/routes/project_routes.php';
require_once SUPABEIN_ROOT . '/app/routes/ai_routes.php';

set_time_limit(0);

$jobId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: ai_worker.php <job_id>\n");
    exit(1);
}

$catalog = \SupaBein\Catalog::getInstance();
$db      = \App::get('db');

if (!$catalog->claimJob($jobId, getmypid())) {
    exit(0); // already claimed/finished/cancelled — nothing to do
}

$row = $db->prepare('SELECT user_id, mode, payload FROM ai_jobs WHERE id = ?');
$row->execute([$jobId]);
$job = $row->fetch(\PDO::FETCH_ASSOC);
if (!$job) exit(1);

$userId  = (int)$job['user_id'];
$mode    = $job['mode'];
$payload = json_decode($job['payload'], true) ?? [];

// Buffer output so that if a helper calls abort() (echoes JSON + exit()s —
// uncatchable), the shutdown handler below can recover the real error message
// instead of just reporting "worker exited unexpectedly".
ob_start();

register_shutdown_function(function () use ($db, $catalog, $jobId) {
    $check = $db->prepare("SELECT status FROM ai_jobs WHERE id = ?");
    $check->execute([$jobId]);
    if ($check->fetchColumn() !== 'running') return; // already resolved normally

    $buffered = ob_get_clean();
    $message  = 'Worker exited unexpectedly';
    if ($buffered) {
        $decoded = json_decode(trim($buffered), true);
        if (is_array($decoded) && !empty($decoded['error'])) {
            $message = (string)$decoded['error'];
        }
    }
    $catalog->markJobFailed($jobId, $message);
});

try {
    $config = \App::get('config');
    $client = make_ai_client($config, $payload['provider'] ?? null, $payload['model'] ?? null);

    $report = function (array $event) use ($catalog, $jobId): void {
        $catalog->appendJobProgress($jobId, $event);
    };

    // Surfaces it in the job's own result if make_ai_client()'s resilient
    // FallbackAiClient actually had to switch away from the requested/default
    // provider mid-run — otherwise a rate-limit recovery that worked
    // perfectly would be invisible, and the only sign anything happened would
    // be the job taking a bit longer than usual.
    $withFallbackInfo = function (array $result) use ($client): array {
        if ($client instanceof \SupaBein\FallbackAiClient && $client->getFallbackEvents()) {
            $result['provider_fallback'] = $client->getFallbackEvents();
        }
        return $result;
    };

    if ($mode === 'build') {
        $prompt         = $payload['prompt']  ?? '';
        $history        = $payload['history'] ?? [];
        $approvedIntent = $payload['intent']  ?? null;
        $validate       = $payload['validate'] ?? true;
        $refs           = ai_job_payload_refs($payload);

        // Continuing a previous 'build' job that died partway through (a
        // worker crash, most commonly during the browser-driven test stage) —
        // getJobById scopes by user_id, so this can only ever resolve to a
        // job this same user owns. See ai_run_build_and_deploy()'s doc
        // comment for exactly which stages this lets a retry skip.
        $resumeCheckpoint = null;
        $resumeJobId = (int)($payload['resume_job_id'] ?? 0);
        if ($resumeJobId > 0) {
            $priorJob = $catalog->getJobById($resumeJobId, $userId);
            if ($priorJob && $priorJob['mode'] === 'build' && is_array($priorJob['result'] ?? null)) {
                $resumeCheckpoint = $priorJob['result'];
            }
        }
        $checkpointFn = function (string $stage, array $data) use ($catalog, $jobId): void {
            $catalog->saveJobCheckpoint($jobId, array_merge($data, ['stage' => $stage]));
        };

        // The '/v1/ai/build/job' route is only ever used by the Review-off
        // ("watch only") flow now — Review-on uses the separate
        // build_schema/build_frontend jobs below — so it's safe for this one
        // job to also deploy and test, giving the whole pipeline one
        // reload-proof progress trail instead of three separately-tracked steps.
        $result = ai_run_build_and_deploy($prompt, $history, $approvedIntent, $client, $report, $validate, $config, $catalog, $userId, $refs, $resumeCheckpoint, $checkpointFn);
        $catalog->markJobDone($jobId, $withFallbackInfo(array_merge(['mode' => 'build'], $result)));

    } elseif ($mode === 'build_schema') {
        // Review-on build, stage 1: schema + design brief only — the
        // frontend pauses here for the user to confirm before any frontend
        // code is generated.
        $prompt         = $payload['prompt']  ?? '';
        $history        = $payload['history'] ?? [];
        $approvedIntent = $payload['intent']  ?? null;
        $refs           = ai_job_payload_refs($payload);
        $result = ai_run_build_schema_design($prompt, $history, $approvedIntent, $client, $report, $refs);
        $catalog->markJobDone($jobId, $withFallbackInfo(array_merge(['mode' => 'build_schema'], $result)));

    } elseif ($mode === 'build_frontend') {
        // Review-on build, stage 2: frontend + validate, against the schema
        // and design brief the user already confirmed in stage 1.
        $prompt      = $payload['prompt']       ?? '';
        $schemaPlan  = $payload['schema']       ?? [];
        $designBrief = $payload['design_brief'] ?? [];
        $validate    = $payload['validate'] ?? true;
        $refs        = ai_job_payload_refs($payload);
        $result = ai_run_build_frontend($schemaPlan, $designBrief, $prompt, $client, $config, $report, $validate, $refs);
        $catalog->markJobDone($jobId, $withFallbackInfo(array_merge(['mode' => 'build_frontend'], $result)));

    } elseif ($mode === 'edit') {
        $projectId = (int)($payload['project_id'] ?? 0);
        $prompt    = $payload['prompt']  ?? '';
        $history   = $payload['history'] ?? [];
        $validate  = $payload['validate'] ?? true;
        // Project already exists for an edit, so any uploaded image is
        // uploaded to its real storage right here — the AI gets the real
        // URL directly, no __SB_PID__ placeholder/deferred-upload dance
        // needed (that's only for a fresh build with no project yet).
        $refs      = ai_job_payload_refs($payload, $projectId ?: null);

        // Continuing a previous edit job — either one that gracefully hit its
        // turn budget, or one a worker crash killed outright mid-loop (the
        // checkpoint below is now saved every turn, not just at the graceful
        // turn-limit case) — getJobById scopes by user_id, so this can only
        // ever resolve to a job this same user owns, never another user's.
        $resumeState = null;
        $resumeJobId = (int)($payload['resume_job_id'] ?? 0);
        if ($resumeJobId > 0) {
            $priorJob = $catalog->getJobById($resumeJobId, $userId);
            if ($priorJob && $priorJob['mode'] === 'edit' && is_array($priorJob['result'] ?? null)) {
                $resumeState = $priorJob['result']['resume_state'] ?? null;
            }
        }
        $checkpointFn = function (array $state) use ($catalog, $jobId): void {
            $catalog->saveJobCheckpoint($jobId, ['resume_state' => $state]);
        };

        $result = ai_run_edit_generation($projectId, $prompt, $history, $client, $catalog, $config, $report, $validate, $resumeState, $refs, $checkpointFn);
        $catalog->markJobDone($jobId, $withFallbackInfo(array_merge(['mode' => 'edit'], $result)));

    } elseif ($mode === 'test') {
        $projectId = (int)($payload['project_id'] ?? 0);
        $autoFix   = !empty($payload['auto_fix']);
        $result    = $autoFix
            ? ai_run_test_and_autofix($projectId, $userId, $catalog, $config, $report, $client)
            : ai_run_project_tests($projectId, $userId, $catalog, $config, $report, $client);
        $catalog->markJobDone($jobId, $withFallbackInfo(array_merge(['mode' => 'test'], $result)));

    } elseif ($mode === 'seed') {
        $projectId = (int)($payload['project_id'] ?? 0);
        $result = ai_run_project_seed($projectId, $catalog, $db, $client, $report);
        $catalog->markJobDone($jobId, $withFallbackInfo(array_merge(['mode' => 'seed'], $result)));

    } else {
        throw new \RuntimeException('Unknown job mode: ' . $mode);
    }

} catch (\Throwable $e) {
    $catalog->markJobFailed($jobId, $e->getMessage());
}
