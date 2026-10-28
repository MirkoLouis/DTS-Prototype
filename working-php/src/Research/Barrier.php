<?php

namespace App\Research;

use App\Core\Database;
use RuntimeException;

/**
 * Forks worker processes that all start their work at the same instant.
 *
 * Children reconnect to MySQL (Database::resetInstance) and end by SIGKILL-ing themselves,
 * so no PHPUnit teardown, shutdown function or destructor ever runs in a child. That keeps
 * them from closing the parent's inherited PDO socket or stopping the parent's test server.
 */
class Barrier
{
    // Seconds between fixing the start time and the moment every child begins its work
    public const START_DELAY = 2.0;

    // Seconds after the start time by which every child must have exited
    public const TIMEOUT = 60.0;

    // Result key holding the moment the child actually left the barrier
    public const RELEASED_AT = 'barrier_released_at';

    // Holds the parent's Database so the copy inherited by a child is never destructed there
    private static ?Database $inherited = null;

    // Pids of children forked by this process that have not been reaped yet (pid => pid)
    private static array $active = [];

    /**
     * Fork $workers children, run $work(int $index, mixed $prepared): array in each at a shared
     * start time, and return the results ordered by index. The optional $prepare(int $index) runs
     * in the child before the barrier sleep (setup kept out of the race); its return value is $prepared.
     * Each result also carries RELEASED_AT, the child's actual release time.
     */
    public static function forkAll(int $workers, callable $work, ?callable $prepare = null): array
    {
        // Fix the shared start time before the first fork
        $startAt = microtime(true) + self::START_DELAY;
        $dir = sys_get_temp_dir() . '/dts-barrier-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        self::$inherited = Database::getInstance();

        // Fork one child per worker; the parent only collects pids
        $pids = [];
        for ($index = 0; $index < $workers; $index++) {
            $pid = pcntl_fork();

            // Fork failure: reap the children already started, then give up
            if ($pid === -1) {
                try {
                    self::reap($pids, $startAt + self::TIMEOUT);
                } finally {
                    self::cleanup($dir);
                }
                throw new RuntimeException("pcntl_fork failed after {$index} workers.");
            }

            // Child branch: never returns
            if ($pid === 0) {
                self::runChild($index, $startAt, $dir, $work, $prepare);
            }
            $pids[$index] = $pid;
            self::$active[$pid] = $pid;
        }

        // Wait for every child (bounded by the deadline); a timeout is reported together with the results that did arrive
        $results = [];
        $errors = [];
        try {
            self::reap($pids, $startAt + self::TIMEOUT);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        // Read the results in index order
        for ($index = 0; $index < $workers; $index++) {
            $file = "{$dir}/{$index}.json";
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

            // A missing file or a caught throwable is a harness error, not a test result
            if (!is_array($decoded)) {
                $errors[] = "worker {$index}: no result written";
            } elseif (isset($decoded['__barrier_error'])) {
                $errors[] = "worker {$index}: {$decoded['__barrier_error']}";
            } else {
                $results[$index] = $decoded;
            }
        }
        self::cleanup($dir);

        // Surface child failures to the caller, with the results of the workers that finished
        if ($errors) {
            throw new BarrierWorkerException('Barrier workers failed: ' . implode('; ', array_slice($errors, 0, 10)), $results);
        }

        return $results;
    }

    /**
     * Child body: reconnect, wait for the start time, work, write the result, die without cleanup.
     */
    private static function runChild(int $index, float $startAt, string $dir, callable $work, ?callable $prepare): never
    {
        // Fresh DB connection; the inherited instance stays referenced by self::$inherited
        Database::resetInstance();

        try {
            // Per-worker setup happens before the barrier so it is not part of the race
            $prepared = $prepare ? $prepare($index) : null;

            // Sleep until the shared start instant
            $wait = $startAt - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            $releasedAt = microtime(true);

            // Run the work and stamp the actual release time onto its result
            $result = $work($index, $prepared);
            $result[self::RELEASED_AT] = $releasedAt;
        } catch (\Throwable $e) {
            $result = ['__barrier_error' => get_class($e) . ': ' . $e->getMessage()];
        }

        // Write atomically (temp file + rename) so the parent never reads a partial file
        $tmp = "{$dir}/{$index}.tmp";
        file_put_contents($tmp, json_encode($result));
        rename($tmp, "{$dir}/{$index}.json");

        // Equivalent of _exit(): skip shutdown functions, destructors and PHPUnit teardown
        posix_kill(posix_getpid(), SIGKILL);
        exit(0);
    }

    /**
     * Spread in seconds between the earliest and latest actual child release.
     */
    public static function skew(array $results): float
    {
        $released = array_column($results, self::RELEASED_AT);

        return $released ? max($released) - min($released) : 0.0;
    }

    /**
     * Pids of children still running (forked and not yet reaped).
     *
     * @return list<int>
     */
    public static function activePids(): array
    {
        return array_values(self::$active);
    }

    /**
     * SIGKILL every child still running and reap them, waiting at most $graceSeconds.
     * For an interrupted run (signal or fatal error inside forkAll). The final waitpid(-1) also reaps
     * any other child of this process, so only call it from a process whose children are all Barrier workers.
     * Returns the number of children killed.
     */
    public static function killActive(float $graceSeconds = 2.0): int
    {
        // Kill every tracked child first so none can start or finish a request
        $killed = 0;
        foreach (self::$active as $pid) {
            if (posix_kill($pid, SIGKILL)) {
                $killed++;
            }
        }

        // Reap until no child is left or the grace period ends
        $deadline = microtime(true) + $graceSeconds;
        while (microtime(true) < $deadline) {
            // Drop each tracked child that has exited
            foreach (self::$active as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                    unset(self::$active[$pid]);
                }
            }

            // Collect any untracked child too (for example one forked a moment before the interruption)
            $reaped = pcntl_waitpid(-1, $status, WNOHANG);
            if ($reaped === -1 && self::$active === []) {
                break;
            }
            if ($reaped === 0 || self::$active !== []) {
                usleep(10_000);
            }
        }

        return $killed;
    }

    /**
     * Poll every child with WNOHANG until all exit or the deadline passes; stragglers are SIGKILLed.
     */
    private static function reap(array $pids, float $deadline): void
    {
        // Loop until no child is left running
        while ($pids) {
            // Drop each child that has exited since the last poll
            foreach ($pids as $index => $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                    unset($pids[$index], self::$active[$pid]);
                }
            }

            // Past the deadline: kill and reap the stragglers, then fail naming them
            if ($pids && microtime(true) > $deadline) {
                foreach ($pids as $pid) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                    unset(self::$active[$pid]);
                }
                throw new RuntimeException('Barrier workers still running at the deadline were killed: indexes ' . implode(', ', array_keys($pids)));
            }

            // Short pause between polls
            if ($pids) {
                usleep(10_000);
            }
        }
    }

    /**
     * Remove the result directory and its files.
     */
    private static function cleanup(string $dir): void
    {
        // Delete every result file, then the directory
        foreach (glob("{$dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
