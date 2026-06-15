<?php

declare(strict_types=1);

namespace SupaBein;

class RateLimit
{
    // 600 requests per minute per project by default
    private const DEFAULT_LIMIT = 600;

    /**
     * Check + increment the per-project request counter for the current minute.
     * Aborts with 429 if the limit is exceeded.
     */
    public static function checkProject(int $projectId, int $limit = self::DEFAULT_LIMIT): void
    {
        $pdo    = \App::get('db');
        $bucket = (int)floor(time() / 60); // current 60-second window

        // Atomic upsert — increment or insert with count=1
        $pdo->prepare(
            'INSERT INTO rate_limits (project_id, window_start, count)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1'
        )->execute([$projectId, $bucket]);

        $stmt = $pdo->prepare(
            'SELECT count FROM rate_limits WHERE project_id = ? AND window_start = ?'
        );
        $stmt->execute([$projectId, $bucket]);
        $count = (int)($stmt->fetchColumn() ?: 0);

        // Clean up stale windows ~1% of requests (avoid a cron dependency)
        if (mt_rand(1, 100) === 1) {
            $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')
                ->execute([$bucket - 10]);
        }

        if ($count > $limit) {
            http_response_code(429);
            header('Retry-After: 60');
            echo json_encode(['error' => "Rate limit exceeded — max {$limit} requests per minute."]);
            exit;
        }
    }
}
