<?php

declare(strict_types=1);

$workerRoot = dirname(dirname(__DIR__));
defined('SUPABEIN_ROOT') || define('SUPABEIN_ROOT', $workerRoot);

require_once SUPABEIN_ROOT . '/app/bootstrap.php';
require_once SUPABEIN_ROOT . '/app/routes/project_routes.php';
require_once SUPABEIN_ROOT . '/app/routes/ai_routes.php';

set_time_limit(0);

$jobId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: ai_worker.php <job_id>\n");
    exit(1);
}

$db = \App::get('db');

$claim = $db->prepare(
    "UPDATE ai_jobs SET status='running', pid=? WHERE id=? AND status='queued'"
);
$claim->execute([getmypid(), $jobId]);
if ($claim->rowCount() !== 1) {
    exit(0);
}

$row = $db->prepare('SELECT user_id, mode, payload FROM ai_jobs WHERE id=?');
$row->execute([$jobId]);
$job = $row->fetch(\PDO::FETCH_ASSOC);
if (!$job) exit(1);

$userId  = (int)$job['user_id'];
$mode    = $job['mode'];
$payload = json_decode($job['payload'], true);

register_shutdown_function(function () use ($db, $jobId) {
    $check = $db->prepare("SELECT status FROM ai_jobs WHERE id=?");
    $check->execute([$jobId]);
    $status = $check->fetchColumn();
    if ($status === 'running') {
        $db->prepare("UPDATE ai_jobs SET status='failed', error='Worker exited unexpectedly' WHERE id=?")
           ->execute([$jobId]);
    }
});

try {
    if ($mode === 'build') {
        $result = ai_execute_build($payload['plan'], $userId);

    } elseif ($mode === 'edit') {
        $result = ai_execute_edit($payload['delta'], $payload['project_id'], $userId);
        if (!empty($payload['frontend_files'])) {
            $catalog  = \SupaBein\Catalog::getInstance();
            $config   = \App::get('config');
            $project  = $catalog->getProjectByIdInternal($payload['project_id']);
            $sites    = $catalog->listSites($payload['project_id']);
            if ($sites && $project) {
                $deployResult = ai_deploy_files($config, $catalog, (int)$sites[0]['id'],
                                                $project, $payload['frontend_files'], true);
                if (!empty($deployResult['deploy'])) {
                    $result['deploy'] = $deployResult['deploy'];
                }
            }
        }

    } elseif ($mode === 'pipeline_build') {
        $prompt       = $payload['prompt']      ?? '';
        $review       = (bool)($payload['review']   ?? false);
        $history      = $payload['history']     ?? [];
        $provider     = $payload['provider']    ?? null;
        $model        = $payload['model']       ?? null;
        $userResponse = $payload['user_response'] ?? null;
        $approvedIntent = $userResponse['intent'] ?? null;

        $config = \App::get('config');
        $client = make_ai_client($config, $provider, $model);

        // Phase 1: intent generation (only if review mode and no approved intent yet)
        if ($review && $approvedIntent === null) {
            $intent = ai_generate_intent($client, $prompt, $history);
            $db->prepare("UPDATE ai_jobs SET status='waiting_input', result=? WHERE id=?")
               ->execute([json_encode(['wait_mode' => 'intent_review', 'intent' => $intent], JSON_UNESCAPED_UNICODE), $jobId]);
            exit(0);
        }

        // Phase 2: full plan generation + execution
        $plan   = ai_generate_build_plan($client, $prompt, $approvedIntent, $history);
        $result = ai_execute_build($plan, $userId);

    } elseif ($mode === 'pipeline_edit') {
        $prompt      = $payload['prompt']      ?? '';
        $projectId   = (int)($payload['project_id'] ?? 0);
        $review      = (bool)($payload['review']    ?? false);
        $history     = $payload['history']     ?? [];
        $provider    = $payload['provider']    ?? null;
        $model       = $payload['model']       ?? null;
        $userResponse = $payload['user_response'] ?? null;
        $approvedSuggestions = $userResponse['suggestions'] ?? null;

        $config  = \App::get('config');
        $catalog = \SupaBein\Catalog::getInstance();
        $client  = make_ai_client($config, $provider, $model);

        // Phase 1: edit suggestions (only if review mode and no approved suggestions yet)
        if ($review && $approvedSuggestions === null) {
            $suggestions = ai_generate_edit_suggestions($client, $projectId, $userId, $prompt, $catalog, $config);
            $db->prepare("UPDATE ai_jobs SET status='waiting_input', result=? WHERE id=?")
               ->execute([json_encode(['wait_mode' => 'edit_review', 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE), $jobId]);
            exit(0);
        }

        // Phase 2: generate delta and execute
        $refinedPrompt = $approvedSuggestions
            ? $prompt . "\n\nApply ONLY these specific changes:\n"
              . implode("\n", array_map(fn($s, $i) => ($i + 1) . '. ' . ($s['label'] ?? ''), $approvedSuggestions, array_keys($approvedSuggestions)))
            : $prompt;

        $delta  = ai_generate_edit_plan($client, $projectId, $userId, $refinedPrompt, $history, $catalog, $config);
        $result = ai_execute_edit($delta, $projectId, $userId);

        if (!empty($delta['frontend']['files'])) {
            $project = $catalog->getProjectByIdInternal($projectId);
            $sites   = $catalog->listSites($projectId);
            if ($sites && $project) {
                $deployResult = ai_deploy_files($config, $catalog, (int)$sites[0]['id'],
                                                $project, $delta['frontend']['files'], true);
                if (!empty($deployResult['deploy'])) {
                    $result['deploy'] = $deployResult['deploy'];
                }
            }
        }

    } else {
        throw new \RuntimeException('Unknown job mode: ' . $mode);
    }

    $db->prepare("UPDATE ai_jobs SET status='done', result=? WHERE id=?")
       ->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $jobId]);

} catch (\Throwable $e) {
    $db->prepare("UPDATE ai_jobs SET status='failed', error=? WHERE id=?")
       ->execute([substr($e->getMessage(), 0, 4096), $jobId]);
}
