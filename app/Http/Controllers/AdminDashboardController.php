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
                // This is a bit tricky since the route is a JSON array of names.
                // We'll have to filter in the collection.
                $query->whereJsonContains('finalized_route', $department->name);
            }
        }

        $processingDocuments = $query->get();

        $departmentLoads = [];

        // Aggregate documents by their current step's department
        foreach ($processingDocuments as $document) {
            if (!empty($document->finalized_route) && $document->current_step > 0 && $document->current_step <= count($document->finalized_route)) {
                $currentDepartmentName = $document->finalized_route[$document->current_step - 1];

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
            ->where('document_logs.action', 'like', '%completed%');

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
}
