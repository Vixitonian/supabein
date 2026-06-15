<?php

declare(strict_types=1);

namespace SupaBein;

class PolicyResult
{
    public readonly bool $allowed;
    public readonly ?string $constraint;

    private function __construct(bool $allowed, ?string $constraint)
    {
        $this->allowed    = $allowed;
        $this->constraint = $constraint;
    }

    public static function allow(?string $constraint = null): self
    {
        return new self(true, $constraint);
    }

    public static function deny(): self
    {
        return new self(false, null);
    }
}

class Policy
{
    /**
     * Check whether the given API role may perform the operation on a table.
     * Returns a PolicyResult with the resolved constraint (if any).
     *
     * @param \PDO   $pdo
     * @param int    $tableId
     * @param ?array $auth     null = unauthenticated
     * @param int    $projectOwnerId
     * @param string $operation SELECT|INSERT|UPDATE|DELETE
     */
    public static function check(
        \PDO $pdo,
        int $tableId,
        ?array $auth,
        int $projectOwnerId,
        string $operation
    ): PolicyResult {
        // Project owner and service_role bypass all policies
        if ($auth !== null && $auth['user_id'] === $projectOwnerId) {
            return PolicyResult::allow();
        }
        if ($auth !== null && ($auth['role'] ?? '') === 'service_role') {
            return PolicyResult::allow();
        }

        $apiRole = self::deriveApiRole($auth);

        $stmt = $pdo->prepare(
            'SELECT allowed, constraint_sql FROM project_policies
             WHERE project_table_id = ? AND api_role = ? AND operation = ?
             LIMIT 1'
        );
        $stmt->execute([$tableId, $apiRole, $operation]);
        $row = $stmt->fetch();

        if (!$row || !$row['allowed']) {
            return PolicyResult::deny();
        }

        $constraint = self::resolveConstraint($row['constraint_sql'], $auth);
        return PolicyResult::allow($constraint);
    }

    private static function deriveApiRole(?array $auth): string
    {
        if ($auth === null) {
            return 'anon';
        }
        return 'authenticated';
    }

    private static function resolveConstraint(?string $raw, ?array $auth): ?string
    {
        if ($raw === null) {
            return null;
        }

        $userId = $auth ? (int)$auth['user_id'] : 0;

        // Only substitute the known safe token :current_user_id
        return str_replace(':current_user_id', $userId, $raw);
    }
}
