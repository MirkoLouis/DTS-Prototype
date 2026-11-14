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
}
