<?php

/**
 * Heartbeat helper for one running job. Started by runner.php through QueueService::startHeartbeat().
 *
 * Usage: php scripts/job-heartbeat.php <job_id> <runner_pid> [interval_seconds]
 *
 * Writes jobs.heartbeat_at for the job at a fixed interval on its own database connection, and stops
 * by itself when the runner process is gone or the job is no longer reserved in the queue.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
date_default_timezone_set('Asia/Manila');

use App\Common\Config\Config;
use App\Modules\System\QueueService;

// Both ids are required and must be positive whole numbers
$jobId = (int) ($argv[1] ?? 0);
$runnerPid = (int) ($argv[2] ?? 0);
if ($jobId <= 0 || $runnerPid <= 0) {
    fwrite(STDERR, "Usage: php scripts/job-heartbeat.php <job_id> <runner_pid> [interval_seconds]\n");
    exit(1);
}

// Seconds between pings: the optional argument, else JOB_HEARTBEAT_SECONDS, never less than one
$interval = max(1, (int) ($argv[3] ?? Config::get('app', 'job_heartbeat_seconds', 10)));
$queue = new QueueService();

// Ping until the runner is gone or the job has left the queue
while (posix_kill($runnerPid, 0) && $queue->heartbeat($jobId, time())) {
    sleep($interval);
}
