# Admin Dashboard Logic

A comprehensive technical breakdown of the administrative dashboard, detailing the visualization of system metrics, data aggregation strategies, and real-time performance monitoring through Chart.js and Laravel.

## Table of Contents
1. [High-Level Architecture](#high-level-architecture)
2. [Data Fetching & Caching Strategy](#data-fetching--caching-strategy)
3. [Chart-by-Chart Technical Breakdown](#chart-by-chart-technical-breakdown)
4. [Query Logic & Data Integrity](#query-logic--data-integrity)
5. [User Interactions & Dynamic Filtering](#user-interactions--dynamic-filtering)

---

## High-Level Architecture

The admin dashboard's charting system is built on a decoupled, three-tier architecture designed for responsiveness and precision:

1.  **Backend (Laravel Controller):** `AdminDashboardController` serves as the data provider. Each chart has a dedicated API endpoint that performs complex SQL aggregations, time-difference calculations, and JSON serialization.
2.  **Frontend (Blade View):** `resources/views/admin/dashboard.blade.php` defines the structural layout. It uses a grid system to house responsive `<canvas>` elements and semantic `<select>` filters.
3.  **Client-Side (Chart.js):** `resources/js/admin-dashboard.js` handles the orchestration. It manages chart initialization, event delegation for filters, and asynchronous state updates without requiring full page reloads.

---

## Data Fetching & Caching Strategy

To ensure high performance even with large datasets, the dashboard employs a selective caching strategy:

-   **Asynchronous Fetching:** Each chart fetches its data independently via the `fetch` API. This allows the dashboard to remain interactive while heavy metrics (like global throughput) are loading in the background.
-   **Server-Side Caching:** Metrics requiring expensive joins or subqueries (e.g., `Throughput` and `Departmental Load vs Time`) are cached using `Cache::remember()` for **5 minutes**.
-   **Cache Invalidation:** Administrators can manually trigger `clearCache()` via the dashboard UI, which flushes all dashboard-related metrics to ensure the most current data is reflected.
-   **Zero-Gap Mapping:** To prevent "broken" line charts, the backend generates a `periodMap` for every time series (Daily, Weekly, Monthly, Yearly). This ensures that time periods with zero activity are still represented as `0` rather than being omitted.

---

## Chart-by-Chart Technical Breakdown

### 1. Document Status Distribution
-   **Type:** Doughnut Chart
-   **Logic:** Aggregates all documents by their `status` column.
-   **Color Mapping:** Uses a standardized color map (e.g., Green for `completed`, Red for `declined`) to provide consistent visual cues.

### 2. Global Throughput (Avg. Processing Time)
-   **Type:** Line Chart
-   **Logic:** Calculates the end-to-end duration for documents. It joins two subqueries:
    -   `start_logs`: MIN(created_at) for 'Accepted and Document Routing finalized'.
    -   `end_logs`: MAX(created_at) for 'Document Released'.
-   **Metric:** Average `TIMESTAMPDIFF` in **hours**.

### 3. Average Step Time (TAT) by Department
-   **Type:** Horizontal Bar Chart
-   **Logic:** Measures internal departmental efficiency (Turnaround Time).
-   **Calculation:** It identifies pairs of 'Received' and 'Processing Complete' logs for the same document within the same department and calculates the delta.
-   **Features:** Shows the top 5 slowest departments by default; includes a "View Full Report" modal for a system-wide overview.

### 4. Return & Decline Rate Trends
-   **Type:** Multi-Dataset Line Chart
-   **Logic:** Tracks `declined` status timestamps vs. `Return Request` action logs over time.
-   **Purpose:** Monitors system friction and identifies periods of high rejection rates.

### 5. Return Request Sources
-   **Type:** Bar Chart
-   **Logic:** Groups `Return Request` logs by the originating user's department.
-   **Purpose:** Identifies "bottleneck creators"—departments that frequently send documents back for corrections.

### 6. Department Drill-Down: Load vs. Processing Time
-   **Type:** Dual-Axis Combination Line Chart
-   **Logic:** Correlates workload (`Received` logs) against processing efficiency (Avg Step Time) for a specific department.
-   **Dynamic Context:** The chart title and data update dynamically based on the selected department and time period.

### 7. Processing Hotspots by Purpose
-   **Type:** Polar Area Chart
-   **Logic:** Ranks the top 15 document purposes by volume.
-   **Tooltip Enhancement:** Displays both the total volume (area size) and the average processing time (text tooltip) for that specific document category.

---

## Query Logic & Data Integrity

-   **ISO 8601 Standardization:** All weekly groupings use the `%x-%v` format (ISO Year-Week). This prevents the "Week 53" overlap bug and ensures data consistency across years.
-   **JSON Route Filtering:** Since document routes are stored as JSON arrays, the dashboard utilizes `whereJsonContains` to efficiently filter documents that are currently or previously routed through a specific department.
-   **Safety Offsets:** All time calculations include checks to ensure durations are non-negative, protecting averages against manual timestamp edits or system clock drift.

---

## User Interactions & Dynamic Filtering

-   **Isolated Updates:** Changing the "Department" filter in the drill-down section only triggers a re-fetch for that specific chart, preserving the state of all other charts.
-   **Modal Detail View:** Large bar charts (like TAT) utilize a "Modal Instance" pattern where a new Chart.js instance is created inside a modal to display high-resolution data that would otherwise be cramped in the main grid.
-   **Responsive Containers:** All charts are housed in fixed-height containers to prevent the "Infinite Resize" bug common in Chart.js when used inside flexbox/grid layouts.
