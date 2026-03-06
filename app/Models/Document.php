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
                                'finalized_route' => 'array',        'declined_at' => 'datetime',
    ];

    /**
     * Accessor for submitter name.
     */
    public function getSubmitterNameAttribute()
    {
        return $this->guest_info['name'] ?? null;
    }

    /**
     * Accessor for submitter email.
     */
    public function getSubmitterEmailAttribute()
    {
        return $this->guest_info['email'] ?? null;
    }

    /**
     * Accessor for submitter phone.
     */
    public function getSubmitterPhoneAttribute()
    {
        return $this->guest_info['phone'] ?? null;
    }

    /**
     * Determine if the current authenticated user can process this document.
     * This takes integrity checks and freezing into account.
     *
     * @return bool
     */
    public function getCanProcessAttribute()
    {
        $user = auth()->user();
        if (!$user) return false;

        if ($this->status === 'frozen') return false;

        return $user->can('process', $this);
    }

    /**
     * Determine if the current authenticated user can manage (finalize) this document.
     *
     * @return bool
     */
    public function getCanManageAttribute()
    {
        $user = auth()->user();
        if (!$user) return false;

        if ($this->status === 'frozen') return false;

        return $user->can('manage', $this);
    }

    /**
     * Get the route key for the model.
     * Use tracking_code instead of the incremental ID to prevent ID enumeration.
     *
     * @return string
     */
    public function getRouteKeyName()
    {
        return 'tracking_code';
    }

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

        $displayRoute = array_merge($intakeStep, $processingSteps, $releasingStep);

        // Load logs if not already loaded, ordered by created_at for chronological processing
        $logs = $this->logs()->orderBy('created_at', 'asc')->get();

        $stepTimestamps = [];

        // Pre-process logs to get timestamps for each relevant action
        foreach ($logs as $log) {
            // Intake step (reflects when the records officer finalized the route)
            if ($log->action === 'Accepted and Document Routing finalized') {
                $stepTimestamps['Intake'] = $log->created_at->format('M d, Y h:i A');
            }
            // Processing steps
            if ($log->action === 'Processing Complete' && preg_match('/processed by (.+?)\./', $log->remarks, $matches)) {
                $departmentName = trim($matches[1]);
                $stepTimestamps[$departmentName] = $log->created_at->format('M d, Y h:i A');
            }
            // Releasing step
            if ($log->action === 'Ready for Releasing' || $log->action === 'Document Released') {
                // Take the latest timestamp for releasing, which would be 'Document Released' if it exists.
                // We'll prioritize the 'Document Released' if multiple exist, otherwise use 'Ready for Releasing'.
                if ($log->action === 'Document Released') {
                    $stepTimestamps['Releasing'] = $log->created_at->format('M d, Y h:i A');
                } elseif (!isset($stepTimestamps['Releasing'])) {
                    // Only set Ready for Releasing if Document Released isn't already set
                    $stepTimestamps['Releasing'] = $log->created_at->format('M d, Y h:i A');
                }
            }
        }

        // Attach timestamps to the display route objects
        foreach ($displayRoute as $key => $step) {
            $timestamp = null;
            if (isset($stepTimestamps[$step['name']])) {
                $timestamp = $stepTimestamps[$step['name']];
            }
            $displayRoute[$key]['timestamp'] = $timestamp;
        }

        return $displayRoute;
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

        if ($status === 'completed') {
            // For completed documents, all steps including 'Releasing' should be marked as completed.
            return count($this->getDisplayRouteObjectsAttribute()) + 1;
        }

        // For 'declined', 'frozen', etc., there is no current step.
        return null;
    }
}
