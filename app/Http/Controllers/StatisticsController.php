<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReportJob;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\Purpose;
use App\Models\ReportJob;
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
     * Refactored for 1M record scaling by optimizing the submitter list extraction.
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

            // Date and action filtering must happen on the same log entry.
            $query->whereHas('logs', function ($q) use ($departmentId, $filterYear, $filterMonth, $filterDay) {
                $q->where('action', 'Document Released');
                
                // User sub-query
                $q->whereHas('user', function ($userQuery) use ($departmentId) {
                    $userQuery->where('department_id', $departmentId);
                });

                // Apply date filters if they are provided and not 'all'
                $year = $filterYear && $filterYear !== 'all' ? $filterYear : null;
                $month = $filterMonth && $filterMonth !== 'all' ? $filterMonth : null;
                $day = $filterDay && $filterDay !== 'all' ? $filterDay : null;

                if ($year) {
                    $q->whereYear('created_at', $year);
                }
                if ($month) {
                    $q->whereMonth('created_at', $month);
                }
                if ($day) {
                    $q->whereDay('created_at', $day);
                }
            });

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

            // OPTIMIZATION: Instead of $query->get() which is a RAM trap, 
            // use a dedicated query for unique submitters using JSON_EXTRACT in SQL.
            $submitterQuery = clone $query;
            $viewData['submitters'] = $submitterQuery
                ->select(DB::raw('DISTINCT json_unquote(json_extract(guest_info, "$.name")) as submitter_name'))
                ->orderBy('submitter_name')
                ->pluck('submitter_name')
                ->filter();

            $viewData['releasedDocuments'] = $query->with('purpose')->latest('updated_at')->paginate(10)->withQueryString();
            
            $viewData['purposes'] = Purpose::orderBy('name')->get();
            
            // OPTIMIZATION: Avoid heavy DocumentLog query if possible, or limit it.
            $viewData['years'] = DB::table('document_logs')
                ->select(DB::raw('DISTINCT YEAR(created_at) as year'))
                ->where('action', 'Document Released')
                ->orderBy('year', 'desc')
                ->pluck('year');
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

        $query->whereHas('logs', function ($q) use ($departmentId, $filterYear, $filterMonth, $filterDay) {
            $q->where('action', 'Document Released');
            $q->whereHas('user', function ($userQuery) use ($departmentId) {
                $userQuery->where('department_id', $departmentId);
            });
            if ($filterYear && $filterYear !== 'all') {
                $q->whereYear('created_at', $filterYear);
            }
            if ($filterMonth && $filterMonth !== 'all') {
                $q->whereMonth('created_at', $filterMonth);
            }
            if ($filterDay && $filterDay !== 'all') {
                $q->whereDay('created_at', $filterDay);
            }
        });

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
     */
    public function getCurrentLoadData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $departmentId = Auth::user()->department_id;

        list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);

        $results = DB::table('document_logs')
            ->join('users', 'document_logs.user_id', '=', 'users.id')
            ->whereBetween('document_logs.created_at', [$startDate, $endDate])
            ->where('document_logs.action', 'Received')
            ->where('users.department_id', $departmentId)
            ->select(
                DB::raw("DATE_FORMAT(document_logs.created_at, '{$dateFormat}') as period_label"),
                DB::raw('COUNT(DISTINCT document_logs.document_id) as received_count')
            )
            ->groupBy('period_label')
            ->get()
            ->pluck('received_count', 'period_label');

        $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

        foreach ($results as $label => $count) {
            if (isset($periodMap[$label])) {
                $periodMap[$label] = $count;
            }
        }

        return response()->json([
            'labels' => array_keys($periodMap),
            'data' => array_values($periodMap),
        ]);
    }

    /**
     * Get data for the 'Throughput' chart.
     */
    public function getThroughputData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $departmentId = Auth::user()->department_id;

        list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);

        $results = DB::table('document_logs')
            ->join('users', 'document_logs.user_id', '=', 'users.id')
            ->whereBetween('document_logs.created_at', [$startDate, $endDate])
            ->where('document_logs.action', 'Processing Complete')
            ->where('users.department_id', $departmentId)
            ->select(
                DB::raw("DATE_FORMAT(document_logs.created_at, '{$dateFormat}') as period_label"),
                DB::raw('COUNT(DISTINCT document_logs.document_id) as processed_count')
            )
            ->groupBy('period_label')
            ->get()
            ->pluck('processed_count', 'period_label');

        $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

        foreach ($results as $label => $count) {
            if (isset($periodMap[$label])) {
                $periodMap[$label] = $count;
            }
        }

        return response()->json([
            'labels' => array_keys($periodMap),
            'data' => array_values($periodMap),
        ]);
    }

    /**
     * Get data for the 'Average Processing Time' chart.
     * Refactored to use Window Functions to avoid N+1 and RAM traps.
     */
    public function getAverageProcessingTimeData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $departmentId = Auth::user()->department_id;

        list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);
        
        // Use Window Function to find the gap between Received and Complete in one SQL query.
        $durationsResults = DB::table(function ($query) {
            $query->select(
                'document_id',
                'user_id',
                'action',
                'created_at',
                DB::raw("LAG(created_at) OVER (PARTITION BY document_id, user_id ORDER BY created_at) as prev_created_at"),
                DB::raw("LAG(action) OVER (PARTITION BY document_id, user_id ORDER BY created_at) as prev_action")
            )
            ->from('document_logs')
            ->whereIn('action', ['Received', 'Processing Complete']);
        }, 'log_durations')
        ->join('users', 'log_durations.user_id', '=', 'users.id')
        ->where('users.department_id', $departmentId)
        ->where('log_durations.action', 'Processing Complete')
        ->where('log_durations.prev_action', 'Received')
        ->whereBetween('log_durations.created_at', [$startDate, $endDate])
        ->select(
            DB::raw("DATE_FORMAT(log_durations.created_at, '{$dateFormat}') as period_label"),
            DB::raw('AVG(TIMESTAMPDIFF(SECOND, log_durations.prev_created_at, log_durations.created_at)) / 3600 as avg_hours')
        )
        ->groupBy('period_label')
        ->get()
        ->pluck('avg_hours', 'period_label');

        $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

        foreach ($durationsResults as $label => $avg) {
            if (isset($periodMap[$label])) {
                $periodMap[$label] = round($avg, 2);
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
