<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PruneMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dts:prune-metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Summarize granular metrics into hourly averages and prune old data to save space.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting database metrics pruning and summarization...');

        // 1. Identify "Old" granular data (older than 24 hours) that hasn't been summarized yet.
        // We look for hours that have multiple entries.
        $cutoff = now()->subHours(24);

        $hoursToSummarize = DB::table('database_metrics')
            ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour_group"))
            ->where('created_at', '<', $cutoff)
            ->groupBy('hour_group')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        if ($hoursToSummarize->isEmpty()) {
            $this->info('No old granular data needs summarization.');
        } else {
            $summarizedCount = 0;
            foreach ($hoursToSummarize as $group) {
                $hour = $group->hour_group;

                // Calculate averages for this hour
                $stats = DB::table('database_metrics')
                    ->whereBetween('created_at', [
                        Carbon::parse($hour),
                        Carbon::parse($hour)->endOfHour()
                    ])
                    ->select(
                        DB::raw('AVG(connections) as avg_conn'),
                        DB::raw('AVG(avg_query_time_ms) as avg_time'),
                        DB::raw('SUM(slow_queries) as total_slow')
                    )
                    ->first();

                // Delete all granular entries for this hour
                DB::table('database_metrics')
                    ->whereBetween('created_at', [
                        Carbon::parse($hour),
                        Carbon::parse($hour)->endOfHour()
                    ])
                    ->delete();

                // Insert the single summarized hourly entry
                DB::table('database_metrics')->insert([
                    'connections' => $stats->avg_conn,
                    'avg_query_time_ms' => $stats->avg_time,
                    'slow_queries' => $stats->total_slow,
                    'created_at' => $hour,
                ]);

                $summarizedCount++;
            }
            $this->info("Summarized {$summarizedCount} hours of granular data.");
        }

        // 2. Final Pruning: Delete everything older than 3 months (90 days)
        // to prevent infinite growth even with hourly data.
        $deleted = DB::table('database_metrics')
            ->where('created_at', '<', now()->subDays(90))
            ->delete();

        if ($deleted > 0) {
            $this->info("Permanently pruned {$deleted} old metric entries (older than 90 days).");
        }

        $this->info('Metrics pruning complete!');
    }
}
