<?php

namespace App\Http\Controllers;

use App\Models\DocumentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

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

        return view('system-health', [
            'integrityCheckResult' => $integrityCheckResult,
            'mismatchedLogs' => $mismatchedLogs,
        ]);
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
