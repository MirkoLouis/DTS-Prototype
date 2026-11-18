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
