# Changelog

All notable changes to this project will be documented in this file.

## [1.8.3-Alpha+202603062120] - 2026-03-06

### ADDED
- **Proactive Security Guard (Scheduled Integrity Checks):** Transitioned the "Active State Comparison" from a manual tool to a fully automated background process. The `dts:verify-integrity` command is now scheduled to run every 10 minutes via the Laravel Task Scheduler.
- **Automated Security Response (Auto-Freeze):** Implemented a real-time response system that automatically sets a document's status to `frozen` if any integrity mismatch is detected during the background scan.
- **Intelligent Recovery System:** Enhanced the administrative `unfreeze` logic to automatically restore a document to its last valid lifecycle state (e.g., `in_transit`, `ready_for_release`) based on its chronological history.
- **Security Audit Logs (Auto-Freeze):** Every system-initiated freeze is now recorded in the `document_logs` with a "System Auto-Freeze" action and detailed security remarks.

### CHANGED
- **Admin UI Enhancement:** Added intuitive "Freeze/Unfreeze Document" buttons to the main document details view for manual administrative intervention.
- **Non-Repudiation UI Integration:** Standardized all critical document actions to use the secure, masked `SigningModal` for cryptographic authorization.

### FIXED
- **Lifecycle Recovery Consistency:** Resolved a bug where unfrozen documents would default to 'processing' regardless of their actual previous state.

## [1.8.2-Alpha+202603062110] - 2026-03-06

### ADDED
- **Active State Comparison (Live Tamper Detection):** Implemented a real-time verification layer in the `dts:verify-integrity` command. The system now compares the *current live database state* of every document against the `document_state_hash` recorded in its most recent cryptographic log.
- **Tampering Detection UI:** Enhanced the System Health Monitor with a dedicated "Live State Mismatches" section that highlights documents whose details (title, submitter, route) have been modified without a corresponding signed log entry.
- **Automated Integrity Status Card:** Updated the dashboard to show a dual-status overview: Chain Integrity (Historical Logs) vs. Live State (Current Documents), providing a complete picture of system health.

### CHANGED
- **Enhanced Verification Logic:** Refactored `VerifyIntegrityChain` into a two-step process: (1) Historical Chain Verification and (2) Active State Comparison, ensuring both past and present data are protected.
- **Refined Integrity Reporting:** Updated the integrity check cache to store specific tracking codes of tampered documents, enabling targeted investigation by administrators.

### FIXED
- **Document Detail Inconsistencies:** Standardized the state hashing formula to include all critical metadata (tracking_code, title, submitter info, district, department, purpose, and route), closing potential gaps in document protection.

## [1.8.1-Alpha+202603062101] - 2026-03-06

### FIXED
- **Cryptographic Salt Derivation:** Resolved a `sodium_crypto_pwhash` error by ensuring the salt is exactly 16 bytes (SODIUM_CRYPTO_PWHASH_SALTBYTES). Implemented a deterministic 16-byte binary hash of the user's email as the salt.
- **PIN Entry Privacy:** Replaced standard browser `prompt()` calls with a custom `x-signing-modal` utilizing `type="password"`. This ensures Security PINs are masked with dots and never displayed as raw text during entry.
- **Initialization UX:** Enabled "Enter" key submission in the digital signature initialization modal and added automatic focus to the PIN input for a smoother onboarding experience.
- **Database Schema Completeness:** Added the missing `private_key` column to the `users` table and synchronized the `User` model's `$fillable` and `$casts` attributes to support encrypted key storage.

### ADDED
- **True Cryptographic Signatures (Ed25519):** Upgraded the "Trust Builder" system from simple string placeholders to a robust Ed25519 signing architecture. Actions are now mathematically proven using the performing user's private key.
- **Encrypted Private Key Storage:** Implemented a secure key management flow where Ed25519 private keys are encrypted using a user-defined Secret PIN via `sodium_crypto_secretbox` (Argon2id key derivation) before being stored.
- **Secure Signing Modal:** Created a reusable `<x-signing-modal />` component to handle secure PIN collection and callback-based transaction signing across the application.
- **Signature Helper Methods:** Added `signAction` and `verifySignature` to the `DocumentLog` model, and a `sign()` helper to the `User` model to encapsulate complex `sodium` operations.

### CHANGED
- **Mandatory Signing Workflow:** Critical lifecycle events (Route Finalization, Task Completion, and Document Releasing) now strictly require a Security PIN to authorize and sign the transaction.
- **Streamlined Physical Handoff:** Removed the PIN requirement when "receiving" documents for release. This reduces friction in the physical workflow while maintaining strict signing requirements for the final "Released" state.
- **Ledger Verification Formula:** Updated the hash-chaining logic to bake the new cryptographic Ed25519 signatures into the SHA-256 block hash, ensuring absolute non-repudiation.

## [1.8.0-Alpha+202603061900] - 2026-03-06

