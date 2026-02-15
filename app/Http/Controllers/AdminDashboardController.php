<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\Purpose;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminDashboardController extends Controller
{
    /**
     * Display the admin dashboard.
     */
    public function index()
    {
        $departments = Department::all();
        return view('admin.dashboard', ['departments' => $departments]);
    }

    /**
     * Get data for the 'Current Load' chart (documents pending at each department).
     */
    public function getCurrentLoadData(Request $request)
    {
        $departmentId = $request->get('department_id');
        // Base query for processing documents
        $query = Document::where('status', 'processing');
        if ($departmentId && $departmentId !== 'all') {
            $department = Department::find($departmentId);
            if ($department) {
                // Since the route is now an array of objects, we need a more specific query
                // to check if any object in the JSON array has a 'name' field matching the department name.
                $query->whereJsonContains('finalized_route', [['name' => $department->name]]);
            }
        }
        $processingDocuments = $query->get();
        $departmentLoads = [];
        // Aggregate documents by their current step's department
        foreach ($processingDocuments as $document) {
            if (!empty($document->finalized_route) && $document->current_step > 0 && $document->current_step <= count($document->finalized_route)) {
                $currentDepartmentName = $document->finalized_route[$document->current_step - 1]['name'];
                // If a specific department is selected, only count for that one
                if ($departmentId && $departmentId !== 'all') {
                    $selectedDepartment = Department::find($departmentId);
                    if ($selectedDepartment && $currentDepartmentName === $selectedDepartment->name) {
                        $departmentLoads[$currentDepartmentName] = ($departmentLoads[$currentDepartmentName] ?? 0) + 1;
                    }
                } else {
                    $departmentLoads[$currentDepartmentName] = ($departmentLoads[$currentDepartmentName] ?? 0) + 1;
                }
            }
        }
        // Ensure all departments are represented if 'all' is selected, or just the selected one
        if (!$departmentId || $departmentId === 'all') {
            $allDepartments = Department::pluck('name')->toArray();
            foreach ($allDepartments as $deptName) {
                if (!isset($departmentLoads[$deptName])) {
                    $departmentLoads[$deptName] = 0;
                }
            }
        } else {
             $selectedDepartment = Department::find($departmentId);
             if ($selectedDepartment && !isset($departmentLoads[$selectedDepartment->name])) {
                 $departmentLoads[$selectedDepartment->name] = 0;
             }
        }
        // Sort by load (descending)
        arsort($departmentLoads);
        return response()->json([
            'labels' => array_keys($departmentLoads),
            'data' => array_values($departmentLoads),
        ]);
    }

    /**
     * Get data for the 'Throughput' chart (documents processed over time).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getThroughputData(Request $request)
    {
        $period = $request->get('period', 'daily'); // daily, weekly, monthly, yearly
        $departmentId = $request->get('department_id');
        $endDate = Carbon::now();
        $startDate = match ($period) {
            'weekly' => Carbon::now()->subWeeks(4),
            'monthly' => Carbon::now()->subMonths(12),
            'yearly' => Carbon::now()->subYears(5),
            default => Carbon::now()->subDays(30),
        };
        $dateFormat = match ($period) {
            'weekly' => '%Y-%W',
            'monthly' => '%Y-%m',
            'yearly' => '%Y',
            default => '%Y-%m-%d',
        };
        $query = DocumentLog::select(
                DB::raw("DATE_FORMAT(document_logs.created_at, '{$dateFormat}') as period_label"),
                DB::raw('COUNT(DISTINCT document_logs.document_id) as processed_count')
            )
            ->join('users', 'document_logs.user_id', '=', 'users.id')
            ->whereBetween('document_logs.created_at', [$startDate, $endDate])
            ->where('document_logs.action', 'Processing Complete');
        if ($departmentId && $departmentId !== 'all') {
            $query->where('users.department_id', $departmentId);
        }
        $processedDocuments = $query->groupBy('period_label')
            ->orderBy('period_label')
            ->get();
        $labels = $processedDocuments->pluck('period_label')->toArray();
        $data = $processedDocuments->pluck('processed_count')->toArray();
        // This part needs to be more robust to fill in gaps for the chart
        $periodMap = [];
        $current = $startDate->clone();
        while ($current <= $endDate) {
            $label = match($period) {
                'weekly' => $current->format('Y-W'),
                'monthly' => $current->format('Y-m'),
                'yearly' => $current->format('Y'),
                default => $current->format('Y-m-d')
            };
            $periodMap[$label] = 0;
            $current = match($period) {
                'weekly' => $current->addWeek(),
                'monthly' => $current->addMonth(),
                'yearly' => $current->addYear(),
                default => $current->addDay()
            };
        }
        foreach ($processedDocuments as $result) {
            $periodMap[$result->period_label] = $result->processed_count;
        }
        return response()->json([
            'labels' => array_keys($periodMap),
            'data' => array_values($periodMap),
        ]);
    }

    /**
     * Get data for the 'Return & Decline Rate Trends' chart.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getReturnDeclineTrendData(Request $request)
    {
        $period = $request->get('period', 'daily'); // daily, weekly, monthly, yearly
        $endDate = Carbon::now();
        $startDate = match ($period) {
            'weekly' => Carbon::now()->subWeeks(4),
            'monthly' => Carbon::now()->subMonths(12),
            'yearly' => Carbon::now()->subYears(5),
            default => Carbon::now()->subDays(30),
        };
        $dateFormat = match ($period) {
            'weekly' => '%Y-%W',
            'monthly' => '%Y-%m',
            'yearly' => '%Y',
            default => '%Y-%m-%d',
        };
        // Initialize period map for all labels
        $periodMap = [];
        $current = $startDate->clone();
        while ($current <= $endDate) {
            $label = match($period) {
                'weekly' => $current->format('Y-W'),
                'monthly' => $current->format('Y-m'),
                'yearly' => $current->format('Y'),
                default => $current->format('Y-m-d')
            };
            $periodMap[$label] = ['declined' => 0, 'returned' => 0];
            $current = match($period) {
                'weekly' => $current->addWeek(),
                'monthly' => $current->addMonth(),
                'yearly' => $current->addYear(),
                default => $current->addDay()
            };
        }
        // Fetch declined documents data
        $declinedDocuments = Document::select(
                DB::raw("DATE_FORMAT(declined_at, '{$dateFormat}') as period_label"),
                DB::raw('COUNT(*) as count')
            )
            ->where('status', 'declined')
            ->whereBetween('declined_at', [$startDate, $endDate])
            ->groupBy('period_label')
            ->orderBy('period_label')
            ->get();
        foreach ($declinedDocuments as $result) {
            if (isset($periodMap[$result->period_label])) {
                $periodMap[$result->period_label]['declined'] = $result->count;
            }
        }
        // Fetch return requests data
        $returnRequests = DocumentLog::select(
                DB::raw("DATE_FORMAT(created_at, '{$dateFormat}') as period_label"),
                DB::raw('COUNT(*) as count')
            )
            ->where('action', 'LIKE', '%Return Request%') // Assuming action contains "Return Request"
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('period_label')
            ->orderBy('period_label')
            ->get();
        foreach ($returnRequests as $result) {
            if (isset($periodMap[$result->period_label])) {
                $periodMap[$result->period_label]['returned'] = $result->count;
            }
        }
        return response()->json([
            'labels' => array_keys($periodMap),
            'datasets' => [
                [
                    'label' => 'Declined Documents',
                    'data' => array_column($periodMap, 'declined'),
                    'borderColor' => '#ef4444', // Red
                    'backgroundColor' => 'rgba(239, 68, 68, 0.2)',
                    'tension' => 0.1,
                    'fill' => true,
                ],
                [
                    'label' => 'Return Requests',
                    'data' => array_column($periodMap, 'returned'),
                    'borderColor' => '#f97316', // Orange
                    'backgroundColor' => 'rgba(249, 115, 22, 0.2)',
                    'tension' => 0.1,
                    'fill' => true,
                ],
            ],
        ]);
    }
        
    /**
     * Get data for the 'Document Status Distribution' chart.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDocumentStatusDistributionData()
    {
        $statusDistribution = Document::select(DB::raw('count(*) as count, status'))
            ->groupBy('status')
            ->pluck('count', 'status');
        $labels = $statusDistribution->keys();
        $data = $statusDistribution->values();

        // You can define a color map for consistent chart colors
        $colorMap = [
            'pending' => '#f97316', // Orange
            'in_transit' => '#3b82f6', // Blue
            'processing' => '#eab308', // Yellow
            'ready_for_release' => '#84cc16', // Lime
            'completed' => '#22c55e', // Green
            'declined' => '#ef4444', // Red
            'frozen' => '#64748b', // Slate
        ];

        $backgroundColors = $labels->map(fn($status) => $colorMap[$status] ?? '#A9A9A9')->toArray();
        return response()->json([
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $data, 
                    'backgroundColor' => $backgroundColors,
                ],
            ],
        ]);
    }

    /**
     * Get data for the 'Return Request Sources' chart.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getReturnRequestSourcesData()
    {
        $returnData = DocumentLog::join('users', 'document_logs.user_id', '=', 'users.id')
            ->join('departments', 'users.department_id', '=', 'departments.id')
            ->where('document_logs.action', 'LIKE', '%Return Request%')
            ->select('departments.name as department_name', DB::raw('COUNT(document_logs.id) as count'))
            ->groupBy('departments.name')
            ->orderBy('count', 'desc')
            ->get();
        return response()->json([
            'labels' => $returnData->pluck('department_name'),
            'datasets' => [
                [
                    'label' => 'Return Requests Issued',
                    'data' => $returnData->pluck('count'),
                    'backgroundColor' => 'rgba(168, 85, 247, 0.5)', // Purple
                    'borderColor' => 'rgba(168, 85, 247, 1)',
                    'borderWidth' => 1,
                ],
            ],
        ]);
    }

    /**
     * Get data for the 'Processing Hotspots' chart by document purpose.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProcessingHotspotsData()
    {
        // Step 1: Find the top 10 most common document purposes
        $topPurposes = Document::select('purpose_id', DB::raw('count(*) as purpose_count'))
            ->groupBy('purpose_id')
            ->orderBy('purpose_count', 'desc')
            ->limit(10)
            ->pluck('purpose_id');
        // Step 2: Calculate average processing time for documents with these purposes
        $startLogs = DocumentLog::where('action', 'Accepted and Document Routing finalized')->select('document_id', 'created_at as start_time');
        $endLogs = DocumentLog::where('action', 'Document Released')->select('document_id', 'created_at as end_time');
        $processingTimes = Document::joinSub($startLogs, 'start_logs', function ($join) {
                $join->on('documents.id', '=', 'start_logs.document_id');
            })
            ->joinSub($endLogs, 'end_logs', function ($join) {
                $join->on('documents.id', '=', 'end_logs.document_id');
            })
            ->join('purposes', 'documents.purpose_id', '=', 'purposes.id')
            ->whereIn('documents.purpose_id', $topPurposes)
            ->select(
                'purposes.name as purpose_name',
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, start_logs.start_time, end_logs.end_time)) as avg_duration_seconds')
            )
            ->groupBy('purposes.name')
            ->orderBy('avg_duration_seconds', 'desc')
            ->get();
        return response()->json([
            'labels' => $processingTimes->pluck('purpose_name'),
            'datasets' => [  
                [
                    'label' => 'Average Processing Time (seconds)',
                    'data' => $processingTimes->pluck('avg_duration_seconds'),
                    'backgroundColor' => 'rgba(239, 68, 68, 0.5)', // Red
                    'borderColor' => 'rgba(239, 68, 68, 1)',
                    'borderWidth' => 1,
                ],
            ],
        ]);
    }

    /**
     * Get data for the 'Average Step Time by Department' chart.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAvgStepTimeByDepartmentData()
    {
        // 1. Get all relevant logs with department info, ordered correctly.
        $logs = DocumentLog::join('users', 'document_logs.user_id', '=', 'users.id')
            ->whereIn('action', ['Received', 'Processing Complete'])
            ->select('document_logs.document_id', 'users.department_id', 'document_logs.action', 'document_logs.created_at')
            ->orderBy('document_logs.document_id')
            ->orderBy('users.department_id')
            ->orderBy('document_logs.created_at')
            ->get();
        // 2. Process these logs in PHP to calculate durations.
        $durations = []; // [ 'department_id' => [duration1, duration2, ...], ... ]
        $openReceives = []; // [ 'doc_id-dept_id' => received_timestamp ]
        foreach ($logs as $log) {
            $key = $log->document_id . '-' . $log->department_id;
            if ($log->action === 'Received') {
                // Store the 'Received' timestamp. Overwrites previous ones for the same key,
                // which is fine as we only want the one immediately preceding the 'Complete'.
                $openReceives[$key] = $log->created_at;
            } elseif ($log->action === 'Processing Complete') {
                // If a 'Complete' log is found, look for a matching 'Received' log.
                if (isset($openReceives[$key])) {
                    $startTime = $openReceives[$key];
                    $endTime = $log->created_at;
                    $duration = $endTime->getTimestamp() - $startTime->getTimestamp();
                    if (!isset($durations[$log->department_id])) {
                        $durations[$log->department_id] = [];
                    }
                    $durations[$log->department_id][] = $duration;
                    // Unset the open 'Received' event as this pair is now processed.
                    unset($openReceives[$key]);
                }
            }
        }
        // 3. Calculate averages for each department.
        $departmentAverages = [];
        foreach ($durations as $department_id => $dept_durations) {
            if (count($dept_durations) > 0) {
                $departmentAverages[$department_id] = array_sum($dept_durations) / count($dept_durations);
            }
        }
        // 4. Get department names and format for the chart.
        $departmentIds = array_keys($departmentAverages);
        $departments = Department::whereIn('id', $departmentIds)->pluck('name', 'id');
        // Sort by average time descending
        arsort($departmentAverages);
        $labels = [];
        $data = [];
        foreach ($departmentAverages as $department_id => $avg) {
            if (isset($departments[$department_id])) {
                $labels[] = $departments[$department_id];
                $data[] = $avg;
            }
        }
        return response()->json([
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Average Step Time (seconds)',
                    'data' => $data,
                    'backgroundColor' => 'rgba(14, 165, 233, 0.5)', // Sky Blue
                    'borderColor' => 'rgba(14, 165, 233, 1)',
                    'borderWidth' => 1,
                ],
            ],
        ]);
    }
}
