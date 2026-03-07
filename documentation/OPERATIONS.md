# DTS Operations & DevOps

## Summary
A comprehensive guide for developers and system administrators to manage the Document Tracking System (DTS). This document covers the multi-threaded dev environment, project commands, and local SSL management.

## Table of Contents
1. [Setup & Installation](#1-setup--installation)
2. [Project Commands Matrix](#2-project-commands-matrix)
3. [Local SSL Management (HTTPS)](#3-local-ssl-management-https)
4. [Deployment Checklist](#4-deployment-checklist)
5. [Data Simulation & Testing](#5-data-simulation--testing)

---

## 1. Setup & Installation

The DTS is optimized for Linux and WSL2 environments. It utilizes a **Multi-Threaded Development Architecture** via CPU core pinning.

### Initial Installation
```bash
# Clone the repository
git clone <repository-url>
cd dts-prototype

# Install all backend and frontend dependencies
composer install && npm install

# Run the setup script (Env, Key, Migration, Build)
composer run setup
```

### The 5-Pillar Dev Environment
The `composer dev` command launches five concurrent, isolated processes:
1. **Server**: PHP server (Port 3050).
2. **Queue**: Listener for background jobs (AI Learning, Reports).
3. **Logs**: Real-time log streaming via `Laravel Pail`.
4. **Vite**: Asset bundling and Hot Module Replacement (HMR).
5. **Schedule**: Task scheduler (Document Pruning, Metrics Snapshots).

---

## 2. Project Commands Matrix

| Command | Category | Description |
|:---|:---|:---|
| **`composer dev`** | Development | Starts all 5 pillars with CPU core pinning. |
| **`composer prod`** | Performance | Benchmark the system in optimized production mode. |
| **`composer db:dev`** | Database | Resets DB and seeds ~10,000 documents (5-year simulation). |
| **`composer db:prod`** | Database | Resets DB and seeds only production-essential data. |
| **`php artisan dts:verify-integrity`** | Security | System-wide audit of the cryptographic ledger. |
| **`php artisan dts:tune-db`** | Performance | Injects 4GB Buffer Pool and 1GB Redo settings into MySQL. |
| **`php artisan dts:snapshot-db-metrics`** | Monitoring | Captures real-time DB health snapshots. |

---

## 3. Local SSL Management (HTTPS)

Modern browsers require HTTPS for camera access (QR Scanner). The DTS uses a hybrid SSL architecture.

### Implementation: Vite SSL Priming
Frontend assets (Vite) are served over HTTPS using `mkcert` generated certificates.

1. **Install Certificates**: Run `mkcert localhost` to generate `localhost.crt` and `localhost.key`.
2. **Priming**: Before scanning, visit `https://localhost:3050` and accept the self-signed certificate. This allows the browser to load the secure QR processing scripts.

---

## 4. Deployment Checklist

To ensure a stable production deployment:
1. **Protocols**: Use **HTTPS** at the web server level (Nginx/Apache).
2. **Persistence**: Use **Supervisor** to keep `php artisan queue:work` running.
3. **Caching**: Run `php artisan optimize` to cache routes and configurations.
4. **Task Scheduler**: Ensure a cron entry exists for `php artisan schedule:run`.
5. **Integrity**: Execute a baseline `dts:verify-integrity` scan after production seeding.

---

## 5. Data Simulation & Testing

The system includes a sophisticated **Historical Data Simulator** for load testing.

### Historical Seeding Logic
The `DocumentSeeder` backfills 5 years of data by simulating document intake, processing, and release with randomized business-hour timestamps, ensuring the analytics dashboard reflects realistic system trends.

```php
// Backfilling historical documents in DocumentSeeder
$currentTimestamp = Carbon::now()->subYears(rand(0, 5));
$document->created_at = $currentTimestamp;
```
