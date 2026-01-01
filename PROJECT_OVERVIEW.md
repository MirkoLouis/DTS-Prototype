# DTS Prototype - Project Overview

This document provides a detailed overview of the DepEd Iligan Document Tracking System (DTS) prototype, its architecture, and the implementation of its core features and innovations.

## 1. Project Goal

The primary goal of this project is to create a modern, efficient, and secure web application to replace manual processes for tracking official documents. It is built using the Laravel framework, leveraging its powerful features like the Eloquent ORM, Blade templating engine, and robust security practices.

## 2. System Architecture & Workflow

The application is designed around a role-based access control system, providing distinct workflows for different user types.

### 2.1. The Guest Journey (Document Submission)

1.  **Submission:** A guest visits the homepage, which contains the document submission form.
2.  **Dynamic Form:** The guest selects a purpose from a dropdown list. A JavaScript front-end immediately displays the list of required documents for the selected purpose. An "Other" option allows the user to specify a custom purpose.
3.  **Creation:** Upon submission, the `GuestController` validates the data. If an "Other" purpose is submitted, the `RoutePredictionService` is used to generate a suggested route. A `Document` record is created with a `pending` status.
4.  **Tracking Code:** A unique tracking code (e.g., `DEPED-A1B2C3D4E5`) is generated and displayed to the user on a success page.
5.  **QR Code Generation:** Alongside the tracking code, a scannable QR code is also generated and displayed on the success page, allowing guests to easily save or share their tracking information. From here, the guest can directly proceed to the Public Tracking Portal.

### 2.2. The Public Tracking Portal (Guest Document Status)

1.  **Access:** Guests can access the tracking portal directly from the success page or by navigating to `/track` and entering a tracking code. The URL can support tracking multiple documents simultaneously (e.g., `/track?codes=CODE1,CODE2`).
2.  **Modular Display:** Each tracked document is displayed as a distinct "card" module, showing its details, current status, and a visual tracking history (the "subway map").
3.  **Dynamic Addition:** Via an AJAX-powered modal, guests can add more tracking codes to the current page. The new document's status module is dynamically appended, and the browser's URL is updated without a full page reload, providing a seamless experience.
4.  **Visual Progress:** The `x-tracker-subway-map` component visually represents the document's journey through its finalized route, highlighting completed, current, and upcoming steps.
5.  **Granular Status Updates:** The tracking history provides clear, distinct messages for each phase of the document's journey: one for when it's being processed (the subway map), another for when it has finished internal processing and is ready for release, and a final one with the feedback form when the document has been completed.

### 2.3. The Records Officer Journey (Intake & Route Management)

1.  **Login:** The Records Officer logs in and is redirected to the `/intake` dashboard. They can also navigate to the `/tasks` dashboard via a navbar link to process documents assigned to their department.
2.  **Lookup:** On the `/intake` dashboard, they can enter a tracking code to find a pending document.
3.  **Management:** Upon finding a pending document, the officer is taken to the "Manage Route" page, which provides two primary actions:
    a. **Route Editing:** View, re-order, add, or delete steps in the suggested route.
    b. **Decline Document:** Permanently delete a pending document from the system. This is used for submissions that are spam, duplicates, or cannot be processed.
    c. **QR Code Scanning:** On the `/intake` dashboard, officers can activate a modal window to use their webcam or phone camera to scan QR codes. This automatically populates the tracking code field and submits the form for instant lookup.
4.  **Finalization:** Clicking "Accept & Finalize Route" (handled by `DocumentController@finalize`):
    a. Updates the document's status to `processing`.
    b. Saves the `finalized_route` to the document's record.
    c. **Creates the first `DocumentLog` entry**, with hash chain initialized via the `DocumentLog` model's `boot()` method.
5.  **Learning Mechanism:** If the officer's finalized route differs from the purpose's original suggested route, the system updates the purpose with the new, improved route, making future predictions more accurate.
6.  **Document Releasing:** After a document has passed through all departments in its `finalized_route`, it returns to the Records Officer on a new "Releasing" page. Here, the officer can see all documents ready for client pickup. Once the client has received their document, the officer clicks "Release Document," which marks the document as `completed` and creates the final log entry, ending its lifecycle.

### 2.4. The Staff Journey (Processing)

1.  **Login:** A staff member logs in and is redirected to the `/tasks` dashboard.
2.  **View Queue:** The dashboard displays a list of documents currently in the `processing` state, **filtered to show only those documents where the current step in the `finalized_route` is assigned to the logged-in staff member's department.**
3.  **Task Completion:** Staff can click "Complete Step" for an assigned document. This action:
    a. Increments the `current_step` on the document.
    b. Updates the document's `status` to 'completed' if all steps in the route are finished.
    c. Creates a `DocumentLog` entry, logging the completion and advancing the hash chain.

