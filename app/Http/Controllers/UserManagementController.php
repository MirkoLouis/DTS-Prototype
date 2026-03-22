<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Models\Department;
use App\Models\PublicKeyHistory;

class UserManagementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        $users = $query->orderBy('name')->paginate(10)->withQueryString();

        if ($request->ajax()) {
            return view('admin.users.partials.users-list', compact('users'));
        }

        return view('admin.users.index', compact('users'));
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.users.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(['admin', 'officer', 'staff'])],
        ]);

        $user = User::create([
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'password' => Hash::make($validatedData['password']),
            'role' => $validatedData['role'],
        ]);

        // If the user is an officer or staff, create a corresponding department
        if (in_array($user->role, ['officer', 'staff'])) {
            $department = Department::create([
                'name' => $user->name,
            ]);
            $user->department_id = $department->id;
            $user->save();
        } else {
            // For admin users, ensure department_id is null
            $user->department_id = null;
            $user->save();
        }

        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    /**
     * Display the specified resource.
     */
    // Removed show method as it's not used in this context.

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user)
    {
        return view('admin.users.edit', compact('user'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $validatedData = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role' => ['required', Rule::in(['admin', 'officer', 'staff'])],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        // Prevent admin from changing their own role
        if (auth()->id() === $user->id && $validatedData['role'] !== $user->role) {
            return back()->with('error', 'You cannot change your own role.');
        }

        $user->update([
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'role' => $validatedData['role'],
        ]);

        // Logic to manage associated Department based on role
        if (in_array($user->role, ['officer', 'staff'])) {
            // If user is officer/staff, ensure they have a department matching their name
            if ($user->department) {
                // Department exists, update its name if necessary
                if ($user->department->name !== $user->name) {
                    $user->department->update(['name' => $user->name]);
                }
            } else {
                // No department, create one
                $department = Department::create(['name' => $user->name]);
                $user->department_id = $department->id;
                $user->save(); // Save the user to update department_id
            }
        } else {
            // If user is admin, remove association and delete the department if it exists
            if ($user->department) {
                $user->department->delete();
                $user->department_id = null;
                $user->save(); // Save the user to set department_id to null
            }
        }

        if ($request->filled('password')) {
            $user->update([
                'password' => Hash::make($request->password),
            ]);
        }

        return redirect()->route('users.index')->with('success', 'User updated successfully.');
    }

    public function resetSignature(User $user)
    {
        // Archive the current key if it exists
        if ($user->public_key && $user->security_key_set_at) {
            PublicKeyHistory::create([
                'user_id' => $user->id,
                'public_key' => $user->public_key,
                'activated_at' => $user->security_key_set_at,
                'deactivated_at' => now(),
            ]);
        }

        $user->update([
            'public_key' => null,
            'private_key' => null,
            'security_key_set_at' => null,
        ]);

        return back()->with('success', "Digital signature for {$user->name} has been reset. They will be prompted to set a new one upon their next login or critical action.");
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        // Prevent admin from deleting their own account
        if (auth()->user()->id === $user->id) {
            return redirect()->route('users.index')->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully.');
    }
}
