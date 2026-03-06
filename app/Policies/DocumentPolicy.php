<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DocumentPolicy
{
    /**
     * Determine whether the user can view the document.
     */
    public function view(User $user, Document $document): bool
    {
        // 1. Admin can view anything
        if ($user->role === 'admin') {
            return true;
        }

        // 2. Records Officers can view anything (they manage the flow)
        if ($user->role === 'officer') {
            return true;
        }

        // 3. Staff can only view if their department is in the document's route
        if ($user->role === 'staff' && $user->department_id) {
            $departmentName = $user->department->name;
            
            // Check if the department name exists in the finalized_route JSON
            $route = $document->finalized_route ?? [];
            foreach ($route as $step) {
                if (isset($step['name']) && $step['name'] === $departmentName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine whether the user can process the document.
     */
    public function process(User $user, Document $document): bool
    {
        // 0. Pre-action Integrity Check (The "Active Guard")
        // If the live state doesn't match the last signed log, DENY all processing.
        if (!\App\Models\DocumentLog::verifyCurrentState($document)) {
            // Dispatch the IntegrityCheckFailed event to auto-freeze the document
            \App\Events\IntegrityCheckFailed::dispatch($document, 'Pre-Action Verification');
            return false;
        }

        // 1. Admin can process anything (as a fallback)
        if ($user->role === 'admin') {
            return true;
        }

        // 2. Records Officers can process anything
        if ($user->role === 'officer') {
            return true;
        }

        // 3. Staff can only process if their department is the CURRENT step in the route
        if ($user->role === 'staff' && $user->department_id) {
            $departmentName = $user->department->name;
            
            $route = $document->finalized_route ?? [];
            $currentStepIndex = $document->current_step - 1;

            if (isset($route[$currentStepIndex]['name']) && $route[$currentStepIndex]['name'] === $departmentName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the user can manage (finalize) the document.
     */
    public function manage(User $user, Document $document): bool
    {
        // For 'manage' (intake), we don't check state yet because there's no state log yet.
        // But if it's already has logs, we should check it.
        if ($document->logs()->exists()) {
            if (!\App\Models\DocumentLog::verifyCurrentState($document)) {
                \App\Events\IntegrityCheckFailed::dispatch($document, 'Pre-Management Verification');
                return false;
            }
        }

        return $user->role === 'officer' || $user->role === 'admin';
    }

    /**
     * Determine whether the user can freeze the document.
     */
    public function freeze(User $user, Document $document): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can unfreeze the document.
     */
    public function unfreeze(User $user, Document $document): bool
    {
        return $user->role === 'admin';
    }
}
