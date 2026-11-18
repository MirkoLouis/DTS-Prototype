<?php

namespace App\Modules\Analytics;

use App\Modules\System\WorkerScheduleService;

/**
 * Scheduled worker task: rebuild the department metrics of the last two days.
 */
class MetricsBackfillTask
{
    // Name under which the last run is stored in worker_schedule (the name the 30-minute full rebuild used)
    public const TASK = 'metrics_backfill';

    // Every 5 minutes, so the charts follow new documents closely
    public const INTERVAL_SECONDS = 300;

    /**
     * Rebuild the window when the task is due. Returns whether any row changed, or null when it was not due.
     */
    public static function runIfDue(WorkerScheduleService $schedule, int $now): ?bool
    {
        // Not due yet
        if (!$schedule->isDue(self::TASK, self::INTERVAL_SECONDS, $now)) {
            return null;
        }

        // Record the run first, as the other scheduled tasks do, so a failure is not retried every loop
        $schedule->markRun(self::TASK, $now);

        return (new MetricsBackfillService())->rebuild(MetricsBackfillService::windowStart(new \DateTimeImmutable('@' . $now)));
    }
}
