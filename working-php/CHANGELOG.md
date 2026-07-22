# Changelog

## 2026-07-22 16:11

**Version:** 1.15.5-Alpha+202607221611

### Fixed
- Reorganized standalone scratch scripts (`test_cache.php`, `test_spout.php`, `test_spout2.php`, `test_spout.xlsx`, `seed_test.js`) by moving them into `working-php/scripts/` and updating autoload requirements.
- Pruned dead commented-out route block (`/tasks` index test alias) in `public/index.php`.

### Added
- Added comprehensive inline documentation across core security and architectural files (`SecurityHelper.php`, `AuthMiddleware.php`, `RoleMiddleware.php`, `DocumentPolicy.php`, `Router.php`) following context-scaled commenting principles.


**Version:** 1.15.4-Alpha+202607221557

### Fixed
- Fixed broken `/dashboard` route by replacing missing `OfficerController` reference with a role-based redirect closure (`officer` → `/intake`, `staff` → `/tasks`, `admin` → `/admin-dashboard`).
- Resolved duplicate cache implementations by unifying `AdminDashboardController` onto `App\Core\Cache`, adding `clear()` method, and removing unused `src/Utils/Cache.php`.
- Added missing `AuthMiddleware` and `RoleMiddleware:admin` protection to `/api/admin-dashboard/current-load`.
- Extracted monolithic inline SQL from `AdminDashboardController`, `DashboardController`, and `StatisticsController` into dedicated service classes `AdminAnalyticsService` and `DepartmentAnalyticsService`.
- Fixed `StatisticsController::index` query duplication by delegating to `DocumentQueryService::getPaginatedStatistics`.
- Hardened PHP session settings (`cookie_httponly`, `cookie_secure`, `cookie_samesite=Strict`, `use_strict_mode`) in `public/index.php`.
- Resolved duplicate `/statistics` route definition in `public/index.php`.
- Converted `/integrity-monitor` into a 301 permanent redirect pointing to canonical `/all-documents`.
- Added `version` property to `Document` model and `email_verified_at`/`remember_token` properties to `User` model for 100% schema parity.
- Cleaned up obsolete TF-IDF prediction files (`RoutePredictionService.php`, `UpdateKeywordWeights.php`).

### Added
- Created global HTML escaping helper `e()` in `src/helpers.php` (autoloaded via `composer.json` and `public/index.php`) for XSS mitigation.
- Added `DocumentPolicyMiddleware.php` to allow declarative route-level policy middleware execution.
- Added centralized exception handling in `Router::dispatch()` to catch unhandled errors, log details, and display user-friendly notices or JSON responses.
- Added RESTful HTTP verb aliases (`PUT /users/{id}`, `DELETE /users/{id}`) in `public/index.php`.

## 2026-07-22 13:41

**Version:** 1.15.3-alpha+202607221341

### Fixed
- Fixed an `Invalid parameter number` PDO exception in `NotificationService.php` by uniquely naming duplicate PDO parameter bindings.
- Fixed a report generation crash in `GenerateReportJob.php` by updating `Row::fromValues` to `Row::fromValuesWithStyle` to align with the latest OpenSpout library requirements.
- Standardized the text color of the "Submitter Name" column in the Released Documents History table to match the "Title" column in the dark theme.
- Filtered out `in_transit` documents from the Staff Tasks table to ensure users only see documents that have been officially received.
- Removed the "All Departments" option from the department drill-down chart on the admin dashboard to enforce a strict department-specific view.

### Added
- None
## 2026-07-21 17:29

**Version:** 1.15.2-alpha+202607211729

### Fixed
- Fixed the subway tracking design in the guest portal (`/track`) by restructuring the UI into a 4-column responsive grid with a snaking layout, isolating elements from colliding.
- Added visual directional arrows (tails and heads) to the subway tracking map to clearly signify the start and end of document routes.
- Resolved an issue in both `scripts/fast-seed.php` and `seed.js` where declined documents were not assigned a `declined_at` timestamp, causing the Decline Rate Trends chart on the Admin Dashboard to inaccurately appear empty.
- Included the "Others" purpose into the default DB generation array inside `scripts/setup-production-db.js`, allowing seeders to properly distribute it across analytics hotspots.
- Cleaned up obsolete `SecurityHelper::cachePin()` and `clearCachedPin()` calls left over in `TaskController` and `ReleasingController`.

### Added
- None

## 2026-07-20 17:45

**Version:** 1.15.1-alpha+202607201745

### Fixed
- Completely removed the "return-requests" feature from the codebase (routes, controllers, views, layout notifications, JavaScript admin charts, service mutations, and database seeders) as it is highly unlikely to be used in a real-world workflow.

### Added
- None


## 2026-07-20 16:45

