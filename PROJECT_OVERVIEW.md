# DTS Prototype - Project Overview

This document provides a detailed overview of the DepEd Iligan Document Tracking System (DTS) prototype, its architecture, and the implementation of its core features and innovations.

## 1. Project Goal

The primary goal of this project is to create a modern, efficient, and secure web application to replace manual processes for tracking official documents. It is built using the Laravel framework, leveraging its powerful features like the Eloquent ORM, Blade templating engine, and robust security practices.

## 2. System Architecture & Workflow

The application is designed around a role-based access control system, providing distinct workflows for different user types.

### 2.1. The Guest Journey (Document Submission)

1.  **Submission:** A guest visits the homepage, which contains the document submission form.
2.  **Dynamic Form:** The guest selects a purpose from a dropdown list. A JavaScript front-end immediately displays the list of required documents for the selected purpose. An "Other" option allows the user to specify a custom purpose.
3.  **Creation:** Upon submission, the `GuestController` validates the data. If an "Other" purpose is submitted, the `RoutePredictionService` is used to generate a suggested route. A `Document` record is created with a `pending` status.
4.  **Tracking Code:** A unique tracking code (e.g., `DEPED-A1B2C3D4E5`) is generated and displayed to the user on a success page. From here, the guest can directly proceed to the Public Tracking Portal.

### 2.2. The Public Tracking Portal (Guest Document Status)

1.  **Access:** Guests can access the tracking portal directly from the success page or by navigating to `/track` and entering a tracking code. The URL can support tracking multiple documents simultaneously (e.g., `/track?codes=CODE1,CODE2`).
2.  **Modular Display:** Each tracked document is displayed as a distinct "card" module, showing its details, current status, and a visual tracking history (the "subway map").
3.  **Dynamic Addition:** Via an AJAX-powered modal, guests can add more tracking codes to the current page. The new document's status module is dynamically appended, and the browser's URL is updated without a full page reload, providing a seamless experience.
4.  **Visual Progress:** The `x-tracker-subway-map` component visually represents the document's journey through its finalized route, highlighting completed, current, and upcoming steps.

### 2.3. The Records Officer Journey (Intake & Route Management)

1.  **Login:** The Records Officer logs in and is redirected to the `/intake` dashboard. They can also navigate to the `/tasks` dashboard via a navbar link to process documents assigned to their department.
2.  **Lookup:** On the `/intake` dashboard, they can enter a tracking code to find a pending document.
3.  **Management:** Upon finding a pending document, the officer is taken to the "Manage Route" page, which provides two primary actions:
    a. **Route Editing:** View, re-order, add, or delete steps in the suggested route.
    b. **Decline Document:** Permanently delete a pending document from the system. This is used for submissions that are spam, duplicates, or cannot be processed.
4.  **Finalization:** Clicking "Accept & Finalize Route" (handled by `DocumentController@finalize`):
    a. Updates the document's status to `processing`.
    b. Saves the `finalized_route` to the document's record.
    c. **Creates the first `DocumentLog` entry**, with hash chain initialized via the `DocumentLog` model's `boot()` method.
5.  **Learning Mechanism:** If the officer's finalized route differs from the purpose's original suggested route, the system updates the purpose with the new, improved route, making future predictions more accurate.

### 2.4. The Staff Journey (Processing)

1.  **Login:** A staff member logs in and is redirected to the `/tasks` dashboard.
2.  **View Queue:** The dashboard displays a list of documents currently in the `processing` state, **filtered to show only those documents where the current step in the `finalized_route` is assigned to the logged-in staff member's department.**
3.  **Task Completion:** Staff can click "Complete Step" for an assigned document. This action:
    a. Increments the `current_step` on the document.
    b. Updates the document's `status` to 'completed' if all steps in the route are finished.
    c. Creates a `DocumentLog` entry, logging the completion and advancing the hash chain.

### 2.5. The Admin Journey (Integrity Monitoring)

1.  **Login:** The admin logs in and is redirected to the `/integrity-monitor` dashboard.
2.  **Monitor Logs:** This dashboard displays a raw view of the `document_logs` table, showing every action taken on every document. The key feature is the display of each log's `hash` and `previous_hash`, allowing an administrator to verify the integrity of the document's history chain.
3.  **Copy Hashes:** Administrators can easily copy hash values to the clipboard for verification purposes.

## 3. Core Innovations in Detail

### 3.1. Security: Hash-Chaining

- **Implementation:** Handled robustly by the `DocumentLog` model's `boot()` method.
- **Mechanism:** Before a new `DocumentLog` is saved, the `boot()` method:
    1.  Finds the most recent log for the same document to retrieve its hash, which becomes the `previous_hash` for the new log. ("genesis_hash" is used for the first entry).
    2.  Creates a unique data string by combining the new log's data (document ID, user ID, action, timestamp) with the `previous_hash`.
    3.  Hashes this unique string to create the new log's `hash`.
- **Benefit:** This creates an unbreakable chain. Any alteration to a log entry would break the chain, immediately revealing tampering. This mechanism is now more resilient as it doesn't rely on model observers which can be suppressed.

### 3.2. AI: Route Prediction and Learning

- **Implementation:** `app/Services/RoutePredictionService.php` and `app/Http/Controllers/DocumentController.php`.
- **Prediction:** When a guest submits an "Other" purpose, the `RoutePredictionService` performs simple keyword matching on the custom text to generate a sensible default route.
- **Learning:** When a Records Officer finalizes a route in `DocumentController@finalize`, the system updates the purpose's `suggested_route` if the officer made changes, allowing the system to adapt and become more accurate over time.

### 3.3. HCI: Interactive User Interfaces

- **Implementation:** `resources/views/welcome.blade.php`, `resources/views/documents/manage.blade.php`, `resources/views/track.blade.php`, `resources/views/tasks.blade.php`, `resources/views/intake.blade.php`, `resources/views/integrity-monitor.blade.php`.
- **Features:** The system prioritizes a smooth user experience through:
    - **Dynamic Requirements:** The guest form provides immediate feedback by showing requirements as soon as a purpose is selected.
    - **Drag-and-Drop Route Editor:** Officers can intuitively re-order complex document routes.
    - **Visual Tracking (Subway Map):** The `x-tracker-subway-map` Blade component provides a clear, at-a-glance visualization of a document's progress.
    - **Modular & Dynamic Tracking Portal:** Guests can track multiple documents dynamically.
    - **Responsive Dashboard Layouts:** All main tables (`/intake`, `/tasks`, `/integrity-monitor`) automatically switch to a user-friendly card view on mobile devices.
    - **Copy Hash Functionality:** Hashes on the Integrity Monitor can be easily copied to the clipboard.
    - **Consistent Scrollbar:** A always-visible vertical scrollbar provides visual consistency across pages.

## 4. Automated System Maintenance

To ensure database health and prevent the accumulation of stale data, the system includes automated maintenance tasks.

### 4.1. Pruning Pending Documents

- **Implementation:** `app/Console/Commands/PrunePendingDocuments.php` scheduled in `routes/console.php`.
- **Mechanism:** A scheduled Artisan command, `documents:prune-pending`, runs daily. This command automatically finds and deletes any documents that have remained in the `pending` status for more than two weeks.
- **Benefit:** This prevents the database from being cluttered with abandoned document requests that were never processed, ensuring the system remains efficient.
