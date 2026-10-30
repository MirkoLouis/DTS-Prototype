<?php

namespace App\Modules\Documents;

use App\Common\Database\Database;

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
        $submittedPin = $_POST['pin'] ?? '';
        $pin = \App\Modules\Users\SecurityPinCacheService::resolvePin($submittedPin);

        if (empty($pin)) {
            $_SESSION['error'] = "Security PIN is required.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/tasks'));
            exit;
        }

        $currentUser = \App\Modules\Users\UserModel::findById($_SESSION['user_id'] ?? 0);

        // Resolve the tracking code first, so success and error toasts both name the document
        $doc = \App\Modules\Documents\DocumentModel::findById((int)$id);
        $trackingCode = $doc ? $doc->tracking_code : '';

        try {
            $workflow = new \App\Modules\Documents\DocumentWorkflowService();
            $workflow->completeTask((int)$id, '', $currentUser, $pin);
            
            $_SESSION['success'] = "Document $trackingCode is now in transit.";
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Action Denied')) {
                $_SESSION['console_error'] = $e->getMessage();
            } else {
                $_SESSION['error'] = document_message($trackingCode, $e->getMessage());
            }
        }
        
        header("Location: /tasks");
        exit;
    }
}
