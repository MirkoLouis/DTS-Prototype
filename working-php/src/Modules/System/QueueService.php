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
}
