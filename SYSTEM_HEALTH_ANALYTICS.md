# System Health & Analytics Documentation

This document explains how the metrics and analytics on the "System Health Monitor" page are calculated. The logic is primarily located in the `app/Http/Controllers/SystemHealthController.php` controller and the `dts:verify-integrity` Artisan command.

## 1. App Health Metrics

These metrics provide a real-time overview of the application's performance and stability. They are calculated in the `getApplicationHealthMetrics()` method within the `SystemHealthController`.

### a. Average Processing Time

This metric calculates the average time it takes for a document to go from being formally accepted by a Records Officer to being released to the client.

**Calculation Logic:**

1.  The system queries the `document_logs` table.
2.  For each document, it identifies two key moments:
    *   **Start Time:** The timestamp of the log entry where the `action` is `"Accepted and Document Routing finalized"`.
    *   **End Time:** The timestamp of the log entry where the `action` is `"Document Released"`.
3.  It only includes documents that have *both* a start and an end time, meaning they have completed the full workflow.
4.  The system calculates the duration in seconds between the start and end time for each completed document.
5.  Finally, it computes the average of these durations.

If no documents have been fully processed, this metric will display "N/A".

```php
// Simplified logic from SystemHealthController.php

$processingTimes = DocumentLog::select(
        'document_id',
        DB::raw('MIN(CASE WHEN action = "Accepted and Document Routing finalized" THEN created_at END) as start_time'),
        DB::raw('MAX(CASE WHEN action = "Document Released" THEN created_at END) as end_time')
    )
    ->groupBy('document_id')
    ->havingNotNull('start_time')
    ->havingNotNull('end_time')
    ->get();

$totalSeconds = $processingTimes->sum(function ($log) {
    return Carbon::parse($log->end_time)->diffInSeconds(Carbon::parse($log->start_time)));
});

$averageProcessingTime = ($processingTimes->count() > 0) ? $totalSeconds / $processingTimes->count() : 0;
```

### b. Failed Jobs

This metric shows the number of background jobs that have failed. Laravel's queue system is used for tasks like the AI "learning" process (`UpdateKeywordWeights` job). A non-zero number here indicates a potential problem with the queue worker or the job logic itself.

**Calculation Logic:**

This is a direct count of the rows in the `failed_jobs` table in the database.

```php
// Simplified logic from SystemHealthController.php
$failedJobsCount = DB::table('failed_jobs')->count();
```

### c. Cache Status

This metric checks if the application's caching service (like Redis or a file-based cache) is responding correctly.

**Calculation Logic:**

1.  The system attempts to write a value to the cache with a key (`system-health-check`) and a short 10-second lifespan.
2.  It then immediately tries to read that value back.
3.  If the value is written and read back successfully without any errors, the status is **"Operational"**.
4.  If any exception occurs during this process, the status is **"Not Responding"**.

```php
// Simplified logic from SystemHealthController.php
try {
    Cache::put('system-health-check', 'ok', 10);
    $cacheStatus = Cache::get('system-health-check') === 'ok';
} catch (\Exception $e) {
    $cacheStatus = false;
}
```

## 2. Database Integrity ("The Trust Builder")

This is the core feature of the System Health page, designed to provide mathematical proof of the document log's immutability.

### How It Works

The system is built on a "hash chain" mechanism, inspired by blockchain technology.

1.  **Hash-Chaining:** When any log entry is created for a document, a unique digital signature (a `sha256` "hash") is generated. This hash is created by combining the log's own data (like the action, user, and timestamp) with the hash of the *previous* log entry. This creates a cryptographic chain where each link is dependent on the one before it.

2.  **Verification Process:** Clicking the **"Run Verification"** button triggers the `dts:verify-integrity` Artisan command. This command performs a system-wide audit:
    *   It iterates through every single document's log history.
    *   For each log entry, it recalculates what its hash *should* be based on its stored data and the actual hash of the previous log.
    *   It compares this recalculated hash with the hash value currently stored in the database.

3.  **Status Indication:**
    *   **100% Verified:** If the recalculated hash matches the stored hash for every single log entry in the entire system, it proves that the data has not been tampered with.
    *   **Mismatch Detected:** If even one hash is mismatched, it means that data has been altered outside of the application's normal workflow. The system will report a verification percentage less than 100% and display a table of the specific log entries that failed the check.

The results of the last check are cached to avoid running this intensive process on every page load. The "Last checked" timestamp indicates how fresh the displayed data is.

For a more detailed technical explanation of the hashing mechanism, see the `DOCUMENT_HASHING.md` file.
