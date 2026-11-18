<?php

namespace Tests\Workflow;

use App\Common\Cache\Cache;
use App\Common\Cache\CachePurgeTask;
use App\Common\Database\Database;
use App\Modules\System\PeriodicTasks;
use App\Modules\System\WorkerScheduleService;
use Tests\TestCase;

/**
 * The worker deletes expired rows of the cache table once an hour.
 */
class CachePurgeTest extends TestCase
{
    // Insert a cache row with an explicit expiration
    private function put(string $key, int $expiration): void
    {
        Database::getInstance()->query('INSERT INTO cache (`key`, `value`, `expiration`) VALUES (:k, :v, :e)', [':k' => $key, ':v' => '1', ':e' => $expiration]);
    }

    // The keys left in the cache table, sorted
    private function keys(): array
    {
        $keys = Database::getInstance()->query('SELECT `key` FROM cache ORDER BY `key`')->fetchAll(\PDO::FETCH_COLUMN);
        return $keys;
    }

    // Only rows past their expiration are deleted; a row expiring exactly now is still valid, as in Cache::get()
    public function test_purge_deletes_only_expired_rows(): void
    {
        $now = 1800000000;
        $this->put('expired-long-ago', $now - 86400);
        $this->put('expired-just-now', $now - 1);
        $this->put('expires-now', $now);
        $this->put('valid', $now + 60);

        $this->assertSame(2, Cache::purgeExpired($now));
        $this->assertSame(['expires-now', 'valid'], $this->keys());
    }

    // The task runs when it has never run, then not again within the hour, and runs again an hour later
    public function test_task_runs_hourly_and_records_the_run(): void
    {
        $schedule = new WorkerScheduleService();
        $now = 1800000000;
        $this->put('old', $now - 10);

        $this->assertSame(1, CachePurgeTask::runIfDue($schedule, $now));
        $this->assertSame($now, $schedule->lastRun(CachePurgeTask::TASK));

        // Not due 59 minutes later, even though a new row has expired
        $this->put('old2', $now);
        $this->assertNull(CachePurgeTask::runIfDue($schedule, $now + 3540));
        $this->assertSame(['old2'], $this->keys());

        // Due after the full hour
        $this->assertSame(1, CachePurgeTask::runIfDue($schedule, $now + 3600 + 1));
        $this->assertSame([], $this->keys());
    }

    // The scheduler (driven with a fake clock) runs the purge hourly through the persisted schedule
    public function test_worker_scheduler_runs_the_purge(): void
    {
        $schedule = new WorkerScheduleService();
        $now = 1800000000;
        $this->put('old', $now - 10);

        $this->assertSame(1, PeriodicTasks::runDue($schedule, $now)['cache_purge']);
        $this->assertSame([], $this->keys());

        // Not due for an hour, then due again
        $this->put('old2', $now);
        $this->assertNull(PeriodicTasks::runDue($schedule, $now + 3599)['cache_purge']);
        $this->assertSame(['old2'], $this->keys());
        $this->assertSame(1, PeriodicTasks::runDue($schedule, $now + 3600)['cache_purge']);
        $this->assertSame([], $this->keys());
    }

    // forgetPrefix deletes the keys that start with the prefix and treats LIKE wildcards in it literally
    public function test_forget_prefix_matches_only_the_prefix(): void
    {
        $now = time() + 600;
        foreach (['metrics:a', 'metrics:b', 'metricsXa', 'other', 'a_b:1', 'axb:1'] as $key) {
            $this->put($key, $now);
        }

        $this->assertSame(2, Cache::forgetPrefix('metrics:'));
        $this->assertSame(1, Cache::forgetPrefix('a_b:'));
        $this->assertSame(['axb:1', 'metricsXa', 'other'], $this->keys());
    }
}
