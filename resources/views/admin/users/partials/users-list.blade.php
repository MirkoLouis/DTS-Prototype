<table class="min-w-full divide-y divide-gray-200">
    <thead class="bg-gray-50 dark:bg-gray-700">
        <tr>
            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                Name
            </th>
            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                Email
            </th>
            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                Role
            </th>
            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                Signature
            </th>
            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                Date Created
            </th>
            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                Last Modified
            </th>
        </tr>
    </thead>
    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200">
        @foreach ($users as $user)
            <tr>
                <td class="px-6 py-4 text-m font-medium text-gray-900 dark:text-gray-100">
                    <div>{{ $user->name }}</div>
                    <a href="{{ route('users.edit', $user) }}" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300 text-sm mt-1 block">EDIT USER</a>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300">
                    <div class="flex items-start">
                        <span class="break-all flex-grow">{{ $user->email }}</span>
                        <div x-data="{ tooltip: 'Copy' }" class="relative ml-2 mt-0.5 flex-shrink-0">
                            <button @click="navigator.clipboard.writeText('{{ $user->email }}'); tooltip = 'Copied!'; setTimeout(() => tooltip = 'Copy', 2000)" class="text-gray-400 hover:text-gray-600">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M7 3a1 1 0 011-1h5a1 1 0 011 1v2h1.5a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 013 15.5v-9A1.5 1.5 0 014.5 5H6V3zm1 2v1h4V5H8zm-1.5 3a.5.5 0 00-.5.5v9a.5.5 0 00.5.5h9a.5.5 0 00.5-.5v-9a.5.5 0 00-.5-.5h-9z"/>
                                </svg>
                            </button>
                            <span x-show="tooltip === 'Copied!'" class="absolute -top-7 left-1/2 -translate-x-1/2 bg-gray-700 text-white text-xs rounded-md px-2 py-1" style="display: none;">Copied!</span>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                    {{ $user->role }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                    @if($user->public_key)
                        <div class="flex items-center text-green-600 dark:text-green-400">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <span>Active</span>
                            <button type="button" 
                                    onclick="if(confirm('Reset signature for {{ $user->name }}?')) { const form = document.getElementById('global-reset-signature-form'); form.action = '{{ route('users.reset-signature', $user) }}'; form.submit(); }"
                                    class="ml-2 text-xs text-red-500 hover:text-red-700 underline">Reset</button>
                        </div>
                    @else
                        <span class="text-gray-400 italic text-xs">Not set</span>
                    @endif
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                    {{ $user->created_at->format('Y-m-d H:i:s') }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                    {{ $user->updated_at->format('Y-m-d H:i:s') }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
<div class="mt-4" id="pagination-links">
    {{ $users->links() }}
</div>
