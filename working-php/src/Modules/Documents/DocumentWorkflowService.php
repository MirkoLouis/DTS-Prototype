<?php

namespace App\Modules\Documents;

use App\Common\Database\Database;
use App\Modules\Integrity\DigitalSignatureService;
use App\Modules\Integrity\ChainSealService;
use App\Modules\Integrity\ChainVerificationService;
use App\Modules\Integrity\DocumentNotSealedException;
use App\Modules\System\SystemAuditService;
use App\Modules\Integrity\HashChainService;
use App\Modules\Integrity\PreActionIntegrityService;
use App\Modules\Routing\HeuristicRoutingService;
use App\Modules\Documents\TrackingCodeUtil;
use App\Modules\Documents\DocumentPolicy;
use App\Modules\Users\UserModel;
use Exception;

class DocumentWorkflowService
{
    private Database $db;
    private DocumentPolicy $policy;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->policy = new DocumentPolicy();
    }

    /**
     * Safely executes an action within a DB Transaction using Optimistic Locking (via version increment).
     * 
     * Centralizes transaction management, error handling, and the "pre-action integrity check" integrity checks.
     * Failing the policy check or encountering a concurrent modification will safely rollback.
     */
    private function executeInTransaction(string $idField, $idValue, ?UserModel $user, ?string $policyMethod, callable $action)
    {
        $conn = $this->db->getConnection();
        
        try {
            $conn->beginTransaction();
            
            $stmt = $this->db->query("SELECT * FROM documents WHERE $idField = :id", [':id' => $idValue]);
            $document = $stmt->fetch();
            
            if (!$document) {
                throw new Exception("Document not found.");
            }
            
            // Pre-action integrity check / Authorization Check: Verify the user has permission AND the document hasn't been tampered with
            if ($user && $policyMethod) {
                if (!$this->policy->$policyMethod($user, $document)) {
                    // Commit early here: if the policy failed due to tampering, the pre-action integrity check might have logged an automatic freezing event
                    $conn->commit();
                    throw new Exception("Action Denied: Document failed integrity check or you lack permissions.");
                }
            }
            
            // Execute Business Logic: the callable runs with the verified document row
            $result = $action($document, $this->db);
            
            // 4. Commit successful state transition
            $conn->commit();
            return $result;
            
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Submit a new document (Guest).
     * 
     * Handles public form submissions. Initiates the cryptographic hash chain and handles dynamic purpose creation securely.
     */
    /**
     * Hook for the tracking code source; tests override it to force a collision.
     */
    protected function newTrackingCode(): string
    {
        return TrackingCodeUtil::generate();
    }

    public function submitDocument(array $data): array
    {
        $conn = $this->db->getConnection();
        
        $maxAttempts = 3;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            try {
                $conn->beginTransaction();
                
                $finalPurposeId = $data['purpose_id'];

                // Prevent DB bloating/DoS: map custom guest purposes to a generic 'Others' record instead of creating new rows indefinitely
                if ($data['purpose_id'] == '0') {
                    $otherPurposeText = "Others";
                    $stmt = $this->db->query("SELECT id FROM purposes WHERE name = :name AND is_official = 0", [':name' => $otherPurposeText]);
                    $existing = $stmt->fetch();

                    if ($existing) {
                        $finalPurposeId = $existing['id'];
                    } else {
                        $this->db->query("INSERT INTO purposes (name, is_official, requirements, suggested_route) VALUES (:name, 0, '[]', '[]')", [
                            ':name' => $otherPurposeText
                        ]);
                        $finalPurposeId = $conn->lastInsertId();
                    }

                    // Append the specific text to the document's title to preserve the guest's input without bloating the purposes table
                    $specificPurpose = trim($data['other_purpose_text'] ?? '');
                    if ($specificPurpose !== '') {
                        $data['title'] = $data['title'] . " (Purpose: " . substr($specificPurpose, 0, 100) . ")";
                    }
                }

                // Draw a fresh CSPRNG tracking code on every attempt; the UNIQUE index plus the 1062 retry below guarantees uniqueness
                $trackingCode = $this->newTrackingCode();

                $guestInfo = json_encode([
                    'name' => $data['guest_name'],
                    'email' => $data['guest_email'],
                    'phone' => $data['guest_phone']
                ]);

                $this->db->query("INSERT INTO documents (tracking_code, title, guest_info, district, department, purpose_id, status, current_step, created_at, updated_at) VALUES (:tracking_code, :title, :guest_info, :district, :department, :purpose_id, 'pending', 0, NOW(), NOW())", [
                    ':tracking_code' => $trackingCode,
                    ':title' => $data['title'],
                    ':guest_info' => $guestInfo,
                    ':district' => $data['district'],
                    ':department' => $data['department'],
                    ':purpose_id' => $finalPurposeId
                ]);
                
                $documentId = $conn->lastInsertId();

                $documentData = [
                    'tracking_code' => $trackingCode,
                    'title' => $data['title'],
                    'guest_info' => $guestInfo,
                    'district' => $data['district'],
                    'department' => $data['department'],
                    'purpose_id' => $finalPurposeId,
                    'finalized_route' => ''
                ];

                // Initialize the cryptographic hash chain with the first 'Submitted' log entry
                HashChainService::createLog($documentId, null, 'Submitted', 'Document submitted by guest via the public portal.', $documentData);

                $conn->commit();
                return ['tracking_code' => $trackingCode, 'document_id' => $documentId];
                
            } catch (\PDOException $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                
                // Check for MySQL Duplicate Entry Error (1062) instead of generic 23000
                // This prevents infinite loops on Foreign Key violations (like an invalid purpose_id).
                $isDuplicateEntry = isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062;
                
                if ($isDuplicateEntry && $attempt < $maxAttempts - 1) {
                    $attempt++;
                    continue; // Try again with a new random tracking code
                }
                
                // If it's a different database error, or we ran out of attempts, throw it.
                throw $e;
            } catch (\Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
        }
    }

    /**
     * Finalize document routing (Intake).
     * 
     * Called by the Records Office to accept a pending document and establish its definitive departmental route.
     * Also updates machine-learning heuristics for "suggested routing".
     */
    public function finalizeIntake(int $documentId, array $routeNames, UserModel $officer, string $pin): void
    {
        $this->executeInTransaction('id', $documentId, $officer, 'manage', function ($document, $db) use ($routeNames, $officer, $pin) {
            if ($document['status'] !== 'pending') {
                throw new Exception("This document is already being processed.");
            }

            // Validate the route before any write: a non-empty list of strings naming existing departments
            if ($routeNames === [] || !array_is_list($routeNames)) {
                throw new Exception("Invalid route.");
            }
            foreach ($routeNames as $name) {
                if (!is_string($name) || $name === '') {
                    throw new Exception("Invalid route.");
                }
            }
            $unique = array_values(array_unique($routeNames));
            $placeholders = implode(',', array_fill(0, count($unique), '?'));
            $known = $db->query("SELECT name FROM departments WHERE name IN ($placeholders)", $unique)->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($routeNames as $name) {
                // Exact (case-sensitive) match against departments.name is required
                if (!in_array($name, $known, true)) {
                    throw new Exception("Invalid route: '{$name}' is not a department.");
                }
            }

            $firstDepartmentName = $routeNames[0];
            $stmt = $db->query("SELECT id FROM departments WHERE name = :name", [':name' => $firstDepartmentName]);
            $firstDepartment = $stmt->fetch();

            $finalizedRoute = array_map(function ($name) {
                return ['name' => $name, 'type' => 'initial'];
            }, $routeNames);

            $finalizedRouteJson = json_encode($finalizedRoute);

            // Fetch purpose to update suggested route learning
            $stmt = $db->query("SELECT is_official, suggested_route FROM purposes WHERE id = :id", [':id' => $document['purpose_id']]);
            $purpose = $stmt->fetch();

            // Learn the route only for official purposes and only when it actually changed
            $learnedRoute = $purpose
                ? (new HeuristicRoutingService())->learnedRoute($purpose, $routeNames, $document['department'] ?? null)
                : null;
            if ($learnedRoute !== null) {
                $db->query("UPDATE purposes SET suggested_route = :route WHERE id = :id", [
                    ':route' => json_encode($learnedRoute),
                    ':id' => $document['purpose_id']
                ]);
            }

            // Optimistic locking check: increment version and update status. 
            // If rowCount() is 0, another process modified the row between our initial SELECT and this UPDATE.
            $stmt = $db->query("UPDATE documents SET status = 'in_transit', finalized_route = :route, current_step = 1, current_department_id = :dept_id, updated_at = NOW(), version = version + 1 WHERE id = :id AND version = :version", [
                ':route' => $finalizedRouteJson,
                ':dept_id' => $firstDepartment ? $firstDepartment['id'] : null,
                ':id' => $document['id'],
                ':version' => $document['version']
            ]);
            if ($stmt->rowCount() === 0) {
                throw new Exception("This document was just modified by another user. Please refresh.");
            }

            // Re-fetch document for hashing
            $updatedDocument = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $document['id']])->fetch();

            HashChainService::createLog(
                $document['id'],
                $officer->id,
                'Accepted and Document Routing finalized',
                "Route finalized. In transit to {$firstDepartmentName}.",
                $updatedDocument,
                $pin
            );
        });
    }

    /**
     * Scan document (Receive).
     * 
     * Handles the physical receipt of a document via QR code scan. Verifies that the scanning user's
     * department matches the expected next hop in the finalized route.
     */
    public function scanDocument(string $trackingCode, UserModel $user, string $pin): string
    {
        return $this->executeInTransaction('tracking_code', $trackingCode, $user, 'process', function ($document, $db) use ($user, $pin) {
            $route = $document['finalized_route'] ? json_decode($document['finalized_route'], true) : [];
            $currentStepIndex = ((int)$document['current_step']) - 1;

            if ($document['status'] === 'in_transit') {
                if ($currentStepIndex >= count($route)) {
                    // The document has finished its route; only the Records Unit can do the final receive for release
                    $stmt = $db->query("SELECT name FROM departments WHERE id = :id", [':id' => $user->department_id]);
                    $dept = $stmt->fetch();
                    if ($dept && $dept['name'] === 'Records Unit') {
                        $stmt = $db->query("UPDATE documents SET status = 'ready_for_release', current_department_id = :dept_id, updated_at = NOW(), version = version + 1 WHERE id = :id AND version = :version", [
                            ':dept_id' => $user->department_id,
                            ':id' => $document['id'],
                            ':version' => $document['version']
                        ]);
                        if ($stmt->rowCount() === 0) throw new Exception("This document was just modified by another user. Please refresh.");
                        $updatedDoc = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $document['id']])->fetch();
                        HashChainService::createLog($document['id'], $user->id, 'Ready for Releasing', 'All processing steps completed. Document received by Records Unit for final releasing.', $updatedDoc, $pin);
                        return 'releasing';
                    }
                } else {
                    // Verify the scanning user belongs to the department responsible for the current step
                    $responsibleDepartmentName = $route[$currentStepIndex]['name'];
                    $stmt = $db->query("SELECT name FROM departments WHERE id = :id", [':id' => $user->department_id]);
                    $dept = $stmt->fetch();
                    
                    if ($dept && $dept['name'] === $responsibleDepartmentName) {
                        $stmt = $db->query("UPDATE documents SET status = 'processing', current_department_id = :dept_id, updated_at = NOW(), version = version + 1 WHERE id = :id AND version = :version", [
                            ':dept_id' => $user->department_id,
                            ':id' => $document['id'],
                            ':version' => $document['version']
                        ]);
                        if ($stmt->rowCount() === 0) throw new Exception("This document was just modified by another user. Please refresh.");
                        $updatedDoc = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $document['id']])->fetch();
                        HashChainService::createLog($document['id'], $user->id, 'Received', "Document received by {$dept['name']}.", $updatedDoc, $pin);
                        return ($user->role === 'officer') ? 'officer.tasks' : 'staff.tasks';
                    }
                }
                $responsibleDepartmentName = ($currentStepIndex >= count($route)) ? 'the Records Unit' : ($route[$currentStepIndex]['name'] ?? 'an unknown department');
                throw new Exception("This document is not for your department. It is waiting to be received by {$responsibleDepartmentName}.");
            }

            // Document NOT in a receivable state
            switch ($document['status']) {
                case 'processing':
                    $responsibleDepartmentName = $route[$currentStepIndex]['name'] ?? 'an unknown department';
                    $stmt = $db->query("SELECT name FROM departments WHERE id = :id", [':id' => $user->department_id]);
                    $dept = $stmt->fetch();
                    if ($dept && $dept['name'] === $responsibleDepartmentName) {
                        throw new Exception("You are already processing this document.");
                    }
                    throw new Exception("This document is currently being processed by the {$responsibleDepartmentName}.");
                case 'pending':
                    throw new Exception("This document is still pending intake by the Records Office and cannot be received yet.");
                case 'ready_for_release':
                    throw new Exception("This document is already in the 'Awaiting Release' list.");
                case 'completed':
                case 'declined':
                    throw new Exception("This document has already been released, please check your tracking code again.");
                default:
                    throw new Exception("This document cannot be received at this time. Its current status is: " . ucfirst($document['status']));
            }
        });
    }

    /**
     * Complete a processing task.
     * 
     * Marks the current department's action as finished, advances the step counter,
     * and places the document back in transit to the next department.
     */
    public function completeTask(int $documentId, string $remarks, UserModel $user, string $pin): void
    {
        $this->executeInTransaction('id', $documentId, $user, 'process', function ($document, $db) use ($remarks, $user, $pin) {
            if ($document['status'] !== 'processing') {
                throw new Exception("This document is not currently being processed.");
            }

            $route = json_decode($document['finalized_route'], true) ?? [];
            $currentStepIndex = ((int)$document['current_step']) - 1;
            $currentDepartmentName = $route[$currentStepIndex]['name'] ?? 'Unknown';

            $newStep = $document['current_step'] + 1;
            
            $nextDepartmentId = null;
            if ($newStep <= count($route)) {
                $nextDepartmentName = $route[$newStep - 1]['name'];
                $stmt = $db->query("SELECT id FROM departments WHERE name = :name", [':name' => $nextDepartmentName]);
                $nextDept = $stmt->fetch();
                if ($nextDept) {
                    $nextDepartmentId = $nextDept['id'];
                }
            }

            // Atomically advance the step counter and set the next responsible department
            $stmt = $db->query("UPDATE documents SET status = 'in_transit', current_step = :step, current_department_id = :dept_id, updated_at = NOW(), version = version + 1 WHERE id = :id AND version = :version", [
                ':step' => $newStep,
                ':dept_id' => $nextDepartmentId,
                ':id' => $document['id'],
                ':version' => $document['version']
            ]);
            if ($stmt->rowCount() === 0) throw new Exception("This document was just modified by another user. Please refresh.");

            $updatedDoc = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $document['id']])->fetch();

            $logRemarks = $remarks ?: "Step processed by {$currentDepartmentName}.";
            if ($newStep > count($route)) {
                $logRemarks .= " In transit to Records Unit for releasing.";
            } else {
                $nextDeptName = $route[$newStep - 1]['name'];
                $logRemarks .= " In transit to {$nextDeptName}.";
            }

            HashChainService::createLog($document['id'], $user->id, 'Processing Complete', $logRemarks, $updatedDoc, $pin);
        });
    }

    /**
     * Release a document to the client.
     * 
     * Finalizes the workflow, removing the current department hold and permanently completing the transaction.
     */
    public function releaseDocument(int $documentId, UserModel $officer, string $pin): void
    {
        $this->executeInTransaction('id', $documentId, $officer, 'manage', function ($document, $db) use ($officer, $pin) {
            if ($document['status'] !== 'ready_for_release') {
                throw new Exception("This document is not ready for release.");
            }

            $stmt = $db->query("UPDATE documents SET status = 'completed', current_department_id = NULL, released_at = NOW(), released_by_user_id = :uid, updated_at = NOW(), version = version + 1 WHERE id = :id AND version = :version", [
                ':uid' => $officer->id,
                ':id' => $document['id'],
                ':version' => $document['version']
            ]);
            if ($stmt->rowCount() === 0) throw new Exception("This document was just modified by another user. Please refresh.");

            $updatedDoc = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $document['id']])->fetch();

            HashChainService::createLog($document['id'], $officer->id, 'Document Released', 'The document has been released to the client.', $updatedDoc, $pin);
        });
    }

    /**
     * Decline an intake document.
     * 
     * Rejects a pending document before routing begins, permanently halting its progression.
     */
    public function declineDocument(int $documentId, string $reason, UserModel $officer, string $pin): void
    {
        $this->executeInTransaction('id', $documentId, $officer, 'manage', function ($document, $db) use ($reason, $officer, $pin) {
            if ($document['status'] !== 'pending') {
                throw new Exception("This document cannot be declined as it is already being processed.");
            }

            $stmt = $db->query("UPDATE documents SET status = 'declined', decline_reason = :reason, declined_at = NOW(), updated_at = NOW(), version = version + 1 WHERE id = :id AND version = :version", [
                ':reason' => $reason,
                ':id' => $document['id'],
                ':version' => $document['version']
            ]);
            if ($stmt->rowCount() === 0) throw new Exception("This document was just modified by another user. Please refresh.");

            $updatedDoc = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $document['id']])->fetch();

            HashChainService::createLog($document['id'], $officer->id, 'Document Declined', $reason, $updatedDoc, $pin);
        });
    }



    /**
     * Halts all operations on a document. 
     * Typically triggered automatically by the pre-action integrity check when tampering is detected, or manually by an admin.
     */
    public function freezeDocument(string $trackingCode, UserModel $admin, string $pin): void
    {
        $this->executeInTransaction('tracking_code', $trackingCode, $admin, 'freeze', function ($document, $db) use ($admin, $pin) {
            // A document that already fails the integrity check keeps its mismatch: freezing it must not re-seal the tampered state
            // (an unsealed document is not intact either: the admin may freeze it, but its seal is not created here)
            try {
                $wasIntact = PreActionIntegrityService::passes($document);
            } catch (DocumentNotSealedException $e) {
                $wasIntact = false;
            }

            $stmt = $db->query("UPDATE documents SET status = 'frozen', updated_at = NOW(), version = version + 1 WHERE id = :id AND version = :version", [':id' => $document['id'], ':version' => $document['version']]);
            if ($stmt->rowCount() === 0) throw new Exception("This document was just modified by another user. Please refresh.");
            $updatedDoc = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $document['id']])->fetch();
            HashChainService::createLog($document['id'], $admin->id, 'ADMIN: Document frozen.', 'An administrator has frozen this document, likely pending an integrity investigation.', $updatedDoc, $pin, null, $wasIntact);
        });
    }

    /**
     * Restores a frozen document to its last logical status state based on the cryptographic log.
     */
    public function unfreezeDocument(string $trackingCode, UserModel $admin, string $pin): string
    {
        return $this->executeInTransaction('tracking_code', $trackingCode, $admin, 'unfreeze', function ($document, $db) use ($admin, $pin) {
            // Find the newest real workflow log whose seal, block hash and signature verify; refuse (nothing written) when there is none
            ['log' => $verifiedLog, 'snapshot' => $snapshot] = $this->verifiedRestorePoint($document);

            // Unfreeze restores only workflow fields: it refuses when the content (the fields the state hash covers) was changed
            if (!ChainVerificationService::liveStateMatches($document, (string) $verifiedLog['document_state_hash'])) {
                SystemAuditService::record('recovery_refused', [
                    'document_id' => (int) $document['id'],
                    'tracking_code' => $document['tracking_code'],
                    'entry_id' => (int) $verifiedLog['id'],
                    'reason' => "the document's content was changed (unfreeze refused; auto-resolve is the way to restore it)",
                ]);
                throw new Exception("The document's content was changed. Use Auto-resolve to restore it from the last verified entry.");
            }
            $fields = $this->workflowFieldsFromEntry($verifiedLog['action'], $snapshot);
            $previousStatus = $fields['status'];

            // Restore status, step, holder, decline reason and released-by from the verified entry (the fields the chain seal covers)
            $stmt = $db->query("UPDATE documents SET status = :status, current_step = :step, current_department_id = :dept, decline_reason = :reason, released_by_user_id = :released_by, updated_at = NOW(), version = version + 1 WHERE id = :id AND version = :version", [
                ':status' => $fields['status'],
                ':step' => $fields['current_step'],
                ':dept' => $fields['current_department_id'],
                ':reason' => $fields['decline_reason'],
                ':released_by' => $fields['released_by_user_id'],
                ':id' => $document['id'],
                ':version' => $document['version']
            ]);
            if ($stmt->rowCount() === 0) throw new Exception("This document was just modified by another user. Please refresh.");

            // The log entry re-seals the chain (createLog refreshes the chain seal)
            $updatedDoc = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $document['id']])->fetch();
            HashChainService::createLog($document['id'], $admin->id, 'ADMIN: Document unfrozen.', "An administrator has unfrozen this document, restoring its status to " . ucfirst($previousStatus) . ".", $updatedDoc, $pin);
            return $previousStatus;
        });
    }

    /**
     * Fully recovers a tampered document by applying the last known-good data snapshot from the hash chain.
     */
    public function autoResolveDocument(string $trackingCode, UserModel $admin, string $pin): string
    {
        return $this->executeInTransaction('tracking_code', $trackingCode, $admin, 'unfreeze', function ($document, $db) use ($admin, $pin) {
            // Tampered documents might not be in 'frozen' status if they were just flagged by verification
            // and haven't triggered the pre-action integrity check yet.

            // Find the newest real workflow entry with a valid seal whose snapshot, state hash, block hash and signature verify
            ['log' => $verifiedLog, 'snapshot' => $snapshot] = $this->verifiedRestorePoint($document);
            $fields = $this->workflowFieldsFromEntry($verifiedLog['action'], $snapshot);
            $previousStatus = $fields['status'];

            // Overwrite the tampered row with the safe snapshot data, incrementing version to lock the recovery
            $stmt = $db->query("UPDATE documents SET
                title = :title,
                guest_info = :guest_info,
                district = :district,
                department = :department,
                purpose_id = :purpose_id,
                finalized_route = :finalized_route,
                status = :status,
                current_step = :step,
                current_department_id = :dept,
                decline_reason = :reason,
                released_by_user_id = :released_by,
                updated_at = NOW(),
                version = version + 1
                WHERE id = :id AND version = :version", [
                ':step' => $fields['current_step'],
                ':dept' => $fields['current_department_id'],
                ':reason' => $fields['decline_reason'],
                ':released_by' => $fields['released_by_user_id'],
                ':title' => $snapshot['title'] ?? null,
                ':guest_info' => empty($snapshot['guest_info']) ? null : (is_array($snapshot['guest_info']) ? json_encode($snapshot['guest_info']) : $snapshot['guest_info']),
                ':district' => $snapshot['district'] ?? null,
                ':department' => $snapshot['department'] ?? null,
                ':purpose_id' => $snapshot['purpose_id'] ?? null,
                ':finalized_route' => empty($snapshot['finalized_route']) ? null : (is_array($snapshot['finalized_route']) ? json_encode($snapshot['finalized_route']) : $snapshot['finalized_route']),
                ':status' => $previousStatus,
                ':id' => $document['id'],
                ':version' => $document['version']
            ]);
            if ($stmt->rowCount() === 0) throw new Exception("This document was just modified by another user. Please refresh.");

            $updatedDoc = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $document['id']])->fetch();
            HashChainService::createLog($document['id'], $admin->id, 'ADMIN: Document Unfrozen & Restored', "An administrator has executed Auto-resolve, restoring the document from its last valid snapshot and returning status to " . ucfirst($previousStatus) . ".", $updatedDoc, $pin);

            return $previousStatus;
        });
    }

    /**
     * Finds the log entry a recovery (unfreeze or auto-resolve) may restore from.
     *
     * Uses the NEWEST real workflow entry (not an ADMIN action or the automatic freeze) and never an older one, because
     * restoring from an older entry would silently roll the workflow back. The entry must verify completely: entry seal
     * (which covers the snapshot), block hash, link to the entry before it, signature, and snapshot against its state
     * hash for the right document. Otherwise recovery is refused, the refusal is recorded in the system audit log, and
     * nothing is changed: the document can only be fixed by restoring a backup.
     *
     * @return array{log: array, snapshot: array} the entry and its decoded snapshot
     * @throws Exception when the newest entry cannot be trusted; nothing has been written at that point
     */
    private function verifiedRestorePoint(array $document): array
    {
        $db = Database::getInstance();

        // A document that is merely not sealed yet, or sealed on the old version, is not "untrustworthy": the administrator must seal or upgrade it first (no refusal record)
        $refusal = ChainSealService::sealingRefusal($document);
        if ($refusal !== null) {
            throw $refusal;
        }

        // The newest regular entry: ADMIN actions and the automatic freeze are not workflow steps
        $log = $db->query(
            "SELECT * FROM document_logs WHERE document_id = :id AND action NOT LIKE 'ADMIN:%' AND action <> 'System Auto-Freeze' ORDER BY id DESC LIMIT 1",
            [':id' => $document['id']]
        )->fetch();

        $problem = $log ? $this->restorePointProblem($document, $log) : 'the document has no regular log entry';
        if ($problem !== null) {
            SystemAuditService::record('recovery_refused', [
                'document_id' => (int) $document['id'],
                'tracking_code' => $document['tracking_code'],
                'entry_id' => $log ? (int) $log['id'] : null,
                'reason' => $problem,
            ]);
            throw new Exception("The newest log entry of this document cannot be trusted for recovery ({$problem}). The document can only be fixed by restoring a backup. Nothing was changed.");
        }

        return ['log' => $log, 'snapshot' => json_decode($log['document_snapshot'], true)];
    }

    /**
     * What is wrong with the entry a recovery would restore from, or null when it verifies completely.
     */
    private function restorePointProblem(array $document, array $log): ?string
    {
        // The entry seal covers the block hash and the snapshot text
        if (!ChainSealService::entrySealIsValid($log)) {
            return "entry {$log['id']} has an invalid seal";
        }

        // Block hash, link to the entry before it (genesis when it is the first) and signature
        $previous = Database::getInstance()->query("SELECT hash FROM document_logs WHERE document_id = :id AND id < :log ORDER BY id DESC LIMIT 1", [':id' => $document['id'], ':log' => $log['id']])->fetch();
        $checks = ChainVerificationService::checkEntry($log, $previous ? (string) $previous['hash'] : HashChainService::GENESIS_HASH);
        if (!$checks['hash'] || !$checks['link'] || !$checks['signature']) {
            return "entry {$log['id']} fails its block hash, link or signature check";
        }

        // The snapshot must belong to this document and match the entry's state hash
        $snapshot = $log['document_snapshot'] === null ? null : json_decode($log['document_snapshot'], true);
        if (!is_array($snapshot) || ($snapshot['tracking_code'] ?? null) !== $document['tracking_code']
            || !ChainVerificationService::snapshotMatchesStateHash($log['document_snapshot'], $log['document_state_hash'])) {
            return "the snapshot of entry {$log['id']} does not match its state hash";
        }

        return null;
    }

    /**
     * The sealed workflow fields a verified entry leaves behind: status from its action, the rest from its snapshot.
     *
     * A snapshot written at submission has no step or holder yet: step 0 and no holder, as the document was inserted.
     */
    private function workflowFieldsFromEntry(string $action, array $snapshot): array
    {
        return [
            'status' => self::statusForAction($action),
            'current_step' => (int) ($snapshot['current_step'] ?? 0),
            'current_department_id' => isset($snapshot['current_department_id']) ? (int) $snapshot['current_department_id'] : null,
            'decline_reason' => isset($snapshot['decline_reason']) ? (string) $snapshot['decline_reason'] : null,
            'released_by_user_id' => isset($snapshot['released_by_user_id']) ? (int) $snapshot['released_by_user_id'] : null,
        ];
    }

    /**
     * Maps a workflow log action to the document status it leaves behind.
     */
    public static function statusForAction(string $action): string
    {
        return match ($action) {
            'Submitted' => 'pending',
            'Accepted and Document Routing finalized' => 'in_transit',
            'Received' => 'processing',
            'Processing Complete' => 'in_transit',
            'Ready for Releasing' => 'ready_for_release',
            'Document Released' => 'completed',
            'Document Declined' => 'declined',
            default => throw new Exception("Cannot determine the status to restore after action: {$action}"),
        };
    }

}
