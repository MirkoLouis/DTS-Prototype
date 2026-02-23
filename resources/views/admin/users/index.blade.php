<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('User Management') }}
        </h2>
    </x-slot>

    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100" id="users-section">
                    @if(session('success'))
                        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                            <span class="block sm:inline">{{ session('success') }}</span>
                        </div>
                    @endif
                    @if(session('error'))
                        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                            <span class="block sm:inline">{{ session('error') }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-2xl font-semibold">All User Accounts</h3>
                        <a href="{{ route('users.create') }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Create User</a>
                    </div>
                    <form action="{{ route('users.index') }}" method="GET" class="mb-4">
                        <div class="flex items-center space-x-4">
                            <input type="text" name="search" placeholder="Search by name or email" class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" value="{{ request('search') }}">
                            <select name="role" class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="">All Roles</option>
                                <option value="admin" @if(request('role') == 'admin') selected @endif>Admin</option>
                                <option value="officer" @if(request('role') == 'officer') selected @endif>Officer</option>
                                <option value="staff" @if(request('role') == 'staff') selected @endif>Staff</option>
                            </select>
                            <a href="{{ route('users.index') }}" class="text-gray-500 hover:text-gray-700">Clear</a>
                        </div>
                    </form>
                    <div id="users-container">
                        @include('admin.users.partials.users-list')
                    </div>
                </div>
            </div>
        </div>
    </div>
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const usersContainer = document.getElementById('users-container');
                const filterForm = document.querySelector('form[action="{{ route('users.index') }}"]');
                const searchInput = filterForm.querySelector('input[name="search"]');
                const roleSelect = filterForm.querySelector('select[name="role"]');
                const clearButton = filterForm.querySelector('a[href="{{ route('users.index') }}"]');
                let debounceTimer;

                const fetchUsers = async (url) => {
                    try {
                        const response = await fetch(url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        if (!response.ok) throw new Error('Network response was not ok.');
                        
                        const html = await response.text();
                        usersContainer.innerHTML = html;
                        history.pushState(null, '', url);
                        document.getElementById("users-section").scrollIntoView({ behavior: "smooth" });
                    } catch (error) {
                        console.error('Fetch error:', error);
                        usersContainer.innerHTML = '<div class="text-center py-4 text-red-500">Failed to load users. Please try again.</div>';
                    }
                };

                function handleFilterChange() {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        const url = new URL('{{ route("users.index") }}');
                        const params = new URLSearchParams();
                        
                        const searchValue = searchInput.value;
                        const roleValue = roleSelect.value;

                        if (searchValue) params.set('search', searchValue);
                        if (roleValue) params.set('role', roleValue);
                        params.set('page', '1');

                        url.search = params.toString();
                        fetchUsers(url.toString());
                    }, 300);
                }

                function clearFilters(e) {
                    e.preventDefault();
                    searchInput.value = '';
                    roleSelect.value = '';
                    
                    const url = new URL('{{ route("users.index") }}');
                    url.searchParams.set('page', '1');
                    fetchUsers(url.toString());
                }

                searchInput.addEventListener('keyup', handleFilterChange);
                roleSelect.addEventListener('change', handleFilterChange);
                clearButton.addEventListener('click', clearFilters);

                usersContainer.addEventListener('click', (e) => {
                    const paginationLink = e.target.closest('#pagination-links a');
                    if (paginationLink) {
                        e.preventDefault();
                        const url = paginationLink.getAttribute('href');
                        if (url && url !== '#') {
                            fetchUsers(url);
                        }
                    }
                });
            });
        </script>
    @endpush
</x-app-layout>
