<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DatabasePerformanceService
{
    /**
     * Get database performance metrics.
     *
     * @param  string  $period
     * @return array
     */
    public function getPerformanceMetrics($period = 'now')
    {
        // For now, we will only fetch the current number of connections.
        // In a real-world scenario, you would query a time-series database
        // or log file for historical data based on the period.

        return [
            'connections' => $this->getConnections(),
            'avg_query_time' => $this->getAverageQueryTime($period),
            'slow_queries' => $this->getSlowQueries($period),
        ];
    }

    /**
     * Get the current number of database connections.
     *
     * @return int
     */
    protected function getConnections()
    {
        try {
            // This query works for MySQL.
            $result = DB::select("SHOW STATUS WHERE `variable_name` = 'Threads_connected'");
            return isset($result[0]->Value) ? (int) $result[0]->Value : 0;
        } catch (\Exception $e) {
            // Log the error or handle it as needed
            return 0;
        }
    }

    /**
     * Get the average query time. (Placeholder)
     *
     * @param  string  $period
     * @return float
     */
    protected function getAverageQueryTime($period)
    {
        // This is a placeholder. A real implementation would require
        // querying the performance_schema or a log aggregator.
        return rand(10, 50) / 1000; // Random value in ms
    }

    /**
     * Get the number of slow queries. (Placeholder)
     *
     * @param  string  $period
     * @return int
     */
    protected function getSlowQueries($period)
    {
        // This is a placeholder. A real implementation would require
        // querying the performance_schema or a log aggregator.
        return rand(0, 5);
    }

    /**
     * Get historical data for the charts.
     * Refactored to use SQL-level aggregation for 1M+ record scaling.
     *
     * @param string $period
     * @return array
     */
    public function getChartData($period = 'daily')
    {
        $dateFormat = match ($period) {
            'hourly' => '%H:00',
            'weekly' => '%Y-%m-%d (Week %v)',
            'monthly' => '%M %Y',
            default => '%M %d', // daily
        };

        $startDate = match ($period) {
            'hourly' => now()->subHours(24),
            'weekly' => now()->subWeeks(12),
            'monthly' => now()->subMonths(12),
            default => now()->subDays(30),
        };

        $results = DB::table('database_metrics')
            ->where('created_at', '>=', $startDate)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '{$dateFormat}') as label"),
                DB::raw('AVG(connections) as avg_connections'),
                DB::raw('AVG(avg_query_time_ms) as avg_query_time'),
                DB::raw('SUM(slow_queries) as total_slow_queries'),
                DB::raw('MIN(created_at) as sort_date')
            )
            ->groupBy('label')
            ->orderBy('sort_date')
            ->get();

        $labels = $results->pluck('label');
        $connectionsData = $results->pluck('avg_connections');
        $avgQueryTimeData = $results->pluck('avg_query_time');
        $slowQueriesData = $results->pluck('total_slow_queries');

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Connections',
                    'data' => $connectionsData,
                    'borderColor' => 'rgba(75, 192, 192, 1)',
                    'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                    'yAxisID' => 'y',
                    'tension' => 0.1
                ],
                [
                    'label' => 'Avg Query Time (ms)',
                    'data' => $avgQueryTimeData,
                    'borderColor' => 'rgba(255, 159, 64, 1)',
                    'backgroundColor' => 'rgba(255, 159, 64, 0.2)',
                    'yAxisID' => 'y1',
                    'tension' => 0.1
                ],
                [
                    'label' => 'Slow Queries',
                    'data' => $slowQueriesData,
                    'borderColor' => 'rgba(255, 99, 132, 1)',
                    'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                    'yAxisID' => 'y2',
                    'type' => 'bar',
                ]
            ]
        ];
    }
}
