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
