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

    /**
     * Same as listProjects() but enriched with table counts and site status,
     * so the dashboard's project list can show more than just names.
     */
    public function listProjectsWithStats(int $userId): array
    {
        $projects = $this->listProjects($userId);
        $ids = array_map('intval', array_column($projects, 'id'));
        if (!$ids) return [];

        $in = implode(',', array_fill(0, count($ids), '?'));

        $tablesByProject = [];
        $st = $this->pdo->prepare("SELECT project_id, COUNT(*) c FROM project_tables WHERE project_id IN ($in) GROUP BY project_id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $tablesByProject[(int)$r['project_id']] = (int)$r['c'];

        $sitesByProject = [];
        $st = $this->pdo->prepare("SELECT id, project_id, current_deploy_id, staging_deploy_id FROM sites WHERE project_id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $sitesByProject[(int)$r['project_id']] = $r;

        return array_map(function ($p) use ($tablesByProject, $sitesByProject) {
            $pid  = (int)$p['id'];
            $site = $sitesByProject[$pid] ?? null;
            $p['tables']      = $tablesByProject[$pid] ?? 0;
            $p['site_id']     = $site ? (int)$site['id'] : null;
            $p['live']        = (bool)($site && !empty($site['current_deploy_id']));
            $p['has_staging'] = (bool)($site && !empty($site['staging_deploy_id']));
            return $p;
        }, $projects);
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

    /**
     * Aggregated data for the Home overview: stats, recent projects, items that
     * need attention (unpublished staging), and a merged activity feed
     * (project-created, deploys, AI sessions). One method, a handful of queries.
     */
    public function getOverview(int $userId): array
    {
        $pdo      = $this->pdo;
        $projects = $this->listProjects($userId); // id, name, created_at (desc)
        $ids      = array_map('intval', array_column($projects, 'id'));
        $nameById = [];
        foreach ($projects as $p) $nameById[(int)$p['id']] = $p['name'];

        $tablesByProject = [];
        $sitesByProject  = [];
        $tableCount = 0;
        $liveSites  = 0;

        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));

            $st = $pdo->prepare("SELECT project_id, COUNT(*) c FROM project_tables WHERE project_id IN ($in) GROUP BY project_id");
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) { $tablesByProject[(int)$r['project_id']] = (int)$r['c']; $tableCount += (int)$r['c']; }

            $st = $pdo->prepare("SELECT id, project_id, subdomain, current_deploy_id, staging_deploy_id FROM sites WHERE project_id IN ($in)");
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) {
                $sitesByProject[(int)$r['project_id']] = $r;
                if (!empty($r['current_deploy_id'])) $liveSites++;
            }
        }

        // Needs attention: sites with an unpublished staging deploy.
        $needs = [];
        foreach ($sitesByProject as $pid => $site) {
            if (!empty($site['staging_deploy_id'])) {
                $needs[] = [
                    'type'         => 'staging',
                    'project_id'   => (int)$pid,
                    'site_id'      => (int)$site['id'],
                    'deploy_id'    => (int)$site['staging_deploy_id'],
                    'project_name' => $nameById[(int)$pid] ?? 'Project',
                ];
            }
        }

        // Merged activity feed.
        $activity = [];
        foreach ($projects as $p) {
            $activity[] = ['type' => 'project_created', 'project_id' => (int)$p['id'], 'project_name' => $p['name'], 'ts' => $p['created_at']];
        }
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare(
                "SELECT d.id, d.status, d.uploaded_at, s.project_id, s.current_deploy_id, s.staging_deploy_id
                 FROM deploys d JOIN sites s ON s.id = d.site_id
                 WHERE s.project_id IN ($in) ORDER BY d.uploaded_at DESC LIMIT 20"
            );
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) {
                $target = ((int)$r['current_deploy_id'] === (int)$r['id']) ? 'live'
                        : (((int)$r['staging_deploy_id'] === (int)$r['id']) ? 'staging' : 'archived');
                $activity[] = [
                    'type'         => 'deploy',
                    'project_id'   => (int)$r['project_id'],
                    'project_name' => $nameById[(int)$r['project_id']] ?? 'Project',
                    'target'       => $target,
                    'status'       => $r['status'],
                    'ts'           => $r['uploaded_at'],
                ];
            }
        }
        foreach (array_slice($this->listAiSessions($userId), 0, 20) as $s) {
            $activity[] = [
                'type'         => 'session',
                'session_id'   => (int)$s['id'],
                'project_id'   => $s['project_id'] ? (int)$s['project_id'] : null,
                'project_name' => $s['project_id'] ? ($nameById[(int)$s['project_id']] ?? null) : null,
                'name'         => $s['name'],
                'ts'           => $s['updated_at'] ?? $s['created_at'],
            ];
        }
        // Last-activity timestamp per project, used to rank "recent projects" by
        // what actually changed most recently rather than just creation order.
        $lastActivity = [];
        foreach ($activity as $a) {
            $pid = $a['project_id'] ?? null;
            $ts  = $a['ts'] ?? null;
            if ($pid === null || $ts === null) continue;
            if (!isset($lastActivity[$pid]) || strcmp((string)$ts, (string)$lastActivity[$pid]) > 0) {
                $lastActivity[$pid] = $ts;
            }
        }

        $recentProjects = array_map(function ($p) use ($tablesByProject, $sitesByProject, $lastActivity) {
            $pid  = (int)$p['id'];
            $site = $sitesByProject[$pid] ?? null;
            return [
                'id'          => $pid,
                'name'        => $p['name'],
                'created_at'  => $p['created_at'],
                'updated_at'  => $lastActivity[$pid] ?? $p['created_at'],
                'tables'      => $tablesByProject[$pid] ?? 0,
                'site_id'     => $site ? (int)$site['id'] : null,
                'live'        => (bool)($site && !empty($site['current_deploy_id'])),
                'has_staging' => (bool)($site && !empty($site['staging_deploy_id'])),
            ];
        }, $projects);
        usort($recentProjects, fn($a, $b) => strcmp((string)$b['updated_at'], (string)$a['updated_at']));
        $recentProjects = array_slice($recentProjects, 0, 2);

        return [
            'stats'           => ['projects' => count($projects), 'tables' => $tableCount, 'live_sites' => $liveSites],
            'recent_projects' => $recentProjects,
            'needs_attention' => $needs,
            'activity'        => $this->capActivityWithSession($activity, 4),
        ];
    }

    /**
     * Sort a merged activity feed newest-first, cap it to $limit, and make sure
     * at least one 'session' entry survives the cap if one exists at all.
     */
    private function capActivityWithSession(array $activity, int $limit): array
    {
        usort($activity, fn($a, $b) => strcmp((string)($b['ts'] ?? ''), (string)($a['ts'] ?? '')));
        $top = array_slice($activity, 0, $limit);
        if (!in_array('session', array_column($top, 'type'), true)) {
            foreach ($activity as $a) {
                if ($a['type'] === 'session') {
                    if (count($top) >= $limit) array_pop($top);
                    $top[] = $a;
                    break;
                }
            }
        }
        return $top;
    }

    /**
     * Aggregated data for a single project's Overview tab: stats, the site's
     * live/staging status, and a merged activity feed scoped to this project.
     */
    public function getProjectOverview(int $projectId, int $userId): array
    {
        $project = $this->getProjectById($projectId, $userId);
        if (!$project) return [];

        $tables = $this->listTables($projectId);
        $sites  = $this->listSites($projectId);
        $site   = $sites[0] ?? null;

        $activity = [];
        $activity[] = ['type' => 'project_created', 'project_id' => $projectId, 'project_name' => $project['name'], 'ts' => $project['created_at']];
        if ($site) {
            foreach ($this->listDeploys((int)$site['id']) as $d) {
                $target = ((int)($site['current_deploy_id'] ?? 0) === (int)$d['id']) ? 'live'
                        : (((int)($site['staging_deploy_id'] ?? 0) === (int)$d['id']) ? 'staging' : 'archived');
                $activity[] = [
                    'type'         => 'deploy',
                    'project_id'   => $projectId,
                    'project_name' => $project['name'],
                    'target'       => $target,
                    'status'       => $d['status'],
                    'ts'           => $d['uploaded_at'],
                ];
            }
        }
        foreach ($this->listAiSessionsForProject($projectId, $userId) as $s) {
            $activity[] = [
                'type'         => 'session',
                'session_id'   => (int)$s['id'],
                'project_id'   => $projectId,
                'project_name' => $project['name'],
                'name'         => $s['name'],
                'ts'           => $s['updated_at'] ?? $s['created_at'],
            ];
        }

        return [
            'project' => $project,
            'stats' => [
                'tables'      => count($tables),
                'live'        => (bool)($site && !empty($site['current_deploy_id'])),
                'has_staging' => (bool)($site && !empty($site['staging_deploy_id'])),
            ],
            'site_id'  => $site ? (int)$site['id'] : null,
            'activity' => $this->capActivityWithSession($activity, 4),
        ];
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
            'SELECT id, project_id, table_name, table_name AS logical_name, physical_name, created_at FROM project_tables WHERE project_id = ? ORDER BY created_at ASC'
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
            'SELECT id, col_name, col_name AS name, data_type, data_type AS type,
                    nullable, default_val, default_val AS `default`, col_order
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

    public function listAiSessionsForProject(int $projectId, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, project_id, name, created_at, updated_at
             FROM ai_sessions WHERE user_id = ? AND project_id = ? ORDER BY updated_at DESC LIMIT 20'
        );
        $stmt->execute([$userId, $projectId]);
        $rows = $stmt->fetchAll() ?: [];
        return self::castRows($rows, ['id', 'user_id', 'project_id']);
    }

    // Sessions started before a build finished creating a project (Home's
    // "Build with AI" flow, before the new project exists yet).
    public function listAiSessionsUnassigned(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, project_id, name, created_at, updated_at
             FROM ai_sessions WHERE user_id = ? AND project_id IS NULL ORDER BY updated_at DESC LIMIT 20'
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

    // Attaches a session to the project a completed build just created, so the
    // conversation that built it shows up in that project's own history from
    // then on instead of staying in the unassigned "Build with AI" bucket.
    public function setAiSessionProject(int $id, int $userId, int $projectId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ai_sessions SET project_id = ? WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$projectId, $id, $userId]);
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

    // ─── Product Requirements ─────────────────────────────────────────────────

    public function upsertProjectRequirements(int $projectId, int $userId, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare(
            'INSERT INTO project_requirements (project_id, user_id, requirements)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE requirements = VALUES(requirements), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$projectId, $userId, $json]);
    }

    public function getProjectRequirements(int $projectId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT requirements FROM project_requirements WHERE project_id = ?');
        $stmt->execute([$projectId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (json_decode($row['requirements'], true) ?? null) : null;
    }

    // ─── AI Jobs ─────────────────────────────────────────────────────────────
    // One detached OS process per job (spawned by the route that creates it) —
    // this is what lets multiple users' builds/edits run fully in parallel with
    // no shared queue/consumer to bottleneck behind.

    public function createJob(int $userId, ?int $sessionId, string $mode, array $payload): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_jobs (user_id, session_id, mode, payload) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $sessionId, $mode, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        $id = (int)$this->pdo->lastInsertId();
        return $this->getJobById($id, $userId);
    }

    public function getJobById(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, session_id, mode, status, progress, result, error, pid, created_at, updated_at
             FROM ai_jobs WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch() ?: null;
        if (!$row) return null;
        $row['progress'] = $row['progress'] !== null ? (json_decode($row['progress'], true) ?? []) : [];
        if ($row['result'] !== null) {
            $row['result'] = json_decode($row['result'], true);
        }
        return self::castRow($row, ['id', 'user_id', 'session_id', 'pid']);
    }

    public function listActiveJobs(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, user_id, session_id, mode, status, created_at, updated_at
             FROM ai_jobs WHERE user_id = ? AND status IN ('queued','running')
             ORDER BY created_at DESC"
        );
        $stmt->execute([$userId]);
        return self::castRows($stmt->fetchAll() ?: [], ['id', 'user_id', 'session_id', 'pid']);
    }

    // Claims a queued job for this worker process — returns false if another
    // worker already claimed it (shouldn't happen since each job gets its own
    // freshly-spawned process, but keeps the claim atomic regardless).
    public function claimJob(int $jobId, int $pid): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE ai_jobs SET status = 'running', pid = ? WHERE id = ? AND status = 'queued'"
        );
        $stmt->execute([$pid, $jobId]);
        return $stmt->rowCount() === 1;
    }

    public function appendJobProgress(int $jobId, array $event): void
    {
        $stmt = $this->pdo->prepare('SELECT progress FROM ai_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $current = $stmt->fetchColumn();
        $events = $current ? (json_decode($current, true) ?? []) : [];
        $events[] = $event;
        $this->pdo->prepare('UPDATE ai_jobs SET progress = ? WHERE id = ?')
                  ->execute([json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $jobId]);
    }

    public function markJobDone(int $jobId, array $result): void
    {
        $this->pdo->prepare("UPDATE ai_jobs SET status = 'done', result = ? WHERE id = ?")
                   ->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $jobId]);
    }

    public function markJobFailed(int $jobId, string $error): void
    {
        $this->pdo->prepare("UPDATE ai_jobs SET status = 'failed', error = ? WHERE id = ?")
                   ->execute([substr($error, 0, 4096), $jobId]);
    }

    // Returns the job's pid (if any) so the caller can signal the process; null
    // if the job wasn't in a cancellable state (already finished, or not owned
    // by this user).
    public function cancelJob(int $jobId, int $userId): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT pid FROM ai_jobs WHERE id = ? AND user_id = ? AND status IN ('queued','running')"
        );
        $stmt->execute([$jobId, $userId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $this->pdo->prepare("UPDATE ai_jobs SET status = 'cancelled' WHERE id = ?")->execute([$jobId]);
        return $row['pid'] !== null ? (int)$row['pid'] : null;
    }

    /**
     * Latest finished test-job verdict for a project — used to warn before
     * publishing a staging deploy to live. project_id lives inside the job's
     * JSON payload (not a column), so scan the recent test jobs and match.
     */
    public function getLatestTestStatus(int $projectId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, status, result, error, payload, updated_at FROM ai_jobs
             WHERE user_id = ? AND mode = 'test' AND status IN ('done','failed')
             ORDER BY id DESC LIMIT 25"
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $payload = json_decode($row['payload'], true) ?? [];
            if ((int)($payload['project_id'] ?? 0) !== $projectId) continue;
            $result = $row['result'] !== null ? (json_decode($row['result'], true) ?? []) : [];
            return [
                'tested' => true,
                'job_id' => (int)$row['id'],
                'passed' => (int)($result['passed'] ?? 0),
                'failed' => (int)($result['failed'] ?? 0),
                'error'  => $row['status'] === 'failed' ? $row['error'] : ($result['error'] ?? null),
                'at'     => $row['updated_at'],
            ];
        }
        return null;
    }
}
