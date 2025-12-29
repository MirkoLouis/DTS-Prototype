# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased] - 2025-12-29

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