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
}
