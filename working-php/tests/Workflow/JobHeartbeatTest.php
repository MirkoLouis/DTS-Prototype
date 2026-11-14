<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Modules\System\QueueService;
use Tests\TestCase;

/**
 * The heartbeat helper process pings while its job's runner is alive and stops when the runner or the job is gone.
 */
class JobHeartbeatTest extends TestCase
{
    private array $processes = [];

    // Stop every process a test started
    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGKILL);
            }
            proc_close($process);
        }
        parent::tearDown();
    }

    // A reserved job whose last ping is long ago
    private function staleJob(): int
    {
        $db = Database::getInstance();
        $old = time() - 500;
        $db->query(
            "INSERT INTO jobs (queue, payload, attempts, reserved_at, heartbeat_at, available_at, created_at) VALUES ('default', '{}', 1, :r, :h, 0, 0)",
            [':r' => $old, ':h' => $old]
        );

        return (int) $db->getConnection()->lastInsertId();
    }

    // A sleeping process that stands in for runner.php; returns [handle, pid]
    private function standInRunner(): array
    {
        $process = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->processes[] = $process;

        return [$process, proc_get_status($process)['pid']];
    }

    // Wait until the condition holds or the time runs out
    private function waitFor(callable $condition, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            usleep(100_000);
        }

        return $condition();
    }

    // Seconds since the job's last ping
    private function heartbeatAge(int $jobId): int
    {
        $row = Database::getInstance()->query('SELECT heartbeat_at FROM jobs WHERE id = :id', [':id' => $jobId])->fetch();

        return time() - (int) $row['heartbeat_at'];
    }

    // The helper keeps the heartbeat fresh while the runner lives, and exits once the runner is gone
    public function test_helper_pings_while_the_runner_lives_and_stops_when_it_dies(): void
    {
        $jobId = $this->staleJob();
        [$runner, $runnerPid] = $this->standInRunner();
        $helper = (new QueueService())->startHeartbeat($jobId, $runnerPid, 1);
        $this->processes[] = $helper;

        // The first ping arrives at once, and pings continue
        $this->assertTrue($this->waitFor(fn() => $this->heartbeatAge($jobId) <= 2, 5), 'no heartbeat was written');
        sleep(2);
        $this->assertLessThanOrEqual(2, $this->heartbeatAge($jobId));

        // Kill the runner: the helper notices and exits by itself
        proc_terminate($runner, SIGKILL);
        proc_close($runner);
        array_shift($this->processes);
        $this->assertTrue($this->waitFor(fn() => !proc_get_status($helper)['running'], 5), 'the helper outlived its runner');
    }

    // Losing its database connection must not stop the helper: the job is still running, so pings resume
    public function test_helper_survives_a_dropped_database_connection(): void
    {
        $jobId = $this->staleJob();
        [, $runnerPid] = $this->standInRunner();
        $helper = (new QueueService())->startHeartbeat($jobId, $runnerPid, 1);
        $this->processes[] = $helper;
        $this->assertTrue($this->waitFor(fn() => $this->heartbeatAge($jobId) <= 2, 5), 'no heartbeat was written');

        // Close every other connection to this database from the server side: that is the helper's
        $db = Database::getInstance();
        $others = $db->query('SELECT ID FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND ID <> CONNECTION_ID()')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotSame([], $others, 'the helper had no database connection');
        foreach ($others as $id) {
            $db->getConnection()->exec('KILL ' . (int) $id);
        }

        // Make the heartbeat old again; only a helper that reconnected can refresh it
        $db->query('UPDATE jobs SET heartbeat_at = :old WHERE id = :id', [':old' => time() - 500, ':id' => $jobId]);
        $this->assertTrue($this->waitFor(fn() => $this->heartbeatAge($jobId) <= 2, 6), 'the helper stopped pinging after its connection was dropped');
        $this->assertTrue(proc_get_status($helper)['running']);
    }

    // The helper exits once its job is no longer in the queue
    public function test_helper_stops_when_the_job_row_is_gone(): void
    {
        $jobId = $this->staleJob();
        [, $runnerPid] = $this->standInRunner();
        $helper = (new QueueService())->startHeartbeat($jobId, $runnerPid, 1);
        $this->processes[] = $helper;
        $this->assertTrue($this->waitFor(fn() => $this->heartbeatAge($jobId) <= 2, 5), 'no heartbeat was written');

        Database::getInstance()->query('DELETE FROM jobs WHERE id = :id', [':id' => $jobId]);
        $this->assertTrue($this->waitFor(fn() => !proc_get_status($helper)['running'], 5), 'the helper kept running without a job');
    }

    // Queue a SleepJob, claim it, and start runner.php for it; returns [job id, runner handle]
    private function runJob(int $seconds, bool $fail): array
    {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES ('default', :payload, 0, 0, 0)",
            [':payload' => json_encode(['class' => \Tests\Support\SleepJob::class, 'data' => [$seconds, $fail]])]
        );
        $jobId = (int) $db->getConnection()->lastInsertId();
        (new QueueService())->claim($jobId, time() - 500);

        $runner = proc_open([PHP_BINARY, BASE_PATH . '/runner.php', (string) $jobId], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->processes[] = $runner;

        return [$jobId, $runner];
    }

    // PIDs of heartbeat helpers still running for a job
    private function helperPids(int $jobId): array
    {
        $process = proc_open(['pgrep', '-f', '--', 'job-heartbeat\\.php ' . $jobId . ' '], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return preg_split('/\s+/', trim((string) $output), -1, PREG_SPLIT_NO_EMPTY);
    }

    // runner.php pings for its job while it runs, removes the job when it finishes, and leaves no helper behind
    public function test_runner_pings_during_the_job_and_cleans_up_after_it(): void
    {
        [$jobId, $runner] = $this->runJob(3, false);

        // The job was claimed long ago, so only a ping from the runner's helper makes the heartbeat fresh
        $this->assertTrue($this->waitFor(fn() => $this->heartbeatAge($jobId) <= 2, 5), 'the runner did not ping');
        $this->assertSame(0, (new QueueService())->recordAbandoned(time(), 60));

        // After the job: gone from the queue, nothing recorded as failed, no helper process left
        $this->assertTrue($this->waitFor(fn() => !proc_get_status($runner)['running'], 10), 'the runner did not finish');
        $db = Database::getInstance();
        $this->assertFalse($db->query('SELECT 1 FROM jobs WHERE id = :id', [':id' => $jobId])->fetch());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) c FROM failed_jobs')->fetch()['c']);
        $this->assertTrue($this->waitFor(fn() => $this->helperPids($jobId) === [], 5), 'a heartbeat helper was left running');
    }

    // A job that throws is recorded once under failed jobs by the runner, and its helper stops too
    public function test_failed_job_is_recorded_by_the_runner_and_its_helper_stops(): void
    {
        [$jobId, $runner] = $this->runJob(1, true);
        $this->assertTrue($this->waitFor(fn() => !proc_get_status($runner)['running'], 10), 'the runner did not finish');

        $db = Database::getInstance();
        $this->assertFalse($db->query('SELECT 1 FROM jobs WHERE id = :id', [':id' => $jobId])->fetch());
        $failed = $db->query('SELECT exception FROM failed_jobs')->fetchAll();
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('SleepJob failed on purpose.', $failed[0]['exception']);
        $this->assertTrue($this->waitFor(fn() => $this->helperPids($jobId) === [], 5), 'a heartbeat helper was left running');
    }
}