### FIXED
- **Integrity Protection Logic:** Corrected a critical flaw in `DocumentLog::calculateStateHash` where submitter information was being hashed as `null` due to missing properties on the `Document` model. Updated the logic to use new model accessors that correctly extract data from the `guest_info` JSON field.
- **Admin Path Traversal:** Patched a security vulnerability in `BackupManagerController` where unsanitized `fileName` parameters allowed potential directory traversal. Implemented `basename()` sanitization for all download and delete operations.
- **Broken Object Level Authorization (BOLA):** Secured document detail routes by transitioning from incremental database IDs to unique `tracking_code` strings for route model binding and implementing a robust `DocumentPolicy`.
- **SortableJS Integration:** Resolved an `Uncaught ReferenceError: Sortable is not defined` by explicitly importing `sortablejs` in the main application bundle and utilizing the global `window.Sortable` object in the UI.
- **RBAC Caching Conflict:** Fixed a bug where role-specific navigation menus were being incorrectly cached across different user sessions. Updated `CacheResponse` middleware to use `private` visibility and `no-store` directives.
- **Circular Navigation Loop:** Resolved a "Back" button trap in the document details view by implementing a persistent `back_to` parameter that intelligently redirects users to their appropriate dashboard (Intake, Tasks, Releasing, or Completed) even after viewing the hash chain.
- **Robust Redirection:** Enhanced `DocumentController@show` with role-aware fallback logic to ensure the "Back" button always leads to a valid, authorized page, preventing 403 errors during navigation.

### ADDED
- **Document Access Policy:** Created `App\Policies\DocumentPolicy` to centralize and enforce granular access rules for viewing, managing, and processing documents based on user roles and departmental assignments.
- **Document State Accessors:** Added `submitter_name`, `submitter_email`, and `submitter_phone` accessors to the `Document` model for cleaner data handling and improved integrity verification.
- **Context-Aware Navigation Links:** Integrated `back_to` query parameters across all administrative tables to provide a seamless "View Details -> Back to List" user experience.

### CHANGED
- **Non-Enumerable Routing:** Reconfigured the `Document` model to use `tracking_code` as the primary route key, preventing unauthorized users from discovering documents through ID guessing.
- **Global Asset Availability:** Updated `app.js` to expose `Sortable` and `Html5Qrcode` to the global scope, ensuring high-interactivity components work reliably across all Blade templates.

## [1.7.5-Alpha+202603061830] - 2026-03-06

### FIXED
- **Environment Port Synchronization:** Resolved a conflict where shell-level `APP_URL` variables were overriding local `.env` settings. Explicitly declared the `APP_URL` within Composer scripts to ensure consistent environment behavior.

### ADDED
- **Production Orchestration Script:** Introduced `composer prod`, a high-performance entry point that utilizes `queue:work` (instead of `listen`) and the `--no-reload` flag. This allows for benchmarking the system's maximum throughput in a "Day 1" clean state.

### CHANGED
- **System-Wide Port Standard:** Reconfigured the default application port to **3050** (from 3000) across all configuration files, documentation, and automated scripts to prevent common local service conflicts.
- **Multithreaded Optimization:** Enabled the `--no-reload` flag for the production-mode server to fully utilize the `PHP_CLI_SERVER_WORKERS=4` configuration, significantly increasing concurrent request handling.

## [1.7.4-Alpha+202603032120] - 2026-03-03

### FIXED
- **SQL Ambiguity in Analytics:** Resolved a critical `500 Internal Server Error` in the `AdminDashboardController` caused by an ambiguous `created_at` column in join-heavy analytics queries. Explicitly referenced subquery aliases in `getAvgStepTimeByDepartmentData` and `getDepartmentalLoadVsTimeData` to restore dashboard functionality.

### ADDED
- **In-Depth Technical Documentation Suite:** Consolidated 15+ individual documentation files into three authoritative guides (`ARCHITECTURE.md`, `ADMINISTRATION.md`, `OPERATIONS.md`). Each guide was enriched with technical deep-dives and relevant code snippets (RBAC Middleware, Hash-Chaining logic, AI Routing, etc.).
- **Hardware & Scaling Guide:** Created `HARDWARE_SPECS.md` providing a comprehensive breakdown of server requirements (Minimal vs. Recommended) and storage forecasts for scaling the system to 1 million documents.

### CHANGED
- **Migration Squashing (Schema Consolidation):** Refactored the database architecture by squashing 21 separate migration files into a single, clean `2025_01_01_000000_create_dts_initial_schema.php`. This significantly reduces project overhead and simplifies the initialization process for new environments while maintaining all performance indexes and security fields.

## [1.7.3-Alpha+202603032041] - 2026-03-03

### FIXED
- **Neutralized the "RAM Trap":** Completely refactored `AdminDashboardController` and `StatisticsController` to use SQL-level aggregation (`COUNT`, `GROUP BY`, `JSON_EXTRACT`) and MySQL 8.0 Window Functions (`LAG`). This prevents the 3-minute lag by ensuring 1M+ records are pre-aggregated by the database instead of being hydrated as Eloquent models.

### ADDED
- **Universal Browser Caching:** Applied `cache.response:55` middleware to all key administrative and staff routes (Intake, Tasks, Releasing, Statistics, Integrity Monitor, etc.). This enables sub-second navigation by serving pages and data from the browser cache.
- **Dynamic Real-time Polling:** Integrated 60-second AJAX polling into the Admin Dashboard and Statistics pages to ensure analytics stay current without manual refreshes.

## [1.7.2-Alpha+202603031030] - 2026-03-03

