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

1.  **Login:** The officer logs in and is redirected to the `/intake` dashboard.
2.  **Lookup:** The dashboard presents a field to enter a tracking code. This simulates the real-world action of receiving a document or QR code from a client.
3.  **Management:** Upon finding a pending document, the officer is taken to the "Manage Route" page. Here, they are presented with the system's `suggested_route` in an interactive, horizontal drag-and-drop interface.
4.  **Finalization:** The officer can re-order, add, or delete steps from the route. When satisfied, they click "Accept & Finalize Route". This action, handled by `DocumentController@finalize`, does three critical things:
    a. Updates the document's status to `processing`.
    b. Saves the `finalized_route` to the document's record.
    c. **Creates the first `DocumentLog` entry**, kicking off the hash chain.
5.  **Learning Mechanism:** If the officer's finalized route differs from the purpose's original suggested route, the system updates the purpose with the new, improved route, making future suggestions more accurate.

### 2.4. The Staff Journey (Processing)

1.  **Login:** A general staff member logs in and is redirected to the `/tasks` dashboard.
2.  **View Queue:** The dashboard displays a list of all documents that are currently in the `processing` state, allowing staff to see the queue of documents that need action. (Further implementation would involve assigning documents to specific staff members).

### 2.5. The Admin Journey (Integrity Monitoring)

1.  **Login:** The admin logs in and is redirected to the `/integrity-monitor` dashboard.
2.  **Monitor Logs:** This dashboard displays a raw view of the `document_logs` table, showing every action taken on every document. The key feature is the display of each log's `hash` and `previous_hash`, allowing an administrator to verify the integrity of the document's history chain.

## 3. Core Innovations in Detail

### 3.1. Security: Hash-Chaining via Observers

- **Implementation:** This is handled by the `app/Observers/DocumentLogObserver.php`.
- **Mechanism:** The observer listens for the `creating` event on the `DocumentLog` model. Before a new log is saved, the observer:
    1.  Finds the most recent log for the same document to retrieve its hash. This becomes the `previous_hash` for the new log. If it's the first log, a "genesis_hash" is used.
    2.  Creates a unique data string by combining the new log's data (document ID, user ID, action, timestamp) with the `previous_hash`.
    3.  Hashes this unique string to create the new log's `hash`.
- **Benefit:** This creates an unbreakable chain. If a malicious actor were to alter a log entry in the database, its hash would no longer match the `previous_hash` of the subsequent log, immediately revealing the tampering.

### 3.2. AI: Route Prediction and Learning

- **Implementation:** `app/Services/RoutePredictionService.php` and `app/Http/Controllers/DocumentController.php`.
- **Prediction:** When a guest submits an "Other" purpose, the `RoutePredictionService` performs simple keyword matching on the custom text to generate a sensible default route. For example, text containing "records" or "application" will automatically suggest the "Records" and "Human Resources" departments.
- **Learning:** When a Records Officer finalizes a route in `DocumentController@finalize`, the system compares their finalized route to the original suggestion for that purpose. If a change was made, it updates the purpose's `suggested_route` in the database. This feedback loop allows the system to adapt and become more accurate over time.

### 3.3. HCI: Interactive User Interfaces

- **Implementation:** `resources/views/welcome.blade.php`, `resources/views/documents/manage.blade.php`, and `resources/views/track.blade.php`.
- **Features:** The system prioritizes a smooth user experience through:
    - **Dynamic Requirements:** The guest form provides immediate feedback by showing requirements as soon as a purpose is selected.
    - **Drag-and-Drop:** The route management interface uses `SortableJS` to provide a tactile, intuitive way for officers to re-order complex document routes.
    - **Add/Delete On-the-Fly:** Officers are empowered to build a document route from scratch for custom purposes, using simple "Add" and "Delete" buttons that modify the DOM instantly.
    - **Visual Tracking (Subway Map):** The `x-tracker-subway-map` Blade component provides a clear, at-a-glance visualization of a document's progress.
    - **Modular & Dynamic Tracking Portal:** Guests can track multiple documents on a single page, adding new ones dynamically via AJAX without full page reloads, enhancing interactivity and user control.