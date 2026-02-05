<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tracking_code',
        'title',
        'guest_info',
        'purpose_id',
        'details',
        'district',
        'department',
        'status',
        'decline_reason',
        'declined_at',
        'finalized_route',
        'current_step',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'guest_info' => 'array',
        'finalized_route' => 'array',
        'declined_at' => 'datetime',
    ];

    /**
     * Get the purpose associated with the document.
     */
    public function purpose()
    {
        return $this->belongsTo(Purpose::class);
    }

    /**
     * Get the logs for the document.
     */
    public function logs()
    {
        return $this->hasMany(DocumentLog::class);
    }

    /**
     * Get the full route as an array of objects, including Intake and Releasing steps.
     * This is a dynamic attribute, designed to be used by view components.
     *
     * @return array
     */
    public function getDisplayRouteObjectsAttribute() {
        $processingSteps = $this->finalized_route ?? [];

        $intakeStep = [['name' => 'Intake', 'type' => 'intake']];
        $releasingStep = [['name' => 'Releasing', 'type' => 'releasing']];

        return array_merge($intakeStep, $processingSteps, $releasingStep);
    }

    /**
     * Get the user-facing current step number based on the document's status.
     * This is a dynamic attribute.
     *
     * @return int|null
     */
    public function getDisplayCurrentStepAttribute() {
        $status = $this->status;
        $currentStep = $this->current_step;

        if ($status === 'pending') {
            return 1; // 'Intake' is the first step
        }

        if ($status === 'processing' || $status === 'in_transit') {
            // The 'current_step' from the DB is the index of the processing route.
            // We add 1 to account for 'Intake' being the first step in the display route.
            return $currentStep + 1;
        }

        if ($status === 'ready_for_release') {
            // This status means we are at the final 'Releasing' step.
            return count($this->getDisplayRouteObjectsAttribute());
        }

        // For 'completed', 'declined', 'frozen', etc., there is no current step.
        return null;
    }
}
