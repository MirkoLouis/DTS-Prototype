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

        $currentUser = \App\Models\User::findById($_SESSION['user_id'] ?? 0);

        try {
            $workflow = new \App\Services\DocumentWorkflowService();
            $workflow->releaseDocument((int)$id, $currentUser, $pin);
            
            $_SESSION['success'] = "Document marked as completed and released.";
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Action Denied')) {
                $_SESSION['console_error'] = $e->getMessage();
            } else {
                $_SESSION['error'] = $e->getMessage();
            }
        }
        
        header("Location: /releasing");
        exit;
    }
}
