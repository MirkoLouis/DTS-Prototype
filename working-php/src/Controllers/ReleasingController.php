<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\IntegrityManager;

class ReleasingController
{
    /**
     * Mark the specified document as completed and released.
     *
     * @param string $id
     */
    public function complete($id)
    {
        $db = Database::getInstance();
        $pin = $_POST['pin'] ?? '';

        if (empty($pin)) {
            $_SESSION['error'] = "Security PIN is required.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/releasing'));
            exit;
        }

        $userId = $_SESSION['user_id'] ?? null;

        $stmt = $db->query("SELECT * FROM documents WHERE id = :id", [':id' => $id]);
        $documentData = $stmt->fetch();

        if (!$documentData) {
            $_SESSION['error'] = "Document not found.";
            header("Location: /releasing");
            exit;
        }

        if ($documentData['status'] !== 'ready_for_release') {
            $_SESSION['error'] = "This document is not ready for release.";
            header("Location: /releasing");
            exit;
        }

        $documentData['status'] = 'completed';
        $documentData['current_department_id'] = null;

        $db->query("UPDATE documents SET 
                    status = 'completed', 
                    current_department_id = NULL,
                    updated_at = NOW()
                    WHERE id = :id", [
            ':id' => $id
        ]);

        IntegrityManager::createLog(
            $id, 
            $userId, 
            'Document Released', 
            'The document has been released to the client.', 
            $documentData, 
            $pin
        );

        $_SESSION['success'] = "Document marked as completed and released.";
        header("Location: /releasing");
        exit;
    }
}
