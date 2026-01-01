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
        return view('admin.dashboard');
    }

    /**
     * Get data for the 'Current Load' chart (documents pending at each department).
     */
    public function getCurrentLoadData()
    {
        // Get all processing documents
        $processingDocuments = Document::where('status', 'processing')->get();

        $departmentLoads = [];

        // Aggregate documents by their current step's department
        foreach ($processingDocuments as $document) {
            if (!empty($document->finalized_route) && $document->current_step > 0 && $document->current_step <= count($document->finalized_route)) {
                $currentDepartmentName = $document->finalized_route[$document->current_step - 1];
                $departmentLoads[$currentDepartmentName] = ($departmentLoads[$currentDepartmentName] ?? 0) + 1;
            }
        }

        // Ensure all departments are represented, even if load is 0
        $allDepartments = Department::pluck('name')->toArray();
        foreach ($allDepartments as $deptName) {
            if (!isset($departmentLoads[$deptName])) {
                $departmentLoads[$deptName] = 0;
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

        $endDate = Carbon::now();
        $startDate = match ($period) {
            'weekly' => Carbon::now()->subWeeks(4), // Last 4 weeks
            'monthly' => Carbon::now()->subMonths(12), // Last 12 months
            'yearly' => Carbon::now()->subYears(5), // Last 5 years
            default => Carbon::now()->subDays(30), // Default to last 30 days
        };

        $dateFormat = match ($period) {
            'weekly' => '%Y-%W', // Year-Week number
            'monthly' => '%Y-%m', // Year-Month
            'yearly' => '%Y', // Year
            default => '%Y-%m-%d', // Year-Month-Day
        };

        $processedDocuments = DocumentLog::select(
                DB::raw("DATE_FORMAT(created_at, '{$dateFormat}') as period_label"),
                DB::raw('COUNT(DISTINCT document_id) as processed_count')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('action', 'like', '%completed%') // Or any action that signifies 'processed'
            ->groupBy('period_label')
            ->orderBy('period_label')
            ->get();

        $labels = $processedDocuments->pluck('period_label')->toArray();
        $data = $processedDocuments->pluck('processed_count')->toArray();

        return response()->json([
            'labels' => $labels,
            'data' => $data,
        ]);
    }
}
