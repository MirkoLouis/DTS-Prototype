# Project Fallback Strategies & System Resilience

This document tracks the implemented fallback mechanisms designed to ensure the stability, reliability, and performance of the Document Tracking System (DTS).

## 📊 1. Queue Worker Persistence
**Fallback:** Automatic Worker Restart Loop
**Implementation:** `while true; do php artisan queue:work --timeout=1200; echo 'Queue worker stopped. Restarting in 10s...'; sleep 10; done`
**Purpose:** Ensures the high-performance queue worker (`queue:work-prod`) automatically recovers from crashes, memory exhaustion, or unexpected process termination. The 10-second sleep interval prevents rapid-fire restart loops if a persistent issue exists.

## 📄 2. Large Report Generation
**Fallback:** PDF Chunking & Disk-Buffered Merging
**Implementation:** `GenerateReportJob.php` (Chunking at 250 docs)
**Purpose:** Instead of generating one massive PDF in RAM (which would cause `ProcessTimedOutException` or memory errors), the system generates small, manageable chunks, saves them as temporary files on disk, and then merges them. This keeps memory usage low regardless of report size.

## 🔑 3. AI Route Prediction
**Fallback:** Default to "Records" Department
**Implementation:** `RoutePredictionService.php`
**Purpose:** If the AI keywords database cannot find a matching department for a given purpose text, the system defaults to assigning the document to the "Records" department for manual initial routing. This ensures the document workflow is never blocked by a failed prediction.

## 🔒 4. Security & Audit Trail
**Fallback:** Manual Hash-Chain Rebuilding
**Implementation:** `php artisan dts:rebuild-chain {logId}`
**Purpose:** If the "Trust Builder" hash-chain is corrupted (due to database error or tampering), authorized administrators have a dedicated tool to programmatically repair and re-link the cryptographic chain from a known-good point.

## 📦 5. Database Performance Metrics
**Fallback:** Scheduled Snapshot System
**Implementation:** `php artisan dts:snapshot-db-metrics` (5-minute intervals)
**Purpose:** Rather than querying intensive database metrics in real-time on every dashboard load, the system uses a scheduled task to capture snapshots. The dashboard then reads from these snapshot tables, ensuring analytics remain responsive even under high load.
