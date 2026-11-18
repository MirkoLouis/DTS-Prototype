<?php

namespace App\Common\Cache;

use App\Modules\System\WorkerScheduleService;

/**
 * Scheduled worker task: delete the expired rows of the cache table (console.php calls it every loop).
 */
class CachePurgeTask
{
    // Name under which the last run is stored in worker_schedule
    public const TASK = 'cache_purge';

    // Hourly: an expired row is already ignored on read, so this only bounds the table's size
    public const INTERVAL_SECONDS = 3600;

    /**
     * Purge when the task is due. Returns the number of rows deleted, or null when it was not due.
     */
    public static function runIfDue(WorkerScheduleService $schedule, int $now): ?int
    {
        // Not due yet
        if (!$schedule->isDue(self::TASK, self::INTERVAL_SECONDS, $now)) {
            return null;
        }

        // Record the run first, as the other scheduled tasks do, so a failure is not retried every loop
        $schedule->markRun(self::TASK, $now);

        return Cache::purgeExpired($now);
    }
}
