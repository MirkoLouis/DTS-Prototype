<?php

namespace Tests\Unit;

use App\Common\Database\Database;
use App\Common\Database\SqlScriptRunner;
use Tests\Support\TestFiles;
use Tests\TestCase;

/**
 * The SQL script runner returns the notices a script raises and still stops on a failing statement.
 */
class SqlScriptRunnerTest extends TestCase
{
    // Write a script into the test folder and return its path
    private function script(string $sql): string
    {
        $path = TestFiles::dir('sql-script') . '/script.sql';
        file_put_contents($path, $sql);

        return $path;
    }

    // Whether a table exists in the test database
    private function tableExists(string $table): bool
    {
        return (bool) Database::getInstance()->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', [':t' => $table])->fetch();
    }

    // Notice rows between other statements are returned, with their other columns; ordinary result rows are dropped and later statements still run
    public function test_returns_notice_rows_from_the_middle_of_a_script(): void
    {
        $path = $this->script(
            "SELECT 1 AS ordinary_result;\n"
            . "SELECT 'first notice' AS " . SqlScriptRunner::NOTICE_COLUMN . ", 'Records Unit' AS name, 2 AS department_rows;\n"
            . "CREATE TABLE runner_probe (id INT);\n"
            . "SELECT 'second notice' AS " . SqlScriptRunner::NOTICE_COLUMN . ";\n"
        );

        $notices = SqlScriptRunner::runFile(Database::getInstance()->getConnection(), $path);

        $this->assertSame(['first notice [name=Records Unit, department_rows=2]', 'second notice'], $notices);
        $this->assertTrue($this->tableExists('runner_probe'));
    }

    // A script without notices returns an empty list
    public function test_script_without_notices_returns_nothing(): void
    {
        $this->assertSame([], SqlScriptRunner::runFile(Database::getInstance()->getConnection(), $this->script("CREATE TABLE runner_quiet (id INT);\n")));
    }

    // A failing statement still throws, and the statements after it do not run
    public function test_failing_statement_still_throws(): void
    {
        $path = $this->script("SELECT 'before' AS " . SqlScriptRunner::NOTICE_COLUMN . ";\nSELECT * FROM runner_no_such_table;\nCREATE TABLE runner_after_failure (id INT);\n");

        try {
            SqlScriptRunner::runFile(Database::getInstance()->getConnection(), $path);
            $this->fail('The failing statement should have thrown');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('runner_no_such_table', $error->getMessage());
        }
        $this->assertFalse($this->tableExists('runner_after_failure'));
    }

    // The migration's duplicate-department notice uses the shared column, so the runner returns it
    public function test_migration_notice_for_duplicate_departments_is_returned(): void
    {
        $connection = Database::getInstance()->getConnection();
        $connection->exec('ALTER TABLE departments DROP INDEX uq_departments_name');
        $connection->exec("INSERT INTO departments (name) VALUES ('Dup Unit'), ('Dup Unit')");

        $notices = SqlScriptRunner::runFile($connection, BASE_PATH . '/scripts/migrations/master-migration.sql');

        $this->assertCount(1, $notices);
        $this->assertStringContainsString('NOT APPLIED: departments.name has duplicates', $notices[0]);
        $this->assertStringContainsString('duplicated_department_name=Dup Unit', $notices[0]);
    }
}
