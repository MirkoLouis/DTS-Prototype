<?php

namespace App\Http\Controllers;

use App\Models\DocumentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SystemHealthController extends Controller
{
    /**
     * Display the system health page.
     */
    public function index()
    {
        $integrityCheckResult = Cache::get('integrity-check-result', [
            'verified_percentage' => 'N/A',
            'last_checked' => 'Never',
            'mismatched_ids' => [],
        ]);

        $mismatchedLogs = collect();
        if (!empty($integrityCheckResult['mismatched_ids'])) {
            $mismatchedLogs = DocumentLog::whereIn('id', $integrityCheckResult['mismatched_ids'])
                                        ->with(['document', 'user'])
                                        ->paginate(10);
        }

        $appHealthMetrics = $this->getApplicationHealthMetrics();

        return view('system-health', [
            'integrityCheckResult' => $integrityCheckResult,
            'mismatchedLogs' => $mismatchedLogs,
            'appHealthMetrics' => $appHealthMetrics,
        ]);
    }

    /**
     * Gather application-level health metrics.
     */
    private function getApplicationHealthMetrics()
    {
        // 1. Average Processing Time
        $processingTimes = DocumentLog::select(
                'document_id',
                DB::raw('MIN(CASE WHEN action LIKE "%Accepted and route finalized%" THEN created_at END) as start_time'),
                DB::raw('MAX(CASE WHEN action LIKE "%Processing complete%" THEN created_at END) as end_time')
            )
            ->groupBy('document_id')
            ->havingNotNull('start_time')
            ->havingNotNull('end_time')
            ->having('end_time', '>', DB::raw('start_time'))
            ->get();

        $totalSeconds = $processingTimes->reduce(function ($carry, $log) {
            $startTime = Carbon::parse($log->start_time)->timestamp;
            $endTime = Carbon::parse($log->end_time)->timestamp;
            return $carry + ($endTime - $startTime);
        }, 0);

        $averageProcessingTime = ($processingTimes->count() > 0) ? $totalSeconds / $processingTimes->count() : 0;

        // 2. Failed Jobs Count
        $failedJobsCount = DB::table('failed_jobs')->count();

        // 3. Cache Status
        try {
            Cache::put('system-health-check', 'ok', 10);
            $cacheStatus = Cache::get('system-health-check') === 'ok';
        } catch (\Exception $e) {
            $cacheStatus = false;
        }

        return [
            'average_processing_time' => $averageProcessingTime, // in seconds
            'failed_jobs_count' => $failedJobsCount,
            'cache_status' => $cacheStatus,
        ];
    }


    /**
     * Run the integrity check Artisan command.
     */
    public function runIntegrityCheck()
    {
        try {
            Artisan::call('dts:verify-integrity');
            return response()->json(['status' => 'success', 'message' => 'Integrity check completed.']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get the latest integrity check results.
     */
    public function getIntegrityCheckResults()
    {
        $result = Cache::get('integrity-check-result', [
            'verified_percentage' => 'N/A',
            'last_checked' => 'Never',
        ]);

        return response()->json($result);
    }

    /**
     * Trigger the hash chain rebuild for a specific log.
     *
     * @param  \App\Models\DocumentLog  $log
     * @return \Illuminate\Http\JsonResponse
     */
    public function rebuildChain(DocumentLog $log)
    {
        try {
            // Rebuild the chain for the specific document
            Artisan::call('dts:rebuild-chain', ['logId' => $log->id]);
            
            // Immediately run a new integrity check to update the cache
            Artisan::call('dts:verify-integrity');

            return response()->json(['status' => 'success', 'message' => 'Hash chain rebuilt and system re-verified successfully.']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
