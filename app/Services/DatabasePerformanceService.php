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
     * For now, this will generate random data.
     *
     * @param string $period
     * @return array
     */
    public function getChartData($period = 'daily')
    {
        $query = DB::table('database_metrics');

        switch ($period) {
            case 'weekly':
                $query->where('created_at', '>=', now()->subWeeks(12));
                break;
            case 'monthly':
                $query->where('created_at', '>=', now()->subMonths(12));
                break;
            default: // 'daily'
                $query->where('created_at', '>=', now()->subDays(30));
                break;
        }

        // To avoid having too many data points on the chart, you can group the data
        $results = $query->orderBy('created_at')->get()->groupBy(function($date) use ($period) {
            if ($period === 'daily') return \Carbon\Carbon::parse($date->created_at)->format('M d');
            if ($period === 'weekly') return \Carbon\Carbon::parse($date->created_at)->startOfWeek()->format('M d');
            if ($period === 'monthly') return \Carbon\Carbon::parse($date->created_at)->format('M Y');
            return \Carbon\Carbon::parse($date->created_at)->format('Y-m-d');
        });

        $labels = $results->keys();
        $connectionsData = $results->map(fn($group) => $group->avg('connections'));
        $avgQueryTimeData = $results->map(fn($group) => $group->avg('avg_query_time_ms'));
        $slowQueriesData = $results->map(fn($group) => $group->sum('slow_queries'));


        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Connections',
                    'data' => $connectionsData->values(),
                    'borderColor' => 'rgba(75, 192, 192, 1)',
                    'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                    'yAxisID' => 'y',
                    'tension' => 0.1
                ],
                [
                    'label' => 'Avg Query Time (ms)',
                    'data' => $avgQueryTimeData->values(),
                    'borderColor' => 'rgba(255, 159, 64, 1)',
                    'backgroundColor' => 'rgba(255, 159, 64, 0.2)',
                    'yAxisID' => 'y1',
                    'tension' => 0.1
                ],
                [
                    'label' => 'Slow Queries',
                    'data' => $slowQueriesData->values(),
                    'borderColor' => 'rgba(255, 99, 132, 1)',
                    'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                    'yAxisID' => 'y2',
                    'type' => 'bar',
                ]
            ]
        ];
    }
}
