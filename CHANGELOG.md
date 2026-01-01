# Changelog

All notable changes to this project will be documented in this file.

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