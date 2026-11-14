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

        $recorded = 0;
        $connection = $this->db->getConnection();
        foreach ($abandoned as $job) {
            // Record and remove together, so a job is never lost or recorded twice
            $connection->beginTransaction();
            try {
                $deleted = $this->db->query("DELETE FROM jobs WHERE id = :id AND reserved_at IS NOT NULL", [':id' => $job['id']]);

                // Another process finished or recorded this job in the meantime
                if ($deleted->rowCount() !== 1) {
                    $connection->rollBack();
                    continue;
                }
                $this->db->query(
                    "INSERT INTO failed_jobs (uuid, connection, queue, payload, exception, failed_at) VALUES (:uuid, 'database', :queue, :payload, :exception, NOW())",
                    [
                        ':uuid' => bin2hex(random_bytes(16)),
                        ':queue' => $job['queue'],
                        ':payload' => $job['payload'],
                        ':exception' => "Job stopped sending its health ping for more than {$timeoutSeconds} seconds (its process ended or the server restarted); recorded here and not retried.",
                    ]
                );
                $connection->commit();
                $recorded++;
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
        }

        return $recorded;
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
