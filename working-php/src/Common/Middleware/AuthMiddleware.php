<?php

namespace App\Common\Middleware;

use App\Common\Database\Database;

/**
 * Authentication and Key Setup Guard Middleware.
 *
 * Enforces session authentication, ends the sessions of deactivated accounts, and verifies that users have
 * initialized their Ed25519 digital signature key pair before allowing state-mutating requests.
 */
class AuthMiddleware
{
    /**
     * Inspects the active user session, account state and key initialization status.
     */
    public function handle()
    {
        // Require active session — unauthenticated requests are refused immediately to prevent unauthorized route execution
        if (!isset($_SESSION['user_id'])) {
            $this->refuse();
        }

        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Re-read the account on every request (before the session lock is released) so a deactivation applies immediately
        $db = Database::getInstance();
        $user = $db->query("SELECT security_key_set_at FROM users WHERE id = :id AND deactivated_at IS NULL", ['id' => $_SESSION['user_id']])->fetch();
        if (!$user) {
            $this->endSession("Your account is no longer active.");
        }

        // Release the session write lock early ONLY for read-only API requests (e.g. chart/polling GET endpoints).
        // For state-mutating requests and web page renders, keep session write open so controllers can set
        // $_SESSION['success'], $_SESSION['error'], and $_SESSION['info'] flash messages.
        if ($method === 'GET' && str_starts_with($uri, '/api/')) {
            session_write_close();
        }

        // Enforce mandatory security key setup for all routes except the key setup page and logout endpoint
        if ($uri !== '/security-key' && $uri !== '/logout') {
            // Check if user has initialized their cryptographic signing keys
            $needsKey = is_null($user['security_key_set_at']);
            $_SESSION['needs_security_key'] = $needsKey;

            // Block POST operations if security key setup is missing to ensure no unsigned document transactions occur
            if ($needsKey && $method === 'POST') {
                $_SESSION['error'] = "You must set up your security key first.";
                header("Location: /");
                exit;
            }
        }
    }

    /**
     * Drop the authenticated session but keep a fresh, anonymous one for the flash message and CSRF token.
     */
    private function endSession(string $message): never
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['error'] = $message;

        $this->refuse($message);
    }

    /**
     * Answer an unauthenticated request: 401 JSON for scripts (so a poller never follows a redirect to the
     * login page and consumes its flash message), a redirect to the login page for page requests.
     */
    private function refuse(string $message = 'Your session has ended. Please sign in again.'): never
    {
        // Background pollers and fetch calls parse JSON, so they must not receive an HTML page
        if (request_expects_json()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }

        header("Location: /login");
        exit;
    }
}
