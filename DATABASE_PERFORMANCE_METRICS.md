# Database Performance Metrics

This document explains the purpose and functionality of the Database Performance chart found on the System Health page.

## 1. Purpose of the Chart

The Database Performance chart provides a real-time, at-a-glance overview of the health and efficiency of the application's database. Monitoring these metrics is crucial for identifying potential bottlenecks, diagnosing performance issues, and ensuring a smooth experience for users. By tracking these indicators over time, administrators can proactively address problems before they become critical.

## 2. How the Data is Collected

The chart is powered by a system that periodically captures and stores key performance indicators from the database.

1.  **Data Source (MySQL Performance Schema):** The system leverages MySQL's built-in `Performance Schema`, a powerful feature that provides detailed statistics about server events, including query execution times. This must be enabled on your database server.

2.  **Data Snapshot Command:** A custom Artisan command, `dts:snapshot-db-metrics`, has been created. This command queries the `Performance Schema` to gather the current metrics.

3.  **Storage:** The captured metrics are stored in a dedicated `database_metrics` table. Each time the command runs, it adds a new row with the latest data and a timestamp.

4.  **Scheduled Task:** To ensure the data is collected consistently, the `dts:snapshot-db-metrics` command is automatically executed every five minutes via Laravel's Task Scheduler. This provides the historical data needed to render the time-series charts.

## 3. Understanding the Metrics

The chart displays three critical performance metrics. Here's what they mean and how they relate to overall database health:

### a. Connections

*   **What it is:** This line shows the number of active connections to the database at the time of the snapshot.
*   **Why it matters:** Every user action, from loading a page to submitting a form, requires a connection to the database. This metric is a direct indicator of how much load the application is putting on the database.
*   **What to look for:**
    *   **High, sustained connections:** A consistently high number of connections can indicate that the database is under heavy load. It can also be a symptom of "connection leaks," where the application is not properly closing connections after use.
    *   **Relation to Memory:** Each database connection consumes memory on the server. A very high number of connections can lead to increased memory usage and potentially exhaust the server's available RAM, causing performance degradation or crashes.

### b. Average Query Time (ms)

*   **What it is:** This line represents the average amount of time (in milliseconds) it took for the database to execute all queries during the snapshot period.
*   **Why it matters:** This is one of the most important indicators of database efficiency. Faster query times lead to a faster, more responsive application.
*   **What to look for:**
    *   **Upward trends:** A steady increase in average query time is a major red flag. It often points to inefficient queries, missing database indexes, or an overloaded server. As your database grows, queries that were once fast can become slow if not properly optimized.
    *   **Relation to CPU:** Inefficient queries (e.g., those that have to scan millions of rows because of a missing index) are CPU-intensive. A spike in average query time will often correspond to a spike in the database server's CPU usage.

### c. Slow Queries

*   **What it is:** This bar chart shows the total number of queries that took longer than a predefined threshold (currently 1 second) to execute.
*   **Why it matters:** Slow queries are the primary culprits behind a sluggish user experience. A single slow query on a frequently accessed page can bring the entire application to a crawl.
*   **What to look for:**
    *   **Any slow queries:** Ideally, this number should be zero. Any non-zero value indicates a performance bottleneck that needs to be investigated.
    *   **Identifying the queries:** While this chart shows the *number* of slow queries, the next step in a real investigation would be to use a tool like MySQL's slow query log to identify the exact SQL statements that are performing poorly so they can be optimized.
