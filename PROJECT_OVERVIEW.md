# DTS Prototype - Project Overview

This document provides a detailed overview of the DepEd Iligan Document Tracking System (DTS) prototype, its architecture, and the implementation of its core features and innovations.

## 1. Project Goal

The primary goal of this project is to create a modern, efficient, and secure web application to replace manual processes for tracking official documents. It is built using the latest Laravel 12 framework, leveraging its powerful features like the Eloquent ORM, Blade templating engine, and robust security practices.

## 2. System Architecture & Workflow

The application is built on a robust Role-Based Access Control (RBAC) system, enforced by a dedicated `RoleMiddleware`. This middleware ensures that users can only access the routes and dashboards appropriate for their assigned role (`admin`, `officer`, `staff`). All routes are organized into middleware groups in `routes/web.php` to guarantee security.

### 2.1. The Guest Journey (Document Submission)

1.  **Submission:** A guest visits the homepage and fills out the document submission form.
2.  **Dynamic Form:** The form dynamically shows requirements based on the selected purpose.
3.  **Creation:** The `GuestController` validates the data. For custom purposes, the `RoutePredictionService` suggests a route. A new `Document` is created with a `pending` status.
4.  **Tracking Code & QR Code:** A unique tracking code and a scannable QR code are generated and displayed on the success page, allowing the guest to proceed directly to the Public Tracking Portal.

### 2.2. The Public Tracking Portal (Guest Document Status)

1.  **Access:** Guests can visit `/track` and enter one or more tracking codes to view document statuses.
2.  **Modular & Dynamic Display:** The page shows each document's status in a separate "card" module, complete with a visual "subway map" of its progress. Guests can add more documents to the view by typing a code or scanning a QR code, all without a full page reload.
3.  **Feedback:** Once a document is `completed`, the tracking card is replaced with a 1-5 star rating form, allowing the guest to provide feedback on the service.

### 2.3. The Records Officer Journey (`officer` role)

1.  **Login & Redirection:** Upon login, the `RoleMiddleware` redirects the officer to their primary dashboard at `/intake`.
2.  **Security Initialization:** On first login, the officer must initialize their department's **Security Key**. This key is used to "sign" their actions, providing cryptographic proof of authorization (Non-Repudiation).
3.  **Permissions:** Officers have access to all routes within the `role:officer` and `role:officer,staff` groups. This includes intake, releasing, return requests, and task management.
4.  **Intake (`/intake`):** The officer finds pending documents by tracking code, using search/filter tools or the integrated QR scanner.
5.  **Route Management:** On the "Manage Route" page, the officer can edit the document's route (using a drag-and-drop interface), decline the submission, or finalize the route.
6.  **Finalization:** Clicking "Accept & Finalize Route" changes the document status to `in_transit`, saves the route, and creates the first secure entry in the document's hash-chain log. This entry includes a **State Hash** of the document metadata to protect against tampering. The system also "learns" from any manual route corrections to improve future AI predictions.
7.  **Task Management (`/tasks`):** Officers can also view and process document tasks assigned to their own department (the Records Unit).
8.  **Releasing (`/releasing`):** When a document has completed its journey, it returns to the Records Officer, who scans it to mark it `ready_for_release` and then `completed` upon handoff to the client.

### 2.4. The Staff Journey (`staff` role)

1.  **Login & Redirection:** Upon login, the `RoleMiddleware` redirects the staff member to their dashboard at `/tasks`.
2.  **Security Initialization:** Like officers, staff members must initialize a unique department security key upon their first login to enable cryptographic signing of actions.
3.  **Permissions:** Staff members have access to routes within the `role:staff` and `role:officer,staff` groups.
4.  **Receive Document:** When a physical document arrives, the staff member scans its QR code to change its status from `in_transit` to `processing`, which adds it to their department's queue. This action is cryptographically signed by the department.
5.  **View Queue (`/tasks`):** The dashboard shows a list of documents currently assigned to the staff member's department.
6.  **Task Completion:** After finishing their work, the staff member clicks "Complete Step." This advances the document's `current_step`, sets its status back to `in_transit` for transfer, and creates a new, secure log in the hash chain.

