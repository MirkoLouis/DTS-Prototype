<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SnapshotDatabaseMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dts:snapshot-db-metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Take a snapshot of database performance metrics.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Metric 1: Current Connections
        $connectionsResult = DB::select("SHOW STATUS WHERE `variable_name` = 'Threads_connected'");
        $connections = $connectionsResult[0]->Value ?? 0;

        // Metrics from Performance Schema
        // Note: This requires the Performance Schema to be enabled.
        $avgTimeResult = DB::select("
            SELECT (SUM(SUM_TIMER_WAIT) / SUM(COUNT_STAR)) / 1000000 AS avg_time_ms
            FROM performance_schema.events_statements_summary_by_digest
        ");
        $avgQueryTime = $avgTimeResult[0]->avg_time_ms ?? 0;

        // Define a "slow query" as anything over 1 second (1,000,000,000 nanoseconds)
        $slowQueriesResult = DB::select("
            SELECT COUNT(*) as slow_query_count
            FROM performance_schema.events_statements_summary_by_digest
            WHERE AVG_TIMER_WAIT > 1000000000
        ");
        $slowQueries = $slowQueriesResult[0]->slow_query_count ?? 0;

        // Store the snapshot
        DB::table('database_metrics')->insert([
            'connections' => $connections,
            'avg_query_time_ms' => $avgQueryTime,
            'slow_queries' => $slowQueries,
            'created_at' => now(),
        ]);

        $this->info('Database performance metrics snapshot has been taken.');
    }
}
