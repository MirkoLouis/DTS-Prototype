<?php

namespace App\Middleware;

class CacheResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  int  $ttl  Time to live in seconds
     */
    public function handle(int $ttl = 60)
    {
        // Only cache GET requests
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header('Cache-Control: private, no-cache, no-store, max-age=' . $ttl . ', must-revalidate');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
        }
    }
}
