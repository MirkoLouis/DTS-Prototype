# Role-Based Access Control (RBAC) Logic

Role-Based Access Control (RBAC) is the security foundation of the Document Tracking System (DTS), ensuring that users only access the data and actions necessary for their specific job functions.

## Table of Contents
1. [Business Importance of RBAC](#business-importance-of-rbac)
2. [Role Definitions](#role-definitions)
3. [Technical Implementation (Middleware)](#technical-implementation-middleware)
4. [User Management & Automated Permissions](#user-management--automated-permissions)
5. [Route Protection Strategy](#route-protection-strategy)

---

## Business Importance of RBAC

In a government setting like DepEd, RBAC is not just a technical feature; it is a critical business requirement for:
-   **Security:** Prevents unauthorized users from viewing sensitive documents or altering tracking histories.
-   **Accountability:** By linking actions to specific roles, the system creates a clear audit trail of who is responsible for each step in a document's lifecycle.
-   **Workflow Efficiency:** Simplifies the user interface by hiding irrelevant features. A Department Staff member only sees their tasks, while a Records Officer sees the intake and releasing queues.
-   **Data Integrity:** Restricts destructive actions (like deleting users or rebuilding hash chains) to a small group of trusted Administrators.

---

## Role Definitions

The system defines three distinct levels of access:

| Role | Business Function | Primary Access |
|:---|:---|:---|
| **`admin`** | System Overseer | Analytics, System Health, User Management, Backups, Integrity Repair. |
| **`officer`** | Records Unit Staff | Document Intake, Route Finalization, Releasing, and basic Task Processing. |
| **`staff`** | Department Personnel | Receiving documents via QR scan and marking processing steps as complete. |

---

## Technical Implementation (Middleware)

The core logic resides in `app/Http/Middleware/RoleMiddleware.php`. This middleware performs two vital functions:

### 1. The "Traffic Cop" (Login Redirection)
When a user first logs in, they are sent to a generic `/dashboard` route. The middleware intercepts this and redirects them based on their role:
-   `admin` &rarr; `/admin-dashboard`
-   `officer` &rarr; `/intake`
-   `staff` &rarr; `/staff-tasks`

### 2. The "Gatekeeper" (Route Protection)
For protected routes, the middleware checks the user's `role` column in the `users` table against a list of allowed roles. If a mismatch occurs, it throws a `403 Forbidden` error.
-   **Syntax:** `->middleware('role:admin,officer')`

---

## User Management & Automated Permissions

Administrators manage users through the **User Management** dashboard (`admin/users/index.blade.php`).

### Creating New Users (`create.blade.php`)
When a new user is created, the Administrator selects a **Role**. This simple selection triggers complex background logic in `UserManagementController@store`:

1.  **Validation:** Ensures the email is unique and the role is valid.
2.  **Automated Department Creation:**
    -   If the role is `officer` or `staff`, the system **automatically creates a new Department** named after the user.
    -   The user is then linked to this department via `department_id`.
    -   **Why?** This ensures that every processing staff member has a dedicated "Inbox" for documents routed specifically to them.
3.  **Admin Exclusion:** Admins are created without a department, as they oversee the system globally rather than processing individual documents.

---

## Route Protection Strategy

All routes in `routes/web.php` are organized into logical groups to prevent permission "leaks":

```php
// Admin-Only Zone
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::resource('users', UserManagementController::class);
    Route::get('/system-health', [SystemHealthController::class, 'index']);
});

// Processing Zone (Staff and Officers can both process tasks)
Route::middleware(['auth', 'role:officer,staff'])->group(function () {
    Route::post('/scan', [DocumentController::class, 'scan']);
    Route::get('/tasks', [TaskController::class, 'index']);
});
```

This structure makes it impossible for a Staff member to accidentally access the User Management or System Health pages, even if they know the URL.
