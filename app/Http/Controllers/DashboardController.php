<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * Display the user's dashboard based on their role.
     */
    public function index()
    {
        $user = Auth::user();
        $role = $user ? $user->role : 'guest'; // Should not be guest due to middleware

        return view('dashboard', ['role' => $role]);
    }
}