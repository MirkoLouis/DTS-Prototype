# Data Generation & Simulation Logic

This document details the strategies used to populate the Document Tracking System (DTS) with both initial production data and a rich, simulated historical dataset for monitoring and development.

## Table of Contents
1. [Seeder Categories](#seeder-categories)
2. [Simulation Engine (DocumentSeeder)](#simulation-engine-documentseeder)
3. [Correlated Performance Metrics](#correlated-performance-metrics)
4. [Live vs. Seeded Data Integration](#live-vs-seeded-data-integration)

---

## Seeder Categories

The project organizes its data generation into two distinct categories based on their purpose and environment.

### 1. Core / Production Seeders
These seeders provide the foundation for the application. They contain official DepEd data and are required for the system to function correctly in any environment.

| Seeder | Purpose |
|:---|:---|
| `DepartmentSeeder.php` | Populates the official DepEd divisions (Records Unit, ICT, HR, Cashier, etc.). |
| `PurposeSeeder.php` | Seeds common document purposes (e.g., "Request for TOR") with their required attachments and suggested routes. |
| `PredictionKeywordSeeder.php` | Initializes the AI Route Prediction engine with its first set of weighted keywords. |
| `UserSeeder.php` | Creates the initial Administrator and Records Officer accounts. |
| **`ProductionSeeder.php`** | **The Wrapper:** Runs all the above for a clean, production-ready setup. |

### 2. Development / Simulation Seeders
These seeders are designed to create a "living" environment for testing, debugging, and demonstrating the analytics dashboards.

| Seeder | Purpose |
|:---|:---|
| `DocumentSeeder.php` | The complex simulation engine. Generates 10,000 documents with detailed, multi-year history and cryptographic hash chains. |
| **`DevelopmentSeeder.php`** | **The Wrapper:** Runs all Production seeders PLUS the `DocumentSeeder` for a rich development dataset. |

---

## Simulation Engine (DocumentSeeder)

The `DocumentSeeder` is the most complex part of the data generation logic. It doesn't just create random records; it simulates a **living workflow** spanning the last **5 years**.

### 1. Historical Timeline Logic
Each document's initial `created_at` timestamp is randomized within a 5-year window. The logic intelligently ensures that:
-   Most actions happen during **business hours** (8 AM to 5 PM).
-   Timestamps do not fall on **weekends** (automatically moving them to the next Monday).
-   Steps follow a logical chronological order (Receive -> Process -> Release).

### 2. Workflow Complexity
To mimic real-world unpredictability, the simulation includes:
-   **Declines (1% chance):** Documents that are rejected during intake with one of several realistic reasons.
-   **Return Requests (10% chance):** Documents where a department requests a correction from a previous step, creating a non-linear "loop" in the hash chain.
-   **Status Variation:** A mix of `completed`, `processing`, and `declined` documents.

---

## Correlated Performance Metrics

A key innovation in the seeding process is the generation of **correlated database metrics**. 

When a document log is simulated, the system uses a `$generateMetrics` helper function to add a corresponding entry into the `database_metrics` table. This ensures the dashboard charts look realistic:
-   **Business Hours:** Connection counts are higher during simulated daytimes.
-   **Performance "Spikes":** Complex actions like "Return Requested" or "Document Declined" trigger higher query times and a higher chance of a "Slow Query" in the logs.
-   **Long-Term Trends:** Because the data is spread over 5 years, the "Bottleneck Detector" can immediately show long-term throughput trends.

---

## Live vs. Seeded Data Integration

A critical design choice of the DTS is that **the system does not differentiate between seeded and live data**.

### 1. From the Guest Portal
When a real guest visits the `welcome.blade.php` page and submits a request, the `GuestController@store` method creates a document record and an initial `DocumentLog` entry.
-   **Structure:** This record is identical in structure to those created by the seeder.
-   **Integrity:** The model's `boot()` method automatically calculates the same cryptographic hash chain used during simulation.

### 2. Monitoring Convergence
-   **The History:** The seeder "backfills" the `database_metrics` table with historical data points.
-   **The Live Data:** The `php artisan dts:snapshot-db-metrics` command (running every 5 minutes) captures the **real** performance of the server.
-   **The Convergence:** On the Admin Dashboard, the historical data and the live snapshots are rendered on the same timeline, providing a seamless view of the system's performance evolution.
