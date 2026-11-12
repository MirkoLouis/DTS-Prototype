-- Master migration: brings an existing deped_dts up to the current schema without touching its data.
-- Every statement is idempotent, so the whole file can be run again safely. New schema changes are appended below.
-- Run: mysql -u <user> -p deped_dts < scripts/migrations/master-migration.sql
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
