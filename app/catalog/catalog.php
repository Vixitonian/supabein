<?php

declare(strict_types=1);

namespace SupaBein;

class Catalog
{
    private static ?self $instance = null;
    private \PDO $pdo;

    private function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(\App::get('db'));
        }
        return self::$instance;
    }

    // Cast integer fields in a single row fetched via PDO FETCH_ASSOC
    private static function castRow(?array $row, array $intFields): ?array
    {
        if ($row === null || $row === false) return null;
        foreach ($intFields as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) {
                $row[$f] = (int)$row[$f];
            }
        }
        return $row;
    }

    private static function castRows(array $rows, array $intFields): array
    {
        return array_map(fn($r) => self::castRow($r, $intFields), $rows);
    }

    // ─── Projects ────────────────────────────────────────────────────────────

    public function createProject(int $userId, string $name, string $serviceKey): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (owner_user_id, name, service_key) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $name, $serviceKey]);
        $id = (int)$this->pdo->lastInsertId();
        return $this->getProjectById($id, $userId);
    }

    public function listProjects(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, owner_user_id, name, created_at FROM projects WHERE owner_user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return self::castRows($stmt->fetchAll(), ['id', 'owner_user_id']);
    }

    public function getProjectById(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, owner_user_id, name, service_key, created_at FROM projects WHERE id = ? AND owner_user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch() ?: null;
        return $row ? self::castRow($row, ['id', 'owner_user_id']) : null;
    }

    public function getProjectByIdInternal(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, owner_user_id, name, service_key, created_at FROM projects WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: null;
        return $row ? self::castRow($row, ['id', 'owner_user_id']) : null;
    }

    public function setServiceKey(int $projectId, string $key): void
    {
        $this->pdo->prepare('UPDATE projects SET service_key = ? WHERE id = ?')
                  ->execute([$key, $projectId]);
    }

    public function deleteProject(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM projects WHERE id = ? AND owner_user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    // ─── Tables ──────────────────────────────────────────────────────────────

    public function createTable(int $projectId, string $logicalName): array
    {
        $physical = 'p' . $projectId . '_' . strtolower($logicalName);
        $stmt = $this->pdo->prepare(
            'INSERT INTO project_tables (project_id, table_name, physical_name) VALUES (?, ?, ?)'
        );
        $stmt->execute([$projectId, $logicalName, $physical]);
        $id = (int)$this->pdo->lastInsertId();
        return ['id' => $id, 'project_id' => $projectId, 'table_name' => $logicalName, 'physical_name' => $physical];
    }

    public function listTables(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, project_id, table_name, physical_name, created_at FROM project_tables WHERE project_id = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$projectId]);
        return self::castRows($stmt->fetchAll(), ['id', 'project_id']);
    }

    public function getTable(int $projectId, string $logicalName): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, project_id, table_name, physical_name FROM project_tables WHERE project_id = ? AND table_name = ?'
        );
        $stmt->execute([$projectId, $logicalName]);
        return self::castRow($stmt->fetch() ?: null, ['id', 'project_id']);
    }

    public function getTableById(int $tableId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, project_id, table_name, physical_name FROM project_tables WHERE id = ?'
        );
        $stmt->execute([$tableId]);
        return self::castRow($stmt->fetch() ?: null, ['id', 'project_id']);
    }

    public function deleteTable(int $projectId, string $logicalName): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM project_tables WHERE project_id = ? AND table_name = ?'
        );
        $stmt->execute([$projectId, $logicalName]);
        return $stmt->rowCount() > 0;
    }

    // ─── Columns ─────────────────────────────────────────────────────────────

    public function addColumn(int $tableId, string $colName, string $dataType, bool $nullable = true, ?string $defaultVal = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(col_order),0)+1 FROM project_columns WHERE project_table_id = ?'
        );
        $stmt->execute([$tableId]);
        $order = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO project_columns (project_table_id, col_name, data_type, nullable, default_val, col_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$tableId, $colName, $dataType, $nullable ? 1 : 0, $defaultVal, $order]);
        return [
            'id'               => (int)$this->pdo->lastInsertId(),
            'project_table_id' => $tableId,
            'col_name'         => $colName,
            'data_type'        => $dataType,
            'nullable'         => $nullable,
            'default_val'      => $defaultVal,
            'col_order'        => $order,
        ];
    }

    public function listColumns(int $tableId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, col_name, data_type, nullable, default_val, col_order
             FROM project_columns WHERE project_table_id = ? ORDER BY col_order ASC'
        );
        $stmt->execute([$tableId]);
        return self::castRows($stmt->fetchAll(), ['id', 'nullable', 'col_order']);
    }

    public function getColumn(int $tableId, string $colName): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, col_name, data_type, nullable, default_val FROM project_columns WHERE project_table_id = ? AND col_name = ?'
        );
        $stmt->execute([$tableId, $colName]);
        return self::castRow($stmt->fetch() ?: null, ['id', 'nullable']);
    }

    public function deleteColumn(int $tableId, string $colName): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM project_columns WHERE project_table_id = ? AND col_name = ?'
        );
        $stmt->execute([$tableId, $colName]);
        return $stmt->rowCount() > 0;
    }

    // ─── Policies ────────────────────────────────────────────────────────────

    public function upsertPolicy(int $tableId, string $apiRole, string $operation, bool $allowed, ?string $constraintSql): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO project_policies (project_table_id, api_role, operation, allowed, constraint_sql)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), constraint_sql = VALUES(constraint_sql)'
        );
        $stmt->execute([$tableId, $apiRole, $operation, $allowed ? 1 : 0, $constraintSql]);
        return $this->getPolicy($tableId, $apiRole, $operation);
    }

    public function listPolicies(int $tableId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, api_role, operation, allowed, constraint_sql FROM project_policies WHERE project_table_id = ?'
        );
        $stmt->execute([$tableId]);
        return self::castRows($stmt->fetchAll(), ['id', 'allowed']);
    }

    public function getPolicy(int $tableId, string $apiRole, string $operation): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, api_role, operation, allowed, constraint_sql
             FROM project_policies WHERE project_table_id = ? AND api_role = ? AND operation = ?'
        );
        $stmt->execute([$tableId, $apiRole, $operation]);
        return self::castRow($stmt->fetch() ?: null, ['id', 'allowed']);
    }

    // ─── Migrations ──────────────────────────────────────────────────────────

    public function recordMigration(int $projectId, string $sql): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO migrations (project_id, statement) VALUES (?, ?)'
        );
        $stmt->execute([$projectId, $sql]);
    }

    // ─── Sites ───────────────────────────────────────────────────────────────

    public function createSite(int $projectId, string $subdomain, bool $spaMode = false): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sites (project_id, subdomain, spa_mode) VALUES (?, ?, ?)'
        );
        $stmt->execute([$projectId, $subdomain, $spaMode ? 1 : 0]);
        $id = (int)$this->pdo->lastInsertId();
        return $this->getSiteById($id);
    }

    public function listSites(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, d.version_label AS current_version, d.uploaded_at AS deployed_at
             FROM sites s
             LEFT JOIN deploys d ON d.id = s.current_deploy_id
             WHERE s.project_id = ?
             ORDER BY s.created_at DESC'
        );
        $stmt->execute([$projectId]);
        return self::castRows($stmt->fetchAll(), ['id', 'project_id', 'current_deploy_id', 'staging_deploy_id', 'spa_mode']);
    }

    public function getSiteById(int $siteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sites WHERE id = ?'
        );
        $stmt->execute([$siteId]);
        return self::castRow($stmt->fetch() ?: null, ['id', 'project_id', 'current_deploy_id', 'staging_deploy_id', 'spa_mode']);
    }

    public function getSiteByProjectId(int $projectId, int $siteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sites WHERE id = ? AND project_id = ?'
        );
        $stmt->execute([$siteId, $projectId]);
        return self::castRow($stmt->fetch() ?: null, ['id', 'project_id', 'current_deploy_id', 'staging_deploy_id', 'spa_mode']);
    }

    public function updateSiteCurrentDeploy(int $siteId, int $deployId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sites SET current_deploy_id = ? WHERE id = ?'
        );
        $stmt->execute([$deployId, $siteId]);
    }

    public function updateSiteStagingDeploy(int $siteId, ?int $deployId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sites SET staging_deploy_id = ? WHERE id = ?'
        );
        $stmt->execute([$deployId, $siteId]);
    }

    public function deleteSite(int $projectId, int $siteId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM sites WHERE id = ? AND project_id = ?'
        );
        $stmt->execute([$siteId, $projectId]);
        return $stmt->rowCount() > 0;
    }

    // ─── Deploys ─────────────────────────────────────────────────────────────

    public function createDeploy(int $siteId, string $versionLabel, int $sizeBytes): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO deploys (site_id, version_label, size_bytes) VALUES (?, ?, ?)'
        );
        $stmt->execute([$siteId, $versionLabel, $sizeBytes]);
        $id = (int)$this->pdo->lastInsertId();
        return $this->getDeployById($id);
    }

    public function updateDeploy(int $deployId, string $status, string $path = ''): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE deploys SET status = ?, path = ? WHERE id = ?'
        );
        $stmt->execute([$status, $path, $deployId]);
    }

    public function getDeployById(int $deployId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM deploys WHERE id = ?');
        $stmt->execute([$deployId]);
        return self::castRow($stmt->fetch() ?: null, ['id', 'site_id', 'size_bytes']);
    }

    public function listDeploys(int $siteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM deploys WHERE site_id = ? ORDER BY uploaded_at DESC'
        );
        $stmt->execute([$siteId]);
        return self::castRows($stmt->fetchAll(), ['id', 'site_id', 'size_bytes']);
    }

    // ─── Personal Access Tokens ──────────────────────────────────────────────

    public function listPats(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, created_at, last_used_at FROM personal_access_tokens WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return self::castRows($stmt->fetchAll(), ['id']);
    }

    public function createPat(int $userId, string $name): string
    {
        $raw  = 'sb_pat_' . bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $stmt = $this->pdo->prepare(
            'INSERT INTO personal_access_tokens (user_id, name, token_hash) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $name, $hash]);
        return $raw;
    }

    public function deletePat(int $userId, int $patId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM personal_access_tokens WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$patId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function findPatByHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id FROM personal_access_tokens WHERE token_hash = ?'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch() ?: null;
        if ($row) {
            $this->pdo->prepare('UPDATE personal_access_tokens SET last_used_at = NOW() WHERE id = ?')
                      ->execute([$row['id']]);
        }
        return $row ? self::castRow($row, ['id', 'user_id']) : null;
    }

    // ─── AI Sessions ─────────────────────────────────────────────────────────

    public function createAiSession(int $userId, string $name, ?int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_sessions (user_id, project_id, name, messages) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $projectId, $name, '[]']);
        $id = (int)$this->pdo->lastInsertId();
        return $this->getAiSession($id, $userId);
    }

    public function getAiSession(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, project_id, name, messages, created_at, updated_at
             FROM ai_sessions WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch() ?: null;
        if (!$row) return null;
        $row['messages'] = json_decode($row['messages'], true) ?? [];
        return self::castRow($row, ['id', 'user_id', 'project_id']);
    }

    public function listAiSessions(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, project_id, name, created_at, updated_at
             FROM ai_sessions WHERE user_id = ? ORDER BY updated_at DESC LIMIT 100'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll() ?: [];
        return self::castRows($rows, ['id', 'user_id', 'project_id']);
    }

    public function updateAiSession(int $id, int $userId, string $name, array $messages): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ai_sessions SET name = ?, messages = ? WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$name, json_encode($messages, JSON_UNESCAPED_UNICODE), $id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function deleteAiSession(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ai_sessions WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }
}
