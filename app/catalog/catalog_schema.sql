-- SupaBein Control Plane Schema
-- Run this once against your MySQL/MariaDB database.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email`         VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role`          ENUM('owner','member') NOT NULL DEFAULT 'owner',
    `country`       CHAR(2) DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `projects` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `owner_user_id` INT UNSIGNED NOT NULL,
    `name`          VARCHAR(128) NOT NULL,
    `service_key`   TEXT DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`owner_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_owner_name` (`owner_user_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `project_tables` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_id`    INT UNSIGNED NOT NULL,
    `table_name`    VARCHAR(64) NOT NULL,
    `physical_name` VARCHAR(64) NOT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_proj_logical` (`project_id`, `table_name`),
    UNIQUE KEY `uq_physical` (`physical_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `project_columns` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_table_id` INT UNSIGNED NOT NULL,
    `col_name`         VARCHAR(64) NOT NULL,
    `data_type`        VARCHAR(32) NOT NULL,
    `nullable`         TINYINT(1) NOT NULL DEFAULT 1,
    `default_val`      VARCHAR(255) DEFAULT NULL,
    `col_order`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (`project_table_id`) REFERENCES `project_tables`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_col` (`project_table_id`, `col_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `project_policies` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_table_id` INT UNSIGNED NOT NULL,
    `api_role`         VARCHAR(64) NOT NULL,
    `operation`        ENUM('SELECT','INSERT','UPDATE','DELETE') NOT NULL,
    `allowed`          TINYINT(1) NOT NULL DEFAULT 0,
    `constraint_sql`   TEXT DEFAULT NULL,
    FOREIGN KEY (`project_table_id`) REFERENCES `project_tables`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_policy` (`project_table_id`, `api_role`, `operation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `migrations` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT UNSIGNED NOT NULL,
    `statement`  TEXT NOT NULL,
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sites` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_id`        INT UNSIGNED NOT NULL,
    `subdomain`         VARCHAR(63) NOT NULL,
    `custom_domain`     VARCHAR(255) DEFAULT NULL,
    `current_deploy_id` INT UNSIGNED DEFAULT NULL,
    `staging_deploy_id` INT UNSIGNED DEFAULT NULL,
    `spa_mode`          TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_subdomain` (`subdomain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Neutral hostname -> docroot registry for the wildcard vhost's router.
-- Deliberately NOT foreign-keyed to `projects`/`sites`: this table is meant
-- to outlive any single product's schema (see site-server/router.php, which
-- reads only this table and never touches SupaBein's own DB structures).
-- `project_id` is stored for ownership checks on update/delete only.
--
-- `registered_by_table`/`registered_by_user_id` identify which specific
-- project_user (e.g. one Zera business owner, not just "someone in project
-- 56") owns a given hostname -- both NULL means an owner/PAT registered it
-- (the project's own default subdomain, or a manual override), which no
-- project_user can ever touch regardless of project match. Without this,
-- `project_id` alone let any authenticated end-user of a project delete or
-- reassign any *other* end-user's hostname within that same project.
CREATE TABLE IF NOT EXISTS `site_registry` (
    `hostname`              VARCHAR(255) NOT NULL PRIMARY KEY,
    `docroot`               VARCHAR(512) NOT NULL,
    `spa_mode`              TINYINT(1) NOT NULL DEFAULT 0,
    `project_id`            INT UNSIGNED NOT NULL,
    `registered_by_table`   VARCHAR(64) DEFAULT NULL,
    `registered_by_user_id` INT UNSIGNED DEFAULT NULL,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `deploys` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `site_id`       INT UNSIGNED NOT NULL,
    `version_label` VARCHAR(128) NOT NULL,
    `path`          VARCHAR(512) NOT NULL DEFAULT '',
    `status`        ENUM('pending','processing','ready','failed') NOT NULL DEFAULT 'pending',
    `size_bytes`    BIGINT UNSIGNED DEFAULT 0,
    `uploaded_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `name`         VARCHAR(128) NOT NULL,
    `token_hash`   VARCHAR(64) NOT NULL,
    -- NULL = account-wide (original behavior). Set = scoped to one project;
    -- enforced generically wherever resolved auth carries project_id
    -- (see Crud::resolve() and auth_middleware()'s route allowlist).
    `project_id`   INT UNSIGNED DEFAULT NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS `rate_limits` (
    `project_id`   INT UNSIGNED NOT NULL,
    `window_start` INT UNSIGNED NOT NULL,
    `count`        INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`project_id`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_sessions` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `project_id` INT UNSIGNED DEFAULT NULL,
    `name`       VARCHAR(120) NOT NULL DEFAULT 'New session',
    `messages`   LONGTEXT NOT NULL DEFAULT '[]',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_jobs` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `session_id` INT UNSIGNED DEFAULT NULL,
    `mode`       ENUM('build','edit','test','seed','build_schema','build_frontend') NOT NULL,
    `payload`    LONGTEXT NOT NULL,
    `progress`   LONGTEXT DEFAULT NULL,
    `status`     ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
    `result`     LONGTEXT DEFAULT NULL,
    `error`      TEXT DEFAULT NULL,
    `pid`        INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    KEY `idx_user_status` (`user_id`, `status`),
    KEY `idx_status_created` (`status`, `created_at`),
    KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_reset_tokens` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at`    DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_user_reset_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `project_requirements` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_id`   INT UNSIGNED NOT NULL,
    `user_id`      INT UNSIGNED NOT NULL,
    `requirements` LONGTEXT NOT NULL DEFAULT '{}',
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_project` (`project_id`),
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_error_logs` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_id`    INT UNSIGNED NOT NULL,
    `type`          ENUM('js_error','promise_rejection','api_error','console_error') NOT NULL,
    `message`       TEXT NOT NULL,
    `stack`         TEXT DEFAULT NULL,
    `url`           VARCHAR(1024) DEFAULT NULL,
    `user_agent`    VARCHAR(512) DEFAULT NULL,
    `meta`          TEXT DEFAULT NULL,
    `fingerprint`   CHAR(32) NOT NULL,
    `occurrences`   INT UNSIGNED NOT NULL DEFAULT 1,
    `first_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_project_fingerprint` (`project_id`, `fingerprint`),
    KEY `idx_project_last_seen` (`project_id`, `last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_error_log_limits` (
    `project_id`   INT UNSIGNED NOT NULL,
    `window_start` INT UNSIGNED NOT NULL,
    `count`        INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`project_id`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-project rate limiting for end-user (project_user) storage writes --
-- separate bucket from the general data-API limit for the same reason
-- ai_error_log_limits is separate: a burst on one shouldn't eat into or
-- trip the other's quota.
CREATE TABLE IF NOT EXISTS `storage_rate_limits` (
    `project_id`   INT UNSIGNED NOT NULL,
    `window_start` INT UNSIGNED NOT NULL,
    `count`        INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`project_id`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-project, per-bucket opt-in for end-user storage writes. No row (or
-- allow_authenticated_upload=0) means the bucket stays operator-only --
-- same "unpolicied = locked" default as table policies.
CREATE TABLE IF NOT EXISTS `storage_bucket_policies` (
    `id`                         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_id`                 INT UNSIGNED NOT NULL,
    `bucket`                     VARCHAR(64) NOT NULL,
    `allow_authenticated_upload` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_project_bucket` (`project_id`, `bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-project registered outbound integration: a name-scoped external API
-- base_url + a write-only secret (never returned by GET -- same trust model
-- as the PASSWORD column type, settable, never readable). SupaBein injects
-- the secret server-side per `auth_style` when proxying a call on the
-- project's behalf; the caller never sees it. See
-- app/routes/integration_routes.php.
CREATE TABLE IF NOT EXISTS `integrations` (
    `id`                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_id`                  INT UNSIGNED NOT NULL,
    `name`                        VARCHAR(64) NOT NULL,
    `base_url`                    VARCHAR(255) NOT NULL,
    `auth_style`                  VARCHAR(64) NOT NULL,
    `secret_ciphertext`           TEXT NOT NULL,
    -- NULL = proxy calls require a PAT/owner token. A JSON array of path
    -- prefixes (e.g. ["bank/resolve","transaction/verify/"]) opts specific
    -- request paths in for any authenticated project_user too -- deny by
    -- default, the same posture as a table policy with no row.
    `allowed_project_user_paths` TEXT DEFAULT NULL,
    `created_at`                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_project_integration` (`project_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-project registered inbound webhook: verifies a signed payload from an
-- external service, then applies a fixed, declarative column-update template
-- (`write_json` -- never arbitrary code, same "bounded but not arbitrary"
-- reasoning as `constraint_sql`) to one row of one of the project's own
-- tables. See app/routes/webhook_routes.php.
CREATE TABLE IF NOT EXISTS `webhooks` (
    `id`                           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_id`                   INT UNSIGNED NOT NULL,
    `name`                         VARCHAR(64) NOT NULL,
    `signature_header`             VARCHAR(128) NOT NULL,
    `signature_algorithm`          VARCHAR(32) NOT NULL,
    `signature_secret_ciphertext`  TEXT NOT NULL,
    `match_json`                   TEXT DEFAULT NULL,
    `write_json`                   TEXT NOT NULL,
    `created_at`                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_project_webhook` (`project_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;

-- ─── Migration: project_requirements (run once on existing installs) ─────────
-- CREATE TABLE IF NOT EXISTS `project_requirements` (
--     `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--     `project_id`   INT UNSIGNED NOT NULL,
--     `user_id`      INT UNSIGNED NOT NULL,
--     `requirements` LONGTEXT NOT NULL DEFAULT '{}',
--     `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
--     UNIQUE KEY `uq_project` (`project_id`),
--     FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
--     FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Migration: ai_sessions (run once on existing installs) ─────────────────
-- CREATE TABLE IF NOT EXISTS `ai_sessions` (
--     `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--     `user_id`    INT UNSIGNED NOT NULL,
--     `project_id` INT UNSIGNED DEFAULT NULL,
--     `name`       VARCHAR(120) NOT NULL DEFAULT 'New session',
--     `messages`   LONGTEXT NOT NULL DEFAULT '[]',
--     `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
--     FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
--     FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Migration SQL for existing installs ────────────────────────────────────
-- Run these ALTER statements once if you already have the projects table:
--
-- ALTER TABLE `sites`
--   ADD COLUMN `staging_deploy_id` INT UNSIGNED DEFAULT NULL AFTER `current_deploy_id`;
--
-- ALTER TABLE `projects`
--   ADD COLUMN `anon_key`    TEXT DEFAULT NULL AFTER `name`,
--   ADD COLUMN `service_key` TEXT DEFAULT NULL AFTER `anon_key`;
--
-- CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
--     `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--     `project_user_id` INT UNSIGNED NOT NULL,
--     `token_hash`      VARCHAR(64) NOT NULL,
--     `expires_at`      DATETIME NOT NULL,
--     `used_at`         DATETIME NULL DEFAULT NULL,
--     `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     FOREIGN KEY (`project_user_id`) REFERENCES `project_users`(`id`) ON DELETE CASCADE,
--     UNIQUE KEY `uq_token_hash` (`token_hash`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--
-- CREATE TABLE IF NOT EXISTS `rate_limits` (
--     `project_id`   INT UNSIGNED NOT NULL,
--     `window_start` INT UNSIGNED NOT NULL,
--     `count`        INT UNSIGNED NOT NULL DEFAULT 0,
--     PRIMARY KEY (`project_id`, `window_start`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--
-- CREATE TABLE IF NOT EXISTS `project_users` (
--     `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--     `project_id`    INT UNSIGNED NOT NULL,
--     `email`         VARCHAR(255) NOT NULL,
--     `password_hash` VARCHAR(255) NOT NULL,
--     `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
--     UNIQUE KEY `uq_project_email` (`project_id`, `email`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--
-- CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
--     `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--     `user_id`      INT UNSIGNED NOT NULL,
--     `name`         VARCHAR(128) NOT NULL,
--     `token_hash`   VARCHAR(64) NOT NULL,
--     `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     `last_used_at` TIMESTAMP NULL DEFAULT NULL,
--     FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
--     UNIQUE KEY `uq_token_hash` (`token_hash`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Migration: ai_jobs resumable-progress columns (run once on existing installs) ──
-- Links a job back to the AI session that created it, and stores the same stage
-- events the old NDJSON stream emitted so a polling client can replay them.
-- ALTER TABLE `ai_jobs`
--   ADD COLUMN `session_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`,
--   ADD COLUMN `progress`   LONGTEXT DEFAULT NULL AFTER `payload`,
--   MODIFY COLUMN `status`  ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
--   ADD KEY `idx_session` (`session_id`);

-- ─── Migration: ai_jobs test mode (run once on existing installs) ───────────
-- ALTER TABLE `ai_jobs`
--   MODIFY COLUMN `mode` ENUM('build','edit','test') NOT NULL;

-- ─── Migration: project_seed_rows (run once on existing installs) ───────────
-- Tracks which rows in a generated app's tables were inserted by AI seeding
-- (build's initial seed_data or an on-demand edit-mode "seed N fake rows"
-- request), so "clear seed data" can remove only those rows and leave real
-- user-entered data alone.
-- CREATE TABLE IF NOT EXISTS `project_seed_rows` (
--     `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--     `project_id` INT UNSIGNED NOT NULL,
--     `table_name` VARCHAR(64) NOT NULL,
--     `row_id`     INT UNSIGNED NOT NULL,
--     `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     KEY `idx_project_table` (`project_id`, `table_name`),
--     FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Migration: ai_jobs seed mode (run once on existing installs) ───────────
-- ALTER TABLE `ai_jobs`
--   MODIFY COLUMN `mode` ENUM('build','edit','test','seed') NOT NULL;

-- ─── Migration: ai_jobs build_schema/build_frontend modes (run once on existing installs) ───
-- ALTER TABLE `ai_jobs`
--   MODIFY COLUMN `mode` ENUM('build','edit','test','seed','build_schema','build_frontend') NOT NULL;

-- ─── Migration: ai_error_logs + ai_error_log_limits (run once on existing installs) ───
-- Stores end-user-browser errors reported by the platform-injected core/errors.js
-- script. Deduped server-side by (project_id, fingerprint) — repeat occurrences of
-- the same error increment `occurrences` and bump `last_seen_at` instead of adding
-- rows, so a tight error loop can't grow the table unbounded. `ai_error_log_limits`
-- is a per-project sliding-window request counter (same shape as `rate_limits`,
-- kept separate so a flood of error reports can't also exhaust the project's
-- normal data-API quota, or vice versa).
-- CREATE TABLE IF NOT EXISTS `ai_error_logs` (
--     `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--     `project_id`    INT UNSIGNED NOT NULL,
--     `type`          ENUM('js_error','promise_rejection','api_error','console_error') NOT NULL,
--     `message`       TEXT NOT NULL,
--     `stack`         TEXT DEFAULT NULL,
--     `url`           VARCHAR(1024) DEFAULT NULL,
--     `user_agent`    VARCHAR(512) DEFAULT NULL,
--     `meta`          TEXT DEFAULT NULL,
--     `fingerprint`   CHAR(32) NOT NULL,
--     `occurrences`   INT UNSIGNED NOT NULL DEFAULT 1,
--     `first_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     `last_seen_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
--     UNIQUE KEY `uq_project_fingerprint` (`project_id`, `fingerprint`),
--     KEY `idx_project_last_seen` (`project_id`, `last_seen_at`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--
-- CREATE TABLE IF NOT EXISTS `ai_error_log_limits` (
--     `project_id`   INT UNSIGNED NOT NULL,
--     `window_start` INT UNSIGNED NOT NULL,
--     `count`        INT UNSIGNED NOT NULL DEFAULT 0,
--     PRIMARY KEY (`project_id`, `window_start`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Migration: users.country (run once on existing installs) ───────────────
-- ALTER TABLE `users`
--   ADD COLUMN `country` CHAR(2) DEFAULT NULL AFTER `role`;