### 2.5. The Admin Journey (`admin` role)

1.  **Login & Redirection:** Upon login, the `RoleMiddleware` redirects the administrator to the main analytics dashboard at `/admin-dashboard`.
2.  **Permissions:** Administrators have access to all routes within the `role:admin` group, including user management and all system utilities.
3.  **Process Analytics (`/admin-dashboard`):** The dashboard is a comprehensive, multi-section interface providing a deep-dive into system performance and potential bottlenecks. 
    *   **Main Overview:** A top-level, three-column view showing the most critical at-a-glance metrics: the current **Document Status Distribution** across the entire system, the **Global Average Processing Time (hrs)** for all documents, and a "Top 5 Fastest Depts. (Avg)" chart to immediately highlight efficient departments.
    *   **Returns & Declines Analysis:** A dedicated section that groups together charts for **Return & Decline Rate Trends** and **Return Request Sources**, allowing for focused analysis on why documents are being rejected or sent back.
    *   **Department Drill-Down:** An interactive section containing a powerful **Load vs. Processing Time** combination chart. This dual-axis chart allows admins to select a specific department and time period to visually correlate the number of documents received (load) with that department's average internal processing time, making it easy to see how workload impacts efficiency. The chart's title updates dynamically to reflect the selected department.
    *   **Purpose-Based Analysis:** A full-width chart showing **Processing Hotspots by Purpose** helps identify if specific *types* of documents are causing systemic delays.
5.  **System Monitoring & Database Performance (`/system-health`):**
    -   **Real-Time Metrics:** A dedicated section on the System Health page provides a live, time-series overview of database health.
    -   **Key Indicators:** The system tracks active **Connections**, **Average Query Time (ms)**, and **Slow Queries** (queries > 1s).
    -   **Data Collection:** A scheduled Artisan command (`dts:snapshot-db-metrics`) captures these metrics from the MySQL `performance_schema` every five minutes.
    -   **Historical Context:** The system includes a sophisticated seeder that generates years of correlated historical performance data, allowing for immediate trend analysis.
    -   **Data Export:** Administrators can export the captured performance metrics as a CSV file for external analysis.

6.  **System Utilities:** From the main navigation, the admin can access specialized pages:
    *   **System Health Monitor (`/system-health`):** The "Trust Builder" tool for running on-demand integrity checks of the hash chain and managing data recovery.
    *   **Client Ratings Dashboard (`/system/ratings`):** A view of all client feedback and satisfaction scores.
    *   **Backup Manager (`/system/backups`):** The "Safety Net" for creating, downloading, and managing database backups.
    *   **Integrity Monitor (`/integrity-monitor`):** A raw, searchable view of the entire `document_logs` table for deep-dive analysis.

### 2.6. The Return Request Workflow