### FIXED
- **Multithreaded:** Apparently taskset was justa way to separate cores for specific tasks, with PHP_CLI_SERVER_WORKERS=4 the server (php artisan serve) is now truly multithreaded.

## [1.7.1-Alpha+202603031030] - 2026-03-03

### ADDED
- **HTTP Header Optimization (Cache-Control):** Implemented a `CacheResponse` middleware to enable 55-second HTTP browser caching for guest-facing routes and AJAX status endpoints, drastically reducing redundant server hits during peak traffic.
- **Automated Database Tuning Command:** Created the `dts:tune-db` Artisan command to programmatically optimize MySQL's InnoDB Buffer Pool (4GB) and Redo Log (1GB) using project credentials.
- **Cross-Platform Troubleshooting Guides:** Added specific recovery steps for "Address already in use" port conflicts on both Linux and Windows (PowerShell/CMD) to the project documentation.
- **Enhanced Guest Layout:** Integrated cache-control meta tags and DNS prefetching into the `guest.blade.php` layout for faster initial page rendering.

### FIXED
- **Infinite Process Execution:** Set `process-timeout: 0` in `composer.json` to prevent Composer from terminating long-running nested server pillars after 300 seconds.
- **Concurrent Script Resolution:** Refactored the `concurrently` orchestration to use `composer run <command>` syntax, ensuring sub-scripts are correctly located across different environments.

## [1.7.0-Alpha+202603030930] - 2026-03-03

### ADDED
- **Database Performance Indexing (O(log n) scaling):** Implemented critical composite and single indexes on high-traffic tables (`documents`, `document_logs`). This ensures sub-second query performance even with 1M+ records by optimizing status filtering, analytics aggregation, and hash-chain verification.
- **CPU Core Pinning (Taskset Multi-Threading):** Developed a sophisticated thread allocation strategy for 12-thread architectures. Critical services (Web Server, Queue, Vite, Scheduler) are now pinned to dedicated CPU cores, preventing process starvation and ensuring high UI responsiveness during heavy background tasks.
- **Resilient Background Service Wrappers:** All development and production scripts are now wrapped in `while true` loops with 10-second automatic recovery, providing a "self-healing" infrastructure for the dev environment.
- **Dedicated Service Control Scripts:** Introduced separate Composer commands for granular service management (`serve:dev`, `queue:dev`, `logs:dev`, `vite:dev`, `schedule:dev`), allowing developers to isolate logs and debug individual layers in separate terminals.
- **Production Optimization Suite:** Added `prod:optimize` and `prod:clear` commands to manage Laravel's configuration, route, and view caches, significantly reducing framework overhead for performance benchmarking.

### CHANGED
- **High-Performance Production Worker:** Updated `queue:work-prod` to use a long-lived process with a 512MB memory limit, enabling 10x faster job processing compared to standard listeners.
- **Concurrent Development Entrypoint:** Refactored the main `composer dev` command to orchestrate the new taskset-pinned sub-scripts.

## [1.6.1-Alpha+202603011900] - 2026-03-01

### FIXED
- **Initialization Modal Bug:** Resolved a critical issue where the security modal failed to spawn because users were being pre-seeded with public keys. Removed pre-seeded keys to ensure all real users undergo the initialization flow.

### ADDED
- **Granular Departmental Fallbacks:** Introduced dynamic, role-based fallback signatures (e.g., `signed_by_cash_unit`, `signed_by_records`) for the ledger, ensuring detailed accountability even for historical or un-onboarded data.

### CHANGED
- **Synchronized Fallback Logic:** Updated both the `DocumentLog` model and `DocumentSeeder` to use consistent, department-specific signature strings when a unique public key is not yet available.

## [1.6.0-Alpha+202603011830] - 2026-03-01

### ADDED
- **Universal Non-Repudiation System:** Implemented a cryptographic enforcement layer that prevents any authorized user (Staff, Officer, or Admin) from denying their actions within the system.
- **Department & Administrative Digital Signatures:** Integrated unique, user-initialized public keys into the `DocumentLog` hash-chain. Every movement in the ledger is now "signed" and cryptographically immutable.
- **Dynamic Security Key Initialization:** Enhanced the `security-key-modal` component to provide a personalized experience, displaying the specific account name (e.g., "Admin User Digital Signature") during setup.
- **Admin Signature Enforcement:** Closed the administrative security gap by requiring Admins to initialize their own digital signatures for high-level operations like hash-chain rebuilding.

### FIXED
- **Integrity Verification Logic:** Updated the `dts:verify-integrity` and `dts:rebuild-chain` Artisan commands to correctly handle and verify the new `signature` and `document_state_hash` fields.
- **Seeder Cryptographic Alignment:** Synchronized `UserSeeder` and `DocumentSeeder` to generate 100% valid hash chains using the new signature-based formula, ensuring simulated data is production-grade.

### CHANGED
- **Hashing Formula Update:** Expanded the SHA-256 hashing sequence to include the `signature` field as a mandatory component for chain validity.
- **Model-Level Automation:** Refactored the `DocumentLog` model to automatically fetch and attach the performing user's digital signature during the `creating` event.

## [1.5.1-Alpha+202602281645] - 2026-02-28

