# Project Features: The Why and The How

This document provides a deep dive into the design decisions ("The Why") and technical implementations ("The How") behind the key features of the DepEd Iligan DTS Prototype.

---

## 1. Core Document Tracking

### Feature: Non-Sequential, Obfuscated Tracking Codes

*   **Why? (The Rationale):**
    Using a simple, sequential, or date-based tracking code (e.g., `DEPED-20260101-001`) presents a significant security and privacy risk. Malicious actors could easily guess or iterate through tracking codes to access information about documents that do not belong to them. A non-sequential, random code makes it computationally infeasible to guess a valid tracking code, ensuring that only the person with the exact code can view a document's status.

*   **How? (The Implementation):**
    When a new `Document` is created, a unique tracking code is generated within the `DocumentFactory` or `GuestController`. The process is:
    1.  A static prefix, `DEPED-`, is defined.
    2.  A cryptographically secure random string of characters is generated. PHP's `random_bytes()` and `bin2hex()` or Laravel's `Str::random()` are suitable for this.
    3.  The prefix and the random string are concatenated.
    4.  The system performs a quick check against the `documents` table to ensure the generated code is unique. If a collision occurs (however unlikely), the process repeats until a unique code is found.

### Feature: Document Lifecycle Management

*   **Why? (The Rationale):**
    A document in a real-world office setting goes through a clear series of stages. This feature models that reality within the digital system. By defining explicit statuses (`pending`, `processing`, `completed`, `rejected`), the system provides clarity to both the public and the staff, allowing everyone to know the exact stage of a document at a glance.

*   **How? (The Implementation):**
    A `status` column (string) exists on the `documents` table. The application manages the state transitions through its controllers:
    -   **`pending`**: The initial state set by `GuestController` upon successful submission.
    -   **`processing`**: Set by `IntakeController` when a Records Officer accepts the document and finalizes its route.
    -   **`completed` / `rejected`**: The final state set by a Department Staff member in the `TaskController` or `ReleasingController` when the document's journey is finished. Each state change is logged in the `document_logs` table.

---

## 2. Security: The "Trust Builder" Hash-Chain

### Feature: Immutable Document History

*   **Why? (The Rationale):**
    To build trust in a digital system, it's essential to guarantee that records have not been altered after the fact. This feature creates a tamper-evident log, similar to a blockchain, for every action taken on a document. If a single log entry is modified in the database, the entire chain "breaks," providing cryptographic proof of tampering.

