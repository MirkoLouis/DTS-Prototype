<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Edit User: ' . $user->name) }}
        </h2>
    </x-slot>

    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <form action="{{ route('users.update', $user) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div class="space-y-4">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                                <input type="text" name="name" id="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200" value="{{ old('name', $user->name) }}" required>
                                @error('name')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                                <input type="email" name="email" id="email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200" value="{{ old('email', $user->email) }}" required>
                                @error('email')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="role" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Role</label>
                                <select name="role" id="role" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 @if(auth()->id() === $user->id) bg-gray-100 cursor-not-allowed @endif" required @if(auth()->id() === $user->id) disabled @endif>
                                    <option value="staff" {{ old('role', $user->role) == 'staff' ? 'selected' : '' }}>Staff</option>
                                    <option value="officer" {{ old('role', $user->role) == 'officer' ? 'selected' : '' }}>Officer</option>
                                    <option value="admin" {{ old('role', $user->role) == 'admin' ? 'selected' : '' }}>Admin</option>
                                </select>
                                @if(auth()->id() === $user->id)
                                    <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                                        <i class="fas fa-info-circle mr-1"></i> You cannot change your own role to prevent accidental lockout.
                                    </p>
                                    <input type="hidden" name="role" value="{{ $user->role }}">
                                @endif
                                @error('role')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <h3 class="text-lg font-semibold mt-6">Change Password (Optional)</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Leave blank to keep current password.</p>

                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">New Password</label>
                                <input type="password" name="password" id="password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200">
                                @error('password')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm New Password</label>
                                <input type="password" name="password_confirmation" id="password_confirmation" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200">
                                @error('password_confirmation')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <h3 class="text-lg font-semibold mt-6">Digital Signature</h3>
                            @if($user->public_key)
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    This user has a digital signature initialized (since {{ $user->security_key_set_at->format('M d, Y') }}).
                                    If the user has forgotten their Security PIN, you can reset it here.
                                </p>
                                <div class="mt-2">
                                    <button type="button" 
                                            onclick="if(confirm('Are you sure you want to reset the digital signature for {{ $user->name }}? They will need to set it up again before performing any signed actions.')) document.getElementById('reset-signature-form').submit();"
                                            class="bg-red-100 text-red-700 hover:bg-red-200 font-medium py-1 px-3 rounded text-sm border border-red-300">
                                        Reset Digital Signature
                                    </button>
                                </div>
                            @else
                                <p class="text-sm text-gray-500 italic">No digital signature initialized yet.</p>
                            @endif

                            <div class="flex justify-between items-center pt-6 border-t border-gray-100 dark:border-gray-700 mt-6">
                                <div class="flex items-center">
                                    @if(auth()->id() !== $user->id)
                                        <button type="button" 
                                                onclick="if(confirm('Are you sure you want to PERMANENTLY delete this user? This cannot be undone.')) document.getElementById('delete-user-form').submit();"
                                                class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                                            Delete User
                                        </button>
                                    @else
                                        <div class="bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 py-2 px-4 rounded border border-gray-200 dark:border-gray-600 text-sm italic">
                                            Self-deletion is disabled for administrators.
                                        </div>
                                    @endif
                                </div>
                                <div class="flex items-center">
                                    <a href="{{ route('users.index') }}" class="mr-4 bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">Cancel</a>
                                    <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                        Update User
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                    
                    <form id="delete-user-form" action="{{ route('users.destroy', $user) }}" method="POST" class="hidden">
                        @csrf
                        @method('DELETE')
                    </form>
                    
                    @if($user->public_key)
                        <form id="reset-signature-form" action="{{ route('users.reset-signature', $user) }}" method="POST" class="hidden">
                            @csrf
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
