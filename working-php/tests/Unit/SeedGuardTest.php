<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The production seeder refuses to rebuild a database that already holds data unless --force is passed.
 *
 * Only the guard module is run here. The seeder itself is never started by a test, because it rebuilds a database.
 */
class SeedGuardTest extends TestCase
{
    // Ask the guard module for its decision and return it (a message, or null to go ahead)
    private function refusal(int $documents, int $users, array $argv): ?string
    {
        $script = 'const g = require(process.argv[1]);'
            . 'const a = JSON.parse(process.argv[2]);'
            . 'process.stdout.write(JSON.stringify(g.refusalMessage(a.documents, a.users, a.argv)));';
        $command = ['node', '-e', $script, BASE_PATH . '/scripts/seeders/seed-guard.js', json_encode(['documents' => $documents, 'users' => $users, 'argv' => $argv])];

        $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "node failed: {$errors}");

        return json_decode($output, true);
    }

    // An empty database is seeded without asking
    public function test_empty_database_is_seeded(): void
    {
        $this->assertNull($this->refusal(0, 0, ['node', 'setup-production-db.js']));
    }

    // A database with documents is refused, and the message says what is there and how to go ahead
    public function test_database_with_documents_is_refused(): void
    {
        $message = $this->refusal(100001, 16, ['node', 'setup-production-db.js']);

        $this->assertStringContainsString('100001 document(s)', $message);
        $this->assertStringContainsString('16 user(s)', $message);
        $this->assertStringContainsString('composer run seed:prod -- --force', $message);
    }

    // Accounts alone are data worth protecting too
    public function test_database_with_users_only_is_refused(): void
    {
        $this->assertStringContainsString('--force', (string) $this->refusal(0, 16, ['node', 'setup-production-db.js']));
    }

    // --force goes ahead whatever the database holds
    public function test_force_overrides_the_refusal(): void
    {
        $this->assertNull($this->refusal(100001, 16, ['node', 'setup-production-db.js', '--force']));
    }

    // The seeders clear their cache and session files through a helper that only removes matching files in one folder
    public function test_remove_files_in_only_removes_matching_files_in_that_folder(): void
    {
        // A folder with two session files, another file and a subfolder holding a session-like file
        $dir = \Tests\Support\TestFiles::dir('seed files');
        mkdir("{$dir}/sub");
        foreach (['sess_a', 'sess_b', 'keep.txt', 'sub/sess_c'] as $name) {
            file_put_contents("{$dir}/{$name}", 'x');
        }

        $script = 'const f = require(process.argv[1]);'
            . 'process.stdout.write(JSON.stringify([f.removeFilesIn(process.argv[2], /^sess_/), f.removeFilesIn(process.argv[2] + "/missing", /./)]));';
        $process = proc_open(['node', '-e', $script, BASE_PATH . '/scripts/seeders/seed-files.js', $dir], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "node failed: {$errors}");

        // Two files removed here, none in a folder that does not exist; everything else is untouched
        $this->assertSame([2, 0], json_decode($output, true));
        $this->assertFileDoesNotExist("{$dir}/sess_a");
        $this->assertFileExists("{$dir}/keep.txt");
        $this->assertFileExists("{$dir}/sub/sess_c");
    }

    // database.sql names no database, so whoever runs it selects one; the guard therefore checks DB_DATABASE only
    public function test_schema_file_names_no_database_and_guard_has_no_schema_lookup(): void
    {
        $schema = file_get_contents(BASE_PATH . '/database.sql');
        $this->assertDoesNotMatchRegularExpression('/^\s*(CREATE\s+DATABASE|USE)\b/im', $schema);

        // The guard module no longer exports a lookup of the database named in the schema file
        $guard = file_get_contents(BASE_PATH . '/scripts/seeders/seed-guard.js');
        $this->assertStringNotContainsString('databaseNamedIn', $guard);

        // The seeder counts rows in DB_DATABASE (documents and users), nothing else
        $seeder = file_get_contents(BASE_PATH . '/scripts/seeders/setup-production-db.js');
        $this->assertStringNotContainsString('databaseNamedIn', $seeder);
        $this->assertSame(2, substr_count($seeder, 'countRows(connection, process.env.DB_DATABASE'));
    }

    // Throwaway database for the rebuild tests: the name ends in _test and is dropped before and after
    private const REBUILD_DATABASE = 'deped_dts_seed_schema_test';

    // Server connection without a database selected
    private function rebuildServer(): \PDO
    {
        $config = (require BASE_PATH . '/src/Common/Config/app.config.php')['database'];
        return new \PDO("mysql:host={$config['host']};port={$config['port']};charset=utf8mb4", $config['user'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    // Run rebuildSchema() of seed-database.js in Node against $database (never the seeder script itself: it also deletes
    // this app's session and cache files and writes keys). Returns [exit code, stdout, stderr].
    private function runRebuild(string $database, bool $force): array
    {
        $this->assertTrue(str_ends_with($database, '_test') || in_array($database, ['mysql', 'sys'], true), 'only _test databases are rebuilt here');
        $config = (require BASE_PATH . '/src/Common/Config/app.config.php')['database'];
        $script = 'const mysql = require("mysql2/promise"); const fs = require("fs");'
            . '(async () => {'
            . 'const c = await mysql.createConnection({ host: process.env.H, port: process.env.P, user: process.env.U, password: process.env.W, multipleStatements: true });'
            . 'await require(process.argv[1]).rebuildSchema(c, process.env.D, fs.readFileSync(process.argv[2], "utf8"), process.env.F === "1");'
            . 'const [rows] = await c.query("SELECT DATABASE() AS db"); process.stdout.write(rows[0].db); await c.end();'
            . '})().catch(e => { console.error(e.message); process.exit(1); });';
        $process = proc_open(
            ['node', '-e', $script, BASE_PATH . '/scripts/seeders/seed-database.js', BASE_PATH . '/database.sql'],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            BASE_PATH,
            ['PATH' => (string) getenv('PATH'), 'H' => (string) $config['host'], 'P' => (string) $config['port'], 'U' => (string) $config['user'], 'W' => (string) $config['password'], 'D' => $database, 'F' => $force ? '1' : '0']
        );
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errors];
    }

    // Table names of a database
    private function tablesOf(\PDO $server, string $database): array
    {
        return $server->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$database}' ORDER BY TABLE_NAME")->fetchAll(\PDO::FETCH_COLUMN);
    }

    // The rebuild creates a missing database, replaces leftover tables that carry the app's names, and keeps the utf8mb4 charset
    public function test_rebuild_schema_creates_and_selects_the_database(): void
    {
        $database = self::REBUILD_DATABASE;
        $server = $this->rebuildServer();
        $server->exec("DROP DATABASE IF EXISTS `{$database}`");

        try {
            // A missing database is created
            [$code, $output, $errors] = $this->runRebuild($database, false);
            $this->assertSame(0, $code, "node failed: {$errors}");
            $this->assertSame($database, $output);
            $tables = $this->tablesOf($server, $database);
            $this->assertContains('documents', $tables);
            $this->assertContains('departments', $tables);
            $charset = $server->query("SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$database}'")->fetchColumn();
            $this->assertSame('utf8mb4', $charset);

            // Leftovers with the app's names (a legacy table, an old worker_schedule) are replaced, with data in them
            $server->exec("CREATE TABLE `{$database}`.sessions (id INT)");
            $server->exec("DROP TABLE `{$database}`.worker_schedule");
            $server->exec("CREATE TABLE `{$database}`.worker_schedule (task INT)");
            $server->exec("INSERT INTO `{$database}`.worker_schedule (task) VALUES (1)");
            [$code, , $errors] = $this->runRebuild($database, false);
            $this->assertSame(0, $code, "node failed: {$errors}");
            $tables = $this->tablesOf($server, $database);
            $this->assertNotContains('sessions', $tables);
            $this->assertSame(1, (int) $server->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{$database}' AND TABLE_NAME = 'worker_schedule' AND COLUMN_NAME = 'last_run_at'")->fetchColumn());
            $this->assertSame(0, (int) $server->query("SELECT COUNT(*) FROM `{$database}`.worker_schedule")->fetchColumn());
        } finally {
            $server->exec("DROP DATABASE IF EXISTS `{$database}`");
        }
    }

    // A table that is not the app's: refused without --force and nothing dropped; with --force only the app's tables go
    public function test_rebuild_keeps_foreign_tables_and_refuses_without_force(): void
    {
        $database = self::REBUILD_DATABASE;
        $server = $this->rebuildServer();
        $server->exec("DROP DATABASE IF EXISTS `{$database}`");
        $server->exec("CREATE DATABASE `{$database}`");

        try {
            $server->exec("CREATE TABLE `{$database}`.other_app_orders (id INT)");
            $server->exec("INSERT INTO `{$database}`.other_app_orders (id) VALUES (7)");
            $server->exec("CREATE TABLE `{$database}`.departments (id INT)");

            // Refused, names the foreign table, nothing changed
            [$code, , $errors] = $this->runRebuild($database, false);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('other_app_orders', $errors);
            $this->assertSame(['departments', 'other_app_orders'], $this->tablesOf($server, $database));
            $this->assertSame(0, (int) $server->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{$database}' AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'name'")->fetchColumn());

            // With --force the app's tables are rebuilt and the foreign table and its row survive
            [$code, , $errors] = $this->runRebuild($database, true);
            $this->assertSame(0, $code, "node failed: {$errors}");
            $this->assertContains('documents', $this->tablesOf($server, $database));
            $this->assertContains('other_app_orders', $this->tablesOf($server, $database));
            $this->assertSame(7, (int) $server->query("SELECT id FROM `{$database}`.other_app_orders")->fetchColumn());
        } finally {
            $server->exec("DROP DATABASE IF EXISTS `{$database}`");
        }
    }

    // A server schema is refused even with --force. Only the refusal is asserted, through the pure function: nothing
    // connects to or touches a system schema.
    public function test_rebuild_refuses_system_schemas_even_with_force(): void
    {
        $script = 'const s = require(process.argv[1]); const sql = require("fs").readFileSync(process.argv[2], "utf8");'
            . 'const out = ["mysql", "sys", "information_schema", "performance_schema", "MySQL"].map(n => s.rebuildRefusal(n, [], sql, true));'
            . 'out.push(s.rebuildRefusal("deped_dts_x_test", [], sql, true));'
            . 'process.stdout.write(JSON.stringify(out));';
        $output = (string) shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && node -e ' . escapeshellarg($script) . ' ' . escapeshellarg(BASE_PATH . '/scripts/seeders/seed-database.js') . ' ' . escapeshellarg(BASE_PATH . '/database.sql'));
        $messages = json_decode($output, true);

        // Five system names refused with a message that says so; an ordinary name is allowed
        $this->assertCount(6, $messages);
        foreach (array_slice($messages, 0, 5) as $message) {
            $this->assertStringContainsString('system schema', (string) $message);
        }
        $this->assertNull($messages[5]);
    }

    // The app's table list comes from database.sql, so it cannot drift: every CREATE TABLE is in it, with the legacy names
    public function test_table_list_is_read_from_the_schema_file(): void
    {
        $script = 'const s = require(process.argv[1]); process.stdout.write(JSON.stringify(s.dtsTableNames(require("fs").readFileSync(process.argv[2], "utf8"))));';
        $output = (string) shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && node -e ' . escapeshellarg($script) . ' ' . escapeshellarg(BASE_PATH . '/scripts/seeders/seed-database.js') . ' ' . escapeshellarg(BASE_PATH . '/database.sql'));
        $names = json_decode($output, true);

        preg_match_all('/^CREATE TABLE (\w+)/mi', file_get_contents(BASE_PATH . '/database.sql'), $matches);
        $this->assertGreaterThanOrEqual(15, count($matches[1]));
        foreach (array_merge($matches[1], ['job_batches', 'password_reset_tokens', 'sessions', 'prediction_keywords']) as $name) {
            $this->assertContains($name, $names);
        }
    }

    // A 429 from the server gives the API seeder a clear message that names RATE_LIMIT_ENABLED; other statuses give none
    public function test_rate_limited_response_gives_a_clear_message(): void
    {
        $script = 'const g = require(process.argv[1]);'
            . 'process.stdout.write(JSON.stringify([g.rateLimitMessage(429, "60"), g.rateLimitMessage(429, null), g.rateLimitMessage(200, null), g.rateLimitMessage(500, null)]));';
        $process = proc_open(['node', '-e', $script, BASE_PATH . '/scripts/seeders/seed-guard.js'], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "node failed: {$errors}");

        [$withRetry, $withoutRetry, $ok, $serverError] = json_decode($output, true);
        $this->assertStringContainsString('429', $withRetry);
        $this->assertStringContainsString('60 s', $withRetry);
        $this->assertStringContainsString('RATE_LIMIT_ENABLED=false', $withRetry);
        $this->assertStringContainsString('RATE_LIMIT_ENABLED=false', $withoutRetry);
        $this->assertNull($ok);
        $this->assertNull($serverError);
    }

    // The API seeder checks every response with that helper
    public function test_api_seeder_stops_on_429(): void
    {
        $source = file_get_contents(BASE_PATH . '/scripts/seeders/seed.js');
        $this->assertStringContainsString("require('./seed-guard')", $source);
        $this->assertStringContainsString('rateLimitMessage(response.status', $source);
    }

    // In the seeder, nothing is cleared, generated or rebuilt before the guard has decided
    public function test_seeder_does_nothing_before_the_guard(): void
    {
        $source = file_get_contents(BASE_PATH . '/scripts/seeders/setup-production-db.js');
        $guard = strpos($source, 'refusalMessage(');
        $this->assertNotFalse($guard);

        // The system-schema / foreign-table check also runs before anything is cleared, generated or dropped
        $tablesCheck = strpos($source, 'rebuildRefusal(');
        $this->assertGreaterThan($guard, $tablesCheck);
        $this->assertLessThan(strpos($source, 'clearSessionsAndCache();'), $tablesCheck);

        // Each state-changing step comes after the guard's decision
        foreach (['clearSessionsAndCache();', "'--system']", "'--integrity']", 'rebuildSchema('] as $step) {
            $position = strpos($source, $step);
            $this->assertNotFalse($position, "{$step} not found in the seeder");
            $this->assertGreaterThan($guard, $position, "{$step} runs before the guard");
        }

        // Only this app's own folders are cleared, never the folder all PHP apps share
        $this->assertStringNotContainsString('tmpdir', $source);
        $this->assertStringContainsString('storage/sessions', $source);
    }
}
