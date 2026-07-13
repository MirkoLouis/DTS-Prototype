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
    // Look for a pending job
    $sql = "SELECT * FROM jobs WHERE reserved_at IS NULL AND available_at <= UNIX_TIMESTAMP() ORDER BY id ASC LIMIT 1";
    $stmt = $db->query($sql);
    $job = $stmt->fetch();

    if ($job) {
        $jobId = $job['id'];
        
        // Reserve the job (pessimistic lock equivalent for simple worker)
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
        }
    } else {
        // Sleep if no jobs
        sleep(2);
    }
}
