<?php

/**
 * Job Runner Script
 * Executed as a separate process by console.php for true concurrency.
 */

require __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('Asia/Manila');

use App\Common\Database\Database;

define('BASE_PATH', __DIR__);

if (!isset($argv[1])) {
    echo "Usage: php runner.php <job_id>\n";
    exit(1);
}

$jobId = (int) $argv[1];
$db = Database::getInstance();

// Double check the job exists and is reserved
$job = $db->query("SELECT * FROM jobs WHERE id = :id AND reserved_at IS NOT NULL", ['id' => $jobId])->fetch();

if (!$job) {
    echo "Job ID: {$jobId} not found or not reserved.\n";
    exit(0);
}

// A helper process pings for this job while it runs; the worker records a job whose pings stop
$queue = new \App\Modules\System\QueueService();
$heartbeat = null;
$exitCode = 0;

try {
    // Without its helper a running job would be recorded as abandoned, so a helper that cannot start fails the job
    $heartbeat = $queue->startHeartbeat($jobId, getmypid());

    $payload = json_decode($job['payload'], true);
    
    $jobClass = $payload['class'];
    $jobData = $payload['data'] ?? [];

    if (class_exists($jobClass)) {
        $instance = new $jobClass(...array_values($jobData));
        if (method_exists($instance, 'handle')) {
            $instance->handle();
        }
    } else {
        throw new Exception("Job class {$jobClass} not found.");
    }

    // Success: remove the job from the queue
    $queue->finish($jobId);
    echo "Job ID: {$jobId} processed successfully.\n";

} catch (\Throwable $e) {
    echo "Job ID: {$jobId} failed: " . $e->getMessage() . "\n";
    
    // Record the failure once and remove the job from the queue
    $queue->fail($jobId, (string) $e);
    $exitCode = 1;
} finally {
    // The job is over either way, so the pings stop
    if ($heartbeat !== null) {
        $queue->stopHeartbeat($heartbeat);
    }
}

exit($exitCode);
