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

    // ─── Projects ────────────────────────────────────────────────────────────

    public function createProject(int $userId, string $name): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (owner_user_id, name) VALUES (?, ?)'
        );
        $stmt->execute([$userId, $name]);
        return $this->getProjectById((int)$this->pdo->lastInsertId(), $userId);
    }

    public function listProjects(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, owner_user_id, name, created_at FROM projects WHERE owner_user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getProjectById(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, owner_user_id, name, created_at FROM projects WHERE id = ? AND owner_user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->fetch() ?: null;
    }

    /** Returns project without ownership check — for internal use only. */
    public function getProjectByIdInternal(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, owner_user_id, name, created_at FROM projects WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
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
        return $stmt->fetchAll();
    }

    public function getTable(int $projectId, string $logicalName): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, project_id, table_name, physical_name FROM project_tables WHERE project_id = ? AND table_name = ?'
        );
        $stmt->execute([$projectId, $logicalName]);
        return $stmt->fetch() ?: null;
    }

    public function getTableById(int $tableId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, project_id, table_name, physical_name FROM project_tables WHERE id = ?'
        );
        $stmt->execute([$tableId]);
        return $stmt->fetch() ?: null;
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
        return $stmt->fetchAll();
    }

    public function getColumn(int $tableId, string $colName): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, col_name, data_type, nullable, default_val FROM project_columns WHERE project_table_id = ? AND col_name = ?'
        );
        $stmt->execute([$tableId, $colName]);
        return $stmt->fetch() ?: null;
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
        return $stmt->fetchAll();
    }

    public function getPolicy(int $tableId, string $apiRole, string $operation): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, api_role, operation, allowed, constraint_sql
             FROM project_policies WHERE project_table_id = ? AND api_role = ? AND operation = ?'
        );
        $stmt->execute([$tableId, $apiRole, $operation]);
        return $stmt->fetch() ?: null;
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
        return $stmt->fetchAll();
    }

    public function getSiteById(int $siteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sites WHERE id = ?'
        );
        $stmt->execute([$siteId]);
        return $stmt->fetch() ?: null;
    }

    public function getSiteByProjectId(int $projectId, int $siteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sites WHERE id = ? AND project_id = ?'
        );
        $stmt->execute([$siteId, $projectId]);
        return $stmt->fetch() ?: null;
    }

    public function updateSiteCurrentDeploy(int $siteId, int $deployId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sites SET current_deploy_id = ? WHERE id = ?'
        );
        $stmt->execute([$deployId, $siteId]);
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
        return $stmt->fetch() ?: null;
    }

    public function listDeploys(int $siteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM deploys WHERE site_id = ? ORDER BY uploaded_at DESC'
        );
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }
}
