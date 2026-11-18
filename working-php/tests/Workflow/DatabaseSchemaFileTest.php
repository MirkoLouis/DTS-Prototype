<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Common\Database\MysqlCli;
use App\Common\Database\SqlScriptRunner;
use Tests\TestCase;

/**
 * database.sql builds a new, empty database and nothing else: run again on a database that holds data, it must fail
 * at the first statement and change nothing. (TestCase has just built the test database from it and seeded rows.)
 */
class DatabaseSchemaFileTest extends TestCase
{
    // The script contains no statement that removes or deletes anything
    public function test_schema_file_has_no_drop_truncate_or_delete(): void
    {
        $sql = file_get_contents(BASE_PATH . '/database.sql');

        // No executable comment (/*! ... */) can hide a statement
        $this->assertStringNotContainsString('/*!', $sql);

        // Comments of every kind may talk about it; only statements count
        $statements = preg_replace(['~/\*.*?\*/~s', '/^\s*(--|#).*$/m'], '', $sql);
        // (a statement starts at the beginning of the text or after a semicolon; "ON DELETE CASCADE" inside a table is fine)
        $this->assertDoesNotMatchRegularExpression('/(\A|;)\s*(DROP|TRUNCATE|DELETE)\b/i', $statements);
        $this->assertDoesNotMatchRegularExpression('/IF\s+NOT\s+EXISTS/i', $statements);

        // The first statement creates a table every real database has
        $this->assertMatchesRegularExpression('/\A\s*CREATE TABLE departments\b/i', $statements);
    }

    // Through the mysql client (how a person runs it): fails with "already exists", rows and tables unchanged
    public function test_running_it_again_through_the_mysql_client_changes_nothing(): void
    {
        $before = $this->populateAndSnapshot();

        $message = '';
        try {
            MysqlCli::run('mysql', [], BASE_PATH . '/database.sql');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        // Evidence for the test log
        fwrite(STDERR, "\n[mysql client] {$message}\n");
        $this->assertStringContainsString("Table 'departments' already exists", $message);
        $this->assertSame($before, $this->snapshot());
    }

    // Through SqlScriptRunner (restore and test paths): same result
    public function test_running_it_again_through_the_script_runner_changes_nothing(): void
    {
        $before = $this->populateAndSnapshot();

        $message = '';
        try {
            SqlScriptRunner::runFile(Database::getInstance()->getConnection(), BASE_PATH . '/database.sql');
        } catch (\PDOException $e) {
            $message = $e->getMessage();
        }

        fwrite(STDERR, "\n[SqlScriptRunner] {$message}\n");
        $this->assertStringContainsString("Table 'departments' already exists", $message);
        $this->assertSame($before, $this->snapshot());
    }

    // Add rows to several tables, then return the snapshot
    private function populateAndSnapshot(): array
    {
        $connection = Database::getInstance()->getConnection();
        $connection->exec("INSERT INTO cache (`key`, `value`, `expiration`) VALUES ('keep-me', '1', 2000000000)");
        $connection->exec("INSERT INTO worker_schedule (task, last_run_at) VALUES ('keep-me', 1)");
        $connection->exec("INSERT INTO notifications (title, message) VALUES ('keep', 'keep')");
        $connection->exec("INSERT INTO rate_limits (rate_key, hits, expires_at) VALUES ('" . str_repeat('a', 64) . "', 1, 2000000000)");

        $snapshot = $this->snapshot();
        foreach (['departments', 'users', 'purposes', 'cache', 'worker_schedule', 'notifications', 'rate_limits'] as $table) {
            $this->assertGreaterThan(0, $snapshot[$table]['rows'], $table);
        }
        fwrite(STDERR, "\n[rows before] " . json_encode(array_filter(array_map(fn (array $entry) => $entry['rows'], $snapshot))) . "\n");

        return $snapshot;
    }

    // Per table: row count, a checksum of the rows, and the CREATE TABLE text (columns, indexes, foreign keys)
    private function snapshot(): array
    {
        $connection = Database::getInstance()->getConnection();
        $snapshot = [];
        foreach ($connection->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $checksum = $connection->query("CHECKSUM TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $snapshot[$table] = [
                'rows' => (int) $connection->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn(),
                'checksum' => $checksum['Checksum'],
                'create' => $connection->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_NUM)[1],
            ];
        }
        ksort($snapshot);

        return $snapshot;
    }
}
