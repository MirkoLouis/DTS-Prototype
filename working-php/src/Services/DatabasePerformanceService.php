<?php

namespace App\Services;

use App\Core\Database;

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
            $db = Database::getInstance();
            $stmt = $db->query("SHOW STATUS WHERE `variable_name` = 'Threads_connected'");
            $result = $stmt->fetch();
            return isset($result['Value']) ? (int) $result['Value'] : 0;
        } catch (\Exception $e) {
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
        return rand(0, 5);
    }

    /**
     * Get historical data for the charts.
     *
     * @param string $period
     * @return array
     */
    public function getChartData($period = 'daily')
    {
        $db = Database::getInstance();

        $dateFormat = match ($period) {
            'hourly' => '%H:00',
            'weekly' => '%Y-%m-%d (Week %v)',
            'monthly' => '%M %Y',
            default => '%M %d',
        };

        $startDateStr = match ($period) {
            'hourly' => date('Y-m-d H:i:s', strtotime('-24 hours')),
            'weekly' => date('Y-m-d H:i:s', strtotime('-12 weeks')),
            'monthly' => date('Y-m-d H:i:s', strtotime('-12 months')),
            default => date('Y-m-d H:i:s', strtotime('-30 days')),
        };

        $sql = "SELECT 
                    DATE_FORMAT(created_at, :date_format) as label,
                    AVG(connections) as avg_connections,
                    AVG(avg_query_time_ms) as avg_query_time,
                    SUM(slow_queries) as total_slow_queries,
                    MIN(created_at) as sort_date
                FROM database_metrics
                WHERE created_at >= :start_date
                GROUP BY label
                ORDER BY sort_date ASC";

        $stmt = $db->query($sql, [
            'date_format' => $dateFormat,
            'start_date' => $startDateStr
        ]);

        $results = $stmt->fetchAll();

        $labels = [];
        $connectionsData = [];
        $avgQueryTimeData = [];
        $slowQueriesData = [];

        foreach ($results as $row) {
            $labels[] = $row['label'];
            $connectionsData[] = $row['avg_connections'];
            $avgQueryTimeData[] = $row['avg_query_time'];
            $slowQueriesData[] = $row['total_slow_queries'];
        }

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