1.  **Access:** Any staff member can navigate to the dedicated "Return Requests" page.
2.  **Request Form:** The page provides a form to input a document's tracking code and a mandatory reason for the return request.
3.  **Dynamic Route Modification:** Upon submission, if the document is in an active processing state (`processing` or `in_transit`), the system dynamically modifies its `finalized_route`. The requesting staff member's department is injected into the route immediately after the document's current step. This effectively "reroutes" the document to the requesting department.
4.  **Logging:** A `DocumentLog` entry is created, detailing the reroute request, the requesting department, and the reason provided.
5.  **Receiving:** The requesting department will then need to physically receive the document and scan its QR code (via their dashboard's "Receive Document" section) to pull it into their queue for processing.


## 3. Core Innovations in Detail

### 3.1. Security: Enhanced Hash-Chaining & Non-Repudiation

- **Implementation:** Handled robustly by the `DocumentLog` model's `boot()` method using a `sha256` algorithm and a standardized `ISO-8601` timestamp format.
- **Enhanced Mechanism:** The system protects not just the log history, but the document metadata and the identity of the actor:
    1.  **Chaining:** Finds the most recent log's hash to use as the `previous_hash`.
    2.  **State Protection:** Calculates a **Document State Hash** — a SHA-256 hash of the document's current metadata (title, submitter, etc.). This is included in the log entry.
    3.  **Identity Verification:** Each action is associated with a **Digital Signature** based on the department's initialized security key.
    4.  **Final Hash:** Creates a unique data string combining the log data, `previous_hash`, and the `document_state_hash`, then hashes it to create the new log's `hash`.
- **Benefit:** This creates an unbreakable and verifiable chain. Any alteration to a log entry *or* a silent modification of the document's title/submitter details will break the chain, immediately revealing tampering. The use of digital signatures ensures non-repudiation, providing cryptographic proof of who authorized each movement. The integrity of this chain can be verified at any time using the System Health Monitor tool.

### 3.1.1. On-Demand Integrity Verification (The "Trust Builder")

To build trust and provide concrete proof of the system's data integrity, an on-demand verification tool is built into the Admin's "System" page.

- **Implementation:** `dts:verify-integrity` Artisan command, `SystemHealthController`, and the `/system-health` dashboard view.
- **Mechanism:**
    1. An administrator clicks the "Run Verification" button on the `/system-health` page.
    2. An AJAX request triggers the `dts:verify-integrity` command on the backend.
    3. The command iterates through every document's log chain, recalculates the `sha256` hash of each log based on its stored data (including the precise ISO-8601 timestamp), and compares it to the `hash` value stored in the database.
    4. The result (e.g., "100% Verified"), the timestamp of the check, and a list of any mismatched log IDs are cached.
    5. After the check is complete, the browser reloads the page, displaying the fresh results. If there are errors, a paginated table of the invalid logs is shown.
- **Benefit:** This feature provides a powerful, transparent way to prove to stakeholders, auditors, or a thesis panel that the document history is immutable and has not been tampered with. It moves the concept of data integrity from a theoretical promise to a demonstrable reality.

### 3.2. AI: Database-Driven Route Prediction and Learning

The route prediction system has been upgraded from a hardcoded, code-based logic to a flexible and intelligent database-driven system. This demonstrates advanced database concepts like weighted querying and makes the system maintainable without code changes.

- **Implementation:** `prediction_keywords` table, `app/Services/RoutePredictionService.php`, and `app/Jobs/UpdateKeywordWeights.php`.
- **Prediction:** When a guest submits an "Other" purpose, the `GuestController` now prepends "Others: " to the custom text. The `RoutePredictionService` then tokenizes this text and queries the `prediction_keywords` table. It calculates a `score` for each department by summing the `weight` of all matched keywords. The suggested route is then generated by ordering the departments by their score.
- **Learning:** When a Records Officer finalizes a route for a non-official purpose that differs from the system's suggestion, the `DocumentController` dispatches an `UpdateKeywordWeights` job. This background job analyzes the text and increments the weight of the associated keywords for the departments in the officer's manually chosen route, allowing the system's future predictions to become more accurate over time.

### 3.3. HCI: Interactive User Interfaces

- **Implementation:** `resources/views/welcome.blade.php`, `resources/views/documents/manage.blade.php`, `resources/views/track.blade.php`, `resources/views/tasks.blade.php`, `resources/views/intake.blade.php`, `resources/views/integrity-monitor.blade.php`, `resources/views/return-requests/index.blade.php`. All frontend libraries (`html5-qrcode`, `SortableJS`, `Chart.js`) are locally managed via NPM and compiled with Vite. UI components for session messages and QR scanner modals are now reusable Blade components.
- **Features:** The system prioritizes a smooth user experience through: 
- **Consistent User Feedback:** Centralized `success`, `error`, and `info` messages displayed consistently across all dashboards with auto-hide functionality.
- **Dynamic Requirements:** The guest form provides immediate feedback by showing requirements as soon as a purpose is selected.
- **Drag-and-Drop Route Editor:** Officers can intuitively re-order complex document routes.
- **Visual Tracking (Subway Map):** The `x-tracker-subway-map` Blade component provides a clear, at-a-glance visualization of a document's progress.
- **Modular & Dynamic Tracking Portal:** Guests can track multiple documents dynamically.
- **Responsive Dashboard Layouts:** All main tables (`/intake`, `/tasks`, `/integrity-monitor`) automatically switch to a user-friendly card view on mobile devices.
- **Copy Hash Functionality:** Hashes on the Integrity Monitor can be easily copied to the clipboard.

- **Enhanced QR Code Integration:** To streamline interactions, QR code scanning (via a reusable component) is integrated into dedicated "Receive Document" sections on the `/intake`, `/tasks`, and `/releasing` dashboards for quick, camera-based document processing and status updates.

### 3.4. Performance: Asynchronous Large-Scale Exports

- **Implementation:** `app/Jobs/GenerateReportJob.php`, `iio/libmergepdf`, and AJAX polling logic in `statistics.blade.php`.
- **The Challenge:** Generating a PDF report for 10,000+ documents with charts is a memory-intensive task that causes standard PHP servers to time out or crash (Out of Memory).
- **The Solution:** 
    1.  **Background Processing:** Report generation is dispatched to a background queue, preventing the web server from becoming unresponsive.
    2.  **The Merge Strategy:** To keep RAM usage low, the system processes documents in batches of 500. Each batch is saved as a temporary PDF file on disk. Once all batches are complete, they are "stitched" together into one final file.
    3.  **Database Chunking:** The system uses database chunking to ensure it never holds more than 500-1000 models in memory at once.
    4.  **CSV Fallback:** For datasets that are too large even for optimized PDF generation (e.g., 20,000+), the system provides a high-speed CSV export option.
    5.  **Progress Tracking:** A real-time modal polls the database to show the user exactly which "milestone" the server is currently working on, along with a calculated time estimate.
- **Benefit:** This architecture transforms a potentially system-crashing task into a stable, background-ready feature that can handle "Enterprise-level" data volumes.

## 4. Automated System Maintenance

To ensure database health and prevent the accumulation of stale data, the system includes automated maintenance tasks.

### 4.1. Pruning Pending Documents

- **Implementation:** `app/Console/Commands/PrunePendingDocuments.php` scheduled in `routes/console.php`.
- **Mechanism:** A scheduled Artisan command, `documents:prune-pending`, runs daily. This command automatically finds and deletes any documents that have remained in the `pending` status for more than two weeks.
- **Benefit:** This prevents the database from being cluttered with abandoned document requests that were never processed, ensuring the system remains efficient.

## 5. Testing Strategy for Integrity Verification

To ensure the reliability and trustworthiness of the hash-chaining mechanism, a dedicated testing strategy has been implemented. This allows developers to simulate data corruption and verify that the system's integrity checks correctly identify tampering.

### 5.1. Simulated Data Corruption Tool

- **Implementation:** `dts:corrupt-log {logId}` Artisan command (`app/Console/Commands/CorruptDocumentLog.php`).
- **Mechanism:** This command allows an administrator or developer to intentionally modify a specific `DocumentLog` entry (e.g., changing its 'action' field) in the database. Since the 'action' field is part of the hash calculation, this deliberate change will break the hash chain for that particular log and all subsequent logs in its chain.
- **Benefit:** Provides a controlled method for creating a known point of failure, which is essential for testing the "Trust Builder" functionality.

### 5.2. Automated Integrity Test Suite

- **Implementation:** `tests/Integrity/IntegrityCheckTest.php` (PHPUnit test).
- **Mechanism:** This test suite performs the following steps:
    1.  Starts with a fresh, seeded database to ensure a clean state.
    2.  Runs the `dts:verify-integrity` command and asserts that it reports 100% integrity.
    3.  Uses the `dts:corrupt-log` command to deliberately corrupt a random `DocumentLog` entry.
    4.  Runs `dts:verify-integrity` again and asserts that it now correctly reports a failure, indicating the specific corrupted log(s).
- **Benefit:** This automated test provides continuous validation that the hash-chaining security feature is working as intended, assuring that any data tampering will be detected.

## 6. Administrative Recovery Workflow

When the System Health Monitor detects a mismatched hash, it is not an error to be "fixed" automatically, but an alert that requires administrative action. The system provides a suite of tools for this investigation and recovery process.

### 6.1. Investigation and Triage

-   **View Data:** The "View" button next to a mismatched log allows the administrator to see the full details and complete history of the affected document. This is the first step in any investigation.
-   **Freeze/Unfreeze:**
    -   The "Freeze" button changes a document's status to `frozen`. This is a critical first step to prevent any further actions on a document while it is under investigation.
    -   Once an issue is resolved, the "Unfreeze" button (which conditionally replaces "Freeze") reverts the document's status to `processing`, allowing it to continue its workflow. Both actions are logged in the document's history.

### 6.2. Chain Rebuilding

-   **Mechanism:** The "Rebuild Chain" button triggers the `dts:rebuild-chain {logId}` command for the specific corrupted log. The command executes the following logic:
    1.  **Finds the Last Good Link:** It identifies the last valid log in the document's chain before the point of corruption.
    2.  **Iterative Re-hashing:** Starting with the corrupted log, it recalculates its hash based on its current data and the last good hash.
    3.  It then proceeds sequentially through all subsequent logs for that document, re-calculating each one's hash based on the newly fixed hash of the one before it.
    4.  **Logs the Action:** A final log entry is created to record that an administrator performed the rebuild, maintaining a transparent audit trail.
-   **Automatic Re-verification:** After a successful rebuild, the system automatically triggers the `dts:verify-integrity` command again. This updates the system cache, and upon page reload, the fixed log is removed from the "Mismatched" list, giving the administrator immediate confirmation of a successful repair.

## 7. Client Feedback and Service Quality

To ensure continuous improvement and measure client satisfaction, the DTS includes an integrated feedback mechanism. This closes the loop on the service delivery process, turning a simple tracking system into a tool for quality assurance.

### 7.1. Client-Side Rating

-   **Implementation:** `resources/views/components/document-card.blade.php`, `DocumentController@rate`.
-   **Mechanism:** Once a document's status is updated to `completed` (i.e., after it has been released by the Records Officer), the public tracking page for that document automatically changes. The final status message is replaced with a "Thank You" message and an interactive 1-5 star rating form.
-   **User Experience:** The client can click on a star to submit their rating. The submission is handled seamlessly via an AJAX request, preventing a page reload. Once the rating is submitted, the form is replaced by a confirmation message. This can only be done once per document.

### 7.2. Administrative Feedback Dashboard

-   **Implementation:** `SystemRatingsController.php`, `resources/views/system/ratings.blade.php`.
-   **Mechanism:** A new "Ratings" page, accessible only to administrators from the main navigation, provides an overview of all client feedback.
-   **Features:** The dashboard presents key statistics, including:
    -   Total number of ratings submitted.
    -   The overall average rating across all services.
    -   A breakdown of how many 1, 2, 3, 4, and 5-star ratings have been received.
    -   A paginated list of every rated document, showing its tracking code, purpose, and the rating it received.
-   **Benefit:** This provides the administration with direct, quantitative data on service performance, helping to identify areas of excellence and opportunities for improvement.

## 8. Data Redundancy: The "Safety Net" Backup Manager

To provide administrators with peace of mind and an enterprise-grade safety net against data loss, a simple yet powerful Backup Manager is integrated into the system.

-   **Implementation:** `BackupManagerController.php`, `spatie/laravel-backup` package.
-   **Access:** The Backup Manager is accessible via the "System Utilities" section on the main Admin Dashboard.
-   **On-Demand Backups:** Administrators can trigger an on-demand database backup at any time. To ensure the UI remains responsive, this action is queued and runs in the background. A success message notifies the admin that the backup is being created.
-   **Backup Management:** The page displays a list of all available backup files, sorted with the newest first. Each entry shows the file name, size, and creation date.
-   **Download & Restore:**
    -   **Download:** A "Download" link is available for every backup, allowing an administrator to save a copy of the `.zip` backup file to their local machine for archival.
    -   **Delete:** A "Delete" button is provided to remove specific backup files directly from the UI, protected by a confirmation dialog.
    -   **Restore (Safeguarded):** A "Restore" button is present but is intentionally disabled by default. Clicking it opens a modal that explains the destructive nature of restoring from a backup and requires the user to confirm they wish for the full one-click restore functionality to be built. This acts as a crucial safeguard in the prototype.
