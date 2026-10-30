-- Master migration: brings an existing deped_dts up to the current schema without touching its data.
-- Every statement is idempotent, so the whole file can be run again safely. New schema changes are appended below.
-- Run: mysql -u <user> -p deped_dts < scripts/migrations/master-migration.sql
-- Fresh databases built from database.sql already include everything here.

-- 2026-10-27: failed-attempt limiting for login and Security PIN entry
CREATE TABLE IF NOT EXISTS attempt_throttles ( throttle_key CHAR(64) PRIMARY KEY, failures INT UNSIGNED NOT NULL DEFAULT 0, first_failed_at INT UNSIGNED NOT NULL, locked_until INT UNSIGNED NULL );

-- 2026-10-30: persisted worker schedule (last run time of each scheduled task)
CREATE TABLE IF NOT EXISTS worker_schedule ( task VARCHAR(64) PRIMARY KEY, last_run_at INT UNSIGNED NOT NULL );
