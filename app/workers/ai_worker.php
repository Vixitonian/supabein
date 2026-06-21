<?php

declare(strict_types=1);

$workerRoot = dirname(dirname(__DIR__));
defined('SUPABEIN_ROOT') || define('SUPABEIN_ROOT', $workerRoot);

require_once SUPABEIN_ROOT . '/app/bootstrap.php';
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
    } else {
        throw new \RuntimeException('Unknown job mode: ' . $mode);
    }

    $db->prepare("UPDATE ai_jobs SET status='done', result=? WHERE id=?")
       ->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $jobId]);

} catch (\Throwable $e) {
    $db->prepare("UPDATE ai_jobs SET status='failed', error=? WHERE id=?")
       ->execute([substr($e->getMessage(), 0, 4096), $jobId]);
}
