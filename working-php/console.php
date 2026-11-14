<?php

/**
 * Console Worker for processing background jobs.
 * This simulates Laravel's `php artisan queue:work`.
 * Run this script via CLI: `php console.php`
 */

require __DIR__ . '/vendor/autoload.php';

// Match Laravel's default timezone so background hash generation aligns properly
date_default_timezone_set('Asia/Manila');

use App\Common\Database\Database;

define('BASE_PATH', __DIR__);

echo "Starting async queue worker...\n";

$db = Database::getInstance();

$stopWorker = false;

// Memory leak prevention limits
$maxMemory = 8 * 1024 * 1024 * 1024; // 8 GB limit before restarting
$maxJobs = 100; // Restart after 100 jobs to clear any accumulated state
$jobsProcessed = 0;

// Container-compatible internal task scheduler; last-run times persist in worker_schedule, so restarts never postpone a task
$schedule = new \App\Modules\System\WorkerScheduleService();

// Job queue operations (claim, heartbeat, abandoned jobs)
$queue = new \App\Modules\System\QueueService();

/**
 * Execute periodic system maintenance and telemetry tasks inside the worker loop.
 * Eliminates reliance on host system cron daemons in containerized environments.
 */
function runScheduledTasks(\App\Modules\System\WorkerScheduleService $schedule, \App\Modules\System\QueueService $queue): void
{
    $now = time();

    // Record jobs whose health ping has stopped (every 30s); they go to Failed Jobs and are not retried
    if ($schedule->isDue('abandoned_jobs', 30, $now)) {
        $schedule->markRun('abandoned_jobs', $now);
        try {
            $timeout = (int) \App\Common\Config\Config::get('app', 'job_abandoned_seconds', 60);
            $abandoned = $queue->recordAbandoned($now, $timeout);
            if ($abandoned > 0) {
                echo "⏰ [Worker Scheduler] Recorded {$abandoned} abandoned job(s) under Failed Jobs.\n";
            }
        } catch (\Throwable $e) {
            echo "⏰ [Worker Scheduler] Abandoned-job check failed: " . $e->getMessage() . "\n";
        }
    }

    // 0. Signed chain-head checkpoint every 24 hours (86400s)
    if ($schedule->isDue('checkpoint', 86400, $now)) {
        $schedule->markRun('checkpoint', $now);
        try {
            $path = (new \App\Modules\Integrity\Jobs\ChainCheckpointJob())->create();
            echo "⏰ [Worker Scheduler] Chain-head checkpoint written: " . basename($path) . "\n";
        } catch (\Throwable $e) {
            echo "⏰ [Worker Scheduler] Chain-head checkpoint failed: " . $e->getMessage() . "\n";
        }
    }

    // 1. Sample database performance metrics every 5 minutes (300s)
    if ($schedule->isDue('db_metrics_sample', 300, $now)) {
        $schedule->markRun('db_metrics_sample', $now);
        $scriptPath = BASE_PATH . '/scripts/sample-db-metrics.php';
        if (file_exists($scriptPath)) {
            echo "⏰ [Worker Scheduler] Sampling database performance metrics...\n";
            passthru(PHP_BINARY . ' ' . escapeshellarg($scriptPath));
        }
    }

    // 2. Departmental TAT & Volume Metrics Backfill every 30 minutes (1800s)
    if ($schedule->isDue('metrics_backfill', 1800, $now)) {
        $schedule->markRun('metrics_backfill', $now);
        $scriptPath = BASE_PATH . '/scripts/backfill-metrics.php';
        if (file_exists($scriptPath)) {
            echo "⏰ [Worker Scheduler] Running departmental metrics backfill...\n";
            passthru(PHP_BINARY . ' ' . escapeshellarg($scriptPath));
        }
    }

    // 3. Roll up old database metrics (>24h) into hourly aggregates every 24 hours (86400s)
    if ($schedule->isDue('metrics_rollup', 86400, $now)) {
        $schedule->markRun('metrics_rollup', $now);
        $scriptPath = BASE_PATH . '/scripts/rollup-metrics.php';
        if (file_exists($scriptPath)) {
            echo "⏰ [Worker Scheduler] Running historical database metrics rollup...\n";
            passthru(PHP_BINARY . ' ' . escapeshellarg($scriptPath));
        }
    }

    // 4. Response cache GC + log rotation every hour (3600s).
    // Cache serve TTL is 55s, so any file older than 1 hour is guaranteed stale.
    if ($schedule->isDue('cache_gc', 3600, $now)) {
        $schedule->markRun('cache_gc', $now);

        $cacheDir = \App\Common\Middleware\CacheMiddleware::cacheDir();
        $purged = 0;
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '/*.html') as $file) {
                if (time() - filemtime($file) > 3600) {
                    if (@unlink($file)) {
                        $purged++;
                    }
                }
            }
        }
        echo "⏰ [Worker Scheduler] Response cache GC: purged {$purged} stale file(s).\n";

        // Rotate navigation.log when it exceeds 50 MB to prevent unbounded growth
        $navLog = BASE_PATH . '/storage/logs/navigation.log';
        if (file_exists($navLog) && filesize($navLog) > 50 * 1024 * 1024) {
            $archiveName = BASE_PATH . '/storage/logs/navigation-' . date('Y-m-d-His') . '.log';
            rename($navLog, $archiveName);
            echo "⏰ [Worker Scheduler] Rotated navigation.log → " . basename($archiveName) . "\n";
        }
    }

    // 5. Cleanup stale pending documents (>3 days without intake) every 24 hours (86400s)
    if ($schedule->isDue('stale_cleanup', 86400, $now)) {
        $schedule->markRun('stale_cleanup', $now);
        echo "⏰ [Worker Scheduler] Running stale pending documents cleanup...\n";
        try {
            $job = new \App\Modules\Documents\Jobs\CleanupStalePendingDocumentsJob();
            $expiredCount = $job->handle(3);
            echo "⏰ [Worker Scheduler] Stale pending cleanup finished. Expired {$expiredCount} document(s).\n";
        } catch (\Throwable $e) {
            echo "⏰ [Worker Scheduler] Stale pending cleanup failed: " . $e->getMessage() . "\n";
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

$maxWorkers = 2; // Can be changed freely
$activeWorkers = [];

// Clean up function to check for dead processes
function cleanupActiveWorkers(array &$activeWorkers) {
    foreach ($activeWorkers as $key => $pid) {
        // posix_kill($pid, 0) checks if process is alive without sending a signal
        if (!posix_kill($pid, 0)) {
            unset($activeWorkers[$key]);
        }
    }
}

while (!$stopWorker) {
    // Re-open the database connection if MySQL has dropped it since the last poll
    $db->ensureConnected();

    // 0. Run scheduled maintenance & telemetry tasks
    runScheduledTasks($schedule, $queue);

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

    cleanupActiveWorkers($activeWorkers);

    if (count($activeWorkers) >= $maxWorkers) {
        sleep(1);
        continue;
    }

    // Look for a pending job
    $sql = "SELECT * FROM jobs WHERE reserved_at IS NULL AND available_at <= UNIX_TIMESTAMP() ORDER BY id ASC LIMIT 1";
    $stmt = $db->query($sql);
    $job = $stmt->fetch();

    if ($job) {
        $jobId = $job['id'];
        
        // Claim the job: only the worker whose UPDATE reserved the row dispatches it
        if ($queue->claim((int) $jobId, time())) {
            echo "Dispatching Job ID: {$jobId}\n";
            
            // Dispatch in background
            $runnerScript = escapeshellarg(__DIR__ . '/runner.php');
            $cmd = PHP_BINARY . " {$runnerScript} " . (int)$jobId . " > /dev/null 2>&1 & echo $!";
            $pid = (int) shell_exec($cmd);
            
            if ($pid > 0) {
                $activeWorkers[] = $pid;
                echo "Dispatched to PID: {$pid} (" . count($activeWorkers) . "/{$maxWorkers} active workers)\n";
            }
            
            $jobsProcessed++;
        }
    } else {
        // Sleep if no jobs
        sleep(2);
    }
}
