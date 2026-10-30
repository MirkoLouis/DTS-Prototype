<?php

namespace Tests\Concurrency;

use App\Common\Database\Database;
use App\Modules\Integrity\Jobs\IntegrityCheckJob;
use App\Models\User;
use App\Services\DocumentWorkflowService;
use PHPUnit\Framework\Attributes\Group;
use App\Research\Barrier;
use Tests\Support\Fixtures;
use App\Research\ResultWriter;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Simultaneous completions of one task: the optimistic version check lets exactly one through.
 */
#[Group('concurrency')]
class TaskCompletionConcurrencyTest extends TestCase
{
    // Read a positive integer knob from the environment
    private static function knob(string $name, int $default): int
    {
        $value = (int) getenv($name);

        return $value > 0 ? $value : $default;
    }

    // Each forked child must open its own MySQL connection instead of reusing the parent's socket
    public function test_forked_workers_use_distinct_connections(): void
    {
        $parentConnection = (int) Database::getInstance()->query('SELECT CONNECTION_ID() AS c')->fetch()['c'];

        $results = Barrier::forkAll(10, function (int $index): array {
            return ['connection_id' => (int) Database::getInstance()->query('SELECT CONNECTION_ID() AS c')->fetch()['c']];
        });

        // All ten children report distinct ids, none of them the parent's
        $ids = array_column($results, 'connection_id');
        $this->assertCount(10, array_unique($ids));
        $this->assertNotContains($parentConnection, $ids);

        // The parent's own connection still works after the children exit
        $this->assertSame($parentConnection, (int) Database::getInstance()->query('SELECT CONNECTION_ID() AS c')->fetch()['c']);
    }

    // Five Cash Unit sessions press "Complete" at the same instant, repeated CONCURRENCY_RUNS times (default 1)
    public function test_simultaneous_task_completion_only_one_succeeds(): void
    {
        $workers = 5;
        $runs = self::knob('CONCURRENCY_RUNS', 1);

        // Each repetition uses a fresh document and its own simultaneous batch
        for ($run = 1; $run <= $runs; $run++) {
            $doc = Fixtures::submit(['title' => 'CONC-TASK']);
            Fixtures::advanceTo($doc['id'], 'processing', ['Cash Unit']);
            $documentId = $doc['id'];

            // Every child loads its own user and calls the real workflow service
            // Before the barrier: open the child's own connection and load the user, so setup is not in the race
            $prepare = function (int $i): User {
                Database::getInstance();

                return Fixtures::user('cash.unit');
            };
            $results = Barrier::forkAll($workers, function (int $i, User $cash) use ($documentId): array {
                $start = microtime(true);
                try {
                    (new DocumentWorkflowService())->completeTask($documentId, "Worker {$i} concurrent completion", $cash, TestDatabase::PIN);

                    return ['ok' => true, 'error' => null, 'start' => $start, 'end' => microtime(true)];
                } catch (\Exception $e) {
                    return ['ok' => false, 'error' => $e->getMessage(), 'start' => $start, 'end' => microtime(true)];
                }
            }, $prepare);

            // Wall time runs from the first call starting to the last one returning
            $wall = max(array_column($results, 'end')) - min(array_column($results, 'start'));

            // Split winners from losers
            $db = Database::getInstance();
            $successes = count(array_filter(array_column($results, 'ok')));
            $conflicts = count(array_filter(array_column($results, 'error'), fn($m) => $m !== null && str_contains($m, 'modified by another user')));
            $completeLogs = (int) $db->query("SELECT COUNT(*) c FROM document_logs WHERE document_id = :id AND action = 'Processing Complete'", [':id' => $documentId])->fetch()['c'];

            // Run the full integrity audit and read its verified percentage
            $checkId = sprintf('00000000-0000-4000-8000-%012d', 11 + $run);
            $db->query("INSERT INTO integrity_checks (id, user_id, status, created_at, updated_at) VALUES (:id, :u, 'queued', NOW(), NOW())", [
                ':id' => $checkId,
                ':u' => $this->fx['users']['admin'],
            ]);
            (new IntegrityCheckJob($checkId))->handle();
            $audit = json_decode($db->query('SELECT results FROM integrity_checks WHERE id = :id', [':id' => $checkId])->fetch()['results'], true);

            // Record the evidence row before asserting
            ResultWriter::append('concurrency.csv', [
                'timestamp' => date('c'),
                'test' => 'task_completion',
                'run' => $run,
                'workers' => $workers,
                'successes' => $successes,
                'distinct_codes' => '',
                'mismatches' => '',
                'documents_added' => '',
                'submitted_log_faults' => '',
                'wall_seconds' => round($wall, 3),
                'max_request_seconds' => '',
                'server' => 'in-process(pcntl_fork)',
                'cache_warmed' => 'false',
                'skew_seconds' => round(Barrier::skew($results), 4),
                'notes' => 'conflicts=' . $conflicts . '; unaccounted_outcomes=' . ($workers - $successes - $conflicts) . '; DB connection and user loaded before barrier',
            ]);

            $this->assertSame(1, $successes, "Run {$run}: exactly one completion must succeed: " . json_encode($results));
            $this->assertSame($workers - 1, $conflicts, "Run {$run}: every loser must hit the version check: " . json_encode($results));
            $this->assertSame(1, $completeLogs);
            $this->assertSame('in_transit', $db->query('SELECT status FROM documents WHERE id = :id', [':id' => $documentId])->fetch()['status']);
            $this->assertSame(100.0, (float) $audit['verified_percentage']);
        }
    }
}
