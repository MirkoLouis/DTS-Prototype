<?php

namespace App\Common\RateLimit;

use App\Modules\System\WorkerScheduleService;

/**
 * Scheduled worker task: delete the rate_limits rows whose window has ended (console.php calls it every loop).
 */
class RateLimitPurgeTask
{
    // Name under which the last run is stored in worker_schedule
    public const TASK = 'rate_limit_purge';

    // Hourly: rows are tiny and an expired row is already ignored by the limiter, so this only bounds table size
    public const INTERVAL_SECONDS = 3600;

    /**
     * Purge when the task is due. Returns the number of rows deleted, or null when it was not due.
     */
    public static function runIfDue(WorkerScheduleService $schedule, RateLimitService $service, int $now): ?int
    {
        // Not due yet
        if (!$schedule->isDue(self::TASK, self::INTERVAL_SECONDS, $now)) {
            return null;
        }

        // Record the run first, as the other scheduled tasks do, so a failure is not retried every loop
        $schedule->markRun(self::TASK, $now);

        return $service->purgeExpired($now);
    }
}
