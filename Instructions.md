# Role
Act as a Senior Laravel Developer and Mentor.

# Project
Create a working prototype for the "DepEd Iligan Document Tracking System (DTS)" using the **Laravel Framework**.

# Context
I am new to Laravel, so I need clear instructions on **where to put the files** (e.g., "Put this in `app/Models/Document.php`").
The system must be **offline-first**, hosted locally, and use **MySQL**. We are moving away from raw PHP to use Laravel's modern features (Eloquent ORM, Blade Templates, Migrations).

# Core Tech Stack
* **Framework:** Laravel 10 or 11.
* **Frontend:** Blade Templates + Bootstrap 5 (CDN) + Vanilla JavaScript (for simple interactivity).
* **Database:** MySQL (managed via Laravel Migrations).

---

## Part 1: Database Schema (Laravel Migrations)
Please write the **Laravel Migration files** (`Schema::create`) for these tables:
1.  **`departments`**: `id`, `name`.
2.  **`users`**: Standard Laravel user fields + `department_id`, `role` (enum: 'officer', 'staff', 'admin').
3.  **`purposes`**: `id`, `name`, `requirements` (JSON), `suggested_route` (JSON).
4.  **`documents`**: `id`, `tracking_code` (unique string), `guest_info` (JSON), `purpose_id`, `status`.
5.  **`document_logs`**: `id`, `document_id`, `action`, `user_id`, `integrity_hash` (string).

---

## Part 2: The Three "Thesis Innovations" (Laravel Implementation)
You must guide me on how to implement these using Laravel Best Practices:

**1. Security Innovation (Hash-Chaining via Observers):**
* Create a Laravel **Observer** for the `DocumentLog` model.
* Logic: Before a log is created (`creating` event), calculate the `integrity_hash`.
* Formula: `Hash::make($log->action . $log->timestamp . $previous_log->integrity_hash)`.

**2. AI Innovation (Service Class):**
* Create a folder `app/Services` and a class `RoutePredictionService`.
* Logic: A method `predict($purpose_text)` that analyzes keywords and returns a suggested route array.
* Show me how to inject this service into the `DocumentController`.

**3. HCI Innovation (Blade Components):**
* Create a Blade Component `x-tracker-subway-map`.
* Logic: It should accept the `$document` history and render a horizontal progress bar (Green = Done, Blinking = Current).

---

## Part 3: The Routes & Controllers

**A. Guest Portal (No Login)**
* **Route:** `GET /` (Welcome/Form).
* **Controller:** `GuestController`.
* **Feature:** A Blade view with a dynamic dropdown. When a user picks a purpose, show the requirements list using Alpine.js or vanilla JS.

**B. Staff Dashboard (Middleware Protected)**
* **Route:** `/dashboard` (Redirects based on Role).
* **Middleware:** Create a custom middleware `CheckRole` to direct traffic:
    * **Records Officer:** Goes to `/intake`.
    * **Staff:** Goes to `/tasks`.
    * **Admin:** Goes to `/integrity-monitor`.

---

# Task
1.  First, show me the **Migration Files** code.
2.  Second, explain the **Folder Structure** for the Service Class and Observer.
3.  Third, write the `GuestController` and the `welcome.blade.php` view.
