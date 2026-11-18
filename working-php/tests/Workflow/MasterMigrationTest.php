<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * The master migration brings a pre-deactivation schema up to date and can run again safely.
 */
class MasterMigrationTest extends TestCase
{
    private const MIGRATION = '/scripts/migrations/master-migration.sql';

    // database.sql as it was before S11 (2.7.12), kept as a fixture
    private const PREVIOUS_SCHEMA = '/tests/Fixtures/database-before-s11.sql';

    // A database from before user deactivation is upgraded, and a second run changes nothing
    public function test_migration_upgrades_pre_s1_schema_and_is_idempotent(): void
    {
        // Recreate the old schema: no deactivated_at column, auto-named SET NULL / CASCADE foreign keys
        $connection = Database::getInstance()->getConnection();
        $connection->exec('ALTER TABLE document_logs DROP FOREIGN KEY fk_document_logs_user');
        $connection->exec('ALTER TABLE document_logs ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
        $connection->exec('ALTER TABLE user_public_key_histories DROP FOREIGN KEY fk_user_key_histories_user');
        $connection->exec('ALTER TABLE user_public_key_histories ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        $connection->exec('ALTER TABLE users DROP COLUMN deactivated_at');

        // Run the migration twice; the second run must be a no-op
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);

        // The column exists and each table has exactly one RESTRICT foreign key to users
        $this->assertTrue($this->columnExists('users', 'deactivated_at'));
        $this->assertSame(['RESTRICT'], $this->deleteRules('document_logs'));
        $this->assertSame(['RESTRICT'], $this->deleteRules('user_public_key_histories'));
    }

    // Running the migration on a current schema leaves it unchanged
    public function test_migration_is_a_no_op_on_current_schema(): void
    {
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);

        $this->assertTrue($this->columnExists('users', 'deactivated_at'));
        $this->assertSame(['RESTRICT'], $this->deleteRules('document_logs'));
        $this->assertSame(['RESTRICT'], $this->deleteRules('user_public_key_histories'));
    }

    // A database from before the job heartbeat gets jobs.heartbeat_at, and a second run changes nothing
    public function test_migration_adds_the_job_heartbeat_column(): void
    {
        Database::getInstance()->getConnection()->exec('ALTER TABLE jobs DROP COLUMN heartbeat_at');
        $this->assertFalse($this->columnExists('jobs', 'heartbeat_at'));

        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);