**Version:** 1.15.0-alpha+202607201645

### FIXED
- Optimized large document count queries in `IntakeController` and `DashboardController` by replacing O(N^2) correlated `NOT EXISTS` subqueries with linearly scalable `ROW_NUMBER()` CTEs.
- Fixed the Records Officer's "Documents Received" and "Average Processing Time" graphs by accurately attributing 'Intake' and 'Releasing' actions in `backfill-metrics.php`.
- Clarified the document path UI in `show-document.php` to accurately map a 6-step lifecycle that includes both 'Records Unit (Intake)' and 'Records Unit (Processing)'.
- Removed the default test credentials from the login page UI.

### ADDED
- Implemented automatic digital signature clearing at the end of both `seed.js` and `scripts/fast-seed.php`. Seeder-generated signatures remain valid, while forcing users to initialize their PIN on their first login.

## 2026-07-17 21:05

**Version:** 1.14.4-alpha+202607172105

### ADDED
- Completed an extensive codebase documentation pass. Added inline comments explaining the purpose and logic of critical, moderately complex code blocks across services, core components, controllers, middleware, policies, scripts, jobs, and models.

## 2026-07-17 20:40

**Version:** 1.14.3-alpha+202607172040

### ADDED
- Migrated database credentials out of `config.php` and into `.env` file for secure configuration.
- Formally adopted Optimistic Locking for database transactions by renaming `executeWithLock` to `executeInTransaction` and clarifying service documentation.

### FIXED
- Removed rate limiting logic from the Guest portal, ensuring reliable operations inside LAN-only environments.
- Removed deprecated memory-based PIN caching methods from `SecurityHelper` and cleaned up their lingering usages.
- Updated `seed.js` database connection keys to dynamically map to the new `.env` schema.

## 2026-07-15 22:43

**Version:** 1.14.2-alpha+202607152243

### ADDED
- Added a "Freeze All" button to the System Health Overview for administrators to batch-freeze all documents with flagged integrity issues.

### FIXED
- Fixed a bug where `DocumentWorkflowService::autoResolveDocument()` crashed with a MySQL 3140 Invalid JSON text error (`The document is empty`) by correctly mapping empty strings to `null` for the `guest_info` and `finalized_route` columns when restoring snapshots from the genesis block.
- Removed unused and obsolete file `working-php/src/Views/profile/edit.php`.
- Removed obsolete mass-migration scripts (`rewrite_dashboard.php`, `rewrite_intake.php`, `rewrite_integrity.php`, `rewrite_statistics.php`) from the `scripts/` directory as the offset-to-cursor pagination migration is complete.

## 2026-07-15 15:16

**Version:** 1.14.0-alpha+202607151516

### ADDED
- Implemented Cursor-Based Pagination and 5-minute table count caching to significantly improve loading times for massive document tables.
- Added Optimistic Locking with a `version` column to handle concurrent modifications properly with UI toast alerts instead of database row locks.

### FIXED
- Fixed a bug where `IntegrityManager` wrongly flagged valid private keys as corrupted due to AES PKCS7 padding length mismatches.
- Added `notifications` table drop statement to `database.sql` to prevent production seeding from failing.
- Fixed UI components that crashed because they were hardcoded to use offset-based pagination instead of supporting `CursorPaginator`.

## 2026-07-14 22:35

**Version:** 1.13.1-alpha+202607142235

### FIXED
- Fixed CSRF Misconfigurations by properly injecting `<meta name="csrf-token">` and patching `fetch` AJAX headers.
- Fixed weak Database Encryption architecture by upgrading Ed25519 private key storage to use Argon2id KDF and AES-256-CBC random IVs.
- Fixed insecure PIN caching vulnerability (Session Hijacking) by migrating the memory cache from the server into the browser's `sessionStorage`.
- Fixed Application-Level DoS via Custom Purposes by normalizing custom inputs to a single "Others" database record and appending specific text to document titles.
- Fixed Canonicalization / Hash Collision Injection attack in the tamper-evident chain by using strict `json_encode` instead of ambiguous pipe `|` delimiters.
- Fixed seeder scripts (`seed.js`, `generate-keys.php`) to natively understand the upgraded block hash algorithms and Argon2id KDF without breaking the rate-limiting rules.

## 2026-07-09 13:34

**Version:** 1.12.8-alpha+202607091334

### ADDED
- Ported the "Generate Report" functionality for released documents from Laravel to raw PHP, including UI integration with progress modal and dynamic SQL filtering.

### FIXED
- Fixed file download path bug where generated reports were attempting to be downloaded from the root directory instead of the `storage/app/` folder.
- Fixed an issue where the "Generate Report" button and progress bar were invisible in light mode due to using non-compiled Tailwind classes (`bg-blue-600`), replacing them with standard theme variables (`bg-accent-1`).
## 2026-07-08 22:52