*   **How? (The Implementation):**
    The `DocumentLog` model has a `creating` event listener (often in a Model Observer or the model's `boot` method). When a new log is created:
    1.  It retrieves the `current_hash` of the *most recent log* for the same document. This becomes the new log's `previous_hash`.
    2.  It generates a new `current_hash` by performing a `sha256` hash on a concatenated string of its own data (e.g., document_id, user_id, action, timestamp) plus the `previous_hash`.
    3.  This linking of hashes creates an unbreakable chain back to the very first log entry.

### Feature: On-Demand Integrity Verification

*   **Why? (The Rationale):**
    This feature provides an active, demonstrable way for an administrator to audit the entire system and prove that the data is, and has always been, integral. It's a tool for transparency and accountability, allowing the administration to confidently state that no digital records have been illicitly altered.

*   **How? (The Implementation):**
    The `dts:verify-integrity` Artisan command is triggered from the "System Health" dashboard.
    1.  It queries the database for all documents.
    2.  For each document, it iterates through its associated `document_logs` in chronological order.
    3.  At each step, it recalculates the expected hash using the same logic as the `creating` event (current log's data + previous log's stored hash).
    4.  It compares this recalculated hash to the `current_hash` stored in the database for that log. If they do not match, an error is flagged, indicating the exact point of data corruption.

---

## 3. AI: Route Prediction & Learning

### Feature: Intelligent Route Suggestion

*   **Why? (The Rationale):**
    Many documents submitted by the public may have unique or vaguely worded purposes that don't fit into a standard, predefined route. This feature reduces the cognitive load on Records Officers by suggesting a probable route, saving them the time of manually constructing it from scratch.

*   **How? (The Implementation):**
    The `RoutePredictionService` is the core of this feature.
    1.  It takes the text from the document's custom purpose (e.g., "Inquiry about teacher salary bonus").
    2.  It tokenizes the text (breaks it into individual words: "inquiry", "teacher", "salary", "bonus") and discards common "stop words" (like "about", "the").
    3.  It queries the `prediction_keywords` table, which contains a list of keywords, each associated with a `department_id` and a numerical `weight`.
    4.  It calculates a total score for each department by summing the weights of the matching keywords found in the purpose text.
    5.  The departments with the highest scores are assembled into a suggested route, which is presented to the Records Officer for confirmation.

### Feature: System Learning from Corrections

*   **Why? (The Rationale):**
    No prediction system is perfect. This feature allows the DTS to become smarter and more accurate over time. By learning from the corrections made by expert human users (the Records Officers), the system adapts its knowledge base, improving the quality of future suggestions.

*   **How? (The Implementation):**
    When a Records Officer saves a document, the system checks if the `finalized_route` is different from the `predicted_route`.
    1.  If a correction was made, the `UpdateKeywordWeights` job is dispatched to the background queue.
    2.  This job receives the original purpose text and the corrected route.
    3.  It re-analyzes the keywords from the purpose text.
    4.  For each keyword, it finds the corresponding entry in the `prediction_keywords` table for the **department the officer chose** and increments its `weight`. This makes the system more likely to suggest that department for similar purposes in the future.

---

## 4. Human-Computer Interaction (HCI)

### Feature: Guest-Facing QR Codes

*   **Why? (The Rationale):**
    This feature bridges the physical-digital divide. A user can save a screenshot of the QR code or print their submission receipt. This allows them to check their document's status instantly with their smartphone's camera, without needing to type a long tracking code or navigate through website menus. It's fast, convenient, and modern.

*   **How? (The Implementation):**
    On the document submission success page (`success.blade.php`), the `simple-qrcode` Laravel package is used. It's a Blade component that takes the document's tracking URL as input and generates an SVG image of the corresponding QR code, which is displayed to the user.

### Feature: Staff-Side QR Code Scanning

*   **Why? (The Rationale):**
    This dramatically improves the efficiency of the intake process. Instead of manually typing a long tracking code from a client's phone or printed receipt—a slow and error-prone task—the officer can use a webcam to scan the QR code in an instant.

*   **How? (The Implementation):**
    The Intake page (`intake.blade.php`) uses the `html5-qrcode` JavaScript library.
    1.  A "Scan QR Code" button opens a modal window containing a video feed from the user's webcam.
    2.  The library continuously scans the video feed for a valid QR code.
    3.  Upon a successful scan, the library's callback function automatically populates the `tracking_code` input field on the page and submits the form, finding the document for the officer.

### Feature: Responsive Dashboard UI

*   **Why? (The Rationale):**
    Staff members may need to access the system from various devices, including tablets or even their mobile phones. A responsive interface ensures that the application is fully functional and easy to use, regardless of screen size. A wide table that requires horizontal scrolling on a phone is a poor user experience.

*   **How? (The Implementation):**
    The frontend is built with Tailwind CSS, a utility-first framework. The views use responsive prefixes to apply different styles at different screen sizes. For example, the "Recently Handled Documents" list uses `hidden md:block` for the `<table>` (hiding it on small screens) and `grid md:hidden` for a "card view" (showing it only on small screens), providing an optimal layout for each context.

### Feature: Drag-and-Drop Route Management

*   **Why? (The Rationale):**
    Defining or re-ordering a multi-step document route can be cumbersome with traditional forms. A drag-and-drop interface provides a much more intuitive, tactile, and visually satisfying way to perform this task, reducing clicks and improving user satisfaction.

*   **How? (The Implementation):**
    On the document management page, the list of departments in the proposed route is rendered as a list. The `SortableJS` JavaScript library is initialized on this list.
    1.  `SortableJS` makes the list items draggable.
    2.  When the user drops an item into a new position, the library fires an event.
    3.  A JavaScript listener captures this event and updates the values of hidden input fields that store the ordered list of department IDs. When the form is saved, this updated order is sent to the server.

---

## 5. System Administration & Maintenance

### Feature: Automated Pending Document Cleanup

*   **Why? (The Rationale):**
    Public-facing forms will inevitably accumulate abandoned or incomplete submissions. If left unchecked, this "junk" data can bloat the database, slow down queries, and skew analytics. This automated task acts as a self-cleaning mechanism for the system.

*   **How? (The Implementation):**
    An Artisan command, `documents:prune-pending`, is defined to query for all documents that have a `pending` status and were created more than two weeks ago. It then deletes these records. This command is registered as a scheduled task in `app/Console/Kernel.php` to run automatically once per day.

### Feature: On-Demand Database Backups

*   **Why? (The Rationale):**
    To provide administrators with an easy, accessible way to create database backups directly from the web interface. This is crucial for disaster recovery and for creating safe restore points before performing major system updates or data migrations, without needing to ask a system administrator for shell access.

*   **How? (The Implementation):**
    The `spatie/laravel-backup` package is installed and configured. A dedicated `BackupManagerController` provides a method that programmatically calls the `php artisan backup:run` command. The admin dashboard (`admin/backups.blade.php`) contains a UI with a button that sends an AJAX request to this controller, allowing an admin to trigger a full database backup with a single click.
