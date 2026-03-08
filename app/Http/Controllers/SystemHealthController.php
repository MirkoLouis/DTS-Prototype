<?php

namespace App\Http\Controllers;

use App\Models\DocumentLog;
use App\Services\DatabasePerformanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemHealthController extends Controller
{
    /**
     * Display the system health page.
     */
    public function index(Request $request)
    {
        $integrityCheckResult = Cache::get('integrity-check-result', [
            'verified_percentage' => 'N/A',
            'last_checked' => 'Never',
            'mismatched_ids' => [],
        ]);

        // Handle Historical Log Mismatches
        $mismatchedLogsQuery = DocumentLog::query();
        $perPage = $request->input('per_page', 10);
        
        if (!empty($integrityCheckResult['mismatched_ids'])) {
            $ids = $integrityCheckResult['mismatched_ids'];
            
            // If we have filters, we still need to apply them to the whole set of mismatched IDs.
            // To avoid the placeholder limit, we only query the IDs we need for the current page
            // UNLESS the user is searching. If searching, we must filter first.
            if ($request->filled('search') || $request->filled('user') || $request->filled('date')) {
                // If searching, we have to use whereIn, but we'll chunk the IDs to stay safe
                // or just take a reasonable subset for the search.
                // Given the placeholder limit is ~65k, and the error showed 90k+, 
                // we'll chunk it to 1000 IDs per whereIn block to be absolutely safe.
                $mismatchedLogsQuery->where(function($q) use ($ids) {
                    foreach (array_chunk($ids, 1000) as $chunk) {
                        $q->orWhereIn('id', $chunk);
                    }
                });

                if ($request->filled('search')) {
                    $search = strtolower($request->input('search'));
                    $mismatchedLogsQuery->whereHas('document', function ($q) use ($search) {
                        $q->whereRaw('LOWER(tracking_code) LIKE ?', ["%{$search}%"]);
                    });
                }

                if ($request->filled('user')) {
                    $user = strtolower($request->input('user'));
                    $mismatchedLogsQuery->whereHas('user', function ($q) use ($user) {
                        $q->whereRaw('LOWER(name) LIKE ?', ["%{$user}%"]);
                    });
                }

                if ($request->filled('date')) {
                    $mismatchedLogsQuery->whereDate('created_at', $request->input('date'));
                }

                $mismatchedLogs = $mismatchedLogsQuery->with(['document', 'user'])
                    ->paginate($perPage, ['*'], 'logs_page')->withQueryString();
            } else {
                // Optimized manual pagination for the default view (no search)
                $currentPage = $request->input('logs_page', 1);
                $pagedIds = array_slice($ids, ($currentPage - 1) * $perPage, $perPage);
                
                $items = DocumentLog::whereIn('id', $pagedIds)
                    ->with(['document', 'user'])
                    ->get();
                    
                $mismatchedLogs = new \Illuminate\Pagination\LengthAwarePaginator(
                    $items,
                    count($ids),
                    $perPage,
                    $currentPage,
                    ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'logs_page']
                );
            }
        } else {
            $mismatchedLogs = $mismatchedLogsQuery->whereRaw('1 = 0')->paginate($perPage, ['*'], 'logs_page');
        }

        // Handle Live State Mismatches (Active State Comparison)
        $mismatchedDocsQuery = \App\Models\Document::query();
        if (!empty($integrityCheckResult['mismatched_document_tracking_codes'])) {
            $trackingCodes = $integrityCheckResult['mismatched_document_tracking_codes'];

            if ($request->filled('search')) {
                $mismatchedDocsQuery->where(function($q) use ($trackingCodes) {
                    foreach (array_chunk($trackingCodes, 1000) as $chunk) {
                        $q->orWhereIn('tracking_code', $chunk);
                    }
                });

                $search = strtolower($request->input('search'));
                $mismatchedDocsQuery->where(function($q) use ($search) {
                    $q->whereRaw('LOWER(tracking_code) LIKE ?', ["%{$search}%"])
                      ->orWhereRaw('LOWER(title) LIKE ?', ["%{$search}%"]);
                });
                
                $mismatchedDocuments = $mismatchedDocsQuery->paginate($perPage, ['*'], 'docs_page')->withQueryString();
            } else {
                // Optimized manual pagination for the default view
                $currentPage = $request->input('docs_page', 1);
                $pagedCodes = array_slice($trackingCodes, ($currentPage - 1) * $perPage, $perPage);
                
                $items = \App\Models\Document::whereIn('tracking_code', $pagedCodes)->get();
                
                $mismatchedDocuments = new \Illuminate\Pagination\LengthAwarePaginator(
                    $items,
                    count($trackingCodes),
                    $perPage,
                    $currentPage,
                    ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'docs_page']
                );
            }
        } else {
            $mismatchedDocuments = $mismatchedDocsQuery->whereRaw('1 = 0')->paginate($perPage, ['*'], 'docs_page');
        }

        $appHealthMetrics = $this->getApplicationHealthMetrics();

        return view('admin.system-health', [
            'integrityCheckResult' => $integrityCheckResult,
            'mismatchedLogs' => $mismatchedLogs,
            'mismatchedDocuments' => $mismatchedDocuments,
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
                DB::raw('MIN(CASE WHEN action = "Accepted and Document Routing finalized" THEN created_at END) as start_time'),
                DB::raw('MAX(CASE WHEN action = "Document Released" THEN created_at END) as end_time')
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

        // 2. Failed Jobs
        $failedJobs = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->get()->map(function($job) {
            $payload = json_decode($job->payload, true);
            $job->display_name = $payload['displayName'] ?? 'Unknown Job';
            return $job;
        });

        // 3. Cache Status
        try {
            Cache::put('system-health-check', 'ok', 10);
            $cacheStatus = Cache::get('system-health-check') === 'ok';
        } catch (\Exception $e) {
            $cacheStatus = false;
        }

        return [
            'average_processing_time' => $averageProcessingTime, // in seconds
            'failed_jobs_count' => $failedJobs->count(),
            'failed_jobs' => $failedJobs,
            'cache_status' => $cacheStatus,
        ];
    }

    /**
     * Delete a specific failed job.
     */
    public function deleteFailedJob($id)
    {
        DB::table('failed_jobs')->where('id', $id)->delete();
        return back()->with('success', 'Failed job resolved and removed from the list.');
    }

    /**
     * Delete all failed jobs.
     */
    public function deleteAllFailedJobs()
    {
        DB::table('failed_jobs')->truncate();
        return back()->with('success', 'All failed jobs have been cleared.');
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

    /**
     * Get database performance data for charts.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Services\DatabasePerformanceService  $dbPerformanceService
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDbPerformanceData(Request $request, DatabasePerformanceService $dbPerformanceService)
    {
        $period = $request->input('period', 'daily');
        $data = $dbPerformanceService->getChartData($period);
        return response()->json($data);
    }

    /**
     * Export database performance metrics as a CSV file.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportDbPerformanceMetrics()
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="database-performance-metrics-' . now()->format('Y-m-d-His') . '.csv"',
        ];

        return new StreamedResponse(function () {
            $handle = fopen('php://output', 'w');
            
            // Add CSV headers
            fputcsv($handle, [
                'id',
                'connections',
                'avg_query_time_ms',
                'slow_queries',
                'created_at',
            ]);

            DB::table('database_metrics')->orderBy('id')->chunk(1000, function ($metrics) use ($handle) {
                foreach ($metrics as $metric) {
                    fputcsv($handle, [
                        $metric->id,
                        $metric->connections,
                        $metric->avg_query_time_ms,
                        $metric->slow_queries,
                        $metric->created_at,
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}