### FIXED
- **PDF Tracking Form Template:** Corrected a `ParseError` (syntax error, unexpected identifier "images") in `resources/views/general/tracking-form-pdf.blade.php`. Resolved by replacing Blade `{{ }}` syntax with proper PHP string concatenation within the `@php` block.

### ADDED
- **Comprehensive Visual Documentation:** Updated `README.md` with 10+ new high-resolution screenshots in a categorized, multi-column layout, showcasing the Administrative Suite, Records Officer operations, and Staff dashboards.
- **Sample Output Documentation:** Added direct links in the `README.md` to generated sample PDF outputs (`Tracking Form` and `Historical Report`) to demonstrate the system's document generation capabilities.
- **Expanded Default Accounts:** Updated the "Default Accounts" section of the `README.md` to include the complete list of 15+ predefined accounts for all department units (Cash, Budget, Accounting, etc.) to assist in system testing and evaluation.

## [1.5.0-Alpha+202602281530] - 2026-02-28

### ADDED
- **Environment-Specific Migration Tool:** Created a custom `php artisan dts:migrate` command to streamline project initialization.
    - `--devseed`: Runs a fresh migration with the full `DevelopmentSeeder`, including 10,000+ dummy documents and metrics for testing.
    - `--prodseed`: Runs a fresh migration using the new `ProductionSeeder`, creating only essential system data (Departments, Users, AI Keywords) for a clean production state.
- **Production Seeder:** Implemented `ProductionSeeder.php` to provide a "Day 1" clean database configuration for DepEd Iligan.
- **Comprehensive AI Knowledge Base:** Expanded `PredictionKeywordSeeder` to include keywords for all 14 departments and units, ensuring the AI Route Prediction works division-wide from the first document.

### CHANGED
- **Environment-Aware Purposes:** Updated `PurposeSeeder` to automatically exclude "System Test" purposes when the application is running in a production environment.
- **Deployment Strategy:** Updated `DEPLOYMENT.md` with platform-specific instructions for Linux (Supervisor/Cron) and Windows Server (NSSM/Task Scheduler).

### FIXED
- **Department Naming Consistency:** Synchronized department names between the `DepartmentSeeder` and `PredictionKeywordSeeder` to prevent missing keyword associations.

## 2026-02-25 (Wednesday, 9:30 PM)

### ADDED
- **Document State Hashing:** Enhanced the security chain by including a `document_state_hash` in every `DocumentLog`. This SHA-256 hash protects document metadata (title, submitter, etc.), ensuring any "silent" modifications to the document itself are detected.
- **Digital Signatures (Non-Repudiation):** Each department now initializes a unique security key upon their first login. Every document movement is cryptographically "signed," providing non-repudiation and proof of authorization.
- **Security Key Initialization Modal:** Created a new `x-security-key-modal` component that automatically guides department staff through the security key setup process on their first official login.

### CHANGED
- **Enhanced Security Verification:** Updated the `dts:verify-integrity` Artisan command to include the new `document_state_hash` in its chain verification logic.
- **Seeder Security Upgrade:** Refactored `DocumentSeeder` to generate cryptographically valid chains that include the new state hashes and dummy signatures, ensuring seeded data is 100% integral.

### FIXED
- **Security Key Initialization:** Resolved a `JSON.parse` error by adding the `Accept: application/json` header to the initialization request.
- **Key Validation:** Adjusted the minimum public key length in `SecurityKeyController` to 30 characters to match the system's generated key format.
- **Modal Interactivity:** Removed the `readonly` restriction on the security key field and added a "Regenerate" button, allowing departments to more actively "decide" their cryptographic identity or provide their own.

## 2026-02-23 (Monday, 6:45 PM)

### ADDED
- **Asynchronous Report Generation System:** Implemented a robust, non-blocking background job system for generating large-scale PDF and CSV reports.
- **Merge Strategy for Large PDFs:** Optimized memory usage for massive reports by processing documents in small batches (500-1000 docs), generating temporary PDF chunks, and merging them using the `iio/libmergepdf` library.
- **CSV Export Option:** Added a high-speed, memory-efficient CSV export option using database chunking, providing a reliable alternative for extremely large datasets.
- **Real-Time Progress Modal:** Integrated a dynamic UI modal that tracks background job progress with specific status updates ("Filtering documents", "Generating PDF pages", etc.) and a real-time "Estimated time remaining" calculation.
- **Report Cancellation:** Added functionality to safely cancel a running background report generation from the UI, preventing wasted server resources.

### CHANGED
- **Smart Export Constraints:** Implemented an intelligent validation layer that enforces a 3,000-document limit for PDF generation to ensure server stability, automatically suggesting the CSV option for larger datasets.
- **Optimized Database Querying:** Refined data fetching logic to only select necessary columns and use relationship constraints, drastically reducing the RAM footprint during data preparation.
- **Enhanced PDF Layout:** Reorganized report layout to accommodate large horizontal charts, utilizing strategic page breaks and fixed table dimensions for a polished, professional presentation.

