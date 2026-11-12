<?php

namespace App\Modules\System;

use App\Common\Database\Database;

/**
 * Persisted schedule for the worker's periodic tasks (console.php).
 *
 * Last-run times live in the worker_schedule table instead of process memory, so a worker restart
 * (crash, reboot, or the planned re-exec after 100 jobs) never postpones a daily task. A task that
 * has never run is due immediately; after downtime a task runs once, not once per missed interval.
 */
class WorkerScheduleService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Whether a task is due at $now, given its interval.
     */
    public function isDue(string $task, int $intervalSeconds, int $now): bool
    {
        // Never run means due; otherwise due once the full interval has passed
        $last = $this->lastRun($task);
        return $last === null || $now - $last >= $intervalSeconds;
    }

    /**
     * Record that a task ran (started) at $now.
     */
    public function markRun(string $task, int $now): void
    {
        // Insert the first run, or move the existing last-run time forward
        $this->db->query(
            "INSERT INTO worker_schedule (task, last_run_at) VALUES (:task, :now) ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at)",
            [':task' => $task, ':now' => $now]
        );
    }

    /**
     * Last run time of a task as a Unix epoch, or null if it has never run.
     */
    public function lastRun(string $task): ?int
    {
        // Read the stored time for this task
        $row = $this->db->query("SELECT last_run_at FROM worker_schedule WHERE task = :task", [':task' => $task])->fetch();
        return $row ? (int) $row['last_run_at'] : null;
    }
}
