<?php

/**
 * Console Worker for processing background jobs.
 * This simulates Laravel's `php artisan queue:work`.
 * Run this script via CLI: `php console.php`
 */

require __DIR__ . '/vendor/autoload.php';

// Match Laravel's default timezone so background hash generation aligns properly
date_default_timezone_set('Asia/Manila');

use App\Core\Database;

define('BASE_PATH', __DIR__);

echo "Starting async queue worker...\n";

$db = Database::getInstance();

$stopWorker = false;

// Memory leak prevention limits
$maxMemory = 8 * 1024 * 1024 * 1024; // 8 GB limit before restarting
$maxJobs = 100; // Restart after 100 jobs to clear any accumulated state
$jobsProcessed = 0;

// Timestamps for container-compatible internal worker task scheduler
$lastSampleTime = 0;
$lastBackfillTime = 0;
$lastRollupTime = 0;

/**
 * Execute periodic system maintenance and telemetry tasks inside the worker loop.
 * Eliminates reliance on host system cron daemons in containerized environments.
 */
function runScheduledTasks(int &$lastSampleTime, int &$lastBackfillTime, int &$lastRollupTime): void
{
    $now = time();

    // 1. Sample database performance metrics every 5 minutes (300s)
    if ($now - $lastSampleTime >= 300) {
        $lastSampleTime = $now;
        $scriptPath = BASE_PATH . '/scripts/sample-db-metrics.php';
        if (file_exists($scriptPath)) {
            echo "⏰ [Worker Scheduler] Sampling database performance metrics...\n";
            passthru(PHP_BINARY . ' ' . escapeshellarg($scriptPath));
        }
    }

    // 2. Departmental TAT & Volume Metrics Backfill every 30 minutes (1800s)
    if ($now - $lastBackfillTime >= 1800) {
        $lastBackfillTime = $now;
        $scriptPath = BASE_PATH . '/scripts/backfill-metrics.php';
        if (file_exists($scriptPath)) {
            echo "⏰ [Worker Scheduler] Running departmental metrics backfill...\n";
            passthru(PHP_BINARY . ' ' . escapeshellarg($scriptPath));
        }
    }

    // 3. Roll up old database metrics (>24h) into hourly aggregates every 24 hours (86400s)
    if ($now - $lastRollupTime >= 86400) {
        $lastRollupTime = $now;
        $scriptPath = BASE_PATH . '/scripts/rollup-metrics.php';
        if (file_exists($scriptPath)) {
            echo "⏰ [Worker Scheduler] Running historical database metrics rollup...\n";
            passthru(PHP_BINARY . ' ' . escapeshellarg($scriptPath));
        }
    }
}

// Intercepts system termination signals to allow the worker to finish its current job 
// before shutting down gracefully, preventing data corruption mid-task.
if (extension_loaded('pcntl')) {
    pcntl_async_signals(true);
    $signalHandler = function ($signo) use (&$stopWorker) {
        echo "\nReceived shutdown signal ($signo). Finishing current job and stopping...\n";
        $stopWorker = true;
    };
    pcntl_signal(SIGINT, $signalHandler);
    pcntl_signal(SIGTERM, $signalHandler);
} else {
    echo "Warning: PCNTL extension not loaded. Worker cannot shut down gracefully.\n";
}

while (!$stopWorker) {
    // 0. Run scheduled maintenance & telemetry tasks
    runScheduledTasks($lastSampleTime, $lastBackfillTime, $lastRollupTime);

    // 1. Memory Leak Prevention: Check if we exceeded our allowed memory
    if (memory_get_usage(true) > $maxMemory) {
        echo "Memory limit exceeded (" . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB). Restarting worker seamlessly...\n";
        global $argv;
        pcntl_exec(PHP_BINARY, $argv);
        exit(0);
    }
    
    // 2. Job Count Limit: Check if we processed enough jobs
    if ($jobsProcessed >= $maxJobs) {
        echo "Processed {$maxJobs} jobs. Restarting worker seamlessly...\n";
        global $argv;
        pcntl_exec(PHP_BINARY, $argv);
        exit(0);
    }

    // Look for a pending job
    $sql = "SELECT * FROM jobs WHERE reserved_at IS NULL AND available_at <= UNIX_TIMESTAMP() ORDER BY id ASC LIMIT 1";
    $stmt = $db->query($sql);
    $job = $stmt->fetch();

    if ($job) {
        $jobId = $job['id'];
        
        // Claim the job atomically. Using UPDATE ensures that if multiple worker processes 
        // are running concurrently, only one will successfully claim this specific row.
        $db->query("UPDATE jobs SET reserved_at = UNIX_TIMESTAMP(), attempts = attempts + 1 WHERE id = :id AND reserved_at IS NULL", ['id' => $jobId]);
        
        // Ensure we actually reserved it (in case of multiple workers)
        $check = $db->query("SELECT * FROM jobs WHERE id = :id AND reserved_at IS NOT NULL", ['id' => $jobId])->fetch();
        
        if ($check) {
            echo "Processing Job ID: {$jobId}\n";
            
            try {
                $payload = json_decode($job['payload'], true);
                
                // Assuming payload contains a class and serialized data or just a class name for our simple implementation
                $jobClass = $payload['class'];
                $jobData = $payload['data'] ?? [];

                if (class_exists($jobClass)) {
                    // Dynamically instantiate the job class and pass its stored payload as constructor arguments
                    $instance = new $jobClass(...array_values($jobData));
                    if (method_exists($instance, 'handle')) {
                        $instance->handle();
                    }
                } else {
                    throw new Exception("Job class {$jobClass} not found.");
                }

                // If successful, delete the job
                $db->query("DELETE FROM jobs WHERE id = :id", ['id' => $jobId]);
                echo "Job ID: {$jobId} processed successfully.\n";

            } catch (\Throwable $e) {
                echo "Job ID: {$jobId} failed: " . $e->getMessage() . "\n";
                
                // Move to failed jobs
                $db->query(
                    "INSERT INTO failed_jobs (uuid, connection, queue, payload, exception, failed_at) VALUES (:uuid, :connection, :queue, :payload, :exception, NOW())",
                    [
                        'uuid' => uniqid(),
                        'connection' => 'database',
                        'queue' => $job['queue'],
                        'payload' => $job['payload'],
                        'exception' => (string) $e
                    ]
                );
                
                // Delete from jobs table
                $db->query("DELETE FROM jobs WHERE id = :id", ['id' => $jobId]);
            }
            
            // 3. Memory Leak Prevention: Explicitly unset objects and trigger Garbage Collection
            $jobsProcessed++;
            unset($instance);
            unset($payload);
            unset($jobData);
            
            // Force PHP to clean up cyclical references
            gc_collect_cycles();
        }
    } else {
        // Sleep if no jobs
        sleep(2);
    }
}