### FIXED
- **Documentation Alignment:** Standardized system ports (3001) and framework version (Laravel 12) across all documentation files (`README.md`, `PROJECT_OVERVIEW.md`, `WINDOWS_SETUP.md`).
- **Command Accuracy:** Corrected the signature for the database restore command (`db:restore --file=`) and added missing documentation for `dts:snapshot-db-metrics` in `ARTISAN_COMMANDS.md`.
- **RBAC Clarity:** Updated `RBAC_DOCUMENTATION.md` to reflect the correct dashboard route for staff members (`/staff-tasks`).

## 2026-02-22 (Sunday, 3:30 PM)

### CHANGED
- **Enhanced Filtering and User Experience:** Implemented immediate filter application, debounced search, and case-insensitive text searching across several administrative tables for improved usability.
    - **ReleasingController:**
        - Added filtering by search term (tracking code, title), purpose, submitter name, and date to the `index` method.
        - Updated `resources/views/officer/releasing/index.blade.php` to include filter UI elements and JavaScript for AJAX-based filtering and pagination.
    - **UserManagementController:**
        - Added filtering by name, email, and role to the `index` method.
        - Updated `resources/views/admin/users/index.blade.php` to include filter UI elements, implement debounced search for text inputs, and immediate submission for select inputs. The "Filter" button was removed.
    - **SystemHealthController:**
        - Made the search for tracking code and user name in the `index` method case-insensitive for the mismatched logs table.
        - Updated `resources/views/admin/system-health.blade.php` to include a "per page" selector, auto-submit filters on change, and removed the filter button.
    - **SystemRatingsController:**
        - Added filtering by rating, purpose, and date to the `index` method.
        - Updated `resources/views/admin/system/ratings.blade.php` to include filter UI elements, auto-submit filters on change, and removed the filter button.
    - **BackupManagerController:**
        - Expanded the search functionality in the `index` method to include the `last_modified` date, in addition to the file name, with case-insensitive matching.
        - Updated `resources/views/admin/backups.blade.php` to implement a debounced search for the file name/date input and removed the filter button.

## 2026-02-19 (Thursday, 13:45 PM)


### ADDED
- **Windows Setup Guide:** Created `WINDOWS_SETUP.md` with comprehensive instructions for setting up the project on Windows, including prerequisites (XAMPP/Laragon), Git configuration (autocrlf), and `mkcert` installation.
    - Added a **Verification** section with commands (`php -v`, `node -v`, etc.) to confirm prerequisite versions.
- **Database Performance Monitoring System:** Implemented a new performance tracking system for administrators to monitor database health.
    - Added `DATABASE_PERFORMANCE_METRICS.md` to document the chart logic and metrics.
    - Created `SYSTEM_MONITORING_LOGIC.md` explaining the background data generation.
    - Introduced `dts:snapshot-db-metrics` Artisan command (scheduled every 5 minutes) to capture connections, average query time, and slow queries.
    - Enhanced System Health page with an interactive time-series chart for these metrics.
- **Database Metrics Export:** Admins can now download historical performance data as CSV from the System Health page.

### FIXED
- **Performance Data Retention:** Implemented automatic cleanup of old metrics to prevent database bloat.
- **Admin Dashboard UI:** Refined chart labels and standardized layout across analytics pages.

## [1.4.0] - 2026-01-30

### Added
-   **QR Code-Powered Physical Document Workflow:** Implemented a comprehensive physical document handoff system utilizing QR codes for tracking. Documents now move through new `in_transit`, `processing`, and `ready_for_release` statuses, requiring physical scanning by responsible departments at each stage.
-   **Universal Scan Functionality:** Integrated `DocumentController@scan` as a central, robust endpoint for all document receiving actions initiated via QR code or manual input across various dashboards.
-   **"Return Request" Feature:** Introduced a dedicated "Return Requests" page and workflow (`/return-requests`) allowing any department to dynamically inject themselves into a document's route for corrections or re-processing, providing a flexible non-linear process.
-   **Initial Guest Submission Log:** A "genesis" log entry is now created immediately upon guest document submission, providing a complete audit trail from the moment of creation.
-   **Test Purpose for Full Route:** Added a "System Test: Full Route" purpose to the `PurposeSeeder`, automatically including all departments, for comprehensive end-to-end testing of the physical workflow.
-   **Reusable Flash Message Component:** Created a `flash-messages.blade.php` component to centralize the display and auto-hiding logic for `success`, `error`, and `info` session messages across the entire application.
-   **Reusable QR Scanner Modal Component:** Extracted the common HTML structure for the QR scanner modal into a reusable `qr-scanner-modal.blade.php` component.

### Changed
-   **Document Statuses:** Introduced `in_transit` (document physically moving between departments) and `ready_for_release` (document ready for final collection by client) statuses.
-   **Records Officer Intake Workflow:** Adjusted `DocumentController@finalize` to set the initial document status to `in_transit` after route finalization.
-   **Department Staff Processing Workflow:** Modified `TaskController@complete` to set document status to `in_transit` after a step is processed.
-   **Document Releasing Workflow:** Updated `ReleasingController` to specifically query documents with the `ready_for_release` status and integrated a "Receive Document" section with manual input and QR scanning functionality onto the `/releasing` page.
-   **Guest Success Page:** Enhanced `success.blade.php` to clearly instruct guests to print the mandatory Document Tracking Form, which includes the document's QR code.
-   **Scanner UI/UX:** Relocated the universal scan functionality from the main navigation bar to dedicated "Receive Document" sections on the `/tasks` and `/releasing` dashboards, mirroring the design and functionality of the `/intake` page.
-   **Flash Message Display:** Integrated the global `flash-messages` component into `app.blade.php`, removing redundant session message blocks from individual views (`intake`, `tasks`, `releasing`, `return-requests`).
-   **QR Scanner Modal Display:** Replaced hardcoded QR scanner modal HTML in `intake`, `tasks`, and `releasing` views with the new `<x-qr-scanner-modal />` component.
-   **`ReturnRequestController@store` Logic:** Refactored status validation to use a `switch` statement, providing more granular and consistent feedback messages for different document states.

