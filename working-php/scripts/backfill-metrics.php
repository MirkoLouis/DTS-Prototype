<?php
// Rebuilds daily_department_metrics from document_logs.
//   php scripts/backfill-metrics.php          rebuild the last two days (what the worker runs every 5 minutes)
//   php scripts/backfill-metrics.php --full   rebuild every day (what the seeders run after loading data)
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Modules\Analytics\MetricsBackfillService;
use App\Modules\Integrity\HashChainService;

// Day boundaries follow the zone the log timestamps are written in
date_default_timezone_set(HashChainService::APP_TIMEZONE);

// Without --full only the recent window is rebuilt
$full = in_array('--full', $argv, true);
$since = $full ? null : MetricsBackfillService::windowStart(new DateTimeImmutable('now'));

echo $full ? "Rebuilding the daily department metrics for every day...\n" : "Rebuilding the daily department metrics from {$since}...\n";
$changed = (new MetricsBackfillService())->rebuild($since);
echo $changed ? "Backfill complete (rows changed, metrics cache cleared).\n" : "Backfill complete (no change).\n";
