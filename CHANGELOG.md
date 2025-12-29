# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased] - 2025-12-30

### Added
- **Automated Maintenance:**
    - Created a scheduled Artisan command `documents:prune-pending` that runs daily.
    - This command automatically deletes `pending` documents older than two weeks to maintain database health.

### Changed
- **Records Officer Workflow:**
    - Records Officers can now decline and permanently delete a pending document from the 'Manage Route' page.

## [Previous Version] - 2025-12-29

### Added
- **Core Document Routing & Task Management:**
    - `User` Model: Added `department()` relationship for easier access to user's department.
    - `TaskController@complete()` method: Implemented logic to advance a document's `current_step`, update its status (to 'completed' if route finishes), and create a `DocumentLog` entry.
    - `routes/web.php`: Added `POST /tasks/{document}/complete` route (`tasks.complete`) for task completion.
    - Responsive card view for "Recently Handled Documents" on `/intake` page for mobile devices.
    - Copy-to-clipboard functionality for document hashes on the Integrity Monitor.

### Changed
- **User Management:**
    - `UserSeeder`: Consolidated 'Records Officer' and 'Records Staff' into a single `records@dts.com` user with 'officer' role.
    - `UserSeeder`: Admin user (`admin@dts.com`) now has `department_id` set to `null` to reflect system-wide role.
    - `UserSeeder`: All other departments have a dedicated 'staff' user (`[department_name]@dts.com`).
- **Document Log Hashing:**
    - **Refactored:** Moved hash-chaining logic from `DocumentLogObserver` to `DocumentLog` model's `boot()` method for robustness, ensuring hashes are always calculated even when model events are suppressed (e.g., during seeding).
    - `AppServiceProvider`: Removed redundant `DocumentLogObserver` registration.
    - `DocumentLog` Model: Corrected `$fillable` properties to include `remarks` and allow manual assignment of `hash` and `previous_hash` for seeding.
    - `DocumentSeeder`: Rewritten to use `DocumentFactory`, create 5 pending documents, and 10 processing documents with initial `DocumentLog` entries (manually hashing due to seeding event suppression).
    - `DocumentFactory`: Created to generate realistic dummy `Document` data for seeding.
- **UI/UX & Responsiveness:**
    - `integrity-monitor.blade.php`:
        - Removed hash truncation.
        - Eliminated horizontal scrollbar for desktop table by allowing `hash` and `previous_hash` to wrap (`break-all`, `max-w-xs`).
        - Implemented responsive design: table on desktop, card view on mobile.
    - `tasks.blade.php`:
        - Updated `index()` method to filter documents based on logged-in user's department and document's `current_step`.
        - Implemented responsive design: table on desktop, card view on mobile.
        - Eliminated horizontal scrollbar for desktop table by allowing content to wrap (except Tracking Code and Submitted date).
    - `partials/intake-table.blade.php`:
        - Eliminated horizontal scrollbar for desktop table by allowing content to wrap (Tracking Code, Submitter, Purpose, Status, Date Handled).
        - Fixed responsive visibility issues between table and card views.
    - `layouts/navigation.blade.php`:
        - Added "Tasks" link for 'officer' role in navbar.
        - Improved "Dashboard" link active state logic for better UX across roles.
    - `resources/css/app.css`: Added global `overflow-y: scroll;` to `body` for consistent scrollbar visibility.
    - `AuthenticatedSessionController`: Changed post-logout redirect from `/` to `/login`.

### Fixed
- **Critical:** `DocumentLogObserver` not firing during seeding, causing `hash` to be null. Fixed by moving logic to model's `boot()` method and manually hashing in `DocumentSeeder`.
- Duplicate IDs for "View Route" details in `partials/intake-table.blade.php` causing JS issues.
- `intake.blade.php` and `tasks.blade.php` tables not being responsive on mobile.
- Conflicting user roles in `UserSeeder` causing officer account to be downgraded to staff.
- Inaccurate "Dashboard" active state in navbar.
- Horizontal scrollbars on desktop tables (`/integrity-monitor` and `/intake`, `/tasks`).

## [Current Version] - 2025-12-29

