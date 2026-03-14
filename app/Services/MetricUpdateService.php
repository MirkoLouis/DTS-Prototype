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
        $date = $date ?? now()->toDateString();
        
        DB::table('daily_department_metrics')->updateOrInsert(
            ['department_id' => $departmentId, 'date' => $date],
            ['received_count' => DB::raw('received_count + 1'), 'updated_at' => now()]
        );
    }

    /**
     * Increment the processed count and total processing seconds for a department and date.
     */
    public function incrementProcessed(int $departmentId, int $seconds, ?Carbon $date = null)
    {
        $date = $date ?? now()->toDateString();
        
        DB::table('daily_department_metrics')->updateOrInsert(
            ['department_id' => $departmentId, 'date' => $date],
            [
                'processed_count' => DB::raw('processed_count + 1'),
                'total_processing_seconds' => DB::raw("total_processing_seconds + {$seconds}"),
                'updated_at' => now()
            ]
        );
    }

    /**
     * Increment the released count and total processing seconds for a department and date.
     */
    public function incrementReleased(int $departmentId, int $seconds, ?Carbon $date = null)
    {
        $date = $date ?? now()->toDateString();
        
        DB::table('daily_department_metrics')->updateOrInsert(
            ['department_id' => $departmentId, 'date' => $date],
            [
                'released_count' => DB::raw('released_count + 1'),
                'total_processing_seconds' => DB::raw("total_processing_seconds + {$seconds}"),
                'updated_at' => now()
            ]
        );
    }
}
