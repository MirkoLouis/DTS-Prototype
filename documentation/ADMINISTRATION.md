# DTS Administration & Analytics

## Summary
A technical breakdown of the Document Tracking System's administrative suite. This document covers the high-performance analytics engine, system-wide integrity monitoring, and the database scaling strategies required for 1M+ records.

## Table of Contents
1. [Admin Dashboard & Analytics](#1-admin-dashboard--analytics)
2. [System Health & Integrity Monitor](#2-system-health--integrity-monitor)
3. [Backup & Recovery Management](#3-backup--recovery-management)
4. [Scalability: The 1 Million Document Strategy](#4-scalability--the-1-million-document-strategy)

---

## 1. Admin Dashboard & Analytics

The Admin Dashboard provides real-time visibility into the health and efficiency of the document workflow.

### High-Performance Analytics (The "RAM Trap" Guard)
To ensure the dashboard remains responsive even as the database grows to millions of logs, analytics are calculated using **MySQL 8.0 Window Functions**. This offloads heavy calculations to the database engine.

- **Throughput Chart**: Measures end-to-end duration between `Intake` and `Released`.
- **Bottleneck Detector**: Identifies departments with the highest "Processing Complete" vs. "Received" delay using `LAG()` and `OVER()`.
- **Load Distribution**: Shows real-time document counts at each step of the routing process.

### Intelligent Caching
Chart data is fetched independently via AJAX and cached for **5 minutes** to prevent redundant calculations during high-traffic periods.
```php
$cacheKey = "throughput_data_{$period}_{$departmentId}";
return Cache::remember($cacheKey, now()->addMinutes(5), function () { ... });
```

---

## 2. System Health & Integrity Monitor

The System Health dashboard acts as the technical cockpit for administrators, providing a unified view of system stability.

### Key Monitoring Components
- **Integrity Status**: A summary of the "Trust Builder" audit.
- **Average Processing Time (TAT)**: A real-time KPI of system throughput.
- **Database Metrics**: Visualized snapshots of active connections, slow queries, and average query latency.
- **Queue Health**: Monitors failed background jobs (e.g., PDF generation, AI learning).

### Administrative Utilities
- **Integrity Repair**: Allows admins to trigger a `dts:rebuild-chain` for specific documents.
- **Client Ratings**: A qualitative dashboard summarizing guest feedback and 5-star ratings.

---

## 3. Backup & Recovery Management

The DTS includes a secure, integrated Backup Manager powered by `spatie/laravel-backup`.

### Backup Strategy
- **Manual Snapshots**: Admins can trigger immediate system and database backups.
- **Scheduled Backups**: Configured via the task scheduler to run during off-peak hours.
- **Recovery**: High-priority restoration tool that supports specific backup selection and automated database reconstruction.

---

## 4. Scalability: The 1 Million Document Strategy

The system is architected to handle extreme data growth without performance degradation.

### Database Tuning (RAM Optimization)
The `dts:tune-db` command programmatically optimizes MySQL memory allocation:
- **InnoDB Buffer Pool (4GB)**: Ensures the entire index for 1 million documents stays in RAM.
- **Log File Size (1GB)**: Optimizes write-ahead logging for high-volume intake.

### Storage Forecast
For a target of 1,000,000 documents (approx. 5M logs):
1. **Core Data**: ~1.5 GB
2. **Logs & Hashes**: ~3.5 GB
3. **Analytics Metrics**: ~0.5 GB
4. **Total DB Footprint**: **~15-20 GB** (NVMe SSD recommended for random I/O during integrity audits).
