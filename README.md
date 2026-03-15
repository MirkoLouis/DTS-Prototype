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

### Thesis Innovations Implemented

1.  **Security (Hash-Chaining & The "Trust Builder"):** An immutable, `sha256`-based chained log of all actions performed on a document is automatically created. Each log entry's hash is dependent on the previous entry's hash, ensuring the integrity of the document's history. This is complemented by the **System Health Monitor**, an on-demand tool that allows an administrator to verify the integrity of the entire database hash-chain at any time.
2.  **AI (Database-Driven Route Prediction & Learning):** The `RoutePredictionService` has been upgraded from hardcoded logic to a dynamic, database-driven system. It now tokenizes purpose text and queries a `prediction_keywords` table, using weighted scores to suggest routes. The system "learns" from Records Officers' modifications; a background job (`UpdateKeywordWeights`) increases the weight of keywords for chosen departments, making future predictions more accurate.
3.  **HCI (Interactive UI & Feedback Loop):** The system prioritizes user experience with features like the dynamic requirements list, the drag-and-drop route editor, enhanced QR code integration via dedicated "Receive Document" sections on dashboards, the `x-tracker-subway-map` Blade component for visual tracking, a modular, AJAX-driven multi-document tracking portal, and consistent, auto-hiding user feedback messages. It closes the feedback loop by allowing clients to provide a star rating after their document is completed, giving administrators direct insight into service quality.

## Tech Stack

- **Framework:** Laravel 11, simple-qrcode (for backend QR generation), spatie/laravel-backup
- **Database:** MySQL
- **Frontend:** Laravel Blade templates, Bootstrap 5, Tailwind CSS. All frontend libraries are locally managed via NPM/Vite.
- **JavaScript:** Vanilla JavaScript, Chart.js, SortableJS, html5-qrcode.

## Setup & Installation

1.  Clone the repository.
2.  Install dependencies: `composer install` and `npm install`.
3.  Create your `.env` file from `.env.example` and configure your database credentials.
4.  Generate an application key: `php artisan key:generate`.
5.  Run database migrations `php artisan migrate` and seeders: `php artisan migrate:fresh --seed`. This will create the necessary tables and populate them with comprehensive, realistic data.
6.  Set up your local development environment by following the instructions in the section below.

## Local Development Environment

This project uses Vite for frontend asset handling and requires a local SSL certificate to run properly.

### 1. One-Time Setup: Local SSL Certificate

Because the local SSL certificate is not committed to Git, you must generate it on your machine.

1.  **Install `mkcert`:** Follow the installation instructions for your OS here: [mkcert on GitHub](https://github.com/FiloSottile/mkcert). For most Linux distributions, you can use your package manager (e.g., `sudo apt install mkcert` or `sudo dnf install mkcert`).
2.  **Install the `mkcert` CA:** Run this command to install the local certificate authority in your system's trust stores.
    ```bash
    mkcert -install
    ```
3.  **Generate the Certificate:** Navigate to the project root directory and run the following command. This will create the `localhost.crt` and `localhost.key` files that the Vite configuration expects.
    ```bash
    mkcert localhost
    ```
    *(Note: If you need to access the site from other devices on your network, you will need to include your machine's local IP address in the command, e.g., `mkcert localhost 192.168.1.10`)*

### 2. Running the Development Servers

You must run two processes in separate terminals:

1.  **Terminal 1: Start the PHP Backend Server:**
    ```bash
    php artisan serve --host=127.0.0.1 --port=3001
    ```
2.  **Terminal 2: Start the Vite Frontend Server:**
    ```bash
    npm run dev
    ```

### 3. Accessing the Application

- **On your laptop:** Open your browser and navigate to **`http://localhost:3001`**. The UI should be fully functional.
- **Note on Camera Access:** Features that require a secure context, like the QR code scanner, will not work when accessing via `http://`. This is a browser security feature.

## Default Login Accounts

The database seeder creates the following accounts. The password for all accounts is `password`.

- **Admin:** `admin@dts.com`
- **Records Officer:** `records@dts.com` (Handles intake and processing tasks for the Records department)
- **Department Staff:** (e.g., `accounting@dts.com`, `human_resources@dts.com`, `legal@dts.com`, `office_of_the_superintendent@dts.com`)