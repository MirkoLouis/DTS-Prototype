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
        $reason = $_POST['reason'] ?? '';
        
        if ($documentId && $reason) {
            // Update document status or create a return request log
            $db->query("UPDATE documents SET status = 'returned' WHERE id = :id", ['id' => $documentId]);
            
            // Add a log
            \App\Core\IntegrityManager::createLog(
                $documentId, 
                $_SESSION['user_id'], 
                'Document Returned', 
                $reason, 
                $db->query("SELECT * FROM documents WHERE id = :id", ['id' => $documentId])->fetch()
            );
        }

        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/dashboard'));
        exit;
    }
}
