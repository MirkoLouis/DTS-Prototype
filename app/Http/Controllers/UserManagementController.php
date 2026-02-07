<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Models\Department;

class UserManagementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
         public function index()
        {
            $users = User::orderBy('name')->paginate(10);
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
