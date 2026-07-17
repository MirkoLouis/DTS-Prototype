<?php

namespace App\Policies;

use App\Models\User;
use App\Core\IntegrityManager;
use App\Core\Database;

class DocumentPolicy
{
    /**
     * Determine whether the user can view the document.
     * 
     * Grant unconditional access to admins and officers; restrict staff to documents that route through their specific department.
     */
    public function view(User $user, array $document): bool
    {
        if ($user->role === 'admin' || $user->role === 'officer') {
            return true;
        }

        if ($user->role === 'staff' && $user->department_id) {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT name FROM departments WHERE id = :id", [':id' => $user->department_id]);
            $dept = $stmt->fetch();
            $departmentName = $dept ? $dept['name'] : '';

            $route = $document['finalized_route'] ? json_decode($document['finalized_route'], true) : [];
            if (is_array($route)) {
                foreach ($route as $step) {
                    if (isset($step['name']) && $step['name'] === $departmentName) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Determine whether the user can process the document.
     * 
     * Determines if a user can actively process (e.g., receive, complete task) a document. 
     * Critically, this acts as the "Active Guard" by enforcing real-time cryptographic integrity checks 
     * before allowing state changes.
     */
    public function process(User $user, array $document): bool
    {
        // 0. Pre-action Integrity Check (The "Active Guard")
        if (!IntegrityManager::verifyCurrentState($document)) {
            IntegrityManager::autoFreeze($document, 'Pre-Action Verification');
            return false;
        }

        if ($user->role === 'admin' || $user->role === 'officer') {
            return true;
        }

        if ($user->role === 'staff' && $user->department_id) {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT name FROM departments WHERE id = :id", [':id' => $user->department_id]);
            $dept = $stmt->fetch();
            $departmentName = $dept ? $dept['name'] : '';
            
            // Only allow staff to process if the document is currently routed to their department's specific step.
            $route = $document['finalized_route'] ? json_decode($document['finalized_route'], true) : [];
            $currentStepIndex = ((int)($document['current_step'] ?? 1)) - 1;

            if (isset($route[$currentStepIndex]['name']) && $route[$currentStepIndex]['name'] === $departmentName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the user can manage (finalize) the document.
     * 
     * Controls access to finalization actions like declining or accepting intake. 
     * Enforces integrity checks if logs already exist.
     */
    public function manage(User $user, array $document): bool
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id FROM document_logs WHERE document_id = :id LIMIT 1", [':id' => $document['id']]);
        if ($stmt->fetch()) {
            if (!IntegrityManager::verifyCurrentState($document)) {
                IntegrityManager::autoFreeze($document, 'Pre-Management Verification');
                return false;
            }
        }

        return $user->role === 'officer' || $user->role === 'admin';
    }

    /**
     * Determine whether the user can freeze the document.
     */
    public function freeze(User $user, array $document): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can unfreeze the document.
     */
    public function unfreeze(User $user, array $document): bool
    {
        return $user->role === 'admin';
    }
}
