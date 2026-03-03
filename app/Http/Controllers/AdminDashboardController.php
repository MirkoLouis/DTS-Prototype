<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\Purpose;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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
     * Refactored to use SQL-level aggregation for 1M record performance.
     */
    public function getCurrentLoadData(Request $request)
    {
        $departmentId = $request->get('department_id');
        
        $query = DB::table('documents')
            ->where('status', 'processing')
            ->where('current_step', '>', 0)
            ->whereNotNull('finalized_route');

        if ($departmentId && $departmentId !== 'all') {
            $department = Department::find($departmentId);
            if ($department) {
                $query->whereJsonContains('finalized_route', [['name' => $department->name]]);
            }
        }

        // Use JSON_EXTRACT with a dynamic path based on current_step
        // We calculate which department a document is CURRENTLY at in SQL.
        $results = $query->select(
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(finalized_route, CONCAT('$[', current_step - 1, '].name'))) as dept_name"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('dept_name')
            ->get();

        $departmentLoads = $results->pluck('count', 'dept_name')->toArray();

        // Ensure all departments are represented if 'all' is selected
        if (!$departmentId || $departmentId === 'all') {
            $allDepartments = Department::pluck('name')->toArray();
            foreach ($allDepartments as $deptName) {
                if (!isset($departmentLoads[$deptName])) {
                    $departmentLoads[$deptName] = 0;
                }
            }
        } else {
             $selectedDepartment = Department::find($departmentId);
             if ($selectedDepartment) {
                 $name = $selectedDepartment->name;
                 $count = $departmentLoads[$name] ?? 0;
                 $departmentLoads = [$name => $count];
             }
        }

        arsort($departmentLoads);

        return response()->json([
            'labels' => array_keys($departmentLoads),
            'data' => array_values($departmentLoads),
        ]);
    }

    /**
     * Clear all dashboard-related cache.
     */
    public function clearCache()
    {
        Cache::flush(); 
        return back()->with('success', 'Dashboard cache cleared successfully. All cached charts will now fetch fresh data.');
    }

    /**
     * Get data for the 'Throughput' chart (documents processed over time).
     */
    public function getThroughputData(Request $request)
    {
        $period = $request->get('period', 'daily'); 
        $departmentId = $request->get('department_id', 'all');

        $cacheKey = "throughput_data_{$period}_{$departmentId}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($period, $departmentId) {
            $endDate = Carbon::now();
            $startDate = match ($period) {
                'weekly' => Carbon::now()->subWeeks(12),
                'monthly' => Carbon::now()->subMonths(12),
                'yearly' => Carbon::now()->subYears(5),
                default => Carbon::now()->subDays(30),
            };
            $dateFormat = match ($period) {
                'weekly' => '%x-%v', 
                'monthly' => '%Y-%m',
                'yearly' => '%Y',
                default => '%Y-%m-%d',
            };

            $startLogs = DB::table('document_logs')
                ->where('action', 'Accepted and Document Routing finalized')
                ->select('document_id', DB::raw('MIN(created_at) as start_time'))
                ->groupBy('document_id');

            $endLogs = DB::table('document_logs')
                ->where('action', 'Document Released')
                ->select('document_id', DB::raw('MAX(created_at) as end_time'))
                ->groupBy('document_id');
            
            $query = DB::table('documents')
                ->joinSub($startLogs, 'start_logs', 'documents.id', '=', 'start_logs.document_id')
                ->joinSub($endLogs, 'end_logs', 'documents.id', '=', 'end_logs.document_id')
                ->whereBetween('end_logs.end_time', [$startDate, $endDate]);

            if ($departmentId && $departmentId !== 'all') {
                $department = Department::find($departmentId);
                if ($department) {
                    $query->whereJsonContains('documents.finalized_route', [['name' => $department->name]]);
                }
            }

            $avgTimes = $query->select(
                    DB::raw("DATE_FORMAT(end_logs.end_time, '{$dateFormat}') as period_label"),
                    DB::raw('AVG(TIMESTAMPDIFF(HOUR, start_logs.start_time, end_logs.end_time)) as avg_duration_hours')
                )
                ->groupBy('period_label')
                ->orderBy('period_label')
                ->get();

            $periodMap = [];
            $current = $startDate->clone();
            while ($current <= $endDate) {
                $label = match($period) {
                    'weekly' => $current->format('o-W'),
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

            foreach ($avgTimes as $result) {
                if (isset($periodMap[$result->period_label])) {
                    $periodMap[$result->period_label] = (float) $result->avg_duration_hours;
                }
            }

            return [
                'labels' => array_keys($periodMap),
                'datasets' => [
                    [
                        'label' => 'Average Processing Time (hrs)',
                        'data' => array_values($periodMap),
                        'borderColor' => 'rgba(75, 192, 192, 1)',
                        'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                        'fill' => true,
                        'tension' => 0.1,
                    ]
                ]
            ];
        });
    }

    /**
     * Get data for the 'Return & Decline Rate Trends' chart.
     */
    public function getReturnDeclineTrendData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $endDate = Carbon::now();
        $startDate = match ($period) {
            'weekly' => Carbon::now()->subWeeks(12),
            'monthly' => Carbon::now()->subMonths(12),
            'yearly' => Carbon::now()->subYears(5),
            default => Carbon::now()->subDays(30),
        };
        $dateFormat = match ($period) {
            'weekly' => '%x-%v',
            'monthly' => '%Y-%m',
            'yearly' => '%Y',
            default => '%Y-%m-%d',
        };

        $periodMap = [];
        $current = $startDate->clone();
        while ($current <= $endDate) {
            $label = $current->format(match($period) {
                'weekly' => 'o-W',
                'monthly' => 'Y-m',
                'yearly' => 'Y',
                default => 'Y-m-d'
            });
            $periodMap[$label] = ['declined' => 0, 'returned' => 0];
            $current = match($period) {
                'weekly' => $current->addWeek(),
                'monthly' => $current->addMonth(),
                'yearly' => $current->addYear(),
                default => $current->addDay()
            };
        }

        $declinedDocuments = DB::table('documents')
            ->select(
                DB::raw("DATE_FORMAT(declined_at, '{$dateFormat}') as period_label"),
                DB::raw('COUNT(*) as count')
            )
            ->where('status', 'declined')
            ->whereBetween('declined_at', [$startDate, $endDate])
            ->groupBy('period_label')
            ->get();

        foreach ($declinedDocuments as $result) {
            if (isset($periodMap[$result->period_label])) {
                $periodMap[$result->period_label]['declined'] = $result->count;
            }
        }

        $returnRequests = DB::table('document_logs')
            ->select(
                DB::raw("DATE_FORMAT(created_at, '{$dateFormat}') as period_label"),
                DB::raw('COUNT(*) as count')
            )
            ->where('action', 'LIKE', '%Return Request%')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('period_label')
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
                    'borderColor' => '#ef4444', 
                    'backgroundColor' => 'rgba(239, 68, 68, 0.2)',
                    'tension' => 0.1,
                    'fill' => true,
                ],
                [
                    'label' => 'Return Requests',
                    'data' => array_column($periodMap, 'returned'),
                    'borderColor' => '#f97316', 
                    'backgroundColor' => 'rgba(249, 115, 22, 0.2)',
                    'tension' => 0.1,
                    'fill' => true,
                ],
            ],
        ]);
    }
        
    /**
     * Get data for the 'Document Status Distribution' chart.
     */
    public function getDocumentStatusDistributionData()
    {
        $statusDistribution = DB::table('documents')
            ->select(DB::raw('count(*) as count, status'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $labels = $statusDistribution->keys();
        $data = $statusDistribution->values();

        $colorMap = [
            'pending' => '#f97316', 
            'in_transit' => '#3b82f6', 
            'processing' => '#eab308', 
            'ready_for_release' => '#84cc16', 
            'completed' => '#22c55e', 
            'declined' => '#ef4444', 
            'frozen' => '#64748b', 
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
     */
    public function getReturnRequestSourcesData()
    {
        $returnData = DB::table('document_logs')
            ->join('users', 'document_logs.user_id', '=', 'users.id')
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
                    'backgroundColor' => 'rgba(168, 85, 247, 0.5)', 
                    'borderColor' => 'rgba(168, 85, 247, 1)',
                    'borderWidth' => 1,
                ],
            ],
        ]);
    }

    /**
     * Get data for the 'Processing Hotspots' chart by document purpose.
     */
    public function getProcessingHotspotsData()
    {
        $startLogs = DB::table('document_logs')
            ->where('action', 'Accepted and Document Routing finalized')
            ->select('document_id', DB::raw('MIN(created_at) as start_time'))
            ->groupBy('document_id');

        $endLogs = DB::table('document_logs')
            ->where('action', 'Document Released')
            ->select('document_id', DB::raw('MAX(created_at) as end_time'))
            ->groupBy('document_id');

        $purposeMetrics = DB::table('documents')
            ->leftJoinSub($startLogs, 'start_logs', 'documents.id', '=', 'start_logs.document_id')
            ->leftJoinSub($endLogs, 'end_logs', 'documents.id', '=', 'end_logs.document_id')
            ->join('purposes', 'documents.purpose_id', '=', 'purposes.id')
            ->select(
                'purposes.name as purpose_name',
                DB::raw('COUNT(documents.id) as doc_count'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, start_logs.start_time, end_logs.end_time)) / 3600 as avg_duration_hours')
            )
            ->groupBy('purposes.name')
            ->orderBy('doc_count', 'desc')
            ->limit(15) 
            ->get();

        $colors = [
            'rgba(239, 68, 68, 0.6)', 'rgba(59, 130, 246, 0.6)', 'rgba(16, 185, 129, 0.6)',
            'rgba(245, 158, 11, 0.6)', 'rgba(139, 92, 246, 0.6)', 'rgba(236, 72, 153, 0.6)',
            'rgba(20, 184, 166, 0.6)', 'rgba(249, 115, 22, 0.6)', 'rgba(107, 114, 128, 0.6)',
            'rgba(79, 70, 229, 0.6)', 'rgba(217, 70, 239, 0.6)', 'rgba(101, 163, 13, 0.6)',
            'rgba(2, 132, 199, 0.6)', 'rgba(185, 28, 28, 0.6)', 'rgba(30, 58, 138, 0.6)'
        ];

        return response()->json([
            'labels' => $purposeMetrics->pluck('purpose_name'),
            'datasets' => [
                [
                    'label' => 'Document Volume',
                    'data' => $purposeMetrics->pluck('doc_count'),
                    'backgroundColor' => array_slice($colors, 0, $purposeMetrics->count()),
                    'borderColor' => array_map(fn($c) => str_replace('0.6', '1', $c), array_slice($colors, 0, $purposeMetrics->count())),
                    'borderWidth' => 1,
                    'avgHours' => $purposeMetrics->pluck('avg_duration_hours')->map(fn($h) => $h ? round($h, 2) : 'N/A'),
                ]
            ]
        ]);
    }

    /**
     * Get data for the 'Submission Volume by District' chart.
     */
    public function getSubmissionDistrictsData()
    {
        $districtData = DB::table('documents')
            ->select('district', DB::raw('COUNT(*) as count'))
            ->whereNotNull('district')
            ->groupBy('district')
            ->orderBy('count', 'desc')
            ->get();

        return response()->json([
            'labels' => $districtData->pluck('district'),
            'datasets' => [
                [
                    'label' => 'Documents Submitted',
                    'data' => $districtData->pluck('count'),
                    'backgroundColor' => 'rgba(99, 102, 241, 0.5)', 
                    'borderColor' => 'rgba(99, 102, 241, 1)',
                    'borderWidth' => 1,
                ]
            ]
        ]);
    }

    /**
     * Get data for the 'Average Step Time by Department' chart.
     * Refactored to use Window Functions (MySQL 8.0) to avoid RAM traps.
     */
    public function getAvgStepTimeByDepartmentData(Request $request)
    {
        // Use a Window Function to calculate durations between logs directly in SQL.
        // This calculates (Current Action Time - Previous Action Time) for pairs of Received -> Complete.
        $durationsQuery = DB::table(function ($query) {
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
        ->join('departments', 'users.department_id', '=', 'departments.id')
        ->where('action', 'Processing Complete')
        ->where('prev_action', 'Received')
        ->select(
            'departments.name',
            DB::raw('AVG(TIMESTAMPDIFF(SECOND, prev_created_at, log_durations.created_at)) / 3600 as avg_hours')
        )
        ->groupBy('departments.name')
        ->orderBy('avg_hours', 'asc');

        if (!$request->has('full')) {
            $durationsQuery->limit(5);
        }

        $results = $durationsQuery->get();

        return response()->json([
            'labels' => $results->pluck('name'),
            'datasets' => [
                [
                    'label' => 'Average Step Time (hrs)',
                    'data' => $results->pluck('avg_hours'),
                    'backgroundColor' => 'rgba(14, 165, 233, 0.5)', 
                    'borderColor' => 'rgba(14, 165, 233, 1)',
                    'borderWidth' => 1,
                ],
            ],
        ]);
    }

    /**
     * Get data for the 'Load vs. Time' correlation chart for a specific department.
     * Refactored for SQL-level performance.
     */
    public function getDepartmentalLoadVsTimeData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $departmentId = $request->get('department_id', 'all');

        $cacheKey = "dept_load_time_{$period}_{$departmentId}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($period, $departmentId) {
            $endDate = Carbon::now();
            $startDate = match ($period) {
                'weekly' => Carbon::now()->subWeeks(12),
                'monthly' => Carbon::now()->subMonths(12),
                'yearly' => Carbon::now()->subYears(5),
                default => Carbon::now()->subDays(30),
            };
            $dateFormat = match ($period) {
                'weekly' => '%x-%v',
                'monthly' => '%Y-%m',
                'yearly' => '%Y',
                default => '%Y-%m-%d',
            };

            // 1. Calculate Avg Processing Time per Period in SQL
            $durationsSubquery = DB::table(function ($query) {
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
            ->where('action', 'Processing Complete')
            ->where('prev_action', 'Received');

            if ($departmentId && $departmentId !== 'all') {
                $durationsSubquery->where('users.department_id', $departmentId);
            }

            $timeResults = $durationsSubquery->select(
                DB::raw("DATE_FORMAT(log_durations.created_at, '{$dateFormat}') as period_label"),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, prev_created_at, log_durations.created_at)) / 3600 as avg_hours')
            )
            ->groupBy('period_label')
            ->get()
            ->pluck('avg_hours', 'period_label');

            // 2. Calculate Received Load per Period in SQL
            $loadQuery = DB::table('document_logs')
                ->join('users', 'document_logs.user_id', '=', 'users.id')
                ->where('action', 'Received')
                ->whereBetween('document_logs.created_at', [$startDate, $endDate]);

            if ($departmentId && $departmentId !== 'all') {
                $loadQuery->where('users.department_id', $departmentId);
            }

            $loadResults = $loadQuery->select(
                DB::raw("DATE_FORMAT(document_logs.created_at, '{$dateFormat}') as period_label"),
                DB::raw('COUNT(document_logs.id) as received_count')
            )
            ->groupBy('period_label')
            ->get()
            ->pluck('received_count', 'period_label');

            // 3. Map to periods to ensure no gaps
            $periodMap = [];
            $current = $startDate->clone();
            while ($current <= $endDate) {
                $label = $current->format(match($period) {
                    'weekly' => 'o-W',
                    'monthly' => 'Y-m',
                    'yearly' => 'Y',
                    default => 'Y-m-d'
                });
                $periodMap[$label] = [
                    'load' => (int) ($loadResults[$label] ?? 0),
                    'time' => (float) ($timeResults[$label] ?? 0)
                ];
                $current = match($period) {
                    'weekly' => $current->addWeek(),
                    'monthly' => $current->addMonth(),
                    'yearly' => $current->addYear(),
                    default => $current->addDay()
                };
            }

            return [
                'labels' => array_keys($periodMap),
                'datasets' => [
                    [
                        'label' => 'Documents Received',
                        'data' => array_column($periodMap, 'load'),
                        'borderColor' => 'rgba(54, 162, 235, 1)',
                        'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                        'yAxisID' => 'y',
                        'tension' => 0.1,
                    ],
                    [
                        'label' => 'Avg. Processing Time (hrs)',
                        'data' => array_column($periodMap, 'time'),
                        'borderColor' => 'rgba(255, 99, 132, 1)',
                        'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                        'yAxisID' => 'y1',
                        'tension' => 0.1,
                    ]
                ]
            ];
        });
    }
}
