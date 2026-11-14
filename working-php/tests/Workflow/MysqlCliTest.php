<?php

namespace Tests\Workflow;

use App\Common\Database\MysqlCli;
use Tests\Support\TestFiles;
use Tests\TestCase;

/**
 * mysqldump and mysql are run as argument arrays, with no shell and no password on the command line.
 */
class MysqlCliTest extends TestCase
{
    // Connection settings of the test database
    private function database(): array
    {
        return (require BASE_PATH . '/src/Common/Config/app.config.php')['database'];
    }

    // The command is a list of separate arguments ending in the database name, and never carries the password
    public function test_command_is_an_argument_array_without_the_password(): void
    {
        $db = $this->database();
        $command = MysqlCli::command('mysqldump', ['--single-transaction', '--quick']);

        $this->assertSame('mysqldump', $command[0]);
        $this->assertContains('--host=' . $db['host'], $command);
        $this->assertContains('--port=' . $db['port'], $command);
        $this->assertContains('--user=' . $db['user'], $command);
        $this->assertContains('--single-transaction', $command);
        $this->assertSame($db['dbname'], end($command));

        // The password travels in the child's environment only
        foreach ($command as $argument) {
            $this->assertStringNotContainsString('password', strtolower($argument));
            if ($db['password'] !== '') {
                $this->assertStringNotContainsString($db['password'], $argument);
            }
        }
    }

    // A dump writes SQL only: table definitions, and none of the tool's messages
    public function test_dump_writes_only_sql_to_the_output_file(): void
    {
        $file = TestFiles::file('dump') . '.sql';
        MysqlCli::run('mysqldump', ['--single-transaction', '--quick'], null, $file);
        $sql = file_get_contents($file);

        $this->assertStringContainsString('CREATE TABLE `documents`', $sql);
        $this->assertStringNotContainsString('mysqldump:', $sql);
    }

    // A failing tool throws with its own error text, and that text does not land in the output file
    public function test_failure_throws_and_reports_stderr(): void
    {
        $file = TestFiles::file('failed-dump') . '.sql';

        try {
            MysqlCli::run('mysqldump', ['--no-such-option'], null, $file);
            $this->fail('A failing mysqldump did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mysqldump failed', $e->getMessage());
            $this->assertStringContainsString('no-such-option', $e->getMessage());
        }

        $this->assertStringNotContainsString('no-such-option', (string) @file_get_contents($file));
    }

    // SQL fed on standard input is executed against the configured database
    public function test_mysql_runs_a_file_from_standard_input(): void
    {
        $file = TestFiles::file('restore') . '.sql';
        file_put_contents($file, "CREATE TABLE mysql_cli_probe (id INT);\n");
        MysqlCli::run('mysql', [], $file);

        $exists = \App\Common\Database\Database::getInstance()->query("SHOW TABLES LIKE 'mysql_cli_probe'")->fetch();
        $this->assertNotFalse($exists);
    }
}
