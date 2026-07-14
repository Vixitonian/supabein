<?php

declare(strict_types=1);

namespace SupaBein;

class RateLimit
{
    // 600 requests per minute per project by default
    private const DEFAULT_LIMIT = 600;

    // Error-log ingestion gets its own, much lower default — this is a public,
    // unauthenticated endpoint that any visitor's browser calls automatically,
    // so it needs a tighter ceiling than real data traffic.
    private const DEFAULT_ERROR_LOG_LIMIT = 60;

    /**
     * Check + increment the per-project request counter for the current minute.
     * Aborts with 429 if the limit is exceeded.
     */
    public static function checkProject(int $projectId, int $limit = self::DEFAULT_LIMIT): void
    {
        self::checkBucket('rate_limits', $projectId, $limit);
    }

    // Separate counter table from checkProject() on purpose: sharing one counter
    // between real data-API traffic and error-log ingestion would mean a burst of
    // client-side errors could eat into (or trip) the project's normal API quota,
    // and vice versa. Same increment/expire logic, just its own bucket.
    public static function checkProjectErrors(int $projectId, int $limit = self::DEFAULT_ERROR_LOG_LIMIT): void
    {
        self::checkBucket('ai_error_log_limits', $projectId, $limit);
    }

    // End-user (project_user) storage writes -- reachable by anyone signed
    // up in the app, not just the operator, so it gets its own tighter
    // ceiling and its own bucket (same reasoning as checkProjectErrors).
    public static function checkProjectStorage(int $projectId, int $limit = self::DEFAULT_ERROR_LOG_LIMIT): void
    {
        self::checkBucket('storage_rate_limits', $projectId, $limit);
    }

    private static function checkBucket(string $table, int $projectId, int $limit): void
    {
        $pdo    = \App::get('db');
        $bucket = (int)floor(time() / 60); // current 60-second window

        // Atomic upsert — increment or insert with count=1
        $pdo->prepare(
            "INSERT INTO {$table} (project_id, window_start, count)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1"
        )->execute([$projectId, $bucket]);

        $stmt = $pdo->prepare(
            "SELECT count FROM {$table} WHERE project_id = ? AND window_start = ?"
        );
        $stmt->execute([$projectId, $bucket]);
        $count = (int)($stmt->fetchColumn() ?: 0);

        // Clean up stale windows ~1% of requests (avoid a cron dependency)
        if (mt_rand(1, 100) === 1) {
            $pdo->prepare("DELETE FROM {$table} WHERE window_start < ?")
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
