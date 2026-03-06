<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CacheResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  int  $ttl  Time to live in seconds
     */
    public function handle(Request $request, Closure $next, int $ttl = 60): Response
    {
        $response = $next($request);

        // Only cache GET requests and successful responses.
        // We use 'private' to ensure the cache is unique to the user's browser
        // and 'no-cache, no-store' to prevent issues when switching users on the same machine.
        if ($request->isMethod('get') && $response->getStatusCode() == 200) {
            $response->headers->set('Cache-Control', "private, no-cache, no-store, max-age={$ttl}, must-revalidate");
            $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
        }

        return $response;
    }
}
