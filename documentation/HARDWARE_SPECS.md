# System Requirements & Hardware Specifications

## Summary
A technical breakdown of the hardware and software requirements for the Document Tracking System (DTS). This document provides the technical rationale behind the system's performance limits, memory tuning, and storage forecasts.

## Table of Contents
1. [Production Server Requirements](#1-production-server-requirements)
2. [Software Infrastructure](#2-software-infrastructure)
3. [Implementation: Database Memory Optimization](#3-implementation-database-memory-optimization)
4. [Implementation: CPU Thread Allocation](#4-implementation-cpu-thread-allocation)
5. [Storage Scaling for 1M+ Records](#5-storage-scaling-for-1m-records)
6. [Glossary of Terms](#6-glossary-of-terms)

---

## 1. Production Server Requirements

To maintain sub-second response times with a massive dataset, the server must be "right-sized." These specifications support the system's high-memory database strategy and multi-threaded background processing.

| Component | Minimal (Base Operations) | Recommended (1M+ Records) |
|:---|:---|:---|
| **OS** | Ubuntu 24.04 LTS | Ubuntu 24.04 LTS (HWE Kernel) |
| **CPU** | 4 Cores (2.5GHz+) | 12+ Cores (3.0GHz+) |
| **RAM** | 8 GB | 32 GB+ |
| **Storage** | 50 GB SSD (SATA) | 200 GB+ NVMe SSD (High IOPS) |
| **Network** | 100 Mbps | 1 Gbps (Low Latency) |

---

## 2. Software Infrastructure

The following software versions are mandatory for the "Trust Builder" security suite and AI prediction logic:
- **PHP 8.3+**: Required for the `sodium` library, which handles the Ed25519 digital signatures.
- **MySQL 8.0+**: Required for **JSON** data storage and advanced **Window Functions** used in analytics.
- **Redis 7.0+**: A specialized "Speed Layer" used to store temporary analytics and manage high-volume reporting.
- **Nginx 1.24+**: A high-performance "Front Door" for the web server.

---

## 3. Implementation: Database Memory Optimization

The DTS uses a **Large Buffer Pool** strategy. Think of the server's RAM as a "Desk" and the Hard Drive as a "Filing Cabinet."
- **Standard Systems**: Keep only a few folders on the desk. Every time they need something else, they have to walk to the filing cabinet (slow).
- **DTS Strategy**: We use a massive "Desk" (4GB Buffer Pool). This allows us to keep the "Index" (the map) of 1,000,000 documents on the desk at all times.
- **Result**: Looking up a document by its Tracking Code is almost instantaneous, regardless of how many millions of documents are in the cabinet.

---

## 4. Implementation: CPU Thread Allocation

We use a technique called **CPU Core Pinning** (via `taskset`) to ensure that heavy background tasks (like generating reports) don't slow down the main website.
Imagine a highway with 12 lanes:
- **Lanes 0-3**: Reserved exclusively for the **Web Server**. Even if 100 people are submitting documents, the website stays snappy.
- **Lane 4**: Reserved for the **AI and PDF Reports**. This lane can be 100% full, but it won't block the website lanes.
- **Lanes 6-7**: Reserved for **Asset Bundling** (Vite).
- **Lane 8**: Reserved for the **Task Scheduler** (Pruning and Backups).

---

## 5. Storage Scaling for 1M+ Records

The "Trust Builder" creates a lot of cryptographic data. Below is the storage forecast for 1 million documents:

1.  **Core Document Data**: ~1.5 GB
2.  **The Cryptographic Ledger (5M logs)**: ~3.5 GB
3.  **PDF Reports Cache**: ~10 GB (temporary files generated during large exports)
4.  **Database Metrics History**: ~500 MB
5.  **Total Database Footprint**: **~15 GB to 20 GB**

> **Recommendation**: Always use **NVMe SSDs**. During a security audit, the system has to "read" millions of logs very quickly. NVMe drives are significantly faster than SATA at this type of "Random Access."

---

## 6. Glossary of Terms

*   **Buffer Pool**: A slice of RAM used to keep the most important parts of the database "on the desk" for instant access.
*   **Core Pinning**: A way to force specific software to only use specific CPU cores, preventing one task from slowing down others.
*   **HWE Kernel (Hardware Enablement)**: A specific version of Linux that is optimized for newer, faster server hardware.
*   **IOPS (Input/Output Operations Per Second)**: A measure of how many "reads" or "writes" a hard drive can do in one second. Higher is better for databases.
*   **NVMe SSD**: The newest, fastest type of hard drive. It connects directly to the server's brain for maximum speed.
*   **RAM (Random Access Memory)**: The server's "Active Memory." It's thousands of times faster than a hard drive but loses its data when the power goes out.
*   **Redis**: A "Speed Layer" database that stores data entirely in RAM for sub-millisecond access.
*   **SATA SSD**: An older, slower type of hard drive. Fine for basic use, but can become a bottleneck for million-record systems.
*   **Taskset**: The command-line tool used to perform CPU Core Pinning.
