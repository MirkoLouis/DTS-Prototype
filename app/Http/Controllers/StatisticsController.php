<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReportJob;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\Purpose;
use App\Models\ReportJob;
use App\Models\DailyDepartmentMetric;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StatisticsController extends Controller
{
    /**
     * Display the statistics dashboard.
     * OPTIMIZED: Uses denormalized released_at and released_by_user_id.
     */
    public function index(Request $request)
    {
        $viewData = [];

        if (Auth::user()->role === 'officer') {
            $departmentId = Auth::user()->department_id;
            $filterPurposeId = $request->input('purpose_id');
            $filterSubmitter = $request->input('submitter');
            $searchTerm = $request->input('search');
            $filterYear = $request->input('year');
            $filterMonth = $request->input('month');
            $filterDay = $request->input('day');

            // Base query for documents
            $query = Document::query();

            // OPTIMIZED: Use the denormalized columns instead of whereHas('logs')
            $query->where('status', 'completed')
                  ->where('released_by_user_id', '>', 0)
                  ->whereHas('releasedByUser', function ($userQuery) use ($departmentId) {
                      $userQuery->where('department_id', $departmentId);
                  });

            // Apply date filters
            if ($filterYear && $filterYear !== 'all') $query->whereYear('released_at', $filterYear);
            if ($filterMonth && $filterMonth !== 'all') $query->whereMonth('released_at', $filterMonth);
            if ($filterDay && $filterDay !== 'all') $query->whereDay('released_at', $filterDay);

            if ($searchTerm) {
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('tracking_code', 'like', '%' . $searchTerm . '%')
                      ->orWhere('guest_info->name', 'like', '%' . $searchTerm . '%');
                });
            }

            if ($filterPurposeId && $filterPurposeId !== 'all') {
                $query->where('purpose_id', $filterPurposeId);
            }

            if ($filterSubmitter) {
                $query->whereRaw('LOWER(json_unquote(json_extract(guest_info, "$.name"))) LIKE ?', ['%' . strtolower($filterSubmitter) . '%']);
            }

            $viewData['releasedDocuments'] = $query->with('purpose')->latest('released_at')->paginate(10)->withQueryString();
            $viewData['purposes'] = Purpose::orderBy('name')->get();
            
            // OPTIMIZED: Avoid heavy full table scans by caching the years list
            $viewData['years'] = \Illuminate\Support\Facades\Cache::remember('statistics_released_years', now()->addHours(1), function () {
                return Document::where('status', 'completed')
                    ->whereNotNull('released_at')
                    ->select(DB::raw('DISTINCT YEAR(released_at) as year'))
                    ->orderBy('year', 'desc')
                    ->pluck('year');
            });
        }
        
        if ($request->ajax() && Auth::user()->role === 'officer') {
            return view('officer.partials.released-documents-table', $viewData);
        }

        return view('general.statistics', $viewData);
    }

    public function getReportCount(Request $request)
    {
        if (Auth::user()->role !== 'officer') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $departmentId = Auth::user()->department_id;
        $filterPurposeId = $request->input('purpose_id');
        $filterSubmitter = $request->input('submitter');
        $searchTerm = $request->input('search');
        $filterYear = $request->input('year');
        $filterMonth = $request->input('month');
        $filterDay = $request->input('day');

        $query = Document::query();

        $query->where('status', 'completed')
              ->whereHas('releasedByUser', function ($userQuery) use ($departmentId) {
                  $userQuery->where('department_id', $departmentId);
              });

        if ($filterYear && $filterYear !== 'all') $query->whereYear('released_at', $filterYear);
        if ($filterMonth && $filterMonth !== 'all') $query->whereMonth('released_at', $filterMonth);
        if ($filterDay && $filterDay !== 'all') $query->whereDay('released_at', $filterDay);

        if ($searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('tracking_code', 'like', '%' . $searchTerm . '%')
                  ->orWhere('guest_info->name', 'like', '%' . $searchTerm . '%');
            });
        }

        if ($filterPurposeId && $filterPurposeId !== 'all') {
            $query->where('purpose_id', $filterPurposeId);
        }

        if ($filterSubmitter) {
            $query->whereRaw('LOWER(json_unquote(json_extract(guest_info, "$.name"))) LIKE ?', ['%' . strtolower($filterSubmitter) . '%']);
        }

        $count = $query->count();

        return response()->json(['count' => $count]);
    }

    public function generateReport(Request $request)
    {
        $user = Auth::user();
        $filters = $request->all();

        $reportJob = ReportJob::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'status' => 'queued',
        ]);

        GenerateReportJob::dispatch($reportJob, $user, $filters);

        return response()->json(['job_id' => $reportJob->id]);
    }

    public function getReportStatus($jobId)
    {
        $reportJob = ReportJob::findOrFail($jobId);

        if ($reportJob->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json($reportJob);
    }

    public function cancelReport($jobId)
    {
        $reportJob = ReportJob::findOrFail($jobId);

        if ($reportJob->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (in_array($reportJob->status, ['queued', 'processing'])) {
            $reportJob->update(['status' => 'cancelled']);
        }

        return response()->json(['status' => 'success']);
    }

    public function downloadReport($jobId)
    {
        $reportJob = ReportJob::findOrFail($jobId);

        if ($reportJob->user_id !== Auth::id()) {
            abort(403, 'Unauthorized');
        }

        if ($reportJob->status !== 'completed') {
            abort(404, 'Report not ready or failed.');
        }

        if (!Storage::disk('public')->exists($reportJob->file_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('public')->download($reportJob->file_path);
    }

    /**
     * Get data for the 'Documents Received' chart.
     * OPTIMIZED: Uses daily_department_metrics.
     */
    public function getCurrentLoadData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $departmentId = Auth::user()->department_id;

        list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);

        $results = DB::table('daily_department_metrics')
            ->where('department_id', $departmentId)
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                DB::raw("DATE_FORMAT(date, '{$dateFormat}') as period_label"),
                DB::raw('SUM(received_count) as received_count')
            )
            ->groupBy('period_label')
            ->get()
            ->pluck('received_count', 'period_label');

        $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

        foreach ($results as $label => $count) {
            if (isset($periodMap[$label])) {
                $periodMap[$label] = (int) $count;
            }
        }

        return response()->json([
            'labels' => array_keys($periodMap),
            'data' => array_values($periodMap),
        ]);
    }

    /**
     * Get data for the 'Throughput' chart.
     * OPTIMIZED: Uses daily_department_metrics.
     */
    public function getThroughputData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $departmentId = Auth::user()->department_id;

        list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);

        $results = DB::table('daily_department_metrics')
            ->where('department_id', $departmentId)
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                DB::raw("DATE_FORMAT(date, '{$dateFormat}') as period_label"),
                DB::raw('SUM(processed_count + released_count) as processed_count')
            )
            ->groupBy('period_label')
            ->get()
            ->pluck('processed_count', 'period_label');

        $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

        foreach ($results as $label => $count) {
            if (isset($periodMap[$label])) {
                $periodMap[$label] = (int) $count;
            }
        }

        return response()->json([
            'labels' => array_keys($periodMap),
            'data' => array_values($periodMap),
        ]);
    }

    /**
     * Get data for the 'Average Processing Time' chart.
     * OPTIMIZED: Uses daily_department_metrics.
     */
    public function getAverageProcessingTimeData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $departmentId = Auth::user()->department_id;

        list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);
        
        $results = DB::table('daily_department_metrics')
            ->where('department_id', $departmentId)
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                DB::raw("DATE_FORMAT(date, '{$dateFormat}') as period_label"),
                DB::raw('SUM(total_processing_seconds) as total_seconds'),
                DB::raw('SUM(processed_count + released_count) as total_count')
            )
            ->groupBy('period_label')
            ->get();

        $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

        foreach ($results as $result) {
            if (isset($periodMap[$result->period_label])) {
                $avgHours = $result->total_count > 0 ? ($result->total_seconds / $result->total_count) / 3600 : 0;
                $periodMap[$result->period_label] = round($avgHours, 2);
            }
        }
        
        return response()->json([
            'labels' => array_keys($periodMap),
            'data' => array_values($periodMap),
        ]);
    }

    /**
     * Helper function to get date parameters based on the period.
     */
    private function getDateParameters($period)
    {
        $endDate = Carbon::now();
        switch ($period) {
            case 'weekly':
                return [$endDate->copy()->subWeeks(4), $endDate, '%Y-%v'];
            case 'monthly':
                return [$endDate->copy()->subMonths(12), $endDate, '%Y-%m'];
            case 'yearly':
                return [$endDate->copy()->subYears(5), $endDate, '%Y'];
            default: // daily
                return [$endDate->copy()->subDays(30), $endDate, '%Y-%m-%d'];
        }
    }

    /**
     * Helper function to get Carbon format string based on the period.
     */
    private function getCarbonFormat($period)
    {
        return match ($period) {
            'weekly' => 'Y-W',
            'monthly' => 'Y-m',
            'yearly' => 'Y',
            default => 'Y-m-d',
        };
    }

    /**
     * Helper function to generate a map of periods with default value 0.
     */
    private function generatePeriodMap($startDate, $endDate, $period)
    {
        $periodMap = [];
        $current = $startDate->clone();
        $format = $this->getCarbonFormat($period);
        
        $addMethod = match($period) {
            'weekly' => 'addWeek',
            'monthly' => 'addMonth',
            'yearly' => 'addYear',
            default => 'addDay',
        };

        while ($current <= $endDate) {
            $periodMap[$current->format($format)] = 0;
            $current->$addMethod();
        }
        return $periodMap;
    }
}