### Fixed
-   **Scan Error Handling:** Refined error handling in `DocumentController@scan` to provide specific, contextual feedback messages (e.g., "already processing," "pending intake," "frozen") instead of silent redirects for invalid scan attempts.
-   **Records Officer Releasing Bug:** Corrected an issue that prevented Records Officers from receiving documents for release by fixing an incorrect user role check in `DocumentController@scan`.
-   **Feedback Message Inconsistencies:** Standardized `info` and `error` message types and content across `/intake`, `/tasks`, `/releasing`, and `/return-requests` pages for all document status feedback.
-   **"Double Error!" Bug:** Fixed the redundant "Error! Error!" message display on the Intake page.
-   **Missing Info Messages:** Ensured `info` flash messages are correctly displayed on the Intake page.
-   **UI Duplication:** Resolved the accidental duplication of the "Receive Document for Releasing" section on the Releasing page.
-   **Auto-Hiding Messages:** Implemented consistent auto-hide functionality for `success`, `error`, and `info` messages using the new global flash message component.

## [1.3.2] - 2026-01-25

### Development
- **Local HTTPS Setup:** Implemented a local HTTPS development environment using Vite and `mkcert`. This allows for local testing of features that require a secure context (like camera access) and provides a more robust developer experience. The setup enables access from `localhost` and other devices on the same local network.

## [1.3.1] - 2026-01-18

### Fixed
- **QR Code Scanner:** Corrected and hardened the implementation of the QR Code scanner on the guest submission page (`welcome.blade.php`). The inline JavaScript is now properly wrapped in a `DOMContentLoaded` event listener, necessary styles for the scanner modal have been added, and the logic has been updated to correctly use the locally bundled `html5-qrcode` library provided by Vite, removing a previous erroneous CDN link. Added logging and checks to improve debuggability.

## [1.3.0] - 2026-01-04

### Added
- **Guest Tracking Page: QR Code Scanner:** Added a "Scan QR Code" option to the "Track Another Document" modal on the public `/track` page, allowing guests to easily add documents to their tracking view using their device's camera.
- **Records Officer Intake: Advanced Filtering:** Implemented a comprehensive set of new filters on the `/intake` page to allow for more granular searching of recently handled documents.
    - Filters include: Submitter (dynamic list of submitters from the past week), Purpose, Status, and Date Handled.
    - Added a "Clear Filters" button to easily reset all filter inputs.

### Changed
- **Guest Submission:** Custom purposes submitted via the "Other" option on the guest form are now saved with an "Others: " prefix (e.g., "Others: My custom purpose") to distinguish them from official purposes.
- **Project Dependencies:** The `html5-qrcode` library, previously loaded via CDN on the intake page, is now installed as a local NPM package and compiled into the project's main `app.js` asset via Vite.

### Fixed
- **Routing:** Corrected a `MethodNotAllowedHttpException` by changing the `documents.finalize` route from `GET` to `POST` to match the form submission method.
- **Blade Template:** Resolved a `ParseError` on the intake page caused by a typo in a Blade directive (`@end{foreach}` was written as `@end{foreach}`).
- **PDF Generation:** Fixed an `ErrorException` in the printable tracking form (`tracking-form-pdf.blade.php`) that occurred when generating a PDF for a document with a finalized route. The error was caused by incorrectly attempting to access a `->name` property on a route step, which is a string.
- **Styling & Layout:**
    - Adjusted the styling on the printable tracking form PDF to reduce the excess vertical space above the QR code, aligning it to the top of its container.
    - Fixed the vertical alignment of the "Clear Filters" button on the intake page to ensure it aligns correctly with the other filter inputs.

## [1.2.0] - 2026-01-04

### Added
- **AJAX Polling for Releasing Page:** Implemented an AJAX polling mechanism on the `/releasing` page to automatically refresh the list of documents awaiting release every 60 seconds, ensuring the view is always up-to-date for the Records Officer.

### Changed
- **Project Dependencies:** Made the project fully standalone by removing all external CDN dependencies.
    - **Bootstrap 5:** The Bootstrap CSS/JS library, previously loaded via CDN for public pages, has been installed as a local NPM package and is now compiled into the project's assets via Vite.
    - **Figtree Font:** The Figtree web font, previously loaded from `fonts.bunny.net`, has been downloaded and is now hosted locally within the project. The `@font-face` rules are compiled into the main stylesheet via Vite.
    - **Chart.js:** The Chart.js library, previously loaded via CDN on the Admin Dashboard, has been installed as a local NPM package. The chart-building logic has been moved to a separate JavaScript file and is compiled via Vite.

