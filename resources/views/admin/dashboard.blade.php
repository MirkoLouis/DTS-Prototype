<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Admin Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-2xl font-bold">Processing Analytics</h3>
                        <form action="{{ route('admin.dashboard.clear-cache') }}" method="POST" class="confirm-action" data-message="Are you sure you want to clear the dashboard cache? This will force all charts to recalculate from the database.">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-3 py-1 bg-gray-200 dark:bg-gray-700 border border-transparent rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-300 dark:hover:bg-gray-600 focus:outline-none transition">
                                Clear Cache
                            </button>
                        </form>
                    </div>

                    <div class="space-y-6">
                        <!-- Section: Main Overview (3 cols) -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6"
                             data-current-load-url="{{ route('api.admin-dashboard.current-load') }}"
                             data-throughput-url="{{ route('api.admin-dashboard.throughput') }}"
                             data-return-decline-url="{{ route('api.admin-dashboard.return-decline-trends') }}"
                             data-status-distribution-url="{{ route('api.admin-dashboard.status-distribution') }}"
                             data-return-request-sources-url="{{ route('api.admin-dashboard.return-request-sources') }}"
                             data-processing-hotspots-url="{{ route('api.admin-dashboard.processing-hotspots') }}"
                             data-avg-step-time-url="{{ route('api.admin-dashboard.avg-step-time') }}"
                             data-load-vs-time-url="{{ route('api.admin-dashboard.department-load-vs-time') }}"
                             data-submission-districts-url="{{ route('api.admin-dashboard.submission-districts') }}">

                            <!-- Document Status Distribution Chart -->
                            <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow">
                                <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Document Status Distribution</h4>
                                <div class="relative h-64">
                                    <canvas id="statusDistributionChart"></canvas>
                                </div>
                            </div>

                            <!-- Global Throughput Chart -->
                            <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow">
                                <div class="flex justify-between items-center mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                    <h4 class="text-lg font-bold">Departmental Average TAT over time (hrs)</h4>
                                    <select id="globalThroughputPeriod" class="form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm max-w-32">
                                        <option value="daily">Daily (Last 30 Days)</option>
                                        <option value="weekly">Weekly (Last 12 Weeks)</option>
                                        <option value="monthly">Monthly (Last 12 Months)</option>
                                        <option value="yearly">Yearly (Last 5 Years)</option>
                                    </select>
                                </div>
                                <div class="relative h-64"> <!-- Adjusted height to match others -->
                                    <canvas id="throughputChart"></canvas>
                                </div>
                            </div>

                            <!-- Average Processing Time by Dept Chart -->
                            <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow">
                                <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Average TAT by Department (hrs)</h4>
                                <div class="relative h-56">
                                    <canvas id="avgStepTimeChart"></canvas>
                                </div>
                                <div class="text-right mt-2">
                                    <button id="view-full-avg-step-time" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline focus:outline-none">
                                        View Full Chart
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Returns & Declines Analysis -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow">
                            <h3 class="text-xl font-bold mb-4">Returns & Declines Analysis</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Return & Decline Trends Chart -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                    <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Return & Decline Rate Trends</h4>
                                    <div class="flex justify-end mb-4">
                                        <select id="returnDeclinePeriod" class="form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm max-w-36">
                                            <option value="daily">Daily (Last 30 Days)</option>
                                            <option value="weekly">Weekly (Last 12 Weeks)</option>
                                            <option value="monthly">Monthly (Last 12 Months)</option>
                                            <option value="yearly">Yearly (Last 5 Years)</option>
                                        </select>
                                    </div>
                                    <div class="relative h-64">
                                        <canvas id="returnDeclineChart"></canvas>
                                    </div>
                                </div>

                                <!-- Return Request Sources Chart -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                    <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Return Request Sources</h4>
                                    <div class="relative h-64">
                                        <canvas id="returnRequestSourcesChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Department Drill-Down -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow">
                            <h3 class="text-xl font-bold mb-4">Department Drill-Down</h3>
                            <!-- Shared Filters -->
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 p-4 bg-gray-100 dark:bg-gray-900/50 rounded-lg">
                                <div>
                                    <label for="department-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Department</label>
                                    <select id="department-filter" class="mt-1 form-select block w-full rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                        @foreach($departments as $department)
                                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="departmentPeriod" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Time Period</label>
                                    <select id="departmentPeriod" class="mt-1 form-select block w-full rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                        <option value="daily">Daily (Last 30 Days)</option>
                                        <option value="weekly">Weekly (Last 12 Weeks)</option>
                                        <option value="monthly">Monthly (Last 12 Months)</option>
                                        <option value="yearly">Yearly (Last 5 Years)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                <h4 id="load-vs-time-title" class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Load vs. Processing Time</h4>
                                <div class="relative h-96">
                                    <canvas id="loadVsTimeChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Purpose & Origin Analysis -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow mt-6">
                            <h3 class="text-xl font-bold mb-4">Purpose & Origin Analysis</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Processing Hotspots Chart -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                    <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Processing Hotspots (Purpose Popularity)</h4>
                                    <div class="relative h-96">
                                        <canvas id="processingHotspotsChart"></canvas>
                                    </div>
                                </div>

                                <!-- Submission Volume by District Chart -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                    <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Submission Volume by District</h4>
                                    <div class="relative h-96">
                                        <canvas id="submissionDistrictsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

        <x-chart-modal />
    
        <!-- Confirmation Modal -->
        <div id="confirmation-modal" class="fixed inset-0 z-50 overflow-y-auto hidden items-center justify-center bg-gray-900 bg-opacity-75">
            <div class="relative w-full max-w-md p-4 bg-white dark:bg-gray-800 rounded-lg shadow-xl">
                <div class="flex items-center justify-between mb-4 border-b dark:border-gray-700 pb-2">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Confirm Action</h3>
                    <button id="cancel-btn-top" class="text-gray-400 hover:text-gray-500">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="mb-6">
                    <p id="confirmation-message" class="text-sm text-gray-600 dark:text-gray-400"></p>
                </div>
                <div class="flex justify-end space-x-3">
                    <button id="cancel-btn" class="inline-flex items-center px-4 py-2 bg-gray-200 dark:bg-gray-700 border border-transparent rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-300 dark:hover:bg-gray-600 focus:outline-none transition">
                        Cancel
                    </button>
                    <button id="confirm-btn" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none transition">
                        Yes, Proceed
                    </button>
                </div>
            </div>
        </div>
    
        @push('scripts')
            @vite('resources/js/admin-dashboard.js')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const confirmForms = document.querySelectorAll('.confirm-action');
                    const modal = document.getElementById('confirmation-modal');
                    const modalMessage = document.getElementById('confirmation-message');
                    const confirmBtn = document.getElementById('confirm-btn');
                    const cancelBtn = document.getElementById('cancel-btn');
                    const cancelBtnTop = document.getElementById('cancel-btn-top');
                    let currentForm = null;
    
                    confirmForms.forEach(form => {
                        form.addEventListener('submit', (e) => {
                            e.preventDefault();
                            currentForm = form;
                            modalMessage.textContent = form.dataset.message || 'Are you sure?';
                            modal.style.display = 'flex';
                        });
                    });
    
                    [cancelBtn, cancelBtnTop].forEach(btn => {
                        if (btn) btn.addEventListener('click', () => {
                            modal.style.display = 'none';
                            currentForm = null;
                        });
                    });
    
                    if (confirmBtn) {
                        confirmBtn.addEventListener('click', () => {
                            if (currentForm) currentForm.submit();
                        });
                    }
                });
            </script>
        @endpush
    </x-app-layout>
    