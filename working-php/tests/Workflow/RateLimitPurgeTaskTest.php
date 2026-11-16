<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Common\RateLimit\RateLimitPurgeTask;
use App\Common\RateLimit\RateLimitService;
use App\Modules\System\WorkerScheduleService;
use Tests\TestCase;

/**
 * The scheduled worker task that deletes expired rate_limits rows.
 */
class RateLimitPurgeTaskTest extends TestCase
{
    private const T0 = 1_800_000_000;

    // Number of rows in rate_limits
    private function rowCount(): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) c FROM rate_limits')->fetch()['c'];
    }

    // A task that has never run is due: it purges expired rows, records the run and keeps current rows
    public function test_first_run_purges_expired_rows_and_marks_the_run(): void
    {
        $service = new RateLimitService();
        $service->hit('submit', '203.0.113.7', 10, 60, self::T0);
        $service->hit('submit', '203.0.113.8', 10, 60, self::T0 + 3600);

        $schedule = new WorkerScheduleService();
        $purged = RateLimitPurgeTask::runIfDue($schedule, $service, self::T0 + 3600);

        $this->assertSame(1, $purged);
        $this->assertSame(1, $this->rowCount());
        $this->assertSame(self::T0 + 3600, $schedule->lastRun('rate_limit_purge'));
    }

    // The task runs hourly: not again within the hour, again once the hour has passed
    public function test_task_runs_hourly(): void
    {
        $service = new RateLimitService();
        $schedule = new WorkerScheduleService();
        $this->assertSame(0, RateLimitPurgeTask::runIfDue($schedule, $service, self::T0));

        // An expired row appears, but the task is not due until an hour after its last run
        $service->hit('submit', '203.0.113.7', 10, 60, self::T0);
        $this->assertNull(RateLimitPurgeTask::runIfDue($schedule, $service, self::T0 + 3599));
        $this->assertSame(1, $this->rowCount());

        $this->assertSame(1, RateLimitPurgeTask::runIfDue($schedule, $service, self::T0 + 3600));
        $this->assertSame(0, $this->rowCount());
    }

    // The worker script calls the task from its scheduler
    public function test_console_registers_the_task(): void
    {
        $this->assertStringContainsString('RateLimitPurgeTask::runIfDue', file_get_contents(BASE_PATH . '/console.php'));
    }
}
