# System Requirements & Hardware Specifications

## Summary
A comprehensive guide detailing the minimum and recommended hardware and software specifications for the Document Tracking System (DTS). This document explains the technical rationale behind the hardware requirements and how the application enforces these limits.

## Table of Contents
1. [Production Server Requirements](#1-production-server-requirements)
2. [Software Infrastructure](#2-software-infrastructure)
3. [Implementation: Database Tuning](#3-implementation-database-tuning)
4. [Implementation: CPU Core Pinning](#4-implementation-cpu-core-pinning)
5. [Developer Hardware (User's Laptop)](#5-developer-hardware-users-laptop)
6. [Storage Scaling for 1M+ Records](#6-storage-scaling-for-1m-records)

---

## 1. Production Server Requirements

These specifications are tailored to the DTS's performance profile, specifically its **4GB InnoDB Buffer Pool** and **12-Thread Core Pinning** logic.

| Component | Minimal (Base Operations) | Recommended (1M+ Records) |
|:---|:---|:---|
| **OS** | Ubuntu 24.04 LTS | Ubuntu 24.04 LTS (HWE Kernel) |
| **CPU** | 4 Cores (2.5GHz+) | 12+ Cores (3.0GHz+) |
| **RAM** | 8 GB (4GB Buffer + 4GB OS/App) | 32 GB (16GB Buffer + Redis + Buffering) |
| **Storage** | 50 GB SSD (SATA) | 200 GB+ NVMe SSD (High IOPS) |
| **Network** | 100 Mbps | 1 Gbps (Low Latency) |

---

## 2. Software Infrastructure

Regardless of hardware, the following software stack is mandatory:
- **PHP:** 8.3+ (with `bcmath`, `curl`, `mbstring`, `openssl`, `pdo_mysql`)
- **Web Server:** Nginx 1.24+ (Recommended) or Apache 2.4+
- **Database:** MySQL 8.0+ (Required for Window Functions and JSON logic)
- **Cache/Queue:** Redis 7.0+ (Mandatory for high-frequency dashboard updates)
- **Node.js:** 20+ (For Vite asset bundling)

---

## 3. Implementation: Database Tuning

The system includes an Artisan command to programmatically optimize MySQL's memory settings. This ensures the 1,000,000 document index remains in RAM for sub-millisecond lookups.

```php
// app/Console/Commands/TuneDatabase.php
public function handle()
{
    $this->info('Tuning database for 1,000,000 document load...');

    // Set Buffer Pool to 4GB (Crucial for high-volume indexing)
    DB::statement("SET GLOBAL innodb_buffer_pool_size = 4294967296;");
    
    // Set Log File Size to 1GB (Optimizes write-ahead logging)
    DB::statement("SET GLOBAL innodb_log_file_size = 1073741824;");
}
```

---

## 4. Implementation: CPU Core Pinning

The system's development environment utilizes `taskset` to isolate processes and prevent starvation. This strategy ensures the UI remains responsive during heavy background tasks.

```bash
# composer.json - Multi-threading through Core Pinning
"serve:dev": "while true; do PHP_CLI_SERVER_WORKERS=4 taskset -c 0-3 php artisan serve ...; done",
"queue:dev": "while true; do taskset -c 4 php artisan queue:listen ...; done",
"logs:dev": "while true; do taskset -c 5 php artisan pail ...; done",
"vite:dev": "while true; do taskset -c 6-7 npm run dev ...; done",
"schedule:dev": "while true; do taskset -c 8 php artisan schedule:work ...; done"
```

---

## 5. Developer Hardware (User's Laptop)

The development environment is currently running on a high-performance Linux workstation. These specs are well-suited for simulating a full 10,000 document load.

| Component | Specification | Current Status |
|:---|:---|:---|
| **OS** | Bazzite / Fedora Atomic (Linux 6.17) | Running in Distrobox (Ubuntu) |
| **CPU** | 12 Logical Cores | **Optimal** (Matches 12-thread core pinning) |
| **RAM** | 16 GB DDR4/DDR5 | **Sufficient** (Supports 4GB DB Buffer Pool) |
| **Storage** | 476 GB SSD | **Good** (95 GB Available) |
| **Environment** | Distrobox `coding` container | Provides mutable workspace in atomic OS |

---

## 6. Storage Scaling for 1M+ Records

The DTS is designed for extreme data growth. Below is a storage forecast for 1 million documents:

1.  **Core Document Data:** ~1.5 GB
2.  **Document Logs (5M+ logs):** ~3.5 GB (including indexes)
3.  **PDF Reports Cache:** ~10 GB (variable based on cleanup policy)
4.  **Database Metrics:** ~500 MB (assuming 5-minute snapshot cycle)
5.  **Total Database Footprint:** **~15 GB to 20 GB**

> **Recommendation:** Utilize **NVMe SSDs** to maintain sub-10ms query times for the `document_logs` table once it exceeds 5 million rows, as random I/O performance becomes the primary bottleneck during Hash Chain verification.