## [1.1.0] - 2026-01-01

### Added
- **Admin: Backup Deletion:** Added a "Delete" button to the Backup Manager, allowing administrators to remove specific backup files directly from the UI. The action is protected by a confirmation dialog to prevent accidental deletion.
- **Admin: "The Safety Net" (Backup Manager):**
    - Integrated the `spatie/laravel-backup` package.
    - Created a "Backup Manager" dashboard for administrators, accessible from the main Admin Dashboard.
    - Admins can now create on-demand database backups, which are queued to run in the background.
    - The dashboard lists all available backup files with their size and date, and provides a "Download" button for each.
    - Includes a safety-focused modal for a potential "Restore" feature, which is disabled by default and requires explicit user confirmation to build.
- **Admin UI:** Added a "Back to System Health" button on the "System Feedback & Ratings" page for improved navigation.
- **Client Feedback: Star Rating System:**
    - Implemented a 1-5 star rating system on the public `/track` page for documents that have been `completed` (released).
    - The previous "please claim" message is replaced with a "Thank you" message and a rating form.
    - Added a new `rating` column to the `documents` table to store client feedback.
    - Created a new "Ratings" dashboard (`/system/ratings`) for administrators, accessible via a new "Ratings" navigation link.
    - The dashboard displays statistics like total ratings, average rating, and a paginated list of all rated documents.
- **Records Officer: Document Releasing Workflow:**
    - Implemented a new "Releasing" page (`/releasing`), accessible via a dedicated navigation link for Records Officers.
    - This page displays a list of documents that have completed all internal processing steps and are awaiting final release to the client.
    - Added a "Release Document" button, which marks the document's status as 'completed' and creates a final `DocumentLog` entry, officially concluding the document's lifecycle in the system.

### Changed
- **Admin UI:** Reorganized system-level pages. "System Health", "Client Ratings", and the new "Backup Manager" are now linked from a centralized "System Utilities" section on the main Admin Dashboard, and the top-level "Ratings" tab has been removed.
- **Public Tracking Page UI:** Refined the status messages on the `/track` page. It now shows a distinct "Ready for Release" message after internal processing is complete, before the document is officially released by the Records Officer.

## [1.0.0] - 2025-12-30

### Added
- **Admin: Process Analytics Dashboard ("Bottleneck Detector"):**
    - Implemented a new "Admin Dashboard" (`/admin-dashboard`) accessible via a dedicated navigation link for admin users.
    - Features a bar chart displaying the "Current Load" by department (number of documents pending at each step).
    - Includes a line chart showing "Throughput" (documents processed over time), with options to view daily, weekly, monthly, or yearly data.
    - Utilizes API endpoints (`/api/admin-dashboard/current-load`, `/api/admin-dashboard/throughput`) to fetch chart data dynamically.
- **Admin: Document Log Integrity View Button:**
    - Added a "View" button next to each document's tracking code in the Integrity Monitor log table, providing a direct link to the document's detailed view.
- **Admin: System Health Recovery Tools:**
    - Added administrative action buttons ("View", "Freeze", "Rebuild Chain") to the mismatched logs table on the System Health page.
    - Created a `dts:rebuild-chain {logId}` Artisan command to programmatically fix a broken hash chain from a specific point forward.
    - Implemented controller methods to handle freezing a document (setting its status to 'frozen') and showing a detailed view of a document and its full history, providing administrators with tools for both investigation and recovery.
- **Testing: Database Integrity Check Verification:**
    - Created a new Artisan command, `dts:corrupt-log {logId}`, to intentionally corrupt a specific `DocumentLog` entry, enabling controlled testing of the integrity verification system.
    - Implemented a dedicated PHPUnit test suite (`tests/Integrity/IntegrityCheckTest.php`) that:
        - Verifies the `dts:verify-integrity` command reports 100% success on a clean database.
        - Uses `dts:corrupt-log` to tamper with a log.
        - Asserts that `dts:verify-integrity` then correctly reports a failure with mismatched logs.
- **Admin: System Health Monitor ("Trust Builder"):**
    - Implemented a "System Health Monitor" on a new, dedicated "System" page (`/system-health`) for administrators.
    - If the integrity check fails, the page now displays a paginated table listing the specific logs that have mismatched hashes, allowing for easy identification of data anomalies.
    - Created a new Artisan command, `dts:verify-integrity`, which iterates through the entire `document_logs` table, recalculates the hash chain for each document, and compares it against the stored hashes.
    - A "Run Verification" button on the dashboard allows an administrator to trigger the integrity check on-demand.
- **Admin: Searchable Document Logs:**
    - Implemented a search and pagination functionality for the "Document Log Integrity" table on the Admin's Dashboard (`/integrity-monitor`).
    - The search covers Document Tracking Code, Action, Performed By, and Hashes.
- **Automated Maintenance:**
    - Created a scheduled Artisan command `documents:prune-pending` that runs daily to delete `pending` documents older than two weeks.
- **QR Code System:**
    - Implemented QR code generation on the `/success` page and a QR code scanner on the `/intake` page.

