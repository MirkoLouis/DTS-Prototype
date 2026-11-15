<?php

namespace App\Modules\Notifications;

use PDO;
use App\Common\Database\Database;

class NotificationService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Send a notification to an entire department
     */
    public function notifyDepartment(int $departmentId, string $title, string $message, string $type = 'info'): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO notifications (department_id, title, message, type)
            VALUES (:dept_id, :title, :message, :type)
        ");
        $stmt->execute([
            'dept_id' => $departmentId,
            'title' => $title,
            'message' => $message,
            'type' => $type
        ]);

        // Enforce max limit of 10 per department
        $this->db->prepare("
            DELETE FROM notifications 
            WHERE department_id = :dept_id1 
            AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM notifications 
                    WHERE department_id = :dept_id2 
                    ORDER BY created_at DESC 
                    LIMIT 10
                ) as latest
            )
        ")->execute([
            'dept_id1' => $departmentId,
            'dept_id2' => $departmentId
        ]);
    }

    /**
     * Keep a notification link only when it is an internal path ("/..." without whitespace, control
     * characters or backslashes, and not "//host").
     *
     * Anything else (an absolute URL, "javascript:", a protocol-relative URL) becomes null, so a
     * notification can never send a user to another site. Used when storing and when rendering.
     */
    public static function safeLink(?string $link): ?string
    {
        // No link, or not an internal path
        if ($link === null || !str_starts_with($link, '/')) {
            return null;
        }

        // A second slash makes browsers treat the path as another host
        if (str_starts_with($link, '//')) {
            return null;
        }

        // Whitespace, control characters (browsers strip tabs and newlines inside URLs) and backslashes are never valid
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $link) === 1) {
            return null;
        }

        return $link;
    }

    /**
     * Send a notification to a specific user, optionally with a link to an internal page.
     *
     * @param string|null $link       internal path such as "/account/password"; other values are stored as no link
     * @param string|null $linkLabel  text of the link
     */
    public function notifyUser(int $userId, string $title, string $message, string $type = 'info', ?string $link = null, ?string $linkLabel = null): void
    {
        $safeLink = self::safeLink($link);
        $stmt = $this->db->prepare("
            INSERT INTO notifications (user_id, title, message, type, link, link_label)
            VALUES (:user_id, :title, :message, :type, :link, :link_label)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'link' => $safeLink,
            'link_label' => $safeLink === null ? null : $linkLabel
        ]);

        // Enforce max limit of 10 per user
        $this->db->prepare("
            DELETE FROM notifications 
            WHERE user_id = :user_id1 
            AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM notifications 
                    WHERE user_id = :user_id2 
                    ORDER BY created_at DESC 
                    LIMIT 10
                ) as latest
            )
        ")->execute([
            'user_id1' => $userId,
            'user_id2' => $userId
        ]);
    }

    /**
     * Delete every notification of a user that points to the given internal path.
     */
    public function deleteForUserByLink(int $userId, string $link): void
    {
        $this->db->prepare("DELETE FROM notifications WHERE user_id = :user_id AND link = :link")
            ->execute(['user_id' => $userId, 'link' => $link]);
    }

    /**
     * Get unread notifications for the currently logged-in user
     */
    public function getUnreadForCurrentUser(): array
    {
        if (!isset($_SESSION['user_id'])) {
            return [];
        }

        $userId = $_SESSION['user_id'];
        $departmentId = $_SESSION['department_id'] ?? null;

        $query = "
            SELECT * FROM notifications 
            WHERE is_read = 0 
            AND (user_id = :user_id " . ($departmentId ? "OR department_id = :dept_id" : "") . ")
            ORDER BY created_at DESC
            LIMIT 10
        ";

        $stmt = $this->db->prepare($query);
        $params = ['user_id' => $userId];
        if ($departmentId) {
            $params['dept_id'] = $departmentId;
        }

        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Mark a notification as read (or all if ID is null)
     */
    public function markAsRead(?int $notificationId = null): void
    {
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $userId = $_SESSION['user_id'];
        $departmentId = $_SESSION['department_id'] ?? null;

        if ($notificationId) {
            // Mark specific
            $stmt = $this->db->prepare("
                UPDATE notifications 
                SET is_read = 1 
                WHERE id = :id 
                AND (user_id = :user_id " . ($departmentId ? "OR department_id = :dept_id" : "") . ")
            ");
            $params = ['id' => $notificationId, 'user_id' => $userId];
            if ($departmentId) {
                $params['dept_id'] = $departmentId;
            }
            $stmt->execute($params);
        } else {
            // Mark all
            $stmt = $this->db->prepare("
                UPDATE notifications 
                SET is_read = 1 
                WHERE is_read = 0 
                AND (user_id = :user_id " . ($departmentId ? "OR department_id = :dept_id" : "") . ")
            ");
            $params = ['user_id' => $userId];
            if ($departmentId) {
                $params['dept_id'] = $departmentId;
            }
            $stmt->execute($params);
        }
    }
}
