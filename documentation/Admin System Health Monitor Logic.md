# Admin System Health Monitor Logic

The System Health Monitor is a centralized dashboard for administrators to oversee the technical well-being, data integrity, and performance of the Document Tracking System.

## Table of Contents
1. [System Health Overview](#system-health-overview)
2. [Database Performance Monitoring](#database-performance-monitoring)
3. [Database Integrity (The Trust Builder)](#database-integrity-the-trust-builder)
4. [Admin Utilities](#admin-utilities)
5. [Technical Implementation Details](#technical-implementation-details)

---

## System Health Overview

The top section of the monitor provides an immediate snapshot of the application's operational status through three key metrics:

### 1. Avg. Processing Time
*   **What it is:** The average duration from the moment a document is "Accepted" by a Records Officer to when it is "Released."
*   **Logic:** It calculates the time difference between the `MIN(created_at)` and `MAX(created_at)` for documents that have both an acceptance and a release log entry.
*   **Purpose:** Helps administrators identify if the overall department workflow is slowing down.

### 2. Failed Jobs
*   **What it is:** A count of background tasks (like report generation or AI learning) that encountered errors and failed to complete.
*   **Logic:** Queries the `failed_jobs` table. Administrators can "Resolve" jobs (deleting them from the list) or "Clear All" once investigated.
*   **Visibility:** The detailed table of failed jobs (including error messages and timestamps) is **conditionally displayed**; it only appears on the dashboard when the count is greater than zero.
*   **Purpose:** Ensures that background processes are running smoothly and haven't stalled quietly.

### 3. Cache Status
*   **What it is:** A real-time test of the application's caching layer (Redis or File-based).
*   **Logic:** The system attempts to `put` a value into the cache and immediately `get` it back. If successful, the status is "Operational."
*   **Purpose:** Caching is critical for dashboard performance; a failure here could lead to significant slowdowns.

---

## Database Performance Monitoring

This section tracks the efficiency of the database server using metrics collected every 5 minutes.

### Key Metrics Tracked
-   **Connections:** The number of active database connections. High counts can indicate connection leaks or extremely high traffic.
-   **Average Query Time (ms):** The average execution time of all SQL queries. Upward trends suggest missing indexes or inefficient query logic.
-   **Slow Queries:** The count of queries taking longer than 1 second. Ideally, this should always be zero.

### Data Collection Strategy
1.  **MySQL Performance Schema:** The system queries MySQL's internal performance tables to gather statistics.
2.  **Snapshot Command:** The Artisan command `php artisan dts:snapshot-db-metrics` captures these values.
3.  **Scheduled Task:** This command is scheduled to run every 5 minutes via the Laravel Scheduler.
4.  **Time-Series Chart:** Data is visualized using Chart.js, allowing admins to toggle between Daily, Weekly, and Monthly views.

---

## Database Integrity (The Trust Builder)

The "Trust Builder" is the system's most advanced security feature, ensuring that document history is immutable and hasn't been tampered with at the database level.

### How it Works (Simple Explanation)
Imagine every document's history is like a **physical chain**. Every time someone performs an action (receives, forwards, releases), a new link is added.
-   Each link contains a "digital fingerprint" (a **Hash**) of the current action.
-   Critically, each link also includes the fingerprint of the **previous link**.
-   If anyone tries to "change" a link in the middle of the chain, its fingerprint changes, which breaks the connection to the next link.

### The Verification Process
When an admin clicks **"Run Verification"**:
1.  The system starts at the very first log entry for every document.
2.  It re-calculates the "fingerprint" for every single action and checks if it matches the one stored in the database.
3.  If a mismatch is found, it means the data was modified outside of the application's normal workflow (e.g., someone manually edited the database).

### A Simple Scenario
-   **Step 1:** A document is received by Department A. Fingerprint: `abc123`.
-   **Step 2:** It's forwarded to Department B. Fingerprint: `def456` (this fingerprint "knows" about `abc123`).
-   **Tamper Attempt:** An intruder manually changes the "Received Date" in Step 1. The fingerprint for Step 1 would now naturally be `xyz789`.
-   **Detection:** The "Trust Builder" sees that Step 2 expects the previous link to be `abc123`, but it finds `xyz789`. The chain is broken, and the system flags it as **Mismatched**.

> **Detailed Technical Guide:** For a deep dive into the SHA-256 algorithm and the code-level implementation of this feature, see [Document Hashing & Chain Logic.md](./Document Hashing & Chain Logic.md).

---

## Admin Utilities

### 1. Backup Manager
Accessible via the "Backup Manager" utility, this tool manages the physical safety of the system's data.
-   **Powered by:** `spatie/laravel-backup`.
-   **Features:** Create manual backups, download existing ZIP archives, and trigger a system-wide restore.
-   **Storage:** Backups are stored securely in the `storage/app/backups` directory.

### 2. Client Ratings & Feedback
This utility provides a qualitative look at system performance from the perspective of the users (guests).
-   **Rating System:** 1 to 5 stars.
-   **Feedback:** View comments and ratings for documents that have reached the "Released" status.
-   **Analytics:** Displays a distribution (e.g., how many 5-star vs 1-star ratings) to help identify departments that may need workflow improvements.

---

## Technical Implementation Details

-   **Controller:** `app/Http/Controllers/SystemHealthController.php`
-   **Service:** `app/Services/DatabasePerformanceService.php`
-   **Frontend:** `resources/views/admin/system-health.blade.php` & `resources/js/system-health.js`
-   **Metrics Table:** `database_metrics`
-   **Integrity Storage:** Verification results are cached using `Cache::put('integrity-check-result', ...)` to avoid re-running expensive checks on every page load.
