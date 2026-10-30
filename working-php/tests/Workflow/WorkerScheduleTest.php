<?php

namespace Tests\Workflow;

use App\Modules\System\WorkerScheduleService;
use Tests\TestCase;

class WorkerScheduleTest extends TestCase
{
    private const DAY = 86400;
    private const T0 = 1_800_000_000;

    // A task that has never run is due immediately
    public function test_never_run_task_is_due(): void
    {
        $this->assertTrue((new WorkerScheduleService())->isDue('checkpoint', self::DAY, self::T0));
    }

    // A task is not due before its interval has passed, and is due once it has
    public function test_task_is_due_only_after_its_interval(): void
    {
        $schedule = new WorkerScheduleService();
        $schedule->markRun('checkpoint', self::T0);

        // One second short of the interval, then exactly the interval
        $this->assertFalse($schedule->isDue('checkpoint', self::DAY, self::T0 + self::DAY - 1));
        $this->assertTrue($schedule->isDue('checkpoint', self::DAY, self::T0 + self::DAY));
    }

    // A worker restart (a new process, so a new service instance) does not push a daily task back
    public function test_restart_does_not_postpone_daily_task(): void
    {
        // The task ran, then the worker restarted 23 hours later
        (new WorkerScheduleService())->markRun('checkpoint', self::T0);
        $afterRestart = new WorkerScheduleService();

        // Not yet due at 23 h, due at 24 h, counted from the run before the restart
        $this->assertFalse($afterRestart->isDue('checkpoint', self::DAY, self::T0 + 23 * 3600));
        $this->assertTrue($afterRestart->isDue('checkpoint', self::DAY, self::T0 + self::DAY));
    }

    // After a long outage a task runs once, not once per missed interval
    public function test_task_runs_once_after_downtime(): void
    {
        $schedule = new WorkerScheduleService();
        $schedule->markRun('checkpoint', self::T0);

        // Three days down: due, runs, and is then not due again until a day later
        $now = self::T0 + 3 * self::DAY;
        $this->assertTrue($schedule->isDue('checkpoint', self::DAY, $now));
        $schedule->markRun('checkpoint', $now);
        $this->assertFalse($schedule->isDue('checkpoint', self::DAY, $now + 1));
    }

    // Tasks are scheduled independently of each other
    public function test_tasks_are_independent(): void
    {
        $schedule = new WorkerScheduleService();
        $schedule->markRun('checkpoint', self::T0);

        $this->assertTrue($schedule->isDue('db_metrics_sample', 300, self::T0));
        $this->assertSame(self::T0, $schedule->lastRun('checkpoint'));
        $this->assertNull($schedule->lastRun('db_metrics_sample'));
    }
}
