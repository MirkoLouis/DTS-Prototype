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

    // The guard reads which database the schema file itself rebuilds, so that one is checked too
    public function test_database_named_in_the_schema_file_is_found(): void
    {
        $script = 'const g = require(process.argv[1]);'
            . 'process.stdout.write(JSON.stringify(JSON.parse(process.argv[2]).map(sql => g.databaseNamedIn(sql))));';
        $cases = ["CREATE DATABASE IF NOT EXISTS deped_dts;\nUSE deped_dts;\n", "USE `other_db`;", "CREATE TABLE x (id INT);"];
        $process = proc_open(['node', '-e', $script, BASE_PATH . '/scripts/seeders/seed-guard.js', json_encode($cases)], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "node failed: {$errors}");

        $this->assertSame(['deped_dts', 'other_db', null], json_decode($output, true));
    }

    // In the seeder, nothing is cleared, generated or rebuilt before the guard has decided
    public function test_seeder_does_nothing_before_the_guard(): void
    {
        $source = file_get_contents(BASE_PATH . '/scripts/seeders/setup-production-db.js');
        $guard = strpos($source, 'refusalMessage(');
        $this->assertNotFalse($guard);

        // Each state-changing step comes after the guard's decision
        foreach (['clearSessionsAndCache();', "'--system']", "'--integrity']", 'connection.query(sqlContent)'] as $step) {
            $position = strpos($source, $step);
            $this->assertNotFalse($position, "{$step} not found in the seeder");
            $this->assertGreaterThan($guard, $position, "{$step} runs before the guard");
        }

        // Only this app's own folders are cleared, never the folder all PHP apps share
        $this->assertStringNotContainsString('tmpdir', $source);
        $this->assertStringContainsString('storage/sessions', $source);
    }
}
