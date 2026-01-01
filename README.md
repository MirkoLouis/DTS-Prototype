# DepEd Iligan - Document Tracking System (DTS) Prototype

This project is a functional prototype for a modern, web-based Document Tracking System (DTS) for the DepEd Division of Iligan City, built with the Laravel framework. It aims to digitize and streamline the process of submitting, tracking, and managing official documents.

## Core Features

- **Guest Submission Portal:** A public-facing form for guests to submit new document requests.
- **Public Tracking Portal:** A dedicated page where guests can track the status of one or more documents using their unique tracking codes. Features dynamic multi-document display and an interactive subway map view.
- **Role-Based Access Control:** Distinct user roles with specific dashboards and permissions for streamlined workflows:
    - **Admin:** Has access to a "System" page to run on-demand integrity checks (the "Trust Builder") and a dashboard to view and search all document logs.
    - **Records Officer:** Manages initial document intake, can decline and delete invalid or duplicate submissions, handles processing tasks for the Records department, and manages the final release of completed documents to clients.
    - **Department Staff:** Views and processes ongoing documents specifically assigned to their department.
- **Full Document Lifecycle Management:** Documents progress through a defined route, with staff members completing steps and advancing documents through the system.
- **Admin Process Analytics Dashboard:** A dashboard for administrators to view process analytics like department load and system throughput, acting as a "Bottleneck Detector."
- **Searchable Document Logs:** Search and pagination functionality for the "Document Log Integrity" table on the Admin dashboard.
- **Document Releasing Workflow:** A dedicated page for Records Officers to manage and finalize documents that have completed all internal processing and are ready for client release.
- **Client Feedback & Rating System:** After a document is released, clients can provide a 1-5 star rating on the public tracking page, and administrators can view feedback analytics on a dedicated dashboard.
- **Database Backup Manager ("The Safety Net"):** An admin-only dashboard to create and download on-demand database backups, providing a crucial safety net for data recovery.
- **Automated Database Maintenance:** A daily scheduled task automatically prunes stale, pending documents to ensure database health.
- **Dynamic Requirements:** The guest portal dynamically displays the required documents based on the selected purpose.
- **Unique Tracking Code:** A unique tracking code is generated for every submission, allowing guests and staff to reference specific documents.
- **QR Code Integration:** Automatically generates QR codes for tracking numbers on submission success pages. Records officers can scan QR codes via webcam or phone camera on the intake page to auto-populate the tracking code input field and trigger search.
- **Interactive Route Management:** A drag-and-drop interface for Records Officers to easily view, modify, add, and delete steps in a document's route.
- **System Health Monitor ("Trust Builder"):** An on-demand tool for administrators to verify the entire document log hash-chain, proving data immutability. If errors are found, it displays a list of the invalid logs, along with recovery tools like "View", "Freeze", and "Rebuild Chain" options.
- **Task Completion Interface:** Staff members can mark document steps as complete, automatically advancing the document to the next stage in its route.
- **Responsive Dashboard Layouts:** All primary dashboards are fully responsive, providing optimal viewing on both desktop (table view) and mobile (card view).

### Thesis Innovations Implemented

1.  **Security (Hash-Chaining & The "Trust Builder"):** An immutable, `sha256`-based chained log of all actions performed on a document is automatically created. Each log entry's hash is dependent on the previous entry's hash, ensuring the integrity of the document's history. This is complemented by the **System Health Monitor**, an on-demand tool that allows an administrator to verify the integrity of the entire database hash-chain at any time.
2.  **AI (Database-Driven Route Prediction & Learning):** The `RoutePredictionService` has been upgraded from hardcoded logic to a dynamic, database-driven system. It now tokenizes purpose text and queries a `prediction_keywords` table, using weighted scores to suggest routes. The system "learns" from Records Officers' modifications; a background job (`UpdateKeywordWeights`) increases the weight of keywords for chosen departments, making future predictions more accurate.
3.  **HCI (Interactive UI & Feedback Loop):** The system prioritizes user experience with features like the dynamic requirements list, the drag-and-drop route editor, the `x-tracker-subway-map` Blade component for visual tracking, and a modular, AJAX-driven multi-document tracking portal. It closes the feedback loop by allowing clients to provide a star rating after their document is completed, giving administrators direct insight into service quality.

## Tech Stack

- **Framework:** Laravel 11, simple-qrcode (for backend QR generation), spatie/laravel-backup
- **Database:** MySQL
- **Frontend:** Laravel Blade templates, Bootstrap 5 (CDN for public pages), Tailwind CSS (via Laravel Breeze for dashboards).
- **JavaScript:** Vanilla JavaScript, SortableJS (for drag-and-drop), html5-qrcode (for frontend QR scanning).

## Setup & Installation

1.  Clone the repository.
2.  Install dependencies: `composer install` and `npm install`.
3.  Create your `.env` file from `.env.example` and configure your database credentials.
4.  Generate an application key: `php artisan key:generate`.
5.  Run database migrations and seeders: `php artisan migrate:fresh --seed`. This will create the necessary tables and populate them with comprehensive, realistic data.
6.  Build frontend assets: `npm run build`.

### Default Login Accounts

The database seeder creates the following accounts. The password for all accounts is `password`.

- **Admin:** `admin@dts.com`
- **Records Officer:** `records@dts.com` (Handles intake and processing tasks for the Records department)
- **Department Staff:** (e.g., `accounting@dts.com`, `human_resources@dts.com`, `legal@dts.com`, `office_of_the_superintendent@dts.com`)