# Role-Based Access Control (RBAC) and Routing Guide

This document explains how Role-Based Access Control (RBAC) is implemented in this Laravel project, how to protect routes, and how to properly structure the routing file.

## 1. Overview of Roles

The system uses three primary roles for authenticated users:

*   **`admin`**: System administrators with full access to monitoring, system health, and administrative functions.
*   **`officer`**: Records officers responsible for intaking new documents, managing their routes, and releasing them.
*   **`staff`**: Department staff who are responsible for processing and completing tasks assigned to them.

These roles are stored in the `role` column of the `users` table.

## 2. The `RoleMiddleware`

The core of the RBAC system is the `app/Http/Middleware/RoleMiddleware.php` middleware. This middleware is registered in `bootstrap/app.php` with the alias `'role'`, making it easy to use in the routing files.

The `RoleMiddleware` has two primary responsibilities:

### A. Login Redirection

When a user logs in, they are sent to the `/dashboard` route. This route is protected by the `role` middleware without any parameters:

```php
// routes/web.php

Route::get('/dashboard', /* ... */)->middleware(['auth', 'verified', 'role']);
```

When the `RoleMiddleware` is triggered without parameters, it inspects the user's role and redirects them to the appropriate starting page:
*   `admin` -> `/admin-dashboard`
*   `officer` -> `/intake`
*   `staff` -> `/tasks`

### B. Route Protection

To protect a specific route or group of routes, the `role` middleware is used with one or more role names as parameters. The middleware checks if the authenticated user's role matches any of the roles specified.

**Syntax:** `->middleware('role:role1,role2,...')`

If the user's role is not in the allowed list, they are redirected to the home page with an "unauthorized" error message.

## 3. Routing Structure in `routes/web.php`

To keep the application secure and organized, all authenticated routes (except for the `/dashboard` redirect and profile pages) are placed within middleware groups.

### Example Structure:

```php
// routes/web.php

// Routes for Officers ONLY
Route::middleware(['auth', 'role:officer'])->group(function () {
    Route::get('/intake', [IntakeController::class, 'index'])->name('intake');
    // ... more officer routes
});

// Routes for both Officers and Staff
Route::middleware(['auth', 'role:officer,staff'])->group(function () {
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks');
    // ... more task-related routes
});

// Routes for Admins ONLY
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin-dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    // ... more admin routes
});

// Routes accessible by ANY authenticated user
Route::middleware('auth')->group(function() {
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    // ... other general routes
});
```

## 4. How to Add a New Role-Protected Page

Follow these steps to create a new page and ensure it's accessible only to the correct role(s).

1.  **Create the Controller and Method:** Add the necessary logic for your new page in the appropriate controller.

2.  **Add the Route to the Correct Group:** Open `routes/web.php` and find the middleware group for the role(s) that should access the new page.

    *   **Does a group for your required role(s) already exist?** Add your new route to it.
    *   **Is this a new role combination?** Create a new middleware group.

    For example, to add a new page at `/releasing/new-report` that should only be accessible to `officer`s, you would add it to the `role:officer` group:

    ```php
    // routes/web.php

    Route::middleware(['auth', 'role:officer'])->group(function () {
        // ... existing officer routes
        Route::get('/releasing/new-report', [ReleasingController::class, 'showNewReport'])->name('releasing.new-report');
    });
    ```

By following this structure, you ensure that the application's RBAC system remains robust, secure, and easy to maintain.