### Changed
- **Admin Dashboard UI:**
    - Refactored the Admin Dashboard navigation: The main "Dashboard" link now intelligently adapts its destination and active state based on the user's role. Admin users are redirected to the process analytics dashboard (`/admin-dashboard`) via this link.
    - The "Integrity Monitor" was moved from the main "Dashboard" link to its own top-level "Document Integrity" navigation link.
    - The "System Health Monitor" was moved to its own dedicated "System" page.
    - Changed the pagination for the main Document Log Integrity table from 15 to 10 items per page for consistency.
- **Document Details Page:**
    - Added a "Back" button to the document details page (`documents.show`) to easily return to the previous view (e.g., System Health).
    - Enhanced the conditional display of "Freeze" and "Unfreeze" buttons, ensuring "Unfreeze" is shown only for frozen documents and "Freeze" for unfrozen ones.
    - The "Rebuild Chain" button on the System Health Monitor is now only visible if the associated document is NOT in a 'frozen' state, enforcing the correct administrative workflow.
- **Records Officer Workflow:**
    - Records Officers can now decline and permanently delete a pending document from the 'Manage Route' page.
- **AI: Route Prediction and Learning:**
    - **Refactored:** Replaced hardcoded `if/else` logic in `RoutePredictionService` with a dynamic, database-driven system using the `prediction_keywords` table.
    - **Added:** Implemented an `UpdateKeywordWeights` background job that "learns" from routing modifications made by Records Officers.

### Fixed
- **Critical: Hash Chain Verification & Seeding:**
    - Standardized the hashing algorithm to `sha256` and the timestamp format to ISO-8601 across the `DocumentLog` model, `DocumentSeeder`, and `VerifyIntegrityChain` command to ensure consistent hash generation and verification.
    - Corrected the verification logic in the `dts:verify-integrity` command to properly iterate through each document's individual hash chain.
- **Critical: Controller & View Errors:**
    - Fixed a `ParseError` on the `/integrity-monitor` page caused by a stray closing `</x-app-layout>` tag.
    - Fixed a fatal `FatalError` on the `/system-health` page caused by a duplicate `use Illuminate\Http\Request;` statement in the `SystemHealthController`.

## [0.3.0] - 2025-12-29 (Public Tracking)

### Added
- **Public Tracking Portal:**
    - New `/track` route to display document status using query parameters.
    - `x-tracker-subway-map` and `x-document-card` Blade Components for modular display.
    - "Track Another Document" button with modal for dynamic, AJAX-driven addition of documents.

### Changed
- `success.blade.php`: "Track Your Document" button now links directly to the multi-document tracking portal.
- `track.blade.php`: Rewritten to use Bootstrap 5 for visual consistency with other public pages.

### Fixed
- Prevented duplicate document tracking via the "Track Another" modal.

## [0.2.0] - 2025-12-29 (Workflow & Responsiveness)

### Added
- **Core Document Routing & Task Management:**
    - Implemented logic in `TaskController@complete()` to advance a document's `current_step`, update its status, and create a `DocumentLog` entry.
    - Added responsive card views for tables on `/intake` and `/tasks` pages for mobile devices.
    - Added copy-to-clipboard functionality for hashes on the Integrity Monitor page.

### Changed
- **User Management & Seeding:**
    - Consolidated 'Records Officer' and 'Records Staff' into a single `records@dts.com` user.
    - Refactored `DocumentLogObserver` logic into the `DocumentLog` model's `boot()` method for robustness.
    - Rewrote `DocumentSeeder` to use a `DocumentFactory`.
- **UI/UX & Responsiveness:**
    - Implemented responsive designs for `integrity-monitor.blade.php` and `tasks.blade.php`.
    - Eliminated horizontal scrollbars on all main tables.
    - Added "Tasks" link to navbar for 'officer' role.
    - Changed post-logout redirect from `/` to `/login`.

### Fixed
- **Critical:** Resolved `DocumentLogObserver` not firing during seeding by moving logic to the model's `boot()` method.
- Fixed duplicate IDs in `partials/intake-table.blade.php`.
- Fixed various responsiveness and UI bugs across multiple pages.

## [0.1.0] - 2025-12-26 (Initial Prototype)

### Added
- **Initial Project Setup:** Laravel 11, Breeze, MySQL.
- **Database Schema:** Created all initial migrations for `users`, `departments`, `purposes`, `documents`, and `document_logs`.
- **Role-Based Access:** Implemented `CheckRole` middleware and placeholder dashboards for all user roles.
- **Guest Portal:** Created submission form with dynamic requirements display.
- **Records Officer Features:**
    - Tracking code lookup.
    - Drag-and-drop route management page.
- **Security Innovation: Hash-Chaining:** Initial implementation via `DocumentLogObserver`.
- **AI Innovation: Route Prediction & Learning:** Initial implementation of `RoutePredictionService`.
- **Seeders:** Created initial seeders for all core tables.

### Changed
- Refactored `GuestController` to use the database for purposes.
- Upgraded tracking code algorithm to be hash-based.
- Refactored `TaskController` to show only 'processing' documents.

### Fixed
- **Critical:** Resolved `ParseError` (corrupted file), `UrlGenerationException` (typo), and `QueryException` (missing column) to stabilize the application.
- Fixed bugs related to "Other" purpose submissions, incorrect document visibility, and UI issues.
