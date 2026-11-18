-- Builds the schema in the database the caller has selected; it names no database itself.
-- Run: mysql -u <user> -p <database> < database.sql   (create the database first; setup-production-db.js does that from DB_DATABASE)

SET FOREIGN_KEY_CHECKS = 0;

-- Drop all tables to be safe (job_batches, password_reset_tokens, sessions and prediction_keywords are no longer created; they are listed only so a rebuild also removes copies left by older versions)
DROP TABLE IF EXISTS worker_schedule, rate_limits, attempt_throttles, notifications, cache, cache_locks, jobs, job_batches, failed_jobs, departments, users, user_public_key_histories, password_reset_tokens, sessions, purposes, documents, document_logs, prediction_keywords, daily_department_metrics, database_metrics, report_jobs, integrity_checks;

-- 1. Core Laravel & Infrastructure Tables
-- expiration index: the hourly purge of expired rows (CachePurgeTask) reads only the expired range
CREATE TABLE cache ( `key` VARCHAR(255) PRIMARY KEY, `value` MEDIUMTEXT, `expiration` INT, INDEX idx_cache_expiration (expiration) );
CREATE TABLE cache_locks ( `key` VARCHAR(255) PRIMARY KEY, `owner` VARCHAR(255), `expiration` INT );
CREATE TABLE jobs ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, queue VARCHAR(255), payload LONGTEXT, attempts TINYINT UNSIGNED, reserved_at INT UNSIGNED NULL, heartbeat_at INT UNSIGNED NULL, available_at INT UNSIGNED, created_at INT UNSIGNED, INDEX idx_jobs_claim (reserved_at, available_at) );
CREATE TABLE failed_jobs ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, uuid VARCHAR(255) UNIQUE, connection TEXT, queue TEXT, payload LONGTEXT, exception LONGTEXT, failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP );

-- 2. DTS Domain Tables
CREATE TABLE departments ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, UNIQUE INDEX uq_departments_name (name) );
CREATE TABLE users ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), email VARCHAR(255) UNIQUE, email_verified_at TIMESTAMP NULL, password VARCHAR(255), public_key TEXT NULL, private_key TEXT NULL, security_key_set_at TIMESTAMP NULL, department_id BIGINT UNSIGNED NULL, role ENUM('officer', 'staff', 'admin') DEFAULT 'staff', remember_token VARCHAR(100) NULL, deactivated_at TIMESTAMP NULL, must_change_password TINYINT(1) NOT NULL DEFAULT 0, session_version INT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL );
CREATE TABLE user_public_key_histories ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED, public_key TEXT, activated_at TIMESTAMP, deactivated_at TIMESTAMP, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, CONSTRAINT fk_user_key_histories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT, INDEX idx_user_key_period (user_id, activated_at, deactivated_at) );
CREATE TABLE purposes ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), is_official BOOLEAN DEFAULT FALSE, requirements JSON NULL, suggested_route JSON NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, INDEX idx_purposes_name (name) );