        $this->assertTrue($this->columnExists('jobs', 'heartbeat_at'));
    }

    // A database from before the password reminder gets users.must_change_password (default 0) and the
    // notification link columns, and a second run changes nothing
    public function test_migration_adds_the_password_reminder_columns(): void
    {
        $connection = Database::getInstance()->getConnection();
        $connection->exec('ALTER TABLE users DROP COLUMN must_change_password');
        $connection->exec('ALTER TABLE notifications DROP COLUMN link, DROP COLUMN link_label');
        $this->assertFalse($this->columnExists('users', 'must_change_password'));
        $this->assertFalse($this->columnExists('notifications', 'link'));

        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);

        $this->assertTrue($this->columnExists('users', 'must_change_password'));
        $this->assertTrue($this->columnExists('notifications', 'link'));
        $this->assertTrue($this->columnExists('notifications', 'link_label'));

        // Existing accounts are not flagged by the migration
        $flagged = Database::getInstance()->query('SELECT COUNT(*) c FROM users WHERE must_change_password <> 0')->fetch();
        $this->assertSame(0, (int) $flagged['c']);
    }

    // A database from before session versions gets users.session_version (default 0), and a second run changes nothing
    public function test_migration_adds_the_session_version_column(): void
    {
        Database::getInstance()->getConnection()->exec('ALTER TABLE users DROP COLUMN session_version');
        $this->assertFalse($this->columnExists('users', 'session_version'));

        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);

        $this->assertTrue($this->columnExists('users', 'session_version'));
        $versions = Database::getInstance()->query('SELECT DISTINCT session_version FROM users')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['0'], array_map('strval', $versions));
    }

    // A database from before rate limiting gets the rate_limits table, a second run changes nothing, and rows survive it
    public function test_migration_creates_the_rate_limits_table(): void
    {
        Database::getInstance()->getConnection()->exec('DROP TABLE rate_limits');
        $this->assertFalse($this->tableExists('rate_limits'));

        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);
        $this->assertTrue($this->tableExists('rate_limits'));

        // A counter written after the first run is still there after the second
        Database::getInstance()->query('INSERT INTO rate_limits (rate_key, hits, expires_at) VALUES (:k, 3, 1800000060)', [':k' => str_repeat('a', 64)]);
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);

        $this->assertSame(1, (int) Database::getInstance()->query('SELECT COUNT(*) c FROM rate_limits')->fetch()['c']);
        $this->assertTrue($this->columnExists('rate_limits', 'rate_key'));
        $this->assertTrue($this->columnExists('rate_limits', 'hits'));
        $this->assertTrue($this->columnExists('rate_limits', 'expires_at'));
    }

    // A database from before the chain seal gets document_logs.seal and documents.chain_seal (both NULL, existing rows kept), and a second run changes nothing
    public function test_migration_adds_the_chain_seal_columns(): void
    {
        $document = \Tests\Support\Fixtures::submit();
        $connection = Database::getInstance()->getConnection();
        $connection->exec('ALTER TABLE document_logs DROP COLUMN seal');
        $connection->exec('ALTER TABLE documents DROP COLUMN chain_seal');
        $this->assertFalse($this->columnExists('document_logs', 'seal'));
        $this->assertFalse($this->columnExists('documents', 'chain_seal'));

        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);

        // Both columns exist, hold NULL for the data that existed before, and the rows are still there
        $this->assertTrue($this->columnExists('document_logs', 'seal'));
        $this->assertTrue($this->columnExists('documents', 'chain_seal'));
        $db = Database::getInstance();
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) c FROM documents WHERE id = :id AND chain_seal IS NULL', [':id' => $document['id']])->fetch()['c']);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) c FROM document_logs WHERE document_id = :id AND seal IS NULL', [':id' => $document['id']])->fetch()['c']);
    }

    // Indexes the S11 migration adds: table => [index name => its columns]
    private const S11_INDEXES = [
        'notifications' => ['idx_notifications_dept_unread' => ['department_id', 'is_read', 'created_at']],
        'jobs' => ['idx_jobs_claim' => ['reserved_at', 'available_at']],
        'cache' => ['idx_cache_expiration' => ['expiration']],
        'purposes' => ['idx_purposes_name' => ['name']],
        'departments' => ['uq_departments_name' => ['name']],
    ];

    // Laravel tables no code uses, dropped by the S11 migration
    private const DROPPED_TABLES = ['job_batches', 'password_reset_tokens', 'sessions', 'prediction_keywords'];

    // A database built from the schema before S11 (git HEAD of database.sql at that time): the migration adds the indexes,
    // drops the unused tables, keeps data and the kept cache_locks table, and a second run changes nothing
    public function test_migration_upgrades_the_previous_schema_and_is_idempotent(): void
    {
        $this->rebuildFrom(self::PREVIOUS_SCHEMA);
        $connection = Database::getInstance()->getConnection();

        // The previous schema has the unused tables and none of the new indexes; the legacy prediction_keywords table is added by hand
        $connection->exec('CREATE TABLE prediction_keywords (id INT PRIMARY KEY)');
        foreach (self::DROPPED_TABLES as $table) {
            $this->assertTrue($this->tableExists($table), $table);
        }
        $this->assertSame([], $this->s11IndexesPresent());

        // Data that must survive
        $connection->exec("INSERT INTO departments (name) VALUES ('Records Unit')");
        $connection->exec("INSERT INTO cache (`key`, `value`, `expiration`) VALUES ('k', '1', 1)");

        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);
        $afterFirst = $this->schemaSignature();
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);

        // Indexes added, tables dropped, cache_locks kept, rows kept, second run identical
        $this->assertSame(self::S11_INDEXES, $this->s11IndexesPresent());
        foreach (self::DROPPED_TABLES as $table) {
            $this->assertFalse($this->tableExists($table), $table);
        }
        $this->assertTrue($this->tableExists('cache_locks'));
        $this->assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM departments')->fetchColumn());
        $this->assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM cache')->fetchColumn());
        $this->assertSame($afterFirst, $this->schemaSignature());
    }

    // A database built from the new database.sql has the same tables, columns, indexes and foreign keys as a migrated one,
    // so the two definitions cannot drift apart
    public function test_fresh_schema_equals_the_migrated_schema(): void
    {
        // TestCase has just built the database from database.sql
        $fresh = $this->schemaSignature();

        $this->rebuildFrom(self::PREVIOUS_SCHEMA);
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);

        $this->assertSame($fresh, $this->schemaSignature());
    }

    // Two departments with the same name must not make the migration fail: it keeps a plain index, returns the duplicated
    // names, and once they are renamed a second run adds the UNIQUE index
    public function test_migration_reports_duplicate_department_names_instead_of_failing(): void
    {
        $this->rebuildFrom(self::PREVIOUS_SCHEMA);
        $connection = Database::getInstance()->getConnection();
        $connection->exec("INSERT INTO departments (name) VALUES ('Records Unit'), ('Records Unit'), ('Cash Unit')");

        // The migration runs through; departments.name has a plain, non-unique index; every other change still applied
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);
        $this->assertSame(['idx_departments_name' => 1], $this->indexesOf('departments', 'name'));
        $this->assertSame(3, (int) $connection->query('SELECT COUNT(*) FROM departments')->fetchColumn());
        $this->assertFalse($this->tableExists('sessions'));
        $this->assertArrayHasKey('idx_jobs_claim', $this->indexesOf('jobs', 'reserved_at'));

        // The migration also returns the duplicated names (mysql prints them); here its statement ran without error
        $this->assertStringContainsString('NOT APPLIED: departments.name has duplicates', file_get_contents(BASE_PATH . self::MIGRATION));

        // After the duplicate is renamed, the next run replaces the plain index by the UNIQUE one
        $connection->exec("UPDATE departments SET name = 'Records Unit 2' WHERE id = (SELECT id FROM (SELECT MAX(id) AS id FROM departments WHERE name = 'Records Unit') AS newest)");
        TestDatabase::runSqlFile(BASE_PATH . self::MIGRATION);
        $this->assertSame(['uq_departments_name' => 0], $this->indexesOf('departments', 'name'));
    }

    // Index names on a table whose first column is $column, as name => NON_UNIQUE flag
    private function indexesOf(string $table, string $column): array
    {
        $rows = Database::getInstance()->query(
            'SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c AND SEQ_IN_INDEX = 1 AND INDEX_NAME <> \'PRIMARY\' ORDER BY INDEX_NAME',
            [':t' => $table, ':c' => $column]
        )->fetchAll();

        return array_column(array_map(fn($row) => ['n' => $row['INDEX_NAME'], 'u' => (int) $row['NON_UNIQUE']], $rows), 'u', 'n');
    }

    // The S11 indexes found in the database, in the shape of S11_INDEXES
    private function s11IndexesPresent(): array
    {
        $found = [];
        foreach (self::S11_INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $rows = Database::getInstance()->query(
                    'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i ORDER BY SEQ_IN_INDEX',
                    [':t' => $table, ':i' => $name]
                )->fetchAll(\PDO::FETCH_COLUMN);
                if ($rows !== []) {
                    $found[$table][$name] = $rows;
                }
            }
        }

        return $found;
    }

    // Drop every table, then build the schema from a SQL file
    private function rebuildFrom(string $relativePath): void
    {
        $connection = Database::getInstance()->getConnection();
        $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($connection->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $connection->exec('DROP TABLE `' . $table . '`');
        }
        $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
        TestDatabase::runSqlFile(BASE_PATH . $relativePath);
    }

    // Everything that defines the schema: per table its engine and collation, columns, indexes and foreign keys
    private function schemaSignature(): array
    {
        $db = Database::getInstance();
        $signature = [];
        $tables = $db->query('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($tables as $table) {
            $name = $table['TABLE_NAME'];
            $params = [':t' => $name];
            $signature[$name] = [
                'table' => $table,
                'columns' => $db->query('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t ORDER BY ORDINAL_POSITION', $params)->fetchAll(\PDO::FETCH_ASSOC),
                'indexes' => $db->query('SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, COLLATION, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t ORDER BY INDEX_NAME, SEQ_IN_INDEX', $params)->fetchAll(\PDO::FETCH_ASSOC),
                'foreign_keys' => $db->query('SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, rc.UPDATE_RULE, rc.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND rc.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = :t ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION', $params)->fetchAll(\PDO::FETCH_ASSOC),
            ];
        }

        return $signature;
    }

    // Whether a table exists in the connected database
    private function tableExists(string $table): bool
    {
        $row = Database::getInstance()->query(
            'SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            [':t' => $table]
        )->fetch();

        return (int) $row['c'] === 1;
    }

    // Whether a column exists in the connected database
    private function columnExists(string $table, string $column): bool
    {
        $row = Database::getInstance()->query(
            'SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            [':t' => $table, ':c' => $column]
        )->fetch();

        return (int) $row['c'] === 1;
    }

    // Delete rules of every foreign key from $table.user_id to users
    private function deleteRules(string $table): array
    {
        $rows = Database::getInstance()->query(
            "SELECT rc.DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE k
               ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.TABLE_NAME = rc.TABLE_NAME
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = :t AND rc.REFERENCED_TABLE_NAME = 'users' AND k.COLUMN_NAME = 'user_id'",
            [':t' => $table]
        )->fetchAll();

        return array_column($rows, 'DELETE_RULE');
    }
}
