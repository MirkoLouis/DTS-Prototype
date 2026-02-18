# System Monitoring: Data Generation & Logic

This document explains the logic behind the data generation for the system's monitoring charts, including the initial historical data and the live, ongoing data collection.

## 1. Goal

The primary goal is to provide a rich, comprehensive dataset for analysis. This is achieved in two ways:
1.  **Historical Data:** A large, realistic set of historical data is generated one time to provide context and allow for trend analysis right after installation.
2.  **Live Data:** A live monitoring system continuously captures real-time performance data as the application is being used.

## 2. The Seeding Process (Historical Data)

The `DocumentSeeder.php` file is a one-time script responsible for generating the historical data. The process is broken down into three main stages:
1.  **Stage 1: Create Base Documents:** This stage quickly creates 10,000 basic `Document` records.
2.  **Stage 2: Generate Detailed History & Collect Metrics:** This is the most complex stage. The seeder iterates through each document and builds a detailed, simulated history (creating `DocumentLog` entries). **Simultaneously, it generates a corresponding database performance metric for each event in the document's timeline.** These metrics are collected into a large array.
3.  **Stage 3: Insert Metrics:** After all document histories and metrics have been collected, this final stage inserts the entire array of metrics into the `database_metrics` table in chunks.

## 3. The Core Logic: `$generateMetrics` Helper (Historical)

Inside the seeder's main loop, a helper function named `$generateMetrics` is defined. This is the heart of the *historical* metric generation. It is designed to create **correlated** data, meaning the simulated database metrics should reflect the simulated activity.

```php
$generateMetrics = function($timestamp, $isPeak = false) use (&$metricsToInsert) {
    // ... logic to calculate metrics ...
};
```
This function is called every time a `DocumentLog` entry is simulated, and the `$isPeak` flag is used to create performance "spikes" during complex simulated events.

## 4. How Historical Metrics are Calculated

Here is a breakdown of how the values for each historical metric are determined inside the `$generateMetrics` function.

### a. Connections
The number of connections simulates a daily traffic pattern, with a higher baseline during business hours and additional spikes during "peak" events.

### b. Average Query Time (ms)
This simulates database efficiency. The average time is low for normal operations but increases significantly during "peak" events to represent a heavier load.

### c. Slow Queries
This simulates performance bottlenecks. There is a small random chance of a slow query during normal operations, but it's guaranteed during "peak" events.

## 5. The Live Monitoring System

Separate from the seeder, there is a live monitoring system that captures real performance data.

*   **The Command:** A custom Artisan command, `dts:snapshot-db-metrics`, queries the database's `performance_schema` to get the *actual, current* state of database performance.
*   **The Scheduler:** This command is scheduled to run in the background every five minutes. It is completely independent of the seeder.

## 6. Seeded Data vs. Live Data: How They Work Together

You are correct—fundamentally, the system **does not differentiate** between a document created by the seeder and one created by a real user on the guest page. Both are simply rows in the `documents` and `document_logs` tables.

This is how the two types of data provide a complete picture:

1.  **Baseline History (`php artisan migrate:fresh --seed`):** When you run the seeder, it populates the `documents` and `document_logs` tables with thousands of entries. Crucially, it also populates the `database_metrics` table with thousands of corresponding *fake, historical* performance data points. This gives you a rich, multi-year dataset to view on your charts immediately.

2.  **Live Activity (`php artisan schedule:work`):** When the scheduler is running, the `dts:snapshot-db-metrics` command executes every five minutes. It queries the live database and records its *real* performance at that moment. When a guest submits a document from the welcome page, their actions generate real database load. The next time the snapshot command runs, it will capture the effect of that load, and a **real data point** will be added to the `database_metrics` table.

**Conclusion:** The chart seamlessly displays both. The vast majority of the data you see initially is the historical data from the seeder. As you and other users interact with the application, new, live data points will be added to the end of the chart every five minutes, gradually building a true performance history of the live application.
