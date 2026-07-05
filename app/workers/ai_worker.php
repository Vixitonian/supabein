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

    if ($mode === 'build') {
        $prompt         = $payload['prompt']  ?? '';
        $history        = $payload['history'] ?? [];
        $approvedIntent = $payload['intent']  ?? null;
        $validate       = $payload['validate'] ?? true;
        // The '/v1/ai/build/job' route is only ever used by the Review-off
        // ("watch only") flow now — Review-on uses the separate
        // build_schema/build_frontend jobs below — so it's safe for this one
        // job to also deploy and test, giving the whole pipeline one
        // reload-proof progress trail instead of three separately-tracked steps.
        $result = ai_run_build_and_deploy($prompt, $history, $approvedIntent, $client, $report, $validate, $config, $catalog, $userId);
        $catalog->markJobDone($jobId, array_merge(['mode' => 'build'], $result));

    } elseif ($mode === 'build_schema') {
        // Review-on build, stage 1: schema + design brief only — the
        // frontend pauses here for the user to confirm before any frontend
        // code is generated.
        $prompt         = $payload['prompt']  ?? '';
        $history        = $payload['history'] ?? [];
        $approvedIntent = $payload['intent']  ?? null;
        $result = ai_run_build_schema_design($prompt, $history, $approvedIntent, $client, $report);
        $catalog->markJobDone($jobId, array_merge(['mode' => 'build_schema'], $result));

    } elseif ($mode === 'build_frontend') {
        // Review-on build, stage 2: frontend + validate, against the schema
        // and design brief the user already confirmed in stage 1.
        $prompt      = $payload['prompt']       ?? '';
        $schemaPlan  = $payload['schema']       ?? [];
        $designBrief = $payload['design_brief'] ?? [];
        $validate    = $payload['validate'] ?? true;
        $result = ai_run_build_frontend($schemaPlan, $designBrief, $prompt, $client, $config, $report, $validate);
        $catalog->markJobDone($jobId, array_merge(['mode' => 'build_frontend'], $result));

    } elseif ($mode === 'edit') {
        $projectId = (int)($payload['project_id'] ?? 0);
        $prompt    = $payload['prompt']  ?? '';
        $history   = $payload['history'] ?? [];
        $validate  = $payload['validate'] ?? true;
        $result = ai_run_edit_generation($projectId, $prompt, $history, $client, $catalog, $config, $report, $validate);
        $catalog->markJobDone($jobId, array_merge(['mode' => 'edit'], $result));

    } elseif ($mode === 'test') {
        $projectId = (int)($payload['project_id'] ?? 0);
        $result = ai_run_project_tests($projectId, $userId, $catalog, $config, $report, $client);
        $catalog->markJobDone($jobId, array_merge(['mode' => 'test'], $result));

    } elseif ($mode === 'seed') {
        $projectId = (int)($payload['project_id'] ?? 0);
        $result = ai_run_project_seed($projectId, $catalog, $db, $client, $report);
        $catalog->markJobDone($jobId, array_merge(['mode' => 'seed'], $result));

    } else {
        throw new \RuntimeException('Unknown job mode: ' . $mode);
    }

} catch (\Throwable $e) {
    $catalog->markJobFailed($jobId, $e->getMessage());
}
