<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Modules\System\QueueService;
use Tests\TestCase;

/**
 * The job queue: one worker claims a job, and a job that stops sending its health ping is recorded.
 */
class QueueServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    // Insert a queued job and return its id; reserved and heartbeat times are optional
    private function job(?int $reservedAt = null, ?int $heartbeatAt = null): int
    {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO jobs (queue, payload, attempts, reserved_at, heartbeat_at, available_at, created_at)
             VALUES ('default', :payload, 0, :reserved, :heartbeat, 0, 0)",
            [':payload' => json_encode(['class' => 'Example', 'data' => [1]]), ':reserved' => $reservedAt, ':heartbeat' => $heartbeatAt]
        );

        return (int) $db->getConnection()->lastInsertId();
    }

    // The row of a job, or false when it is no longer queued
    private function row(int $id): array|false
    {
        return Database::getInstance()->query('SELECT * FROM jobs WHERE id = :id', [':id' => $id])->fetch();
    }

    // Only the first claim of a job succeeds, and it starts the job's heartbeat clock
    public function test_a_job_is_claimed_once(): void
    {
        $id = $this->job();
        $queue = new QueueService();

        $this->assertTrue($queue->claim($id, self::NOW));
        $this->assertFalse($queue->claim($id, self::NOW + 1));

        $row = $this->row($id);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame(self::NOW, (int) $row['reserved_at']);
        $this->assertSame(self::NOW, (int) $row['heartbeat_at']);
    }

    // Number of failed-job records
    private function failedCount(): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) c FROM failed_jobs')->fetch()['c'];
    }

    // A reserved job whose health ping stopped more than the timeout ago is recorded and removed from the queue
    public function test_job_without_a_recent_heartbeat_is_recorded_as_abandoned(): void
    {
        $id = $this->job(self::NOW - 300, self::NOW - 61);

        $this->assertSame(1, (new QueueService())->recordAbandoned(self::NOW, 60));
        $this->assertFalse($this->row($id));

        // The record keeps the job's payload and says why, so an admin can decide what to do
        $failed = Database::getInstance()->query('SELECT queue, payload, exception FROM failed_jobs')->fetch();
        $this->assertSame('default', $failed['queue']);
        $this->assertSame(json_encode(['class' => 'Example', 'data' => [1]]), $failed['payload']);
        $this->assertStringContainsString('health ping', $failed['exception']);
        $this->assertStringContainsString('not retried', $failed['exception']);
    }

    // A job that keeps pinging is healthy however long it has been running
    public function test_job_with_a_fresh_heartbeat_is_left_alone_however_long_it_runs(): void
    {
        $id = $this->job(self::NOW - 7200, self::NOW - 5);

        $this->assertSame(0, (new QueueService())->recordAbandoned(self::NOW, 60));
        $this->assertNotFalse($this->row($id));
        $this->assertSame(0, $this->failedCount());
    }

    // A job nobody has claimed is not running, so it cannot be abandoned
    public function test_unreserved_job_is_left_alone(): void
    {
        $id = $this->job();

        $this->assertSame(0, (new QueueService())->recordAbandoned(self::NOW, 60));
        $this->assertNotFalse($this->row($id));
    }

    // A job that was claimed but whose runner never started pinging is recorded too
    public function test_claimed_job_whose_runner_never_started_is_recorded(): void
    {
        $id = $this->job();
        $queue = new QueueService();
        $queue->claim($id, self::NOW - 61);

        $this->assertSame(1, $queue->recordAbandoned(self::NOW, 60));
        $this->assertFalse($this->row($id));
    }

    // A ping moves the heartbeat forward for a reserved job and reports a missing job
    public function test_heartbeat_updates_a_reserved_job_and_reports_a_missing_one(): void
    {
        $id = $this->job(self::NOW - 30, self::NOW - 30);
        $queue = new QueueService();

        $this->assertTrue($queue->heartbeat($id, self::NOW));
        $this->assertSame(self::NOW, (int) $this->row($id)['heartbeat_at']);

        // The same second again still counts as alive; a deleted job does not
        $this->assertTrue($queue->heartbeat($id, self::NOW));
        Database::getInstance()->query('DELETE FROM jobs WHERE id = :id', [':id' => $id]);
        $this->assertFalse($queue->heartbeat($id, self::NOW + 10));
    }
}
