<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string[]  ...$roles
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        if (!Auth::check()) {
            return redirect('/');
        }

        // If specific roles are required for the route
        if (!empty($roles)) {
            $userRole = Auth::user()->role;
            if (!in_array($userRole, $roles)) {
                // User's role is not in the list of allowed roles, so abort with a 403 error.
                abort(403);
            }
        } else {
            // If no specific role is passed to the middleware, redirect based on the user's role.
            // This handles the initial login redirection from the '/dashboard' route.
            $userRole = Auth::user()->role;
            switch ($userRole) {
                case 'admin':
                    return redirect()->route('admin.dashboard');
                case 'officer':
                    return redirect()->route('intake');
                case 'staff':
                    return redirect()->route('tasks');
                default:
                    return redirect('/');
            }
        }

        return $next($request);
    }
}
