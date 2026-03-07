# System Requirements & Hardware Specifications

## Summary
A technical breakdown of the hardware and software specifications for the Document Tracking System (DTS). This document provides the rationale behind the system's performance limits and storage requirements.

## Table of Contents
1. [Production Server Requirements](#1-production-server-requirements)
2. [Software Infrastructure](#2-software-infrastructure)
3. [Implementation: Database Memory Optimization](#3-implementation-database-memory-optimization)
4. [Implementation: CPU Thread Allocation](#4-implementation-cpu-thread-allocation)
5. [Storage Scaling for 1M+ Records](#5-storage-scaling-for-1m-records)

---

## 1. Production Server Requirements

These specifications are tailored to support the system's **4GB InnoDB Buffer Pool** and multi-threaded background processing.

| Component | Minimal (Base Operations) | Recommended (1M+ Records) |
|:---|:---|:---|
| **OS** | Ubuntu 24.04 LTS | Ubuntu 24.04 LTS (HWE Kernel) |
| **CPU** | 4 Cores (2.5GHz+) | 12+ Cores (3.0GHz+) |
| **RAM** | 8 GB (4GB Buffer + 4GB App/OS) | 32 GB (16GB Buffer + Redis + OS) |
| **Storage** | 50 GB SSD (SATA) | 200 GB+ NVMe SSD (High IOPS) |
| **Network** | 100 Mbps | 1 Gbps (Low Latency) |

---

## 2. Software Infrastructure

The following software stack is mandatory for system stability:
- **PHP:** 8.3+ (Required for Ed25519 signing via `sodium`).
- **Database:** MySQL 8.0+ (Required for JSON logic and Window Functions).
- **Cache/Queue:** Redis 7.0+ (Mandatory for high-frequency analytics and reporting).
- **Web Server:** Nginx 1.24+ (Recommended for performance).

---

## 3. Implementation: Database Memory Optimization

The DTS uses a **Large Buffer Pool** strategy to ensure that the document index for 1,000,000 documents remains in RAM. This allows for sub-millisecond lookups on tracking codes.

```php
// Tuning command (app/Console/Commands/TuneDatabase.php)
public function handle() {
    // 4GB Buffer Pool: Keeps 1M document index in memory
    DB::statement("SET GLOBAL innodb_buffer_pool_size = 4294967296;");
    
    // 1GB Log File: Optimizes high-volume write-ahead logging
    DB::statement("SET GLOBAL innodb_log_file_size = 1073741824;");
}
```

---

## 4. Implementation: CPU Thread Allocation

The development environment utilizes **CPU Core Pinning** (via `taskset`) to isolate background tasks and prevent process starvation.

- **Cores 0-3**: Web Server (PHP-FPM/Artisan Serve).
- **Core 4**: Queue Listener (AI learning, PDF Generation).
- **Cores 6-7**: Vite (Asset Bundling).
- **Core 8**: Task Scheduler.

---

## 5. Storage Scaling for 1M+ Records

The DTS is designed for extreme data growth. Below is a storage forecast for 1 million documents:

1.  **Core Document Data**: ~1.5 GB
2.  **Document Logs (5M+ logs)**: ~3.5 GB (including indexes and SHA-256 hashes)
3.  **PDF Reports Cache**: ~10 GB (variable based on cleanup policy)
4.  **Database Metrics**: ~500 MB (assuming 5-minute snapshot cycle)
5.  **Total Database Footprint**: **~15 GB to 20 GB**

> **Recommendation**: Utilize **NVMe SSDs** to maintain sub-10ms query times for the `document_logs` table once it exceeds 5 million rows, as random I/O performance becomes the primary bottleneck during Hash Chain verification.
