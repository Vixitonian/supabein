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
        $result = ai_run_build_generation($prompt, $history, $approvedIntent, $client, $report);
        $catalog->markJobDone($jobId, array_merge(['mode' => 'build'], $result));

    } elseif ($mode === 'edit') {
        $projectId = (int)($payload['project_id'] ?? 0);
        $prompt    = $payload['prompt']  ?? '';
        $history   = $payload['history'] ?? [];
        $result = ai_run_edit_generation($projectId, $prompt, $history, $client, $catalog, $config, $report);
        $catalog->markJobDone($jobId, array_merge(['mode' => 'edit'], $result));

    } elseif ($mode === 'test') {
        $projectId = (int)($payload['project_id'] ?? 0);
        $result = ai_run_project_tests($projectId, $userId, $catalog, $config, $report, $client);
        $catalog->markJobDone($jobId, array_merge(['mode' => 'test'], $result));

    } else {
        throw new \RuntimeException('Unknown job mode: ' . $mode);
    }

} catch (\Throwable $e) {
    $catalog->markJobFailed($jobId, $e->getMessage());
}
