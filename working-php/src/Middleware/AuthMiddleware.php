<?php

namespace App\Middleware;

class AuthMiddleware
{
    public function handle()
    {
        if (!isset($_SESSION['user_id'])) {
            // Redirect to login if not authenticated
            header("Location: /login");
            exit;
        }
    }
}
