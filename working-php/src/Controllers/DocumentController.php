<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\IntegrityManager;

class DocumentController
{
    /**
     * Display the specified document's data and logs.
     *
     * @param string $tracking_code
     */
    public function show($tracking_code)
    {
        $db = Database::getInstance();
        
        $stmt = $db->query("SELECT d.*, p.name as purpose_name 
                            FROM documents d 
                            LEFT JOIN purposes p ON d.purpose_id = p.id 
                            WHERE d.tracking_code = :tracking_code", [':tracking_code' => $tracking_code]);
        $document = $stmt->fetch();

        if (!$document) {
            header("HTTP/1.0 404 Not Found");
            echo "Document not found.";
            exit;
        }

        // Fetch logs
        $stmt = $db->query("SELECT l.*, u.name as user_name 
                            FROM document_logs l 
                            LEFT JOIN users u ON l.user_id = u.id 
                            WHERE l.document_id = :document_id 
                            ORDER BY l.created_at ASC", [':document_id' => $document['id']]);
        $logs = $stmt->fetchAll();

        require BASE_PATH . '/src/Views/general/show-document.php';
    }

    /**
     * Display the hash chain for a specific document.
     *
     * @param string $tracking_code
     */
    public function showHashChain($tracking_code)
    {
        $db = Database::getInstance();
        
        $stmt = $db->query("SELECT d.*, p.name as purpose_name 
                            FROM documents d 
                            LEFT JOIN purposes p ON d.purpose_id = p.id 
                            WHERE d.tracking_code = :tracking_code", [':tracking_code' => $tracking_code]);
        $document = $stmt->fetch();

        if (!$document) {
            header("HTTP/1.0 404 Not Found");
            echo "Document not found.";
            exit;
        }

        // Fetch logs
        $stmt = $db->query("SELECT l.*, u.name as user_name 
                            FROM document_logs l 
                            LEFT JOIN users u ON l.user_id = u.id 
                            WHERE l.document_id = :document_id 
                            ORDER BY l.created_at ASC", [':document_id' => $document['id']]);
        $logs = $stmt->fetchAll();

        require BASE_PATH . '/src/Views/general/document-hash-chain.php';
    }

    /**
     * Find a document by its tracking code and redirect to the manage page.
     */
    public function find()
    {
        $trackingCode = $_POST['tracking_code'] ?? '';

        if (empty($trackingCode)) {
            $_SESSION['error'] = "Tracking code is required.";
            header("Location: /intake");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM documents WHERE tracking_code = :tracking_code", [':tracking_code' => $trackingCode]);
        $document = $stmt->fetch();

        if (!$document) {
            $_SESSION['error'] = "Document not found.";
            header("Location: /intake");
            exit;
        }

        switch ($document['status']) {
            case 'pending':
                header("Location: /documents/{$document['id']}/manage");
                exit;

            case 'processing':
            case 'in_transit':
                $_SESSION['info'] = "This document is already in process and cannot be intaked again.";
                header("Location: /intake");
                exit;

            case 'ready_for_release':
            case 'completed':
            case 'declined':
                $_SESSION['info'] = "This document has already been released, please check your tracking code again.";
                header("Location: /intake");
                exit;

            default:
                $_SESSION['error'] = "This document cannot be processed at this time. Its current status is: " . ucfirst($document['status']);
                header("Location: /intake");
                exit;
        }
    }

    /**
     * Show the form for managing a document's route (Intake).
     *
     * @param string $id
     */
    public function manage($id)
    {
        $db = Database::getInstance();
        
        $stmt = $db->query("SELECT d.*, p.name as purpose_name, p.suggested_route 
                            FROM documents d 
                            LEFT JOIN purposes p ON d.purpose_id = p.id 
                            WHERE d.id = :id", [':id' => $id]);
        $document = $stmt->fetch();

        if (!$document) {
            header("Location: /intake");
            exit;
        }

        $document['suggested_route'] = $document['suggested_route'] ? json_decode($document['suggested_route'], true) : [];

        // Fetch departments for the route building dropdown
        $stmt = $db->query("SELECT id, name FROM departments ORDER BY name ASC");
        $departments = $stmt->fetchAll();

        require BASE_PATH . '/src/Views/officer/manage-documents.php';
    }

    /**
     * Finalize the document's route and put it into processing.
     *
     * @param string $id
     */
    public function finalize($id)
    {
        $db = Database::getInstance();
        
        $finalRouteJson = $_POST['final_route'] ?? '';
        $pin = $_POST['pin'] ?? '';

        if (empty($finalRouteJson) || empty($pin)) {
            $_SESSION['error'] = "Route and Security PIN are required.";
            header("Location: /documents/{$id}/manage");
            exit;
        }

        $routeNames = json_decode($finalRouteJson, true);

        if (empty($routeNames)) {
            $_SESSION['error'] = "The route cannot be empty. Please add at least one step.";
            header("Location: /documents/{$id}/manage");
            exit;
        }

        $userId = $_SESSION['user_id'] ?? null;
        $firstDepartmentName = $routeNames[0];
        
        $stmt = $db->query("SELECT id FROM departments WHERE name = :name", [':name' => $firstDepartmentName]);
        $firstDepartment = $stmt->fetch();
        $firstDepartmentId = $firstDepartment ? $firstDepartment['id'] : null;

        $finalizedRoute = array_map(function ($name) {
            return ['name' => $name, 'type' => 'initial'];
        }, $routeNames);

        // We need to fetch the document's current full state to pass to IntegrityManager
        $stmt = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $id]);
        $documentData = $stmt->fetch();

        if (!$documentData) {
            $_SESSION['error'] = "Document not found.";
            header("Location: /intake");
            exit;
        }

        // Apply updates to the state array so the hash reflects the NEW state
        $documentData['status'] = 'in_transit';
        $documentData['finalized_route'] = $finalizedRoute; // This will be json_encoded in the helper
        $documentData['current_step'] = 1;
        $documentData['current_department_id'] = $firstDepartmentId;

        // Perform the DB update
        $db->query("UPDATE documents SET 
                    status = :status, 
                    finalized_route = :finalized_route, 
                    current_step = :current_step, 
                    current_department_id = :current_dept_id,
                    updated_at = NOW()
                    WHERE id = :id", [
            ':status' => 'in_transit',
            ':finalized_route' => json_encode($finalizedRoute),
            ':current_step' => 1,
            ':current_dept_id' => $firstDepartmentId,
            ':id' => $id
        ]);

        $action = 'Accepted and Document Routing finalized';
        $remarks = "Route finalized. In transit to {$firstDepartmentName}.";

        IntegrityManager::createLog($id, $userId, $action, $remarks, $documentData, $pin);

        $_SESSION['success'] = "Document accepted and is now in transit!";
        header("Location: /intake");
        exit;
    }

    /**
     * Handle a document scan action (Global Receive).
     */
    public function scan()
    {
        $trackingCode = $_POST['tracking_code'] ?? '';

        if (empty($trackingCode)) {
            $_SESSION['error'] = "Tracking code is required.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM documents WHERE tracking_code = :tracking_code", [':tracking_code' => $trackingCode]);
        $document = $stmt->fetch();

        if (!$document) {
            $_SESSION['error'] = "Document not found.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
            exit;
        }

        $userRole = $_SESSION['role'] ?? '';
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
        
        $route = $document['finalized_route'] ? json_decode($document['finalized_route'], true) : [];
        $currentStepIndex = $document['current_step'] - 1;

        if ($document['status'] === 'in_transit') {
            if ($currentStepIndex >= count($route)) {
                // Wait for Records Unit (Officer)
                if ($userRole === 'officer') {
                    $this->receiveForRelease($document, $userId, $departmentId);
                } else {
                    $_SESSION['error'] = "This document is waiting to be received by the Records Unit.";
                    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
                    exit;
                }
            } else {
                $responsibleDepartmentName = $route[$currentStepIndex]['name'];
                if ($departmentName === $responsibleDepartmentName) {
                    $this->receiveForProcessing($document, $userId, $departmentName, $userRole, $departmentId);
                } else {
                    $_SESSION['error'] = "This document is not for your department. It is waiting to be received by {$responsibleDepartmentName}.";
                    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
                    exit;
                }
            }
        } else {
            $_SESSION['error'] = "This document cannot be received at this time. Its current status is: " . ucfirst($document['status']);
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
            exit;
        }
    }

    private function receiveForRelease($document, $userId, $departmentId)
    {
        $db = Database::getInstance();
        
        $document['status'] = 'ready_for_release';
        $document['current_department_id'] = $departmentId;

        $db->query("UPDATE documents SET status = 'ready_for_release', current_department_id = :dept_id, updated_at = NOW() WHERE id = :id", [
            ':dept_id' => $departmentId,
            ':id' => $document['id']
        ]);

        IntegrityManager::createLog(
            $document['id'], 
            $userId, 
            'Ready for Releasing', 
            'All processing steps completed. Document received by Records Unit for final releasing.', 
            $document
        );

        $_SESSION['success'] = "Document {$document['tracking_code']} is now ready for releasing.";
        header("Location: /releasing");
        exit;
    }

    private function receiveForProcessing($document, $userId, $departmentName, $userRole, $departmentId)
    {
        $db = Database::getInstance();
        
        $document['status'] = 'processing';
        $document['current_department_id'] = $departmentId;

        $db->query("UPDATE documents SET status = 'processing', current_department_id = :dept_id, updated_at = NOW() WHERE id = :id", [
            ':dept_id' => $departmentId,
            ':id' => $document['id']
        ]);

        IntegrityManager::createLog(
            $document['id'], 
            $userId, 
            'Received', 
            "Document received by {$departmentName}.", 
            $document
        );

        $redirectRoute = ($userRole === 'officer') ? '/tasks' : '/tasks';
        $_SESSION['success'] = "Document {$document['tracking_code']} has been received and added to your tasks.";
        header("Location: {$redirectRoute}");
        exit;
    }
}
