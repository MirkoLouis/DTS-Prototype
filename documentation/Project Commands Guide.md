# Project Commands Guide

A comprehensive reference for the custom aliases, maintenance commands, and development shortcuts used in the Document Tracking System (DTS) for streamlined operations.

## Table of Contents
1. [Composer Quick Aliases](#composer-quick-aliases)
2. [Custom Artisan Commands (DTS Specific)](#custom-artisan-commands-dts-specific)
3. [Standard Laravel & Maintenance Commands](#standard-laravel--maintenance-commands)
4. [Frontend & Development Server](#frontend--development-server)
5. [Testing & Verification](#testing--verification)

---

## Composer Quick Aliases

These shortcuts are defined in `composer.json` to automate complex, multi-step development and deployment processes.

### `composer setup`
-   **Description:** Automates initial project setup: installs dependencies, generates the application key, runs migrations, and builds frontend assets.
-   **Usage:** First-time setup after cloning the repository.

### `composer dev`
-   **Description:** Starts the entire development environment concurrently using `npx concurrently`. This launches the PHP server, the queue listener, the `pail` log viewer, and the Vite dev server in a single terminal session.
-   **Usage:** Daily command for active development.

### `composer db:dev`
-   **Warning:** **[DESTRUCTIVE]** Drops all tables and recreates the database.
-   **Description:** Resets the database and seeds it with **development/dummy data** for testing and feature development.
-   **Usage:** To quickly refresh the environment with a rich dataset.

### `composer db:prod`
-   **Warning:** **[DESTRUCTIVE]** Drops all tables and recreates the database.
-   **Description:** Resets the database and seeds only **production-essential data** (Admin accounts, Departments, Purposes).
-   **Usage:** Preparing for a clean deployment or production reset.

### `composer queue:work-prod`
-   **Description:** Starts a high-performance, long-lived queue worker with a **20-minute (1200s) timeout**. It includes a wrapper script that automatically restarts the worker after 10 seconds if it crashes.
-   **Usage:** Critical for generating large-scale reports (10,000+ items) without worker timeouts.

---

## Custom Artisan Commands (DTS Specific)

| Command | Category | Description |
|:---|:---|:---|
| `php artisan dts:verify-integrity` | Security | **Trust Builder:** Runs a system-wide cryptographic verification of all document logs and chains. |
| `php artisan dts:rebuild-chain {logId}` | Security | Repairs a broken hash-chain starting from a specific log ID forward. |
| `php artisan dts:snapshot-db-metrics` | Monitoring | Captures real-time DB health (connections, slow queries) for the admin dashboard. |
| `php artisan documents:prune-pending` | Maintenance | Deletes unfinalized guest submissions older than 14 days to keep the database clean. |
| `php artisan dts:corrupt-log {logId}` | Testing | **(TEST ONLY)** Intentionally breaks a hash to verify the integrity monitor's detection capabilities. |
| `php artisan backup:run` | Operations | Triggers a full system and database backup (via Spatie Laravel Backup). |
| `php artisan dts:restore-db --file={name}` | Operations | Restores the database from a specified backup file. |

---

## Standard Laravel & Maintenance Commands

### Caching & Optimization
-   **`php artisan optimize:clear`**: Clears all caches (config, route, view, and application).
-   **`php artisan config:cache`**: Compiles all configuration files into a single, fast-loading file.
-   **`php artisan route:cache`**: Pre-calculates the routing table for faster request handling.

### Database Management
-   **`php artisan migrate`**: Runs pending migrations.
-   **`php artisan db:seed`**: Runs the default database seeders.

---

## Frontend & Development Server

-   **`npm run dev`**: Starts the Vite Hot Module Replacement (HMR) server for instant frontend updates.
-   **`npm run build`**: Compiles and minifies assets (JS/CSS) for production.
-   **`php artisan serve`**: Starts the local PHP development server (default: port 3000).

---

## Testing & Verification

To verify the security and integrity of the "Trust Builder" mechanism:
```bash
composer test
```
This command clears the configuration cache and executes the `IntegrityCheckTest.php`, which simulates tampering and confirms the system's ability to detect and block malicious log modifications.
