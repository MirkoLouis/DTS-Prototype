<?php

namespace App\Modules\System;

use App\Common\Database\Database;

/**
 * The database job queue (jobs table): claiming a job and tracking whether a running job is alive.
 *
 * Used by the worker (console.php), the job runner (runner.php) and the heartbeat helper
 * (scripts/job-heartbeat.php).
 */
class QueueService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Reserve a queued job for this worker.
     *
     * The UPDATE only matches an unreserved row, so when several workers race for the same job
     * exactly one of them changes it. Claiming also starts the job's heartbeat clock.
     *
     * @return bool true for the one worker whose UPDATE reserved the job
     */
    public function claim(int $jobId, int $now): bool
    {
        $claim = $this->db->query(
            "UPDATE jobs SET reserved_at = :reserved, heartbeat_at = :heartbeat, attempts = attempts + 1 WHERE id = :id AND reserved_at IS NULL",
            [':reserved' => $now, ':heartbeat' => $now, ':id' => $jobId]
        );

        return $claim->rowCount() === 1;
    }

    /**
     * Record that a running job is alive.
     *
     * @return bool false when the job is no longer reserved in the queue (finished, failed or recorded as abandoned)
     */
    public function heartbeat(int $jobId, int $now): bool
    {
        $ping = $this->db->query(
            "UPDATE jobs SET heartbeat_at = :now WHERE id = :id AND reserved_at IS NOT NULL",
            [':now' => $now, ':id' => $jobId]
        );
        if ($ping->rowCount() === 1) {
            return true;
        }

        // No row changed: either the time is the same second as the last ping, or the job is gone
        return (bool) $this->db->query("SELECT 1 FROM jobs WHERE id = :id AND reserved_at IS NOT NULL", [':id' => $jobId])->fetch();
    }

    /**
     * Record every reserved job whose health ping has stopped, and remove it from the queue.
     *
     * Such a job's process ended without finishing (it crashed, was killed, or the server restarted).
     * It is written to failed_jobs so an admin sees it, and it is never run again automatically:
     * repeating a backup or a restore that nobody asked for again is unsafe.
     *
     * @return int number of jobs recorded
     */
    public function recordAbandoned(int $now, int $timeoutSeconds): int
    {
        // A job that was claimed but never pinged is measured from its claim
        $abandoned = $this->db->query(
            "SELECT * FROM jobs WHERE reserved_at IS NOT NULL AND COALESCE(heartbeat_at, reserved_at) < :cutoff",
            [':cutoff' => $now - $timeoutSeconds]
        )->fetchAll();

        // Each job is removed and recorded together; one that finished in the meantime is skipped
        $reason = "Job stopped sending its health ping for more than {$timeoutSeconds} seconds (its process ended or the server restarted); recorded here and not retried.";
        $recorded = 0;
        foreach ($abandoned as $job) {
            if ($this->fail((int) $job['id'], $reason)) {
                $recorded++;
            }
        }

        return $recorded;
    }

    /**
     * Remove a job that finished successfully from the queue.
     *
     * @return bool false when the job was no longer queued (it had already been recorded as abandoned)
     */
    public function finish(int $jobId): bool
    {
        return $this->db->query("DELETE FROM jobs WHERE id = :id", [':id' => $jobId])->rowCount() === 1;
    }

    /**
     * Record a job under failed jobs and remove it from the queue, as one step.
     *
     * The record is written only when this call removed the job, so a job is never recorded twice
     * (for example once as abandoned and again when its runner fails later).
     *
     * @param string $reason the error text shown to the admin
     * @return bool true when the job was removed and recorded by this call
     */
    public function fail(int $jobId, string $reason): bool
    {
        $connection = $this->db->getConnection();
        $connection->beginTransaction();
        try {
            // Lock and read the job, so its queue and payload go into the record
            $job = $this->db->query("SELECT queue, payload FROM jobs WHERE id = :id FOR UPDATE", [':id' => $jobId])->fetch();
            if (!$job) {
                $connection->rollBack();
                return false;
            }

            $this->db->query("DELETE FROM jobs WHERE id = :id", [':id' => $jobId]);
            $this->db->query(
                "INSERT INTO failed_jobs (uuid, connection, queue, payload, exception, failed_at) VALUES (:uuid, 'database', :queue, :payload, :exception, NOW())",
                [':uuid' => bin2hex(random_bytes(16)), ':queue' => $job['queue'], ':payload' => $job['payload'], ':exception' => $reason]
            );
            $connection->commit();

            return true;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Seconds without a ping after which a job counts as abandoned.
     *
     * Never shorter than three ping intervals, so a missing or zero setting cannot make every
     * running job look abandoned.
     */
    public static function abandonedTimeout(int $configuredSeconds, int $heartbeatSeconds): int
    {
        return max($configuredSeconds, 3 * max(1, $heartbeatSeconds));
    }

    /**
     * Start the helper process that pings for a running job.
     *
     * The job itself runs in one PHP process that cannot ping while it is inside a long call (a database
     * dump, one large query), so a separate small process does it. No shell is involved.
     *
     * @param int|null $intervalSeconds seconds between pings; null uses JOB_HEARTBEAT_SECONDS
     * @return resource the process handle, for stopHeartbeat()
     */
    public function startHeartbeat(int $jobId, int $runnerPid, ?int $intervalSeconds = null)
    {
        $command = [PHP_BINARY, BASE_PATH . '/scripts/job-heartbeat.php', (string) $jobId, (string) $runnerPid];
        if ($intervalSeconds !== null) {
            $command[] = (string) $intervalSeconds;
        }

        // The helper needs no input and its output is not read
        $descriptors = [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException("Could not start the heartbeat helper for job {$jobId}.");
        }

        return $process;
    }

    /**
     * Stop a heartbeat helper started by startHeartbeat().
     *
     * @param resource $process
     */
    public function stopHeartbeat($process): void
    {
        // The helper may already have exited on its own
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
        }
        proc_close($process);
    }
}
