# DepEd Iligan - Document Tracking System (DTS) Prototype

This project is a functional prototype for a modern, web-based Document Tracking System (DTS) for the DepEd Division of Iligan City, built with the Laravel framework. It aims to digitize and streamline the process of submitting, tracking, and managing official documents.

## Core Features

- **Guest Submission Portal:** A public-facing form for guests to submit new document requests.
- **Public Tracking Portal:** A dedicated page where guests can track the status of one or more documents using their unique tracking codes. Features dynamic multi-document display, an interactive subway map view, and a QR code scanner to easily add new documents.
- **Role-Based Access Control:** A robust access control system powered by a dedicated `RoleMiddleware` ensures users can only access pages appropriate for their role. Routes are organized into groups protected by this middleware, granting access to `admin`, `officer`, or `staff` personnel based on clearly defined permissions.
- **Full Document Lifecycle Management:** Documents progress through a defined route, with staff members completing steps and advancing documents through the system.
- **Admin Process Analytics Dashboard:** A comprehensive, multi-section dashboard for administrators to get a deep-dive into system performance. It includes a high-level overview of document statuses and global processing times, a dedicated section for analyzing returns and declines, and an interactive "Department Drill-Down" section with a "Load vs. Processing Time" combination chart to visually correlate department workload with efficiency.
- **Searchable Document Logs:** Search and pagination functionality for the "Document Log Integrity" table on the Admin dashboard.
- **Document Releasing Workflow:** A dedicated page for Records Officers to scan and receive fully processed documents (transitioning to `ready_for_release` status) and manage their final release to the client.
- **Client Feedback & Rating System:** After a document is released, clients can provide a 1-5 star rating on the public tracking page, and administrators can view feedback analytics on a dedicated dashboard.
- **Database Backup Manager ("The Safety Net"):** An admin-only dashboard to create, download, and delete on-demand database backups, providing a crucial safety net for data recovery.
- **Automated Database Maintenance:** A daily scheduled task automatically prunes stale, pending documents to ensure database health.
- **Dynamic Requirements:** The guest portal dynamically displays the required documents based on the selected purpose.
- **Unique Tracking Code:** A unique tracking code is generated for every submission, allowing guests and staff to reference specific documents.
- **QR Code Integration:** Automatically generates QR codes for tracking numbers on submission success pages. QR code scanning functionality is integrated into dedicated "Receive Document" sections on the Intake, Tasks, and Releasing dashboards for efficient document processing and status updates.
- **Return Request Workflow:** A dedicated page and process for staff to request documents for correction or re-processing, dynamically injecting their department into the document's route.
- **Interactive Route Management:** A drag-and-drop interface for Records Officers to easily view, modify, add, and delete steps in a document's route.
- **System Health Monitor ("Trust Builder"):** An on-demand tool for administrators to verify the entire document log hash-chain, proving data immutability. If errors are found, it displays a list of the invalid logs, along with recovery tools like "View", "Freeze", and "Rebuild Chain" options.
- **Task Completion Interface:** Staff members can mark document steps as complete, setting the document status to `in_transit` for physical transfer to the next department.
- **Responsive Dashboard Layouts:** All primary dashboards are fully responsive, providing optimal viewing on both desktop (table view) and mobile (card view).
- **Asynchronous Report Generation:** A high-performance export system for PDF and CSV reports. It uses background workers and a multi-file merge strategy to handle thousands of records without slowing down the web interface. Includes real-time progress tracking and cancellation.

### Thesis Innovations Implemented
... (existing content) ...
3.  **HCI (Interactive UI & Feedback Loop):** The system prioritizes user experience with features like the dynamic requirements list, the drag-and-drop route editor, enhanced QR code integration via dedicated "Receive Document" sections on dashboards, the `x-tracker-subway-map` Blade component for visual tracking, a modular, AJAX-driven multi-document tracking portal, and consistent, auto-hiding user feedback messages. It closes the feedback loop by allowing clients to provide a star rating after their document is completed, giving administrators direct insight into service quality.
4.  **Performance (Enterprise-Scale Exporting):** To demonstrate engineering for scale, the system implements a "chunk-and-merge" strategy for PDF generation. This allows the application to generate reports for 10,000+ documents on standard hardware by intelligently managing PHP's memory lifecycle and offloading heavy tasks to asynchronous background workers.

## Tech Stack
... (existing content) ...
- **JavaScript:** Vanilla JavaScript, Chart.js, SortableJS, html5-qrcode.

## Setup & Installation
... (existing content) ...
6.  Set up your local development environment by following the instructions in the section below.

**For Windows users:** See the detailed [Windows Setup Guide](WINDOWS_SETUP.md) for OS-specific instructions.

## Local Development Environment

This project uses Vite for frontend asset handling and requires a local SSL certificate to run properly.

### 1. One-Time Setup: Local SSL Certificate
... (existing content) ...

### 2. Running the Development Servers

For the best experience and system stability (especially when generating large reports), it is recommended to run the following three processes in **separate terminal tabs**:

1.  **Terminal 1: The PHP Web Server:**
    ```bash
    php artisan serve --host=0.0.0.0 --port=3000
    ```
2.  **Terminal 2: The Vite Frontend Compiler:**
    ```bash
    npm run dev
    ```
3.  **Terminal 3: The Background Queue Worker:**
    ```bash
    # Recommended for development (reloads on every job)
    php artisan queue:listen
    
    # Or for high performance
    php artisan queue:work
    ```

### 3. Production Deployment Considerations

When deploying this application to a live server:
- **Queue Driver:** Ensure `QUEUE_CONNECTION` in `.env` is set to `database` or `redis` (not `sync`).
- **Process Monitoring:** Use a tool like **Supervisor** to keep the `php artisan queue:work` process running continuously.
- **Storage Link:** Run `php artisan storage:link` to ensure generated reports are accessible for download.

### 4. Accessing the Application
... (rest of the file) ...

## Default Login Accounts

The database seeder creates the following accounts. The password for all accounts is `password`.

- **Admin:** `admin@dts.com`
- **Records Officer:** `records@dts.com` (Handles intake and processing tasks for the Records department)
- **Department Staff:** (e.g., `accounting@dts.com`, `human_resources@dts.com`, `legal@dts.com`, `office_of_the_superintendent@dts.com`)