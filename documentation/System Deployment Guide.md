# System Deployment Guide

This document outlines the transition from a local development environment to a production-ready deployment for the Document Tracking System (DTS).

## Table of Contents
1. [Development vs. Production: Key Differences](#development-vs-production-key-differences)
2. [What does `npm run build` do?](#what-does-npm-run-build-do)
3. [General Deployment Steps](#general-deployment-steps)
4. [Platform-Specific Instructions (Linux, Windows Native, WSL2)](#platform-specific-instructions)
5. [Performance & Security Finalization](#performance--security-finalization)

---

## Development vs. Production: Key Differences

In development, we use a multi-process approach for speed and real-time updates. In production, we optimize for stability and speed.

| Feature | Development | Production |
|:---|:---|:---|
| **Vite** | `npm run dev` (Runs a separate HTTPS server) | `npm run build` (Static assets in `public/build`) |
| **PHP Server** | `php artisan serve` | Nginx, Apache, or IIS |
| **Error Reporting** | `APP_DEBUG=true` (Detailed error pages) | `APP_DEBUG=false` (Generic "500 Error") |
| **Asset URLs** | Hot-reloaded from `https://localhost:5173` | Fingerprinted files from `public/build` |

---

## What does `npm run build` do?

When you run `npm run build`, Vite performs several critical production tasks:
1.  **Bundling:** It combines multiple CSS and JS files into single, optimized files to reduce browser requests.
2.  **Minification:** It removes all whitespace and comments to shrink file sizes.
3.  **Versioning (Fingerprinting):** It adds a unique hash to every filename (e.g., `app-A1B2C3.js`). This prevents users from seeing old, cached versions of the site after an update.
4.  **Manifest Generation:** It creates a `public/build/manifest.json`. Laravel's `@vite` directive uses this file to find the correct, fingerprinted filenames.

**Critical Note:** Once `npm run build` is executed, the `npm run dev` (Vite) terminal is **no longer required**.

---

## General Deployment Steps

1.  **Clone the Repository:** `git clone <repository-url> /var/www/dts`
2.  **Configure Environment:**
    -   `cp .env.example .env`
    -   Set `APP_ENV=production` and `APP_DEBUG=false`.
    -   Set `APP_URL` to your production domain (e.g., `https://dts.depediligan.gov.ph`).
3.  **Install Dependencies:**
    -   `composer install --optimize-autoloader --no-dev`
    -   `npm install && npm run build`
4.  **Database Migration:**
    -   `php artisan dts:migrate --prodseed` (Initializes core roles/departments only)

---

## Platform-Specific Instructions

### Option A: Linux (Ubuntu/Nginx) - Recommended
1.  **Permissions:** `chown -R www-data:www-data storage bootstrap/cache`
2.  **Queue Worker:** Use **Supervisor** to keep the queue running:
    ```ini
    [program:dts-worker]
    command=php /var/www/dts/artisan queue:work --timeout=1200
    autorestart=true
    user=www-data
    ```
3.  **Scheduler:** Add to `crontab -e`:
    ```bash
    * * * * * cd /var/www/dts && php artisan schedule:run >> /dev/null 2>&1
    ```

### Option B: Windows Native (IIS)
1.  **IIS Config:** Create a new site pointing to the `public/` folder of the project.
2.  **Queue Worker:** Use **NSSM** (Non-Sucking Service Manager) to run `php artisan queue:work` as a Windows Service.
3.  **Scheduler:** Use **Windows Task Scheduler** to run `php artisan schedule:run` every minute.

### Option C: Windows with WSL2 (Ideal for Linux-like hosting on Windows)
-   Follow the **Linux (Option A)** steps exactly, but within your WSL2 Ubuntu instance.
-   Ensure Nginx is installed inside WSL and you have mapped the ports in your Windows firewall.

---

## Performance & Security Finalization

### 1. Optimize Laravel Cache
Run these commands after every deployment to significantly speed up your application:
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 2. Verify Data Integrity
Once deployed and seeded, run the verification tool to confirm the "Trust Builder" chain is healthy:
```bash
php artisan dts:verify-integrity
```

### 3. Change Default Credentials
Log in to `admin@dts.com` (default password: `password`) and immediately change the password via the Profile page.
