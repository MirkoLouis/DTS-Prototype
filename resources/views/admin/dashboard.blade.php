<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Admin Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h3 class="text-2xl font-bold mb-4">Process Analytics</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6"
                         data-current-load-url="{{ route('api.admin-dashboard.current-load') }}"
                         data-throughput-url="{{ route('api.admin-dashboard.throughput') }}">
                        <!-- Current Load Chart -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Current Load (Pending Documents to Process)</h4>
                            <!-- Department Filter -->
                            <div class="mb-6 max-w-sm">
                                <label for="department-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Filter by Department</label>
                                <select id="department-filter" class="mt-1 form-select block w-full rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                    <option value="all">All Departments</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <canvas id="currentLoadChart"></canvas>
                        </div>

                        <!-- Throughput Chart -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Documents Processed Over Time</h4>
                            <div class="flex justify-end mb-4">
                                <select id="throughputPeriod" class="form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                    <option value="daily">Daily (Last 30 Days)</option>
                                    <option value="weekly">Weekly (Last 4 Weeks)</option>
                                    <option value="monthly">Monthly (Last 12 Months)</option>
                                    <option value="yearly">Yearly (Last 5 Years)</option>
                                </select>
                            </div>
                            <canvas id="throughputChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @vite('resources/js/admin-dashboard.js')
    @endpush
</x-app-layout>