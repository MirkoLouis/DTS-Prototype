<?php

namespace App\Modules\Documents;

use App\Common\Database\Database;

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
        $submittedPin = $_POST['pin'] ?? '';
        $pin = \App\Modules\Users\SecurityPinCacheService::resolvePin($submittedPin);

        if (empty($pin)) {
            $_SESSION['error'] = "Security PIN is required.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/releasing'));
            exit;
        }

        $currentUser = \App\Modules\Users\UserModel::findById($_SESSION['user_id'] ?? 0);

        try {
            $workflow = new \App\Modules\Documents\DocumentWorkflowService();
            $workflow->releaseDocument((int)$id, $currentUser, $pin);
            
            $doc = \App\Modules\Documents\DocumentModel::findById((int)$id);
            $trackingCode = $doc ? $doc->tracking_code : '';
            $_SESSION['success'] = "Document {$trackingCode} marked as completed and released.";
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
