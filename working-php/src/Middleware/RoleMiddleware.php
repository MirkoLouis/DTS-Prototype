<?php

namespace App\Middleware;

class RoleMiddleware
{
    /**
     * Handle the role verification.
     * 
     * Protects routes by ensuring the authenticated user holds one of the required roles.
     * 
     * @param string ...$roles The allowed roles (e.g., 'admin', 'officer')
     */
    public function handle(...$roles)
    {
        // First ensure they are authenticated
        if (!isset($_SESSION['user_id'])) {
            header("Location: /login");
            exit;
        }

        // Check if their role is in the allowed roles list
        if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $roles)) {
            // Redirect to their default dashboard or show 403 Forbidden
            header("HTTP/1.0 403 Forbidden");
            echo "403 Forbidden: You do not have permission to access this page.";
            exit;
        }
    }
}
