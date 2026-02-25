<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SecurityKeyController extends Controller
{
    /**
     * Store the public key for the authenticated user (department).
     */
    public function store(Request $request)
    {
        $request->validate([
            'public_key' => 'required|string|min:32',
        ]);

        $user = Auth::user();
        $user->public_key = $request->public_key;
        $user->security_key_set_at = now();
        $user->save();

        return response()->json(['status' => 'success', 'message' => 'Department Security Key has been initialized successfully.']);
    }
}
