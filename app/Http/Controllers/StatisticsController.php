<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\Purpose;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Models\Department;

class StatisticsController extends Controller
{
    /**
     * Display the statistics dashboard.
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

            if ($filterSubmitter && $filterSubmitter !== 'all') {
                $query->where('guest_info->name', $filterSubmitter);
            }

            // After all filters are applied, get the full list of unique submitters for the dropdown
            $submitterQuery = clone $query;
            $viewData['submitters'] = $submitterQuery->get()
                                        ->pluck('guest_info.name')
                                        ->filter()
                                        ->unique()
                                        ->sort()
                                        ->values();

            $viewData['releasedDocuments'] = $query->with('purpose')->latest('updated_at')->paginate(10)->withQueryString();
            
            $viewData['purposes'] = Purpose::orderBy('name')->get();
            $viewData['years'] = DocumentLog::select(DB::raw('YEAR(created_at) as year'))
                                            ->where('action', 'Document Released')
                                            ->distinct()
                                            ->orderBy('year', 'desc')
                                            ->pluck('year');
        }
        
        if ($request->ajax() && Auth::user()->role === 'officer') {
            return view('officer.partials.released-documents-table', $viewData);
        }

        return view('general.statistics', $viewData);
    }

    /**
     * Get data for the 'Documents Received' chart.
     */
    public function getCurrentLoadData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $departmentId = Auth::user()->department_id;

        list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);

        $query = DocumentLog::select(
                DB::raw("DATE_FORMAT(document_logs.created_at, '{$dateFormat}') as period_label"),
                DB::raw('COUNT(DISTINCT document_logs.document_id) as received_count')
            )
            ->join('users', 'document_logs.user_id', '=', 'users.id')
            ->whereBetween('document_logs.created_at', [$startDate, $endDate])
            ->where('document_logs.action', 'Received')
            ->where('users.department_id', $departmentId);

        $receivedDocuments = $query->groupBy('period_label')
            ->orderBy('period_label')
            ->get()
            ->keyBy('period_label');

        $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

        foreach ($receivedDocuments as $label => $result) {
            if (isset($periodMap[$label])) {
                $periodMap[$label] = $result->received_count;
            }
        }

        return response()->json([
            'labels' => array_keys($periodMap),
            'data' => array_values($periodMap),
        ]);
    }

    /**
     * Get data for the 'Throughput' chart (documents processed over time for the user's department).
     */
    public function getThroughputData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $departmentId = Auth::user()->department_id;

        list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);

        $query = DocumentLog::select(
                DB::raw("DATE_FORMAT(document_logs.created_at, '{$dateFormat}') as period_label"),
                DB::raw('COUNT(DISTINCT document_logs.document_id) as processed_count')
            )
            ->join('users', 'document_logs.user_id', '=', 'users.id')
            ->whereBetween('document_logs.created_at', [$startDate, $endDate])
            ->where('document_logs.action', 'Processing Complete')
            ->where('users.department_id', $departmentId);

        $processedDocuments = $query->groupBy('period_label')
            ->orderBy('period_label')
            ->get()
            ->keyBy('period_label');

        $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

        foreach ($processedDocuments as $label => $result) {
            if (isset($periodMap[$label])) {
                $periodMap[$label] = $result->processed_count;
            }
        }

        return response()->json([
            'labels' => array_keys($periodMap),
            'data' => array_values($periodMap),
        ]);
    }

    /**
     * Get data for the 'Average Processing Time' chart.
     */
    public function getAverageProcessingTimeData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $departmentId = Auth::user()->department_id;

        list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);
        
        $endLogs = DocumentLog::where('action', 'Processing Complete')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereHas('user', function ($query) use ($departmentId) {
                $query->where('department_id', $departmentId);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $processingTimesByPeriod = [];

        foreach ($endLogs as $endLog) {
            $startLog = DocumentLog::where('document_id', $endLog->document_id)
                ->where('user_id', $endLog->user_id)
                ->where('action', 'Received')
                ->where('created_at', '<', $endLog->created_at)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($startLog) {
                $periodLabel = (new Carbon($endLog->created_at))->format($this->getCarbonFormat($period));
                
                if (!isset($processingTimesByPeriod[$periodLabel])) {
                    $processingTimesByPeriod[$periodLabel] = [];
                }
                $processingTimesByPeriod[$periodLabel][] = abs((new Carbon($endLog->created_at))->diffInHours(new Carbon($startLog->created_at)));
            }
        }

        $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

        foreach ($processingTimesByPeriod as $label => $times) {
            if (isset($periodMap[$label]) && count($times) > 0) {
                $periodMap[$label] = round(array_sum($times) / count($times), 2);
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
