# Project Resilience & Fallback Strategies

This document tracks the specialized fallback mechanisms and architectural safeguards designed to ensure the Document Tracking System (DTS) remains stable, reliable, and performant under heavy load or edge-case scenarios.

## Table of Contents
1. [High-Performance Queue Persistence](#high-performance-queue-persistence)
2. [Scalable Report Generation (PDF Chunking)](#scalable-report-generation-pdf-chunking)
3. [AI Route Prediction Defaults](#ai-route-prediction-defaults)
4. [Cryptographic Integrity Repair](#cryptographic-integrity-repair)
5. [Analytics Snapshot Strategy](#analytics-snapshot-strategy)

---

## High-Performance Queue Persistence

### The Fallback
**Automatic Worker Restart Loop**

### Implementation
-   **Command:** `composer queue:work-prod`
-   **Logic:** `while true; do php artisan queue:work --timeout=1200; echo 'Queue worker stopped. Restarting in 10s...'; sleep 10; done`

### How it Works
-   **Backend:** The worker is wrapped in a shell loop. If the `php artisan queue:work` process exits (due to memory exhaustion, a crash, or a server restart), the script waits 10 seconds and automatically spawns a new worker.
-   **Purpose:** Ensures that critical background tasks—like report generation and AI training—continue to process even if an individual worker process fails.

---

## Scalable Report Generation (PDF Chunking)

### The Fallback
**Memory-Safe PDF Chunking & Disk-Buffered Merging**

### Implementation
-   **Class:** `app/Jobs/GenerateReportJob.php`
-   **Constraint:** 250 documents per chunk.

### How it Works
-   **Backend:** Instead of trying to build one massive 10,000-page PDF in RAM, the system:
    1.  Processes documents in small batches (chunks).
    2.  Renders each batch as a standalone PDF.
    3.  Saves each chunk to a temporary file on disk (using `tempnam`).
    4.  Clears PHP's memory using `gc_collect_cycles()`.
    5.  Merges all disk files into the final report using `iio/libmergepdf`.
-   **Frontend:** Users see a real-time progress bar (5% to 100%) as each chunk is successfully buffered.
-   **Purpose:** Prevents `Fatal Error: Allowed memory size exhausted` when generating division-wide monthly or annual reports.

---

## AI Route Prediction Defaults

### The Fallback
**"Records Unit" Primary Routing**

### Implementation
-   **Service:** `app/Services/RoutePredictionService.php`

### How it Works
-   **Backend:** The AI uses a weighted keyword scoring system to suggest a route. If the system cannot find *any* matching keywords in the user's input, the `predict()` method returns a default array: `['Records']`.
-   **Purpose:** Ensures that every document has a valid starting point. It prevents "orphaned" documents that have no assigned handler, shifting the responsibility to a human Records Officer for manual routing.

---

## Cryptographic Integrity Repair

### The Fallback
**Manual Hash-Chain Rebuilding**

### Implementation
-   **Command:** `php artisan dts:rebuild-chain {logId}`

### How it Works
-   **Backend:** If the "Trust Builder" identifies a hash mismatch (due to a database glitch or unauthorized edit), the chain is "broken." An authorized administrator can use this command to:
    1.  Recalculate the fingerprint for the corrupted log.
    2.  Update the `previous_hash` of every subsequent log for that document.
    3.  Re-verify the entire chain to restore the "Verified" status.
-   **Purpose:** Provides a recovery path for data integrity issues without requiring manual database editing.

---

## Analytics Snapshot Strategy

### The Fallback
**Scheduled Database Metric Snapshots**

### Implementation
-   **Command:** `php artisan dts:snapshot-db-metrics` (Scheduled for every 5 minutes).
-   **Table:** `database_metrics`

### How it Works
-   **Backend:** Querying MySQL's `Performance Schema` is computationally expensive. Instead of running these queries every time an admin opens the dashboard, a background task captures the "snapshot" of health metrics.
-   **Frontend:** The Admin Dashboard loads pre-calculated rows from the `database_metrics` table using `Cache::remember`.
-   **Purpose:** Ensures the Admin Dashboard remains responsive (sub-100ms load times) even if the database itself is under heavy transactional load.
