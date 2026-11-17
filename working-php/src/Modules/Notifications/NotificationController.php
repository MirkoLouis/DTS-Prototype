<?php

namespace App\Modules\Notifications;

use App\Modules\Notifications\NotificationService;

class NotificationController
{
    private NotificationService $notificationService;

    public function __construct()
    {
        $this->notificationService = new NotificationService();
    }

    /**
     * Get unread notifications for currently logged in user
     */
    public function getUnread()
    {
        // Every answer below (success and error) is JSON; X-Content-Type-Options: nosniff needs the type to be right
        header('Content-Type: application/json');

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        try {
            $unread = $this->notificationService->getUnreadForCurrentUser();

            echo json_encode([
                'success' => true,
                'notifications' => array_values($unread),
                'count' => count($unread)
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Mark a notification as read via AJAX
     */
    public function markAsRead()
    {
        // Every answer below (success and error) is JSON; X-Content-Type-Options: nosniff needs the type to be right
        header('Content-Type: application/json');

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        try {
            // Get JSON payload
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? null;

            if ($id) {
                $this->notificationService->markAsRead((int)$id);
            } else {
                // Clear all notifications
                $this->notificationService->markAsRead();
            }

            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
