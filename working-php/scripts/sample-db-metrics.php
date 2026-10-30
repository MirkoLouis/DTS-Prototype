<?php
/**
 * Production Telemetry Sampler for Database Performance Metrics.
 *
 * Every run records one row in database_metrics for the interval since the previous run:
 *   connections        Threads_connected at sampling time
 *   avg_query_time_ms  mean execution time of the application's statements in the interval,
 *                      from performance_schema.events_statements_summary_by_digest
 *                      (NULL when no statements ran or Performance Schema is unavailable)
 *   slow_queries       number of new slow queries in the interval (delta of Slow_queries)
 *
 * MySQL counters are cumulative, so the previous run's totals are kept in a small state file.
 * Run by console.php every 5 minutes.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Common\Database\Database;

const STATE_FILE = BASE_PATH . '/storage/cache/db-metrics-state.json';

/**
 * Difference between two cumulative counter readings; a smaller current value means the
 * counter was reset (server restart or digest table truncation), so the current value is the delta.
 */
function counterDelta(float $current, ?float $previous): float
{
    if ($previous === null || $current < $previous) {
        return $current;
    }
    return $current - $previous;
}

try {
    $db = Database::getInstance();

    // 1. Current active connection thread count
    $res = $db->query("SHOW STATUS WHERE Variable_name = 'Threads_connected'")->fetch();
    $connections = isset($res['Value']) ? (int) $res['Value'] : 0;

    // 2. Cumulative slow query counter since server start
    $resSlow = $db->query("SHOW GLOBAL STATUS WHERE Variable_name = 'Slow_queries'")->fetch();
    $slowTotal = isset($resSlow['Value']) ? (float) $resSlow['Value'] : 0.0;

    // 3. Cumulative statement count and wait time (picoseconds) for this application's schema,
    //    excluding the sampler's own SHOW/performance_schema statements
    $digestCount = null;
    $digestTimerPs = null;
    try {
        $digest = $db->query(
            "SELECT COALESCE(SUM(COUNT_STAR), 0) AS cnt, COALESCE(SUM(SUM_TIMER_WAIT), 0) AS timer_ps
             FROM performance_schema.events_statements_summary_by_digest
             WHERE SCHEMA_NAME = DATABASE()
               AND DIGEST_TEXT NOT LIKE 'SHOW%'
               AND DIGEST_TEXT NOT LIKE '%performance_schema%'"
        )->fetch();
        $digestCount = (float) $digest['cnt'];
        $digestTimerPs = (float) $digest['timer_ps'];
    } catch (\PDOException $e) {
        error_log("Performance Schema unavailable for metrics sampling: " . $e->getMessage());
    }

    // 4. Load the previous run's cumulative readings
    $previous = [];
    if (is_file(STATE_FILE)) {
        $previous = json_decode((string) file_get_contents(STATE_FILE), true) ?: [];
    }

    // 5. Convert cumulative counters into per-interval values
    $slowQueries = (int) counterDelta($slowTotal, $previous['slow_total'] ?? null);

    $avgQueryTimeMs = null;
    if ($digestCount !== null) {
        $countDelta = counterDelta($digestCount, $previous['digest_count'] ?? null);
        $timerDelta = counterDelta($digestTimerPs, $previous['digest_timer_ps'] ?? null);
        if ($countDelta > 0) {
            // Picoseconds per statement -> milliseconds
            $avgQueryTimeMs = round(($timerDelta / $countDelta) / 1e9, 4);
        }
    }

    // 6. Persist current cumulative readings for the next run
    if (!is_dir(dirname(STATE_FILE))) {
        mkdir(dirname(STATE_FILE), 0755, true);
    }
    file_put_contents(STATE_FILE, json_encode([
        'slow_total' => $slowTotal,
        'digest_count' => $digestCount,
        'digest_timer_ps' => $digestTimerPs,
        'sampled_at' => date('c')
    ]));

    // 7. Record the interval sample
    $db->query(
        "INSERT INTO database_metrics (connections, avg_query_time_ms, slow_queries, created_at) VALUES (:conn, :time, :slow, NOW())",
        [
            'conn' => $connections,
            'time' => $avgQueryTimeMs,
            'slow' => $slowQueries
        ]
    );

    $timeLabel = $avgQueryTimeMs === null ? 'n/a' : "{$avgQueryTimeMs}ms";
    echo "Database metrics sampled: {$connections} connections, mean statement time {$timeLabel}, {$slowQueries} new slow queries.\n";
} catch (\Throwable $e) {
    error_log("Failed to sample database metrics: " . $e->getMessage());
    echo "Error sampling database metrics: " . $e->getMessage() . "\n";
}
