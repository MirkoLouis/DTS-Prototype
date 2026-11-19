<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Common\Cache\Cache;
use App\Modules\Analytics\MetricsBackfillService;
use App\Modules\Analytics\MetricsBackfillTask;
use App\Modules\System\PeriodicTasks;
use App\Modules\System\WorkerScheduleService;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * The worker rebuilds the department metrics of the last two days; a full rebuild covers every day.
 */
class MetricsBackfillTest extends TestCase
{
    // Application time zone day, shifted by whole days, as Y-m-d
    private function day(int $daysAgo): string
    {
        return (new \DateTimeImmutable('now'))->modify("-{$daysAgo} days")->format('Y-m-d');
    }

    // Write a log entry of the Records Unit officer at a given time, without chain fields (the backfill never reads them)
    private function log(int $documentId, string $action, string $createdAt): void
    {
        Database::getInstance()->query(
            'INSERT INTO document_logs (document_id, user_id, action, hash, created_at, updated_at) VALUES (:d, :u, :a, :h, :c, :c2)',
            [':d' => $documentId, ':u' => $this->fx['users']['records.unit'], ':a' => $action, ':h' => bin2hex(random_bytes(8)), ':c' => $createdAt, ':c2' => $createdAt]
        );
    }

    // Metric rows without ids and timestamps, in a fixed order
    private function rows(): array
    {
        return Database::getInstance()->query(
            'SELECT department_id, date, received_count, processed_count, released_count, total_processing_seconds FROM daily_department_metrics ORDER BY department_id, date'
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    // The received count of the Records Unit on a day (0 when there is no row)
    private function received(string $day): int
    {
        $row = Database::getInstance()->query(
            'SELECT received_count FROM daily_department_metrics WHERE department_id = :d AND date = :day',
            [':d' => $this->fx['departments']['Records Unit'], ':day' => $day]
        )->fetch();

        return $row ? (int) $row['received_count'] : 0;
    }

    // One document received 1 day ago and one 5 days ago, each with a step that took an hour
    private function seedTwoDays(): array
    {
        $recent = Fixtures::submit()['id'];
        $old = Fixtures::submit()['id'];
        $this->log($recent, 'Document Received', $this->day(1) . ' 09:00:00');
        $this->log($recent, 'Processing Complete', $this->day(1) . ' 10:00:00');
        $this->log($old, 'Document Received', $this->day(5) . ' 09:00:00');
        $this->log($old, 'Processing Complete', $this->day(5) . ' 10:00:00');

        return [$recent, $old];
    }

    // The window starts yesterday in Asia/Manila, also when the server clock (UTC) is still on the day before
    public function test_window_start_follows_the_manila_day(): void
    {
        $cases = [
            '2026-11-18 00:30:00 Asia/Manila' => '2026-11-17',
            '2026-11-17 23:59:59 Asia/Manila' => '2026-11-16',
            '2026-11-17 16:00:00 UTC' => '2026-11-17',
            '2026-11-17 15:59:59 UTC' => '2026-11-16',
            '2026-03-01 12:00:00 Asia/Manila' => '2026-02-28',
        ];
        foreach ($cases as $now => $expected) {
            $this->assertSame($expected, MetricsBackfillService::windowStart(new \DateTimeImmutable($now)), $now);
        }
    }

    // A full rebuild produces both days; the windowed rebuild afterwards changes only the recent day
    public function test_window_rebuilds_only_recent_days_and_full_rebuilds_everything(): void
    {
        [$recent, $old] = $this->seedTwoDays();
        $service = new MetricsBackfillService();
        $service->rebuild(null);

        $this->assertSame(1, $this->received($this->day(1)));
        $this->assertSame(1, $this->received($this->day(5)));

        // A second document received on each day since the full rebuild
        $this->log(Fixtures::submit()['id'], 'Document Received', $this->day(1) . ' 11:00:00');
        $this->log(Fixtures::submit()['id'], 'Document Received', $this->day(5) . ' 11:00:00');

        // The window run counts the new entry of yesterday and leaves the 5-day-old row as it was
        $service->rebuild(MetricsBackfillService::windowStart(new \DateTimeImmutable('now')));
        $this->assertSame(2, $this->received($this->day(1)));
        $this->assertSame(1, $this->received($this->day(5)));

        // --full rebuilds everything
        $service->rebuild(null);
        $this->assertSame(2, $this->received($this->day(1)));
        $this->assertSame(2, $this->received($this->day(5)));
    }

    // An entry written just before midnight yesterday still belongs to the window at 00:01 today
    public function test_entry_before_midnight_is_inside_the_window_just_after_midnight(): void
    {
        $document = Fixtures::submit()['id'];
        $this->log($document, 'Document Received', $this->day(1) . ' 23:59:30');

        $now = new \DateTimeImmutable($this->day(0) . ' 00:01:00');
        (new MetricsBackfillService())->rebuild(MetricsBackfillService::windowStart($now));

        $this->assertSame(1, $this->received($this->day(1)));
        $this->assertSame(0, $this->received($this->day(0)));
    }

    // Running the rebuild again, windowed or full, leaves identical rows (counts, seconds and the estimate for steps without a duration)
    public function test_rebuilding_twice_gives_identical_rows(): void
    {
        $this->seedTwoDays();
        // A completed step with no earlier entry has no measurable duration, so it gets the deterministic estimate.
        // The submission's own entries are moved after the step: written at "now", they would be an earlier entry
        // whenever the test runs before 08:00, and the step would then be measured instead of estimated
        $unmeasured = Fixtures::submit()['id'];
        Database::getInstance()->query(
            'UPDATE document_logs SET created_at = :c, updated_at = :c2 WHERE document_id = :d',
            [':c' => $this->day(0) . ' 23:00:00', ':c2' => $this->day(0) . ' 23:00:00', ':d' => $unmeasured]
        );
        $this->log($unmeasured, 'Processing Complete', $this->day(0) . ' 08:00:00');
        $service = new MetricsBackfillService();
        $since = MetricsBackfillService::windowStart(new \DateTimeImmutable('now'));

        $service->rebuild(null);
        $afterFull = $this->rows();
        $service->rebuild(null);
        $this->assertSame($afterFull, $this->rows());

        $service->rebuild($since);
        $this->assertSame($afterFull, $this->rows());
        $service->rebuild($since);
        $this->assertSame($afterFull, $this->rows());

        // The hour-long step is counted as 3600 s, and the unmeasured one as an estimate of 1800 to 5399 s
        $byDate = array_column($afterFull, null, 'date');
        $this->assertSame(3600, (int) $byDate[$this->day(1)]['total_processing_seconds']);
        $this->assertGreaterThanOrEqual(1800, (int) $byDate[$this->day(0)]['total_processing_seconds']);
        $this->assertLessThan(5400, (int) $byDate[$this->day(0)]['total_processing_seconds']);
    }

    // The delete and the inserts are one transaction: when an insert fails, the previous rows are still there
    public function test_failed_rebuild_keeps_the_previous_rows(): void
    {
        $this->seedTwoDays();
        $service = new MetricsBackfillService();
        $service->rebuild(null);
        $before = $this->rows();
        $this->assertNotSame([], $before);

        // A trigger that refuses every insert makes the rebuild fail after its delete
        $connection = Database::getInstance()->getConnection();
        $connection->exec("CREATE TRIGGER refuse_metric_insert BEFORE INSERT ON daily_department_metrics FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused'");

        try {
            $service->rebuild(null);
            $this->fail('The rebuild should have failed');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('refused', $error->getMessage());
        }

        $connection->exec('DROP TRIGGER refuse_metric_insert');
        $this->assertFalse($connection->inTransaction());
        $this->assertSame($before, $this->rows());
    }

    // The rebuild clears only the metrics cache entries, and only when it changed rows
    public function test_rebuild_clears_only_metrics_cache_entries_and_only_on_change(): void
    {
        $this->seedTwoDays();
        $service = new MetricsBackfillService();
        $since = MetricsBackfillService::windowStart(new \DateTimeImmutable('now'));
        $this->assertTrue($service->rebuild(null));

        // A metrics payload and an unrelated one (a paginated count, say), both with a long TTL
        Cache::put(Cache::METRICS_PREFIX . 'throughput_test', ['x' => 1], 600);
        Cache::put('other_payload', ['y' => 2], 600);

        // Nothing new in the logs: the rebuild changes no row and clears nothing
        $this->assertFalse($service->rebuild($since));
        $this->assertSame(['x' => 1], Cache::get(Cache::METRICS_PREFIX . 'throughput_test'));

        // A change older than the window is not rebuilt, so it changes nothing either
        $this->log(Fixtures::submit()['id'], 'Document Received', $this->day(5) . ' 12:00:00');
        $this->assertFalse($service->rebuild($since));
        $this->assertSame(['x' => 1], Cache::get(Cache::METRICS_PREFIX . 'throughput_test'));

        // A new entry inside the window changes a row: the metrics payload goes, the other one stays
        $this->log(Fixtures::submit()['id'], 'Document Received', $this->day(1) . ' 12:00:00');
        $this->assertTrue($service->rebuild($since));
        $this->assertNull(Cache::get(Cache::METRICS_PREFIX . 'throughput_test'));
        $this->assertSame(['y' => 2], Cache::get('other_payload'));
    }

    // The scheduler runs the windowed rebuild every 5 minutes, driven here with a fake clock and the persisted schedule
    public function test_worker_scheduler_runs_the_windowed_rebuild_every_five_minutes(): void
    {
        $this->seedTwoDays();
        $schedule = new WorkerScheduleService();
        $now = time();

        // First run: due immediately, rebuilds yesterday and today only (the 5-day-old day gets no row)
        $this->assertTrue(PeriodicTasks::runDue($schedule, $now)['metrics_backfill']);
        $this->assertSame(1, $this->received($this->day(1)));
        $this->assertSame(0, $this->received($this->day(5)));
        $this->assertSame($now, $schedule->lastRun(MetricsBackfillTask::TASK));

        // 299 s later it is not due, even with a new entry; at 300 s it runs again and picks the entry up
        $this->log(Fixtures::submit()['id'], 'Document Received', $this->day(1) . ' 12:00:00');
        $this->assertNull(PeriodicTasks::runDue($schedule, $now + 299)['metrics_backfill']);
        $this->assertSame(1, $this->received($this->day(1)));
        $this->assertTrue(PeriodicTasks::runDue($schedule, $now + 300)['metrics_backfill']);
        $this->assertSame(2, $this->received($this->day(1)));
    }

    // The seeders rebuild everything with --full. Running a seeder rebuilds a whole database, so this is a source check
    public function test_seeders_pass_full(): void
    {
        foreach (['/scripts/seeders/seed.js', '/scripts/seeders/fast-seed.php'] as $seeder) {
            $this->assertMatchesRegularExpression('/backfill-metrics\.php[^\n]*--full/', file_get_contents(BASE_PATH . $seeder), $seeder);
        }
    }
}
