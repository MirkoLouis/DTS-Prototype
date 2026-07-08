<?php

namespace App\Middleware;

use App\Core\Database;

class AuthMiddleware
{
    public function handle()
    {
        if (!isset($_SESSION['user_id'])) {
            // Redirect to login if not authenticated
            header("Location: /login");
            exit;
        }

        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        if ($uri !== '/security-key' && $uri !== '/logout') {
            $db = Database::getInstance();
            $user = $db->query("SELECT security_key_set_at FROM users WHERE id = :id", ['id' => $_SESSION['user_id']])->fetch();
            
            $needsKey = $user && is_null($user['security_key_set_at']);
            $_SESSION['needs_security_key'] = $needsKey;

            if ($needsKey && $method === 'POST') {
                $_SESSION['error'] = "You must set up your security key first.";
                header("Location: /");
                exit;
            }
        }
    }
}
