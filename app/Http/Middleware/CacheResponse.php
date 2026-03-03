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

        // Only cache GET requests and successful responses
        if ($request->isMethod('get') && $response->getStatusCode() == 200) {
            $response->headers->set('Cache-Control', "public, max-age={$ttl}, must-revalidate");
            $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
        }

        return $response;
    }
}
