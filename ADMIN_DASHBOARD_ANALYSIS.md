# Admin Dashboard Chart Analysis

## High-Level Architecture

The admin dashboard's charting system is built on a three-part architecture:

1.  **Backend (Laravel Controller):** The `app/Http/Controllers/AdminDashboardController.php` contains dedicated methods for each chart. These methods are responsible for querying the database, performing calculations (counts, averages, time differences), and returning the data in a structured JSON format.
2.  **Frontend (Blade View):** The `resources/views/admin/dashboard.blade.php` file defines the HTML structure and layout of the dashboard using TailwindCSS grids. It contains a `<canvas>` element for each chart and the `<select>` dropdowns for filtering.
3.  **JavaScript (Chart.js):** The `resources/js/admin-dashboard.js` file ties everything together. It initializes each chart, attaches specific event listeners to the filters, and handles fetching data from the backend API endpoints to update the charts dynamically.

The system is designed to be efficient by only reloading the data for charts affected by a specific filter, rather than reloading all charts on every change.

---

## Chart-by-Chart Breakdown

### 1. Document Status Distribution
- **Type:** Doughnut Chart
- **Location:** Top Row
- **Purpose:** Provides an at-a-glance overview of the current distribution of all documents across the system by their status (e.g., 'processing', 'completed', 'declined').
- **Data Source:** `getDocumentStatusDistributionData()` - Performs a `COUNT` and `GROUP BY` on the `documents` table's `status` column.
- **Filters:** None (Static).

### 2. Global Average Processing Time (hrs)
- **Type:** Line Chart
- **Location:** Top Row
- **Purpose:** Tracks the overall system efficiency by showing the average end-to-end processing time for all documents.
- **Data Source:** `getThroughputData()` - Calculates the average `TIMESTAMPDIFF` in **hours** between the 'Accepted and Document Routing finalized' log and the 'Document Released' log.
- **Filters:** Time Period (`globalThroughputPeriod`).

### 3. Average Processing Time (hrs)
- **Type:** Horizontal Bar Chart
- **Location:** Top Row
- **Purpose:** Quickly identifies the top 5 slowest departments based on their internal processing time.
- **Data Source:** `getAvgStepTimeByDepartmentData()` - Calculates the average time between a 'Received' log and a 'Processing Complete' log, grouped by department. Returns only the top 5 results.
- **Filters:** None (Static view of top 5).
- **Special Feature:** Includes a "View Full Chart" button that opens a modal to display the same metric for all departments.

### 4. Return & Decline Rate Trends
- **Type:** Line Chart (2 datasets)
- **Location:** Middle Row
- **Purpose:** Monitors trends in document rejections and returns over time.
- **Data Source:** `getReturnDeclineTrendData()` - Fetches two data series: a count of documents with `status = 'declined'` and a count of logs with `action LIKE '%Return Request%'`.
- **Filters:** Time Period (`returnDeclinePeriod`).

### 5. Return Request Sources
- **Type:** Bar Chart
- **Location:** Middle Row
- **Purpose:** Identifies which departments are issuing the most return requests.
- **Data Source:** `getReturnRequestSourcesData()` - Counts `Return Request` logs and groups them by the user's department.
- **Filters:** None (Static).

### 6. Department Drill-Down: Load vs. Processing Time
- **Type:** Combination Line Chart (2 datasets, 2 Y-axes)
- **Location:** "Department Drill-Down" Section
- **Purpose:** Provides a powerful correlation view for a specific department, plotting its incoming workload against its processing efficiency over time.
- **Title:** The chart title is dynamic and updates to reflect the selected department (e.g., "Records Unit - Load vs. Processing Time").
- **Data Source:** `getDepartmentalLoadVsTimeData()`
    - **Load (Left Y-Axis):** Counts 'Received' logs for the selected department, grouped by period.
    - **Time (Right Y-Axis):** Calculates the average internal step time (from 'Received' to 'Processing Complete') in hours for the selected department, grouped by period.
- **Filters:** Department (`department-filter`) and Time Period (`departmentPeriod`).

### 7. Processing Hotspots by Purpose
- **Type:** Horizontal Bar Chart
- **Location:** Bottom Row (Full Width)
- **Purpose:** Identifies which types of documents (by purpose) take the longest to process from start to finish, helping to find bottlenecks related to document categories.
- **Data Source:** `getProcessingHotspotsData()` - Calculates the average end-to-end processing time for documents, grouped by the top 10 most common purposes.
- **Filters:** None (Static).

---

## Key Technical Improvements
- **Efficient Filtering:** Event listeners are decoupled, ensuring that changing a filter only reloads data for the specific chart(s) it controls.
- **Standardized Weekly Calculations:** All weekly groupings now use the ISO 8601 standard on both the backend and in the database, fixing a bug that caused some weeks to show a value of 0. The time window has been standardized to 12 weeks.
- **Layout Stability:** All charts are now placed within containers with a fixed height, preventing the "infinite height" bug where charts would uncontrollably expand.
- **Data Integrity:** The code that calculates time durations now explicitly checks for non-negative results, making the averages robust against inconsistent or erroneous log timestamps.
- **Dynamic Titles:** The titles in the "Department Drill-Down" section update dynamically based on the selected department, providing better context to the user.

---

## System Health & Analytics Charts (`system-health.blade.php`)

This dashboard provides real-time and historical views into the server and application's core health metrics. While the main admin dashboard focuses on document and user-centric analytics, this page focuses on the technical performance and stability of the system itself.

### 1. Failed Jobs Over Time
- **Type:** Line Chart
- **Purpose:** To monitor the number of failed background jobs over a period. Spikes can indicate problems with queues, external APIs, or faulty job logic.
- **Data Source:** `getFailedJobsTrend()` - Would query the `failed_jobs` table, counting entries grouped by day or week.
- **Filters:** Time Period.

### 2. Average Job Wait Time
- **Type:** Line Chart
- **Purpose:** To track the efficiency of the queue workers. A rising average wait time could indicate that the queue workers are overloaded and more resources may be needed.
- **Data Source:** `getAverageJobWaitTime()` - Would calculate the average difference between `created_at` and `processed_at` (or a similar metric) for jobs.
- **Filters:** Time Period, Queue Name.

### 3. Cache Hit/Miss Ratio
- **Type:** Stacked Bar Chart or Line Chart (2 datasets)
- **Purpose:** To visualize the effectiveness of the application cache. A high hit ratio is desirable. A low or decreasing hit ratio might suggest issues with cache invalidation or that the cache isn't being used effectively.
- **Data Source:** `getCacheHitMissRatio()` - Would require custom logic to track cache events (e.g., using Laravel's event system) and store/aggregate the data.
- **Filters:** Time Period.

### 4. Database Performance Metrics
- **Type:** A series of small line charts or a single multi-line chart.
- **Purpose:** To monitor key database performance indicators.
- **Data Source:** This is more complex and might require a dedicated package or platform-specific monitoring tools (e.g., MySQL Performance Schema). Metrics could include:
    - Average Query Time
    - Number of Slow Queries
    - Connections
- **Filters:** Time Period.

### 5. Integrity Check History
- **Type:** Table / Log
- **Purpose:** While not a chart, this view would show a history of when the integrity check was run, who ran it, and what the result was. This provides a clear audit trail.
- **Data Source:** A new table, e.g., `integrity_check_logs`, would be needed to store this history.
