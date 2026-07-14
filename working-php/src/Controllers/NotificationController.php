<?php

namespace App\Controllers;

use App\Core\NotificationService;

class NotificationController
{
    private NotificationService $notificationService;

    public function __construct()
    {
        $this->notificationService = new NotificationService();
    }

    /**
     * Mark a notification as read via AJAX
     */
    public function markAsRead()
    {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        // Get JSON payload
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;

        if ($id) {
            $this->notificationService->markAsRead((int)$id);
        } else {
            // Mark all as read
            $this->notificationService->markAsRead();
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    }
}
