<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return redirect('/login'); // Redirect to login if not authenticated
        }

        $user = Auth::user();

        switch ($user->role) {
            case 'admin':
                return redirect('/integrity-monitor');
            case 'officer': // Records Officer
                return redirect('/intake');
            case 'staff':
                return redirect('/tasks');
            default:
                // Fallback for unexpected roles, or simply let the request proceed
                return redirect('/dashboard'); // Or a generic unauthorized page
        }

        // If for some reason the request makes it here, let it proceed to the dashboard.
        // This line might be unreachable depending on the default case.
        return $next($request);
    }
}