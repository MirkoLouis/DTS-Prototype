# DTS Operations & DevOps

## Summary
A comprehensive guide for developers and system administrators to manage the Document Tracking System (DTS). This document details setup, deployment, custom Artisan commands, SSL management, and data simulation.

## Table of Contents
1. [Setup & Installation](#1-setup--installation)
2. [Project Commands Guide](#2-project-commands-guide)
3. [HTTPS & Local SSL Management](#3-https--local-ssl-management)
4. [System Deployment Guide](#4-system-deployment-guide)
5. [Data Generation & Simulation Logic](#5-data-generation--simulation-logic)

---

## 1. Setup & Installation

The DTS is designed for local development on Windows (Native/WSL2) and Linux environments.

### Implementation: Concurrent Dev Environment
The `composer dev` script launches five concurrent processes with CPU core pinning for high responsiveness.

```bash
# composer.json Script Excerpt
"dev": "npx concurrently \"composer run serve:dev\" \"composer run queue:dev\" \"composer run logs:dev\" \"composer run vite:dev\" \"composer run schedule:dev\""
```

Each process is pinned to dedicated CPU cores (e.g., `taskset -c 0-3 php artisan serve`) to prevent starvation during heavy tasks like PDF generation.

---

## 2. Project Commands Guide

Custom Artisan and Composer aliases for streamlined operations.

- **`composer dev`**: Starts all 5 pillars of the development environment.
- **`composer db:dev`**: Drops all tables, recreates them, and seeds 10,000+ historical documents.
- **`php artisan dts:verify-integrity`**: Runs a system-wide audit of all document hash chains.
- **`php artisan dts:tune-db`**: Programmatically injects high-performance RAM settings into MySQL.
- **`php artisan dts:snapshot-db-metrics`**: Captures DB health metrics for the admin dashboard.

---

## 3. HTTPS & Local SSL Management

Modern browsers require HTTPS to grant access to the camera (needed for the QR scanner).

### Implementation: Hybrid SSL Architecture
The backend runs on HTTP while the frontend assets (Vite) serve over HTTPS using `mkcert` generated certificates.

```javascript
// vite.config.js
export default defineConfig({
    server: {
        https: {
            key: fs.readFileSync('localhost.key'),
            cert: fs.readFileSync('localhost.crt'),
        },
        cors: true,
    }
});
```

**"Vite Priming"**: Before using the QR scanner, visit `https://localhost:5173` and accept the self-signed certificate to allow the browser to load the secure CSS/JS assets.

---

## 4. System Deployment Guide

Optimizing for a stable and performant production environment.

### Performance Checklist
1. **Frontend**: `npm run build` to bundle, minify, and version assets.
2. **Backend**: `php artisan optimize` to cache configurations and routes.
3. **Queue**: Use a tool like **Supervisor** to keep `php artisan queue:work` running.
4. **Integrity**: Run `dts:verify-integrity` after production seeding to establish the initial root of trust.

---

## 5. Data Generation & Simulation Logic

Detailed strategies for populating the system with rich historical data for analytics testing.

### Implementation: Historical Simulation
The `DocumentSeeder` backfills 5 years of data by simulating document intake, processing, and release with randomized business-hour timestamps.

```php
// app/Database/Seeders/DocumentSeeder.php
$documents->each(function (Document $document) {
    // Generate a randomized start date within the last 5 years
    $currentTimestamp = Carbon::now()->subYears(rand(0, 5))->subDays(rand(0, 365))->setHour(rand(8, 16));
    
    // Ensure timestamps only fall on business days
    if ($currentTimestamp->isWeekend()) {
        $currentTimestamp->next(Carbon::MONDAY)->setTime(rand(8, 16), 0);
    }
    
    // Simulate each step in the route with increasing timestamps
    $document->created_at = $currentTimestamp;
    $currentTimestamp->addMinutes(rand(5, 120)); // Delay between intake and processing
});
```

### Correlated Metrics
Performance snapshots are generated alongside document logs, ensuring that the analytics dashboard reflects realistic system load trends.
