# DTS Administration & Analytics

## Summary
A technical breakdown of the Document Tracking System's administrative tools, including the main dashboard for business analytics, the system health monitor for technical oversight, and performance optimization strategies.

## Table of Contents
1. [Admin Dashboard Logic](#1-admin-dashboard-logic)
2. [System Health Monitor](#2-system-health-monitor)
3. [Database Performance & Metrics](#3-database-performance--metrics)
4. [Analytics & Data Summarization](#4-analytics--data-summarization)

---

## 1. Admin Dashboard Logic

The admin dashboard's charting system is built on a decoupled, three-tier architecture using Laravel, Blade, and Chart.js.

### Implementation: Performance-First Caching
To ensure high performance with large datasets, the dashboard fetches chart data independently and caches expensive calculations.

```php
// app/Http/Controllers/AdminDashboardController.php
public function getThroughputData(Request $request)
{
    $cacheKey = "throughput_{$request->period}_{$request->department_id}";
    
    return Cache::remember($cacheKey, now()->addMinutes(5), function () {
        // Complex SQL logic joins min/max logs to calculate end-to-end duration
        $startLogs = DB::table('document_logs')->where('action', 'Intake')->select('document_id', 'created_at');
        $endLogs = DB::table('document_logs')->where('action', 'Released')->select('document_id', 'created_at');
        
        return DB::table('documents')
            ->joinSub($startLogs, 's', 'documents.id', '=', 's.document_id')
            ->joinSub($endLogs, 'e', 'documents.id', '=', 'e.document_id')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, s.created_at, e.created_at)) as avg_hrs')
            ->get();
    });
}
```

### SQL Performance Optimization: Turnaround Time (TAT)
The system uses MySQL 8.0 Window Functions to calculate durations between logs directly in SQL, avoiding the "RAM trap" of processing millions of records in PHP.

```php
// Turnaround Time (TAT) Calculation using Window Functions
$query->select(
    'document_id',
    'created_at',
    DB::raw("LAG(created_at) OVER (PARTITION BY document_id ORDER BY created_at) as prev_at"),
    DB::raw("LAG(action) OVER (PARTITION BY document_id ORDER BY created_at) as prev_action")
);
```

---

## 2. System Health Monitor

A centralized dashboard for administrators to oversee technical well-being and data integrity.

### Components
- **Avg. Processing Time**: Real-time throughput indicator.
- **Failed Jobs**: Monitoring of background tasks like report generation or AI learning.
- **Cache Status**: Validates the availability of the caching layer (Redis or File-based).
- **Integrity Status**: Summary of the "Trust Builder" verification checks.

### Administration Utilities
- **Backup Manager**: Manual and scheduled backups via `spatie/laravel-backup`.
- **Integrity Repair**: Manual triggering of hash chain rebuilds for corrupted logs.
- **Client Ratings**: Qualitative analysis of system performance from guest feedback.

---

## 3. Database Performance & Metrics

Tracks database server efficiency through metrics captured every 5 minutes by the `dts:snapshot-db-metrics` command.

### Implementation: RAM Optimization
The system programmatically tunes the database for high performance using the `dts:tune-db` Artisan command.

```php
// app/Console/Commands/TuneDatabase.php
public function handle() {
    // Set InnoDB Buffer Pool to 4GB to keep 1M record index in RAM
    DB::statement("SET GLOBAL innodb_buffer_pool_size = 4294967296;");
    
    // Set Log File Size to 1GB for faster write throughput
    DB::statement("SET GLOBAL innodb_log_file_size = 1073741824;");
}
```

---

## 4. Analytics & Data Summarization

The `DatabasePerformanceService` handles high-density data by summarizing 5-minute snapshots into readable chart intervals.

### Summarization Logic
To prevent charts from becoming unreadable, the system groups metrics by hour, day, or week and calculates averages.

```php
// app/Services/DatabasePerformanceService.php
$results = $query->get()->groupBy(function($date) {
    return Carbon::parse($date->created_at)->format('Y-m-d H:00');
});

$connectionsData = $results->map(fn($group) => $group->avg('connections'));
$slowQueriesData = $results->map(fn($group) => $group->sum('slow_queries'));
```

### Scalability: The 1 Million Document Goal
The system is designed to handle 1,000,000 documents (approx. 5,000,000 logs) by ensuring application-level memory usage remains constant through the use of `chunkById()` and SQL-level aggregations.
