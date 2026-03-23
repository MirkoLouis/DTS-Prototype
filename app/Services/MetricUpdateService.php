<?php

namespace App\Services;

use App\Models\DailyDepartmentMetric;
use App\Models\Document;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MetricUpdateService
{
    /**
     * Update the current department of a document.
     */
    public function updateCurrentDepartment(Document $document, ?int $departmentId)
    {
        $document->update(['current_department_id' => $departmentId]);
    }

    /**
     * Increment the received count for a department and date.
     */
    public function incrementReceived(int $departmentId, ?Carbon $date = null)
    {
        $dateString = $date ? $date->toDateString() : now()->toDateString();
        
        // Ensure the record exists first (Safe cross-DB approach)
        DB::table('daily_department_metrics')->updateOrInsert(
            ['department_id' => $departmentId, 'date' => $dateString],
            ['updated_at' => now()]
        );

        // Then increment the specific metric
        DB::table('daily_department_metrics')
            ->where('department_id', $departmentId)
            ->where('date', $dateString)
            ->increment('received_count');
    }

    /**
     * Increment the processed count and total processing seconds for a department and date.
     */
    public function incrementProcessed(int $departmentId, int $seconds, ?Carbon $date = null)
    {
        $dateString = $date ? $date->toDateString() : now()->toDateString();
        
        DB::table('daily_department_metrics')->updateOrInsert(
            ['department_id' => $departmentId, 'date' => $dateString],
            ['updated_at' => now()]
        );

        DB::table('daily_department_metrics')
            ->where('department_id', $departmentId)
            ->where('date', $dateString)
            ->incrementEach([
                'processed_count' => 1,
                'total_processing_seconds' => $seconds
            ]);
    }

    /**
     * Increment the released count and total processing seconds for a department and date.
     */
    public function incrementReleased(int $departmentId, int $seconds, ?Carbon $date = null)
    {
        $dateString = $date ? $date->toDateString() : now()->toDateString();
        
        DB::table('daily_department_metrics')->updateOrInsert(
            ['department_id' => $departmentId, 'date' => $dateString],
            ['updated_at' => now()]
        );

        DB::table('daily_department_metrics')
            ->where('department_id', $departmentId)
            ->where('date', $dateString)
            ->incrementEach([
                'released_count' => 1,
                'total_processing_seconds' => $seconds
            ]);
    }
}
