<?php
/**
 * One-time deployment + migration script.
 * DELETE THIS FILE immediately after running.
 *
 * Usage: https://your-domain/deploy-migrate.php?token=REPLACE_THIS_TOKEN
 */

$SECRET_TOKEN = 'REPLACE_THIS_TOKEN'; // change this before uploading

if (($_GET['token'] ?? '') !== $SECRET_TOKEN) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain');
$log = [];

// ── Step 1: Pull latest code ─────────────────────────────────────────────────

$dir = __DIR__;
$branch = 'claude/supabein-staging-project-tabs-jgezdg';

$cmds = [
    "cd $dir && git fetch origin 2>&1",
    "cd $dir && git checkout $branch 2>&1",
    "cd $dir && git pull origin $branch 2>&1",
];

foreach ($cmds as $cmd) {
    $out = [];
    exec($cmd, $out, $code);
    $log[] = "$ $cmd";
    $log[] = implode("\n", $out);
    $log[] = "exit: $code";
    $log[] = str_repeat('-', 60);
}

// ── Step 2: Run SQL migration ────────────────────────────────────────────────

$log[] = "\nRunning SQL migration...";

try {
    $config = require $dir . '/config/secrets.php';
    $pdo = new PDO(
        $config['DB_DSN'],
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Check if column already exists
    $check = $pdo->query("SHOW COLUMNS FROM sites LIKE 'staging_deploy_id'")->fetch();
    if ($check) {
        $log[] = "Column staging_deploy_id already exists — skipping ALTER.";
    } else {
        $pdo->exec("ALTER TABLE sites ADD COLUMN staging_deploy_id INT UNSIGNED DEFAULT NULL AFTER current_deploy_id");
        $log[] = "✓ ALTER TABLE sites: staging_deploy_id column added.";
    }
} catch (Exception $e) {
    $log[] = "✗ DB error: " . $e->getMessage();
}

// ── Done ─────────────────────────────────────────────────────────────────────

$log[] = "\n✓ Done. DELETE this file now: rm $dir/deploy-migrate.php";

echo implode("\n", $log);