### 2.5. The Admin Journey (Analytics, Integrity & System Health)

The administrator role now has a streamlined navigation structure:

1.  **Admin Dashboard (Process Analytics):**
    -   The main admin dashboard, located at `/admin-dashboard`, provides process analytics. This includes:
        -   A "Bottleneck Detector" (bar chart) visualizing current document load at each department.
        -   "Throughput" (line chart) showing documents processed over time (daily, weekly, monthly, yearly).
    -   This dashboard is the default landing page for administrators.
    -   A new "System Utilities" section on this dashboard provides quick access to:
        -   **System Health Monitor ("Trust Builder"):** (at `/system-health`) This page houses the "Trust Builder" tool and application health metrics. An admin can click "Run Verification" to trigger a complete, on-demand integrity check of the entire hash chain. If mismatched hashes are found, a paginated table of invalid logs is displayed with recovery tools.
        -   **Client Ratings Dashboard:** (at `/system/ratings`) Provides an overview of all client feedback, including total ratings, average rating, and a paginated list of all rated documents.
        -   **Backup Manager ("The Safety Net"):** (accessible via a dedicated link from the Admin Dashboard) Allows administrators to create on-demand database backups, download existing backups, and delete old backups.

2.  **Document Integrity (Monitor):**
    -   Accessible via a new top-level navigation tab, this section (at `/integrity-monitor`) displays a raw, searchable view of the `document_logs` table.
    -   It allows the admin to monitor all actions taken on all documents and includes a powerful AJAX-powered search to filter logs by tracking code, action, user, or hash. A "View" button is available next to each log entry, linking directly to the document's detailed view.

## 3. Core Innovations in Detail

### 3.1. Security: Hash-Chaining

- **Implementation:** Handled robustly by the `DocumentLog` model's `boot()` method using a `sha256` algorithm and a standardized `ISO-8601` timestamp format.
- **Mechanism:** Before a new `DocumentLog` is saved, the `boot()` method:
    1.  Finds the most recent log for the same document to retrieve its hash, which becomes the `previous_hash` for the new log. ("genesis_hash" is used for the first entry).
    2.  Creates a unique data string by combining the new log's data (document ID, user ID, action, timestamp) with the `previous_hash`.
    3.  Hashes this unique string to create the new log's `hash`.
- **Benefit:** This creates an unbreakable and verifiable chain. Any alteration to a log entry would break the chain, immediately revealing tampering. The integrity of this chain can be verified at any time using the System Health Monitor tool.

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
- **Prediction:** When a guest submits an "Other" purpose, the `RoutePredictionService` tokenizes the text and queries the `prediction_keywords` table. It calculates a `score` for each department by summing the `weight` of all matched keywords. The suggested route is then generated by ordering the departments by their score.
- **Learning:** When a Records Officer finalizes a route for a non-official purpose that differs from the system's suggestion, the `DocumentController` dispatches an `UpdateKeywordWeights` job. This background job analyzes the text and increments the weight of the associated keywords for the departments in the officer's manually chosen route, allowing the system's future predictions to become more accurate over time.

### 3.3. HCI: Interactive User Interfaces

- **Implementation:** `resources/views/welcome.blade.php`, `resources/views/documents/manage.blade.php`, `resources/views/track.blade.php`, `resources/views/tasks.blade.php`, `resources/views/intake.blade.php`, `resources/views/integrity-monitor.blade.php`.
- **Features:** The system prioritizes a smooth user experience through:
    - **Dynamic Requirements:** The guest form provides immediate feedback by showing requirements as soon as a purpose is selected.
    - **Drag-and-Drop Route Editor:** Officers can intuitively re-order complex document routes.
    - **Visual Tracking (Subway Map):** The `x-tracker-subway-map` Blade component provides a clear, at-a-glance visualization of a document's progress.
    - **Modular & Dynamic Tracking Portal:** Guests can track multiple documents dynamically.
    - **Responsive Dashboard Layouts:** All main tables (`/intake`, `/tasks`, `/integrity-monitor`) automatically switch to a user-friendly card view on mobile devices.
    - **Copy Hash Functionality:** Hashes on the Integrity Monitor can be easily copied to the clipboard.
    - **Consistent Scrollbar:** A always-visible vertical scrollbar provides visual consistency across pages.
    - **QR Code Integration:** Seamlessly generates QR codes for document tracking numbers on the success page and allows officers to scan these codes via a modal window for quick lookup and processing on the intake page.

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
