-- Task 7: failed-attempt limiting for login and Security PIN entry.
-- Run once on an existing database: mysql -u <user> -p deped_dts < scripts/migrations/2026_10_27_add_attempt_throttles.sql
CREATE TABLE IF NOT EXISTS attempt_throttles ( throttle_key CHAR(64) PRIMARY KEY, failures INT UNSIGNED NOT NULL DEFAULT 0, first_failed_at INT UNSIGNED NOT NULL, locked_until INT UNSIGNED NULL );
