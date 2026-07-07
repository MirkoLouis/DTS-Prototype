<?php

namespace App\Controllers;

use App\Core\Database;

class ReturnRequestController
{
    public function index()
    {
        // This is officerReturnRequests in DashboardController currently
        require BASE_PATH . '/src/Views/officer/return-requests.php';
    }

    public function store()
    {
        $db = Database::getInstance();
        $documentId = $_POST['document_id'] ?? null;
        $trackingCode = $_POST['tracking_code'] ?? null;
        $reason = $_POST['reason'] ?? '';
        $pin = $_POST['pin'] ?? ''; // Some frontend forms might not pass it, but IntegrityManager accepts it
        
        $document = null;
        if ($documentId) {
            $document = $db->query("SELECT * FROM documents WHERE id = :id", ['id' => $documentId])->fetch();
        } elseif ($trackingCode) {
            $document = $db->query("SELECT * FROM documents WHERE tracking_code = :tc", ['tc' => $trackingCode])->fetch();
            if ($document) {
                $documentId = $document['id'];
            }
        }
        
        if ($documentId && $reason) {
            $currentUser = \App\Models\User::findById($_SESSION['user_id'] ?? 0);
            
            try {
                $workflow = new \App\Services\DocumentWorkflowService();
                $workflow->requestReturn((int)$documentId, $reason, $currentUser, $pin);
                
                $_SESSION['success'] = "Return requested successfully. The document will be rerouted back to you.";
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'Action Denied')) {
                    $_SESSION['console_error'] = $e->getMessage();
                } else {
                    $_SESSION['error'] = $e->getMessage();
                }
            }
        } else {
            $_SESSION['error'] = "Missing document ID or reason.";
        }

        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/dashboard'));
        exit;
    }
}
