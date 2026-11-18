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

    // The seeder builds the schema through rebuildSchema(), which creates and selects DB_DATABASE.
    // Run here against a throwaway database ending in _test; the seeder script itself is never started,
    // because it also deletes this app's session and cache files and writes keys.
    public function test_rebuild_schema_creates_and_selects_the_database(): void
    {
        $database = 'deped_dts_seed_schema_test';
        $this->assertStringEndsWith('_test', $database);
        $config = (require BASE_PATH . '/src/Common/Config/app.config.php')['database'];
        $server = new \PDO("mysql:host={$config['host']};port={$config['port']};charset=utf8mb4", $config['user'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $server->exec("DROP DATABASE IF EXISTS `{$database}`");

        try {
            // Node builds the real database.sql in the database that does not exist yet
            $script = 'const mysql = require("mysql2/promise"); const fs = require("fs");'
                . '(async () => {'
                . 'const c = await mysql.createConnection({ host: process.env.H, port: process.env.P, user: process.env.U, password: process.env.W, multipleStatements: true });'
                . 'await require(process.argv[1]).rebuildSchema(c, process.env.D, fs.readFileSync(process.argv[2], "utf8"));'
                . 'const [rows] = await c.query("SELECT DATABASE() AS db"); process.stdout.write(rows[0].db); await c.end();'
                . '})().catch(e => { console.error(e.message); process.exit(1); });';
            $process = proc_open(
                ['node', '-e', $script, BASE_PATH . '/scripts/seeders/seed-database.js', BASE_PATH . '/database.sql'],
                [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                BASE_PATH,
                ['PATH' => (string) getenv('PATH'), 'H' => (string) $config['host'], 'P' => (string) $config['port'], 'U' => (string) $config['user'], 'W' => (string) $config['password'], 'D' => $database]
            );
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), "node failed: {$errors}");

            // The database was created, selected, and holds the schema; the dropped Laravel tables are absent
            $this->assertSame($database, $output);
            $tables = $server->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$database}'")->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertContains('documents', $tables);
            $this->assertContains('departments', $tables);
            $this->assertSame([], array_values(array_intersect($tables, ['job_batches', 'password_reset_tokens', 'sessions', 'prediction_keywords'])));
            $charset = $server->query("SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$database}'")->fetchColumn();
            $this->assertSame('utf8mb4', $charset);
        } finally {
            $server->exec("DROP DATABASE IF EXISTS `{$database}`");
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