**Version:** 1.12.7-Alpha+202607082252

### ADDED
- Implemented route adaptation allowing the system to update a purpose's generic route based on Records Officer actions, while carefully ignoring dynamically injected guest-specific departments.
- Added a visual tag/badge to the `/manage-documents` UI to clearly distinguish guest-selected units from the default suggested route steps.
- Ensured all relevant guest intake fields, specifically the Phone Number, are strictly required prior to submission.

### FIXED
- Fixed a major missing data issue where production seeders failed to insert document requirements and suggested routes into the `purposes` table.
- Fixed a conflict where saving a dynamically injected guest department into a route would accidentally override the purpose's permanent template.

## 2026-07-08 21:22

**Version:** 1.12.6-Alpha+202607082122

### ADDED
- Implemented `CacheMiddleware` to handle HTTP Response Caching for resource-heavy pages (`/admin-dashboard`, `/statistics`) and the guest welcome page (`/`), significantly reducing database load on high-traffic routes.
- Added localized cache clearing via `clearPersonalCache()` in `SystemHealthController`, allowing users to refresh their own view's cache without triggering a global cache drop.
- Added "Clear My Cache" buttons to the Admin Dashboard and Statistics pages.
- Added PDF Document Tracking Form generation utilizing `dompdf` and dynamic QR codes (`chillerlan/php-qrcode` v6.x).
- Linked the `/success` page to download the newly generated tracking form.

### FIXED
- Updated the "Print Document Tracking Form" button on the guest success page to correctly generate a downloadable PDF rather than attempting a raw HTML print.
- Ensured caching middleware safely bypasses caching when active flash messages (errors/successes) are present in the session to avoid caching temporary states.

## 2026-07-08 15:14

**Version:** 1.12.5-Alpha+202607081514

### ADDED
- None

### FIXED
- Fixed a bug in `IntegrityCheckJob.php` where Ed25519 (Libsodium) signatures were incorrectly being verified using OpenSSL RSA functions.
- Corrected the system signature validation prefix in `IntegrityCheckJob.php` from `MOCK_SIG:` to `SYSTEM_SIG:` to properly align with `IntegrityManager` defaults.
## 2026-07-08 14:54

**Version:** 1.12.4-Alpha+202607081454

### ADDED
- Introduced a reusable `modal.php` component for standardized dialogs across the application.
- Added comprehensive security architecture documentation (`dts_security_architecture.md`).
- Included initial `database.sql` and `scripts/setup-production-db.js` for production database setup.
- Added document freeze and unfreeze capabilities in the document details view for administrators.
- Added default test credentials helper in the login view for easier testing and demonstration.

### FIXED
- Updated various admin views and layout files to integrate with the new `modal.php` component and enhance the system health dashboard.
- Modified `SystemHealthController`, `IntegrityManager`, and `DocumentWorkflowService` to support document freezing and manual overrides.
- Updated `AuthMiddleware` and `seed.js` for enhanced security handling and database seeding.


## 2026-07-08 14:51

**Version:** 1.12.3-Alpha+202607081451

### ADDED
- Added a confirm password field to the security key setup modal and standalone view to ensure users type their PIN correctly.
- Added server-side validation in `SecurityKeyController` to verify that the confirm PIN matches the main PIN before generating the security key.

### FIXED
- None

## 2026-07-07 21:10

**Version:** 1.13.0-alpha+202607072110

### ADDED
- Implemented a centralized `DocumentWorkflowService` utilizing raw PDO transactions (`SELECT ... FOR UPDATE`) to manage complex, concurrent document lifecycle operations safely.
- Ported and integrated the document decline logic (`/documents/decline`) into the records officer intake phase, ensuring an unbroken audit trail for declined documents.
- Diversified `seed.js` generator to produce full-spectrum document lifecycle states (Pending, Processing, Declined, Released), district varieties, and purpose hotspots for rigorous analytics load testing.

### FIXED
- Removed 10 obsolete model and controller files (`DailyDepartmentMetric`, `DocumentLog`, etc.) to significantly reduce legacy framework-style bloat in favor of the new centralized database service.
- Fixed the Document Status Distribution chart on the Admin Dashboard to dynamically mount and display counts for all 7 lifecycle statuses, preventing zeroes from disappearing from the UI.
- Reverted the records officer intake page (`manage-documents.php`) back to using a raw HTML decline modal to prevent crashes following the deliberate teardown of the global reusable `modal.php` component.
- Repaired a missing closing bracket syntax parsing error inside `DocumentController.php` that was blocking the document timeline view.
- Purged the view full chart TAT modal and associated javascript from the admin dashboard in preparation for the upcoming rebuild of the central `modal.php` component.