-- 3. Workflow & Tracking Tables
CREATE TABLE documents ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tracking_code VARCHAR(255) UNIQUE, title VARCHAR(255) NULL, details TEXT NULL, guest_info JSON NULL, district VARCHAR(255) NULL, department VARCHAR(255) NULL, purpose_id BIGINT UNSIGNED, decline_reason TEXT NULL, declined_at TIMESTAMP NULL, status VARCHAR(255) DEFAULT 'pending', version INT DEFAULT 1, finalized_route JSON NULL, current_step INT NULL, current_department_id BIGINT UNSIGNED NULL, released_at TIMESTAMP NULL, released_by_user_id BIGINT UNSIGNED NULL, chain_seal CHAR(64) NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, FOREIGN KEY (purpose_id) REFERENCES purposes(id) ON DELETE CASCADE, FOREIGN KEY (current_department_id) REFERENCES departments(id) ON DELETE SET NULL, FOREIGN KEY (released_by_user_id) REFERENCES users(id) ON DELETE SET NULL, INDEX idx_status (status), INDEX idx_created_at (created_at), INDEX idx_doc_status_created_composite (status, created_at), INDEX idx_doc_current_dept (current_department_id), INDEX idx_doc_released_at (released_at), INDEX idx_doc_released_by (released_by_user_id) );
CREATE TABLE document_logs ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, document_id BIGINT UNSIGNED, user_id BIGINT UNSIGNED NULL, action VARCHAR(255), remarks TEXT NULL, hash VARCHAR(255), previous_hash TEXT NULL, signature TEXT NULL, seal CHAR(64) NULL, document_state_hash VARCHAR(64) NULL, document_snapshot JSON NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, action_category TINYINT GENERATED ALWAYS AS ( CASE WHEN action = 'Accepted and Document Routing finalized' THEN 1 WHEN action = 'Processing Complete' OR action LIKE 'Document routed to%' THEN 2 ELSE 0 END ) STORED, FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE, CONSTRAINT fk_document_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT, INDEX idx_log_document_id (document_id), INDEX idx_log_user_id (user_id), INDEX idx_log_hash (hash), INDEX idx_log_action (action), INDEX idx_log_created_at (created_at), INDEX idx_log_category (user_id, action_category, document_id, created_at) );

-- 4. Analytics & Utilities
CREATE TABLE daily_department_metrics ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, department_id BIGINT UNSIGNED, date DATE, received_count INT UNSIGNED DEFAULT 0, processed_count INT UNSIGNED DEFAULT 0, released_count INT UNSIGNED DEFAULT 0, total_processing_seconds BIGINT UNSIGNED DEFAULT 0, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE, UNIQUE INDEX idx_dept_date_unique (department_id, date), INDEX idx_metrics_date (date) );
CREATE TABLE database_metrics ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, connections INT UNSIGNED, avg_query_time_ms DECIMAL(10, 4), slow_queries INT UNSIGNED, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_metrics_created_at (created_at) );
CREATE TABLE report_jobs ( id CHAR(36) PRIMARY KEY, user_id BIGINT UNSIGNED, status VARCHAR(255) DEFAULT 'queued', progress INT UNSIGNED DEFAULT 0, total_documents INT UNSIGNED DEFAULT 0, file_path VARCHAR(255) NULL, filters JSON NULL, error_message TEXT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE );
CREATE TABLE integrity_checks ( id CHAR(36) PRIMARY KEY, user_id BIGINT UNSIGNED, status VARCHAR(255) DEFAULT 'queued', progress INT UNSIGNED DEFAULT 0, results JSON NULL, error_message TEXT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE );
-- Failed-attempt limiting for login and Security PIN entry (keys are SHA-256 of scope|subject)
CREATE TABLE attempt_throttles ( throttle_key CHAR(64) PRIMARY KEY, failures INT UNSIGNED NOT NULL DEFAULT 0, first_failed_at INT UNSIGNED NOT NULL, locked_until INT UNSIGNED NULL );
-- Fixed-window rate limiting of the public routes (key is SHA-256 of bucket|client IP; expires_at is the Unix time the window ends)
CREATE TABLE rate_limits ( rate_key CHAR(64) PRIMARY KEY, hits INT UNSIGNED NOT NULL, expires_at INT UNSIGNED NOT NULL, INDEX idx_rate_limits_expires_at (expires_at) );
-- Last run time (Unix epoch) of each scheduled worker task, so worker restarts do not postpone daily tasks
CREATE TABLE worker_schedule ( task VARCHAR(64) PRIMARY KEY, last_run_at INT UNSIGNED NOT NULL );

SET FOREIGN_KEY_CHECKS = 1;

-- 5. Notifications
CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('success', 'error', 'info', 'warning') DEFAULT 'info',
    link VARCHAR(255) NULL,
    link_label VARCHAR(100) NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_department (department_id),
    INDEX idx_user (user_id),
    INDEX idx_unread (is_read),
    -- unread poll of a department (NotificationService::unreadSql): newest unread rows of one department straight from the index
    INDEX idx_notifications_dept_unread (department_id, is_read, created_at)
);
