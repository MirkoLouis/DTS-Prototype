# Changelog

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
