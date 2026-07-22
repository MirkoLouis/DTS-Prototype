<?php

namespace App\Core;

class Router
{
    private array $routes = [];

    /**
     * Add a route to the routing table.
     * 
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $uri The URI pattern (e.g., '/documents/{id}')
     * @param array|callable $action The controller class and method [Controller::class, 'method'] or a closure
     * @param array $middleware Array of middleware classes to run before the action
     */
    public function add(string $method, string $uri, $action, array $middleware = []): self
    {
        // Convert URI to regular expression for parameter matching
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<\1>[a-zA-Z0-9_-]+)', $uri);
        $pattern = "#^" . $pattern . "$#";

        $this->routes[] = [
            'method' => strtoupper($method),
            'uri' => $uri,
            'pattern' => $pattern,
            'action' => $action,
            'middleware' => $middleware
        ];

        return $this;
    }

    public function get(string $uri, $action, array $middleware = []): self
    {
        return $this->add('GET', $uri, $action, $middleware);
    }

    public function post(string $uri, $action, array $middleware = []): self
    {
        return $this->add('POST', $uri, $action, $middleware);
    }

    /**
     * Dispatch the current request to the appropriate route.
     * 
     * Core request dispatcher. Handles URI pattern matching, named parameter extraction, 
     * middleware execution, and global CSRF protection.
     * 
     * @param string $requestUri The requested URI path
     * @param string $requestMethod The HTTP method used
     */
    public function dispatch(string $requestUri, string $requestMethod): void
    {
        // Global CSRF Protection for all POST requests
        // Enforce global CSRF protection on all state-mutating requests to prevent cross-site request forgery.
        if (strtoupper($requestMethod) === 'POST') {
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
                http_response_code(419);
                echo "419 Page Expired: CSRF token mismatch. Please refresh and try again.";
                exit;
            }
        }

        // Strip query string if present
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';

        foreach ($this->routes as $route) {
            if ($route['method'] === strtoupper($requestMethod) && preg_match($route['pattern'], $path, $matches)) {
                
                // Extract named parameters from regex matches (e.g., (?P<id>\d+) captures into 'id')
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Run Middleware (basic implementation)
                foreach ($route['middleware'] as $middlewareConfig) {
                    $parts = explode(':', $middlewareConfig);
                    $middlewareClass = $parts[0];
                    $args = isset($parts[1]) ? explode(',', $parts[1]) : [];
                    
                    $middleware = new $middlewareClass();
                    $middleware->handle(...$args);
                }

                $action = $route['action'];

                try {
                    if (is_callable($action)) {
                        call_user_func_array($action, $params);
                        return;
                    }

                    if (is_array($action) && count($action) === 2) {
                        [$class, $method] = $action;
                        if (class_exists($class)) {
                            $controller = new $class();
                            if (method_exists($controller, $method)) {
                                call_user_func_array([$controller, $method], $params);
                                return;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("Unhandled Exception in Router: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

                    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

                    if ($isAjax) {
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'status' => 'error',
                            'message' => 'An internal server error occurred.'
                        ]);
                        exit;
                    }

                    $_SESSION['error'] = "An unexpected error occurred. Please try again or contact support.";
                    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'));
                    exit;
                }
            }
        }

        // Route not found
        $this->abort(404);
    }

    private function abort(int $code = 404): void
    {
        http_response_code($code);
        echo "404 Not Found";
        exit;
    }
}
