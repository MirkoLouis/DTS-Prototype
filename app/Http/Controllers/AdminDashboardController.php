<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\Purpose;
use App\Models\Department;
use App\Models\DailyDepartmentMetric;
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
     * OPTIMIZED: Uses indexed current_department_id.
     */
    public function getCurrentLoadData(Request $request)
    {
        $departmentId = $request->get('department_id', 'all');
        $cacheKey = "current_load_v2_{$departmentId}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function() use ($departmentId) {
            $query = DB::table('documents')
                ->where('status', 'processing')
                ->whereNotNull('current_department_id');

            if ($departmentId && $departmentId !== 'all') {
                $query->where('current_department_id', $departmentId);
            }

            $results = $query->join('departments', 'documents.current_department_id', '=', 'departments.id')
                ->select('departments.name as dept_name', DB::raw('COUNT(*) as count'))
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

            return [
                'labels' => array_keys($departmentLoads),
                'data' => array_values($departmentLoads),
            ];
        });
    }

    /**
     * Get data for the 'Throughput' chart (Average TAT over time).
     * OPTIMIZED: Uses daily_department_metrics.
     */
    public function getThroughputData(Request $request)
    {
        $period = $request->get('period', 'daily'); 
        $departmentId = $request->get('department_id', 'all');

        $cacheKey = "throughput_data_v2_{$period}_{$departmentId}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period, $departmentId) {
            list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);
            
            $query = DB::table('daily_department_metrics')
                ->whereBetween('date', [$startDate, $endDate]);

            if ($departmentId && $departmentId !== 'all') {
                $query->where('department_id', $departmentId);
            }

            $results = $query->select(
                    DB::raw("DATE_FORMAT(date, '{$dateFormat}') as period_label"),
                    DB::raw('SUM(total_processing_seconds) as total_seconds'),
                    DB::raw('SUM(processed_count + released_count) as total_count')
                )
                ->groupBy('period_label')
                ->orderBy('period_label')
                ->get();

            $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

            foreach ($results as $result) {
                if (isset($periodMap[$result->period_label])) {
                    $avgHours = $result->total_count > 0 ? ($result->total_seconds / $result->total_count) / 3600 : 0;
                    $periodMap[$result->period_label] = round($avgHours, 2);
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
     * Get data for the 'Average TAT by Department' chart.
     * OPTIMIZED: Uses daily_department_metrics.
     */
    public function getAvgStepTimeByDepartmentData(Request $request)
    {
        $isFull = $request->has('full') ? 'full' : 'limited';
        return Cache::remember("avg_step_time_v2_{$isFull}", now()->addMinutes(10), function() use ($request) {
            $query = DB::table('daily_department_metrics')
                ->join('departments', 'daily_department_metrics.department_id', '=', 'departments.id')
                ->select(
                    'departments.name',
                    DB::raw('SUM(total_processing_seconds) as total_seconds'),
                    DB::raw('SUM(processed_count + released_count) as total_count')
                )
                ->groupBy('departments.name')
                ->having('total_count', '>', 0);

            if (!$request->has('full')) {
                $query->limit(5);
            }

            $results = $query->get()->map(function($r) {
                $r->avg_hours = ($r->total_seconds / $r->total_count) / 3600;
                return $r;
            })->sortBy('avg_hours');

            return [
                'labels' => $results->pluck('name'),
                'datasets' => [
                    [
                        'label' => 'Average Step Time (hrs)',
                        'data' => $results->pluck('avg_hours')->map(fn($h) => round($h, 2)),
                        'backgroundColor' => 'rgba(14, 165, 233, 0.5)', 
                        'borderColor' => 'rgba(14, 165, 233, 1)',
                        'borderWidth' => 1,
                    ],
                ],
            ];
        });
    }

    /**
     * Get data for the 'Load vs. Time' correlation chart for a specific department.
     * OPTIMIZED: Uses daily_department_metrics.
     */
    public function getDepartmentalLoadVsTimeData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $departmentId = $request->get('department_id', 'all');

        $cacheKey = "dept_load_time_v2_{$period}_{$departmentId}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period, $departmentId) {
            list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);

            $query = DB::table('daily_department_metrics')
                ->whereBetween('date', [$startDate, $endDate]);

            if ($departmentId && $departmentId !== 'all') {
                $query->where('department_id', $departmentId);
            }

            $results = $query->select(
                DB::raw("DATE_FORMAT(date, '{$dateFormat}') as period_label"),
                DB::raw('SUM(received_count) as received_total'),
                DB::raw('SUM(total_processing_seconds) as total_seconds'),
                DB::raw('SUM(processed_count + released_count) as work_total')
            )
            ->groupBy('period_label')
            ->get();

            $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);
            $finalData = [];
            foreach (array_keys($periodMap) as $label) {
                $finalData[$label] = ['load' => 0, 'time' => 0];
            }

            foreach ($results as $result) {
                if (isset($finalData[$result->period_label])) {
                    $avgHours = $result->work_total > 0 ? ($result->total_seconds / $result->work_total) / 3600 : 0;
                    $finalData[$result->period_label] = [
                        'load' => (int) $result->received_total,
                        'time' => round($avgHours, 2)
                    ];
                }
            }

            return [
                'labels' => array_keys($finalData),
                'datasets' => [
                    [
                        'label' => 'Documents Received',
                        'data' => array_column($finalData, 'load'),
                        'borderColor' => 'rgba(54, 162, 235, 1)',
                        'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                        'yAxisID' => 'y',
                        'tension' => 0.1,
                    ],
                    [
                        'label' => 'Avg. Processing Time (hrs)',
                        'data' => array_column($finalData, 'time'),
                        'borderColor' => 'rgba(255, 99, 132, 1)',
                        'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                        'yAxisID' => 'y1',
                        'tension' => 0.1,
                    ]
                ]
            ];
        });
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
     * Get data for the 'Return & Decline Rate Trends' chart.
     */
    public function getReturnDeclineTrendData(Request $request)
    {
        $period = $request->get('period', 'daily');
        $cacheKey = "return_decline_trends_v2_{$period}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function() use ($period) {
            list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);

            $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);
            $finalData = [];
            foreach (array_keys($periodMap) as $label) {
                $finalData[$label] = ['declined' => 0, 'returned' => 0];
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
                if (isset($finalData[$result->period_label])) {
                    $finalData[$result->period_label]['declined'] = $result->count;
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
                if (isset($finalData[$result->period_label])) {
                    $finalData[$result->period_label]['returned'] = $result->count;
                }
            }

            return [
                'labels' => array_keys($finalData),
                'datasets' => [
                    [
                        'label' => 'Declined Documents',
                        'data' => array_column($finalData, 'declined'),
                        'borderColor' => '#ef4444', 
                        'backgroundColor' => 'rgba(239, 68, 68, 0.2)',
                        'tension' => 0.1,
                        'fill' => true,
                    ],
                    [
                        'label' => 'Return Requests',
                        'data' => array_column($finalData, 'returned'),
                        'borderColor' => '#f97316', 
                        'backgroundColor' => 'rgba(249, 115, 22, 0.2)',
                        'tension' => 0.1,
                        'fill' => true,
                    ],
                ],
            ];
        });
    }
        
    /**
     * Get data for the 'Document Status Distribution' chart.
     */
    public function getDocumentStatusDistributionData()
    {
        return Cache::remember('status_distribution_v2', now()->addMinutes(10), function() {
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
            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'data' => $data, 
                        'backgroundColor' => $backgroundColors,
                    ],
                ],
            ];
        });
    }

    /**
     * Get data for the 'Return Request Sources' chart.
     */
    public function getReturnRequestSourcesData()
    {
        return Cache::remember('return_request_sources_v2', now()->addMinutes(10), function() {
            $returnData = DB::table('document_logs')
                ->join('users', 'document_logs.user_id', '=', 'users.id')
                ->join('departments', 'users.department_id', '=', 'departments.id')
                ->where('document_logs.action', 'LIKE', '%Return Request%')
                ->select('departments.name as department_name', DB::raw('COUNT(document_logs.id) as count'))
                ->groupBy('departments.name')
                ->orderBy('count', 'desc')
                ->get();

            return [
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
            ];
        });
    }

    /**
     * Get data for the 'Processing Hotspots' chart by document purpose.
     */
    public function getProcessingHotspotsData()
    {
        return Cache::remember('processing_hotspots_v2', now()->addMinutes(10), function() {
            $purposeMetrics = DB::table('documents')
                ->join('purposes', 'documents.purpose_id', '=', 'purposes.id')
                ->select(
                    'purposes.name as purpose_name',
                    DB::raw('COUNT(documents.id) as doc_count'),
                    DB::raw('AVG(TIMESTAMPDIFF(SECOND, documents.created_at, IFNULL(released_at, NOW()))) / 3600 as avg_duration_hours')
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

            return [
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
            ];
        });
    }

    /**
     * Get data for the 'Submission Volume by District' chart.
     */
    public function getSubmissionDistrictsData()
    {
        return Cache::remember('submission_districts_v2', now()->addMinutes(10), function() {
            $districtData = DB::table('documents')
                ->select('district', DB::raw('COUNT(*) as count'))
                ->whereNotNull('district')
                ->groupBy('district')
                ->orderBy('count', 'desc')
                ->get();

            return [
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
            ];
        });
    }

    /**
     * Helper function to get date parameters based on the period.
     */
    private function getDateParameters($period)
    {
        $endDate = Carbon::now();
        switch ($period) {
            case 'weekly':
                return [$endDate->copy()->subWeeks(12), $endDate, '%x-%v'];
            case 'monthly':
                return [$endDate->copy()->subMonths(12), $endDate, '%Y-%m'];
            case 'yearly':
                return [$endDate->copy()->subYears(5), $endDate, '%Y'];
            default: // daily
                return [$endDate->copy()->subDays(30), $endDate, '%Y-%m-%d'];
        }
    }

    /**
     * Helper function to generate a map of periods with default value 0.
     */
    private function generatePeriodMap($startDate, $endDate, $period)
    {
        $periodMap = [];
        $current = $startDate->clone();
        
        $format = match($period) {
            'weekly' => 'o-W',
            'monthly' => 'Y-m',
            'yearly' => 'Y',
            default => 'Y-m-d'
        };

        $addMethod = match($period) {
            'weekly' => 'addWeek',
            'monthly' => 'addMonth',
            'yearly' => 'addYear',
            default => 'addDay'
        };

        while ($current <= $endDate) {
            $label = $current->format($format);
            $periodMap[$label] = 0; 
            $current->$addMethod();
        }
        return $periodMap;
    }
}
