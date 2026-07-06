<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\IntegrityManager;

class TaskController
{
    /**
     * Mark the current step for a document as complete and advance it.
     *
     * @param string $id
     */
    public function complete($id)
    {
        $db = Database::getInstance();
        $pin = $_POST['pin'] ?? '';

        if (empty($pin)) {
            $_SESSION['error'] = "Security PIN is required.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/tasks'));
            exit;
        }

        $userId = $_SESSION['user_id'] ?? null;
        $departmentId = $_SESSION['department_id'] ?? null;
        
        $departmentName = '';
        if ($departmentId) {
            $deptStmt = $db->query("SELECT name FROM departments WHERE id = :id", [':id' => $departmentId]);
            $deptRow = $deptStmt->fetch();
            if ($deptRow) {
                $departmentName = $deptRow['name'];
            }
        }

        $stmt = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $id]);
        $documentData = $stmt->fetch();

        if (!$documentData) {
            $_SESSION['error'] = "Document not found.";
            header("Location: /tasks");
            exit;
        }

        if ($documentData['status'] !== 'processing') {
            $_SESSION['error'] = "This document is not currently being processed.";
            header("Location: /tasks");
            exit;
        }

        $route = $documentData['finalized_route'] ? json_decode($documentData['finalized_route'], true) : [];
        $totalSteps = count($route);
        
        $documentData['current_step'] += 1;

        $nextDepartmentId = null;
        if ($documentData['current_step'] <= $totalSteps) {
            $nextDeptName = $route[$documentData['current_step'] - 1]['name'];
            $stmt = $db->query("SELECT id FROM departments WHERE name = :name", [':name' => $nextDeptName]);
            $nextDept = $stmt->fetch();
            $nextDepartmentId = $nextDept ? $nextDept['id'] : null;
        } else {
            // Records Unit for release
            $stmt = $db->query("SELECT id FROM departments WHERE name = 'Records Unit' LIMIT 1");
            $recordsUnit = $stmt->fetch();
            $nextDepartmentId = $recordsUnit ? $recordsUnit['id'] : null;
        }

        $documentData['current_department_id'] = $nextDepartmentId;

        if ($documentData['current_step'] > $totalSteps) {
            $documentData['status'] = 'in_transit';
            $remarks = "Final step processed by {$departmentName}. In transit to Records Unit for releasing.";
        } else {
            $documentData['status'] = 'in_transit';
            $nextDepartmentName = $route[$documentData['current_step'] - 1]['name'];
            $remarks = "Step processed by {$departmentName}. In transit to {$nextDepartmentName}.";
        }

        $db->query("UPDATE documents SET 
                    status = :status, 
                    current_step = :current_step, 
                    current_department_id = :current_dept_id,
                    updated_at = NOW()
                    WHERE id = :id", [
            ':status' => $documentData['status'],
            ':current_step' => $documentData['current_step'],
            ':current_dept_id' => $documentData['current_department_id'],
            ':id' => $id
        ]);

        IntegrityManager::createLog(
            $id, 
            $userId, 
            'Processing Complete', 
            $remarks, 
            $documentData, 
            $pin
        );

        $_SESSION['success'] = "Step completed. Document is now in transit.";
        header("Location: /tasks");
        exit;
    }
}
