# System Health & Analytics Logic

This document details the Document Tracking System's (DTS) logic for performance monitoring, data integrity verification ("Trust Builder"), and resource management.

## Table of Contents
1. [The "Four Pillars" of the Development Environment](#the-four-pillars-of-the-development-environment)
2. [Database Performance Snapshots (5-Minute Cycles)](#database-performance-snapshots-5-minute-cycles)
3. [The "Trust Builder" Hashing Logic](#the-trust-builder-hashing-logic)
4. [Scalability: The 1 Million Document Scenario](#scalability-the-1-million-document-scenario)
5. [Analytics Calculation & Data Summarization](#analytics-calculation--data-summarization)

---

## The "Five Pillars" of the Development Environment

To maintain a fully functional DTS development environment, five separate processes must run concurrently. These are optimized for a **12-thread CPU architecture** using `taskset` for core pinning.

| Process | Core Pin | Responsibility | Dependency |
|:---|:---|:---|:---|
| **Backend Server** | 0-3 | Handles HTTP requests, Blade rendering, and controller logic. | PHP / Laravel |
| **Queue Listener** | 4 | Processes asynchronous tasks like PDF reports and AI learning. | Redis/DB Queue |
| **Pail (Logs)** | 5 | Provides a high-resolution, real-time feed of application logs and errors. | Laravel Pail |
| **Frontend (Vite)** | 6-7 | Real-time CSS/JS compilation and secure asset serving. | Node.js / Vite |
| **Task Scheduler** | 8 | Triggers periodic tasks like DB metrics snapshots and document pruning. | PHP Scheduler |

---

## CPU Core Pinning Strategy

To prevent "process starvation" where one intensive task (like generating a massive report or compiling large CSS files) slows down the entire system, the DTS uses **Process Affinity** (`taskset`).

- **Web Server (4 Threads):** Prioritizes user request handling to ensure a "snappy" UI.
- **Queue & Scheduler:** Isolated to dedicated single threads to handle background processing without impacting the web server's responsiveness.
- **Vite:** Allocated 2 threads for efficient asset bundling during development.
- **Reserved Cores (9-11):** Left unassigned to allow the Operating System and Browser to run smoothly.

---

## Database Performance Snapshots (5-Minute Cycles)

The system automatically captures database performance metrics every 5 minutes via the `dts:snapshot-db-metrics` command.

### Data Collection
The snapshot captures:
-   **Connections:** Active `Threads_connected` in MySQL.
-   **Average Query Time (ms):** Average time taken for database operations.
-   **Slow Queries:** Number of queries exceeding the performance threshold.

### Storage & Density
With a 5-minute cycle, the system generates **12 records per hour** (288 per day, ~105,120 per year).
-   **Seeded Data:** The `DocumentSeeder.php` simulates this density by generating multiple metrics snapshots for every document created, resulting in a rich, high-density historical dataset for the charts.
-   **Performance Note:** While the `database_metrics` table can grow large, the system is designed to handle this through background processing and optimized retrieval.

---

## The "Trust Builder" Hashing Logic

The "Trust Builder" uses SHA-256 hash-chaining to ensure the document log's immutability.

-   **Hashing Process:** Every document action (Intake, Transfer, Receive, Release) generates a unique SHA-256 hash. This hash is created by combining the log's data (including the user's **Digital Signature**) with the hash of the *previous* log entry, forming a cryptographic chain.
-   **Verification:** The `dts:verify-integrity` command performs a system-wide audit by recalculating and comparing every hash in the chain, including the validation of digital signatures for each action. It uses `chunkById(1000)` to keep memory usage low and constant.
-   **Security:** If any hash or signature is mismatched, the system identifies the exact point of tampering, reports a verification percentage, and provides tools for an Administrator to rebuild the chain or freeze the affected document.

---

## Scalability: The 1 Million Document Scenario

What happens when the system grows to **1,000,000 documents** (approx. 5,000,000 log entries)?

### 1. Verification Runtime
-   **Estimated Time:** Verifying 1,000 logs takes ~100ms. 
-   **Total Duration:** Verifying 5 million logs would take approximately **500 seconds (8.3 minutes)**.
-   **Optimization:** Verification is a background process, and the results are cached for 24 hours.

### 2. Storage & Memory
-   **Storage:** 5 million logs require **~2.5 GB of database storage** for the audit trail alone.
-   **Memory (RAM Tuning):** The system automatically tunes MySQL's **InnoDB Buffer Pool to 4GB** and the **Redo Log File to 1GB** upon startup (`composer dev`). This ensures the entire index tree for 1,000,000 documents resides in RAM, achieving sub-millisecond lookups.
-   **Memory Usage:** The `chunkById` strategy ensures application-level memory usage remains **constant** regardless of the total number of logs.

---

## Analytics Calculation & Data Summarization

The `DatabasePerformanceService` handles the challenge of high-density data by summarizing it for the charts:

### Summarization Logic
To prevent the charts from becoming unreadable or slow, the service groups the 5-minute snapshots:
-   **Daily View:** Groups data by day (Last 30 days) and calculates the **Average** of connections and query times.
-   **Weekly View:** Groups data by the start of the week (Last 12 weeks).
-   **Monthly View:** Groups data by month (Last 12 months).

```php
// Summarization example from DatabasePerformanceService.php
$connectionsData = $results->map(fn($group) => $group->avg('connections'));
$avgQueryTimeData = $results->map(fn($group) => $group->avg('avg_query_time_ms'));
$slowQueriesData = $results->map(fn($group) => $group->sum('slow_queries'));
```

This ensures that even with hundreds of thousands of data points, the user only sees a clear, summarized trend.
