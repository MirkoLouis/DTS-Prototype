# Project Commands & Aliases Guide

This guide provides a comprehensive overview of the essential commands and shortcuts used to run, maintain, and develop the Document Tracking System (DTS).

## 🚀 Quick Aliases (Composer)

These shortcuts are defined in `composer.json` to streamline complex operations.

### `composer setup`
**Command:** `composer setup`
**Description:** Automates the entire project initialization (installing dependencies, generating keys, migrating, and building assets).
**When to use:** First-time setup after cloning the repository.

### `composer run dev`
**Command:** `composer run dev`
**Description:** Starts all development services concurrently (PHP server, Queue listener, Vite, and Pail logs).
**When to use:** Your daily command for active development.

### `composer db:dev`
**Command:** `composer db:dev`
**Internal:** `php artisan dts:migrate --devseed`
**Description:** **[DESTRUCTIVE]** Drops all tables, runs migrations, and seeds the database with **dummy/test data** (useful for development).
**When to use:** To reset your local environment for testing and development.

### `composer db:prod`
**Command:** `composer db:prod`
**Internal:** `php artisan dts:migrate --prodseed`
**Description:** **[DESTRUCTIVE]** Drops all tables, runs migrations, and seeds the database with **production-only data** (Departments, Admin User, etc.).
**When to use:** During initial production deployment or when a clean, non-dummy database is required.

### `composer queue:work-prod`
**Command:** `composer queue:work-prod`
**Internal:** `php artisan queue:work --timeout=1200; read`
**Description:** Starts a high-performance, long-lived queue worker with an extended 20-minute timeout specifically optimized for large report generation (10,000+ documents). The `read` suffix ensures the terminal stays open to show completion or errors.
**When to use:** Processing large reports or performing intensive background tasks.

### `composer test`
**Command:** `composer test`
**Description:** Clears the configuration cache and runs the automated test suite.

---

## 🛠️ Artisan Commands (`php artisan`)

### 🛰️ Project-Specific Commands (DTS Custom)

| Command | Description |
|:---|:---|
| `dts:verify-integrity` | **Trust Builder:** Runs a system-wide cryptographic verification of all document logs. |
| `dts:rebuild-chain {logId}` | Repairs a broken hash-chain starting from a specific log ID. |
| `dts:snapshot-db-metrics` | Captures real-time DB health (connections, slow queries). Runs every 5 mins. |
| `documents:prune-pending` | Deletes unfinalized guest submissions older than 14 days. |
| `dts:corrupt-log {logId}` | **(Testing Only)** Intentionally breaks a hash to test the Trust Builder. |
| `backup:run` | Triggers a full system and database backup. |
| `dts:restore-db --file={name}` | Restores the database from a specified backup file. |

### 🧹 Caching & Optimization

*   `php artisan config:cache`: Compiles all config files into one for speed.
*   `php artisan route:cache`: Pre-calculates the routing table.
*   `php artisan view:cache`: Pre-compiles all Blade templates.
*   *   `php artisan optimize:clear`: Clears ALL caches (config, route, view, etc.).

---

## 🏗️ Frontend (npm)

*   `npm run dev`: Starts the Vite HMR (Hot Module Replacement) server.
*   `npm run build`: Bundles and minifies assets for production deployment.

---

## 🧪 Testing Integrity
To verify the security mechanism:
```bash
php artisan test
```
This runs the `IntegrityCheckTest.php` which validates that the system can detect and identify tampered log entries.
