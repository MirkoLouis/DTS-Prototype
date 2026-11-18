-- Master migration: brings an existing deped_dts up to the current schema without touching its data.
-- Every statement is idempotent, so the whole file can be run again safely. New schema changes are appended below.
-- Run: mysql -u <user> -p <database> < scripts/migrations/master-migration.sql   (the database name is your DB_DATABASE)
-- Fresh databases built from database.sql already include everything here.

-- 2026-10-27: failed-attempt limiting for login and Security PIN entry
CREATE TABLE IF NOT EXISTS attempt_throttles ( throttle_key CHAR(64) PRIMARY KEY, failures INT UNSIGNED NOT NULL DEFAULT 0, first_failed_at INT UNSIGNED NOT NULL, locked_until INT UNSIGNED NULL );

-- 2026-10-30: persisted worker schedule (last run time of each scheduled task)
CREATE TABLE IF NOT EXISTS worker_schedule ( task VARCHAR(64) PRIMARY KEY, last_run_at INT UNSIGNED NOT NULL );

-- 2026-11-12: users are deactivated, never deleted (users.deactivated_at); the foreign keys from signed
-- log entries and key histories to users become ON DELETE RESTRICT so a signer can never be deleted.
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deactivated_at');
SET @sql = IF(@missing, 'ALTER TABLE users ADD COLUMN deactivated_at TIMESTAMP NULL AFTER remember_token', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_logs.user_id: drop a foreign key to users that is not RESTRICT (auto-named in older schemas)
SET @fk = (SELECT rc.CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS rc JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.TABLE_NAME = rc.TABLE_NAME WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = 'document_logs' AND rc.REFERENCED_TABLE_NAME = 'users' AND k.COLUMN_NAME = 'user_id' AND rc.DELETE_RULE <> 'RESTRICT' LIMIT 1);
SET @sql = IF(@fk IS NULL, 'DO 0', CONCAT('ALTER TABLE document_logs DROP FOREIGN KEY `', @fk, '`'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_logs.user_id: add the RESTRICT foreign key when none is left (checks off: added in place, existing rows already valid)
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_logs' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users');
SET @sql = IF(@missing, 'ALTER TABLE document_logs ADD CONSTRAINT fk_document_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT', 'DO 0');
SET FOREIGN_KEY_CHECKS = 0;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET FOREIGN_KEY_CHECKS = 1;

-- user_public_key_histories.user_id: drop a foreign key to users that is not RESTRICT
SET @fk = (SELECT rc.CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS rc JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.TABLE_NAME = rc.TABLE_NAME WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = 'user_public_key_histories' AND rc.REFERENCED_TABLE_NAME = 'users' AND k.COLUMN_NAME = 'user_id' AND rc.DELETE_RULE <> 'RESTRICT' LIMIT 1);
SET @sql = IF(@fk IS NULL, 'DO 0', CONCAT('ALTER TABLE user_public_key_histories DROP FOREIGN KEY `', @fk, '`'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- user_public_key_histories.user_id: add the RESTRICT foreign key when none is left
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_public_key_histories' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users');
SET @sql = IF(@missing, 'ALTER TABLE user_public_key_histories ADD CONSTRAINT fk_user_key_histories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT', 'DO 0');
SET FOREIGN_KEY_CHECKS = 0;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET FOREIGN_KEY_CHECKS = 1;

-- 2026-11-14: job heartbeat (jobs.heartbeat_at): a running job's helper process writes the time here every
-- few seconds; the worker records a reserved job whose heartbeat has stopped as abandoned.
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'heartbeat_at');
SET @sql = IF(@missing, 'ALTER TABLE jobs ADD COLUMN heartbeat_at INT UNSIGNED NULL AFTER reserved_at', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2026-11-15: password reminder (users.must_change_password is 1 while the account still has a default or
-- administrator-set sign-in password) and an optional link on notifications (notifications.link, link_label)
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password');
SET @sql = IF(@missing, 'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER deactivated_at', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'link');
SET @sql = IF(@missing, 'ALTER TABLE notifications ADD COLUMN link VARCHAR(255) NULL AFTER type', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'link_label');
SET @sql = IF(@missing, 'ALTER TABLE notifications ADD COLUMN link_label VARCHAR(100) NULL AFTER link', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2026-11-15: session version (users.session_version): raised when the sign-in password is reset or changed,
-- so every session started before that ends on its next request
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_version');
SET @sql = IF(@missing, 'ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER must_change_password', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2026-11-16: per-IP rate limiting of the public guest routes (fixed window; the key is SHA-256 of bucket|client IP,
-- expires_at is the Unix time the window ends)
CREATE TABLE IF NOT EXISTS rate_limits ( rate_key CHAR(64) PRIMARY KEY, hits INT UNSIGNED NOT NULL, expires_at INT UNSIGNED NOT NULL, INDEX idx_rate_limits_expires_at (expires_at) );

-- 2026-11-18: chain seal (S10). document_logs.seal is the HMAC-SHA256 of the entry's block hash under APP_INTEGRITY_KEY;
-- documents.chain_seal is the HMAC of the document's status, step, holder, decline reason, released-by user and the head of
-- its chain. Both stay NULL until scripts/seal-chains.php seals existing data (run it once after this migration).
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_logs' AND COLUMN_NAME = 'seal');
SET @sql = IF(@missing, 'ALTER TABLE document_logs ADD COLUMN seal CHAR(64) NULL AFTER signature', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'chain_seal');
SET @sql = IF(@missing, 'ALTER TABLE documents ADD COLUMN chain_seal CHAR(64) NULL AFTER released_by_user_id', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2026-11-18: operations and performance (S11). Indexes for the queries that need them, unused tables dropped.
-- Index on the notification poll: the newest unread notifications of one department (NotificationService::unreadSql)
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_dept_unread');
SET @sql = IF(@missing, 'ALTER TABLE notifications ADD INDEX idx_notifications_dept_unread (department_id, is_read, created_at)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on the job claim: the next due, unreserved job (QueueService::NEXT_PENDING_SQL)
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND INDEX_NAME = 'idx_jobs_claim');
SET @sql = IF(@missing, 'ALTER TABLE jobs ADD INDEX idx_jobs_claim (reserved_at, available_at)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on the cache purge: expired rows are deleted by expiration (CachePurgeTask)
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cache' AND INDEX_NAME = 'idx_cache_expiration');
SET @sql = IF(@missing, 'ALTER TABLE cache ADD INDEX idx_cache_expiration (expiration)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on purposes.name: lookups by name and the name-ordered lists
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purposes' AND INDEX_NAME = 'idx_purposes_name');
SET @sql = IF(@missing, 'ALTER TABLE purposes ADD INDEX idx_purposes_name (name)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- departments.name becomes UNIQUE (lookups by name assume one department per name). A database that already holds two
-- departments with the same name cannot take a UNIQUE index, so the migration does not fail: it adds a plain index
-- idx_departments_name instead and returns the duplicated names as a result set. Rename or merge those departments, then run
-- this file again: with no duplicates left it replaces the plain index by the UNIQUE index uq_departments_name.
SET @duplicates = (SELECT COUNT(*) FROM (SELECT name FROM departments GROUP BY name HAVING COUNT(*) > 1) AS duplicated);
SET @has_unique = (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND INDEX_NAME = 'uq_departments_name');
SET @has_plain = (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND INDEX_NAME = 'idx_departments_name');

-- No duplicates and no UNIQUE index yet: drop the plain stand-in from an earlier run, then add the UNIQUE index
SET @sql = IF(@duplicates = 0 AND NOT @has_unique AND @has_plain, 'ALTER TABLE departments DROP INDEX idx_departments_name', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@duplicates = 0 AND NOT @has_unique, 'ALTER TABLE departments ADD UNIQUE INDEX uq_departments_name (name)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Duplicates: keep lookups fast with a plain index, and report which names are duplicated
SET @sql = IF(@duplicates > 0 AND NOT @has_plain AND NOT @has_unique, 'ALTER TABLE departments ADD INDEX idx_departments_name (name)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@duplicates > 0, 'SELECT ''NOT APPLIED: departments.name has duplicates, rename or merge them and run this migration again'' AS migration_notice, name AS duplicated_department_name, COUNT(*) AS department_rows FROM departments GROUP BY name HAVING COUNT(*) > 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Unused Laravel tables: nothing in the application reads or writes them (PHP sessions are files, queued jobs use jobs,
-- there are no password-reset tokens or batches), and prediction_keywords belongs to a removed feature. Take a backup first.
DROP TABLE IF EXISTS job_batches;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS prediction_keywords;
