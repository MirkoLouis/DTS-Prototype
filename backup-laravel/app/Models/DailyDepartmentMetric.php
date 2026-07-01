<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyDepartmentMetric extends Model
{
    protected $fillable = [
        'department_id',
        'date',
        'received_count',
        'processed_count',
        'released_count',
        'total_processing_seconds',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Get the department associated with these metrics.
     */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
