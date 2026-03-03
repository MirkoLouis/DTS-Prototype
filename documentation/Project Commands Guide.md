# Project Commands Guide

A comprehensive reference for the custom aliases, maintenance commands, and development shortcuts used in the Document Tracking System (DTS) for streamlined operations.

## Table of Contents
1. [Running the Development Environment](#running-the-development-environment)
2. [Composer Quick Aliases](#composer-quick-aliases)
3. [Custom Artisan Commands (DTS Specific)](#custom-artisan-commands-dts-specific)
4. [Standard Laravel & Maintenance Commands](#standard-laravel--maintenance-commands)
5. [Frontend & Development Server](#frontend--development-server)
6. [Testing & Verification](#testing--verification)

---

## Running the Development Environment

The DTS project requires several background processes to function correctly (server, queue, assets, scheduler). For a 12-thread CPU architecture, the system is optimized to use **CPU Core Pinning (taskset)** to prevent process starvation and ensure high UI responsiveness.

### Option 1: All-in-One (Recommended)
The most efficient way to start development is using the pre-configured Composer script which launches all necessary services concurrently in a single terminal window, each pinned to their respective CPU cores.

```bash
composer dev
```
*This command uses `npx concurrently` to manage: PHP Server (Cores 0-3), Queue Listener (Core 4), Pail Logs (Core 5), Vite (Cores 6-7), and the Task Scheduler (Core 8).*

### Option 2: Manual Isolation (5 Terminals)
For granular debugging, you can run each service in its own terminal session. Each script includes a **10-second automatic restart loop**:

1.  **Backend Server (Cores 0-3):**
    ```bash
    composer serve:dev
    ```

2.  **Queue Listener (Core 4):**
    ```bash
    composer queue:dev
    ```

3.  **Vite Asset Server (Cores 6-7):**
    ```bash
    composer vite:dev
    ```

4.  **Task Scheduler (Core 8):**
    ```bash
    composer schedule:dev
    ```

5.  **Pail Log Viewer (Core 5):**
    ```bash
    composer logs:dev
    ```

---

## Composer Quick Aliases

### `composer prod:optimize`
-   **Description:** Compiles and caches configurations, routes, and Blade views into single files for maximum performance.
-   **Usage:** Mandatory for production-speed testing with large (1M+) datasets.

### `composer prod:clear`
-   **Description:** Clears all production-level caches to reflect fresh code or configuration changes.

### `composer serve:prod`
-   **Description:** Starts the production-mode PHP server (Cores 0-3).

### `composer queue:work-prod`
-   **Description:** Starts a high-performance, long-lived queue worker (Core 4) with a 512MB RAM limit. Much faster than the dev `queue:dev` listener.

### `composer schedule:work-prod`
-   **Description:** Starts a persistent production-mode scheduler (Core 8).

These shortcuts are defined in `composer.json` to automate complex, multi-step development and deployment processes.

### `composer setup`
-   **Description:** Automates initial project setup: installs dependencies, generates the application key, runs migrations, and builds frontend assets.
-   **Actual Command:** `composer install && php artisan key:generate && php artisan migrate --force && npm install && npm run build`
-   **Usage:** First-time setup after cloning the repository.

### `composer dev`
-   **Description:** Starts the entire development environment concurrently using `npx concurrently`. This launches the PHP server, the queue listener, the `pail` log viewer, and the Vite dev server in a single terminal session.
-   **Actual Command:** `npx concurrently "php artisan serve --host 0.0.0.0 --port 3000" "php artisan pail --timeout=0" "php artisan queue:listen --tries=1" "npm run dev" "php artisan schedule:work"`
-   **Usage:** Daily command for active development.

### `composer serve:prod`
-   **Description:** Starts the local PHP development server with a persistent wrapper that automatically restarts the server if it stops.
-   **Actual Command:** `while true; do php artisan serve --host 0.0.0.0 --port 3000; sleep 10; done`
-   **Usage:** Used in the manual terminal setup for the backend.

### `composer db:dev`
-   **Warning:** **[DESTRUCTIVE]** Drops all tables and recreates the database.
-   **Description:** Resets the database and seeds it with **development/dummy data** for testing and feature development.
-   **Actual Command:** `php artisan dts:migrate --devseed`
-   **Usage:** To quickly refresh the environment with a rich dataset.

### `composer db:prod`
-   **Warning:** **[DESTRUCTIVE]** Drops all tables and recreates the database.
-   **Description:** Resets the database and seeds only **production-essential data** (Admin accounts, Departments, Purposes).
-   **Actual Command:** `php artisan dts:migrate --prodseed`
-   **Usage:** Preparing for a clean deployment or production reset.

### `composer queue:work-prod`
-   **Description:** Starts a high-performance, long-lived queue worker with a **20-minute (1200s) timeout**. It includes a wrapper script that automatically restarts the worker after 10 seconds if it crashes.
-   **Actual Command:** `while true; do php artisan queue:work --timeout=1200; sleep 10; done`
-   **Usage:** Critical for generating large-scale reports (10,000+ items) without worker timeouts.

### `composer schedule:work-prod`
-   **Description:** Starts a persistent local scheduler that runs every minute. Includes a wrapper script that automatically restarts the scheduler if it stops.
-   **Actual Command:** `while true; do php artisan schedule:work; sleep 10; done`
-   **Usage:** Ensures background tasks (like integrity checks and metric snapshots) run continuously in production-like environments.

### `composer test`
-   **Description:** Clears configuration cache and executes the system's test suite.
-   **Actual Command:** `php artisan config:clear && php artisan test`
-   **Usage:** For verifying the "Trust Builder" security mechanism.

---

## Custom Artisan Commands (DTS Specific)

| Command | Category | Description |
|:---|:---|:---|
| `php artisan dts:verify-integrity` | Security | **Trust Builder:** Runs a system-wide cryptographic verification of all document logs and chains. |
| `php artisan dts:tune-db` | Performance | **RAM Optimization:** Programmatically injects 4GB Buffer Pool and 1GB Log File settings into MySQL. |
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
-   **`php artisan schedule:work`**: Runs the task scheduler locally every minute (useful for development without Cron).

---

## Testing & Verification

To verify the security and integrity of the "Trust Builder" mechanism:
```bash
composer test
```
This command clears the configuration cache and executes the `IntegrityCheckTest.php`, which simulates tampering and confirms the system's ability to detect and block malicious log modifications.