### Added
- **Public Tracking Portal:**
    - New `/track` route to display document status using query parameters (e.g., `?codes=CODE1,CODE2`).
    - `GuestController@track` method to fetch multiple documents based on `codes` query parameter.
    - `track.blade.php` view for the public tracking portal.
    - `GuestController@getTrackedDocumentModule` method and API route (`/api/track-document/{tracking_code}`) to fetch and render single document cards via AJAX.
    - **`x-tracker-subway-map` Blade Component:** Renders a horizontal progress bar visualizing document route progress.
    - **`x-document-card` Blade Component:** Reusable component to display a single document's status, details, and tracking history.
    - "Track Another Document" button with modal for dynamic, AJAX-driven addition of documents to the tracking page.

### Changed
- `success.blade.php`: "Track Your Document" button now links directly to the multi-document tracking portal (`/track?codes=...`).
- `GuestController@track` method signature updated to accept `Request $request` and process query parameters.
- `routes/web.php`: Updated `/track` route to accept query parameters and added `/api/track-document/{tracking_code}` API route.
- `track.blade.php` visual consistency: Rewritten to use Bootstrap 5 for consistency with other public pages and a wider, "landscape" layout.

### Fixed
- "Track Your Document" button on `success.blade.php` now correctly redirects to the tracking portal.
- Prevented duplicate document tracking: If a tracking code is already on the page, the modal now displays a "This document is already being tracked here" message.

## [Older Changes] - 2025-12-26

### Added
- **Initial Project Setup:**
    - Initialized Laravel 11 project.
    - Integrated Laravel Breeze for authentication scaffolding.
    - Configured MySQL database connection.
- **Database Schema:**
    - Created migrations for `users`, `departments`, `purposes`, `documents`, and `document_logs` tables.
    - Added `role` and `department_id` to `users` table.
    - Added `finalized_route` and `current_step` to `documents` table.
    - Added `hash` and `previous_hash` to `document_logs` table for integrity chain.
    - Added `is_official` boolean flag to `purposes` table.
- **Role-Based Access:**
    - Implemented `CheckRole` middleware to redirect users (`admin`, `officer`, `staff`) to their respective dashboards.
- **Dashboards:**
    - Created placeholder dashboards for Records Officer (`/intake`), Staff (`/tasks`), and Admin (`/integrity-monitor`).
    - Implemented data tables on all dashboards to display relevant documents and logs.
- **Guest Portal:**
    - Created a guest submission form with dynamic display of requirements based on purpose selection.
    - Implemented "Other" purpose feature allowing users to input a custom purpose.
- **Records Officer Features:**
    - Implemented tracking code lookup on the `/intake` dashboard.
    - Created a "Manage Route" page with a drag-and-drop interface (using SortableJS).
    - Added functionality to add new steps to a route from a list of departments.
    - Added functionality to delete steps from a route.
- **Security Innovation: Hash-Chaining:**
    - Created `DocumentLogObserver` to automatically calculate and link integrity hashes for all document logs upon creation.
- **AI Innovation: Route Prediction & Learning:**
    - Created `RoutePredictionService` to suggest a route for "Other" purposes based on keyword matching.
    - Implemented a "learning" mechanism where the system updates an official purpose's `suggested_route` if an officer modifies it.
- **Seeders:**
    - Created seeders for `Departments`, `Users`, `Purposes`, and `Documents`.
    - Populated seeders with comprehensive, realistic data for purposes and departments.
- **Documentation:**
    - Created `README.md`, `CHANGELOG.md`, and `PROJECT_OVERVIEW.md`.

### Changed
- Refactored `GuestController` to fetch official purposes from the database instead of using dummy data.
- Upgraded tracking code algorithm to be based on a hash of user data and time for better uniqueness.
- Refactored `TaskController` to only show documents with a 'processing' status.
- Renamed "Accept" button to "Manage" for clarity in the intake workflow.

### Fixed
- **Critical:** Resolved a persistent `ParseError` related to a corrupted controller file by recreating the file.
- **Critical:** Fixed a `UrlGenerationException` caused by a typo (`trackingCode` vs `tracking_code`) when redirecting to the success page.
- **Critical:** Fixed a `QueryException` (unknown column 'hash') by creating a migration to align the `document_logs` schema with the observer's logic.
- Fixed a bug where submitting a duplicate "Other" purpose would fail due to a unique constraint.
- Fixed a bug where "pending" documents were incorrectly appearing on the staff dashboard.
- Fixed a bug where user-created "Other" purposes were incorrectly appearing in the main dropdown list for all users.
- Fixed a UI bug where drag-and-drop was not working due to text selection interference.
- Fixed a redirect loop on the `/tasks` dashboard.