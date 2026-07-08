# Changelog

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
