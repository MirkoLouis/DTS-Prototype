<?php

namespace App\Modules\System;

use App\Common\Cache\CachePurgeTask;
use App\Common\RateLimit\RateLimitPurgeTask;
use App\Common\RateLimit\RateLimitService;
use App\Modules\Analytics\MetricsBackfillTask;

/**
 * The worker's database maintenance tasks that run on a schedule (console.php calls runDue() every loop).
 */
class PeriodicTasks
{
    /**
     * Run every task that is due at $now. Each result is null when the task was not due.
     *
     * @return array{metrics_backfill: ?bool, rate_limit_purge: ?int, cache_purge: ?int}
     */
    public static function runDue(WorkerScheduleService $schedule, int $now): array
    {
        // Metrics: the last two days every 5 minutes; limiter counters and cache rows: hourly purges of expired rows
        return [
            'metrics_backfill' => MetricsBackfillTask::runIfDue($schedule, $now),
            'rate_limit_purge' => RateLimitPurgeTask::runIfDue($schedule, new RateLimitService(), $now),
            'cache_purge' => CachePurgeTask::runIfDue($schedule, $now),
        ];
    }
}
