<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('System Health Monitor') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h3 class="text-2xl font-bold mb-4">System Health Overview</h3>

                    <div class="space-y-6">
                        <!-- Section: System Status Overview -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            @php
                                $seconds = $appHealthMetrics['average_processing_time'];
                                if ($seconds <= 0) {
                                    $formattedTime = 'N/A';
                                } else {
                                    $days = floor($seconds / 86400);
                                    $hours = floor(($seconds % 86400) / 3600);
                                    $minutes = floor(($seconds % 3600) / 60);

                                    if ($days > 0) {
                                        $formattedTime = round($seconds / 86400, 1) . ' <span class="text-lg font-normal">days</span>';
                                    } elseif ($hours > 0) {
                                        $formattedTime = round($seconds / 3600, 1) . ' <span class="text-lg font-normal">hours</span>';
                                    } else {
                                        $formattedTime = round($seconds / 60, 1) . ' <span class="text-lg font-normal">minutes</span>';
                                    }
                                }
                            @endphp
                            <!-- Avg. Processing Time -->
                            <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                                <h4 class="text-lg font-bold mb-2">Avg. Processing Time</h4>
                                <p class="text-3xl font-semibold">{!! $formattedTime !!}</p>
                            </div>
                            <!-- Failed Jobs -->
                            <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                                <h4 class="text-lg font-bold mb-2">Failed Jobs</h4>
                                <p class="text-3xl font-semibold @if($appHealthMetrics['failed_jobs_count'] > 0) text-red-500 @endif">{{ $appHealthMetrics['failed_jobs_count'] }}</p>
                            </div>
                            <!-- Cache Status -->
                            <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                                <h4 class="text-lg font-bold mb-2">Cache Status</h4>
                                @if($appHealthMetrics['cache_status'])
                                    <p class="text-3xl font-semibold text-green-500">Operational</p>
                                @else
                                    <p class="text-3xl font-semibold text-red-500">Not Responding</p>
                                @endif
                            </div>
                        </div>


                        <!-- Section: Database Performance -->
                        <div id="db-performance-chart-container" 
                             class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow"
                             data-url="{{ route('api.system-health.db-performance') }}">
                            <div class="flex justify-between items-center mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <h3 class="text-xl font-bold">Database Performance</h3>
                                <div class="flex items-center space-x-2">
                                    <select id="db-performance-period" class="form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm max-w-40">
                                        <option value="daily">Daily (Last 30 Days)</option>
                                        <option value="weekly">Weekly (Last 12 Weeks)</option>
                                        <option value="monthly">Monthly (Last 12 Months)</option>
                                    </select>
                                    <a href="{{ route('admin.system-health.export-db-metrics') }}" class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-25 transition ease-in-out duration-150">
                                        Export CSV
                                    </a>
                                </div>
                            </div>
                            <div class="relative h-96">
                                <canvas id="dbPerformanceChart"></canvas>
                            </div>
                        </div>

                        <!-- Section: Database Integrity -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow">
                            <h3 class="text-xl font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Database Integrity</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-4">
                                <div class="md:col-span-1 bg-white dark:bg-gray-800 p-6 rounded-lg shadow-inner">
                                    <h3 class="text-lg font-bold mb-4 text-center">Verification Status</h3>
                                    <div id="integrity-status-container" class="text-center">
                                        <div class="text-5xl font-bold text-green-500" id="verified-percentage">{{ $integrityCheckResult['verified_percentage'] }}%</div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">Verified</div>
                                        <div class="text-xs text-gray-400 dark:text-gray-500 mt-1" id="last-checked-at">
                                            Last checked: {{ $integrityCheckResult['last_checked'] instanceof \Carbon\Carbon ? $integrityCheckResult['last_checked']->diffForHumans() : $integrityCheckResult['last_checked'] }}
                                        </div>
                                    </div>
                                    <div class="mt-6 text-center">
                                        <button id="run-integrity-check" 
                                                data-url="{{ route('system.health.run-check') }}"
                                                class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                            <svg id="button-spinner" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <span id="button-text">Run Verification</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="md:col-span-2 bg-white dark:bg-gray-800 p-6 rounded-lg shadow-inner">
                                    <h3 class="text-lg font-bold mb-4">How it Works</h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-300">
                                        This tool provides a powerful way to verify the integrity of the document tracking system's data. It leverages a "hash chain" mechanism, similar to blockchain technology, to ensure that document logs are immutable and tamper-proof.
                                    </p>
                                    <br>
                                    <ol class="list-decimal list-inside text-sm text-gray-600 dark:text-gray-300 space-y-2">
                                        <li><strong>Hash-Chaining:</strong> When a document log is created, a unique digital signature (a "hash") is generated from its data and the hash of the previous log. This creates a linked chain of records.</li>
                                        <li><strong>Verification Process:</strong> Clicking "Run Verification" triggers a system-wide check. The application iterates through every log for every document, recalculates the hash for each one, and compares it to the hash stored in the database.</li>
                                        <li><strong>Status Indication:</strong> If the recalculated hash matches the stored hash for every single log, the system is 100% verified. If even one hash is mismatched, it indicates that data may have been altered, and the system will report an error.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <!-- Section: Mismatched Logs -->
                        @if ($mismatchedLogs->isNotEmpty())
                            <div class="bg-red-50 dark:bg-red-900/20 pt-3 px-5 pb-5 rounded-lg shadow">
                                <h3 class="text-xl font-bold mb-4 text-red-600 dark:text-red-400 border-b border-red-200 dark:border-red-700 pb-2">Mismatched Integrity Logs</h3>
                                <div class="mt-4 overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Document</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Action</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Performed By</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Stored Hash</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                            @foreach ($mismatchedLogs as $log)
                                                <tr>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100 align-top">{{ $log->document->tracking_code }}</td>
                                                    <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300 align-top">{{ $log->action }}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300 align-top">{{ $log->user->name ?? 'System' }}</td>
                                                    <td class="px-6 py-4 text-sm text-red-500 dark:text-red-400 font-mono break-all max-w-xs">{{ $log->hash }}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                        <div class="flex items-center space-x-2">
                                                            <a href="{{ route('documents.show', $log->document_id) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200">View</a>
                                                            
                                                            @if($log->document->status === 'frozen')
                                                                <form action="{{ route('documents.unfreeze', $log->document_id) }}" method="POST" class="unfreeze-form">
                                                                    @csrf
                                                                    <button type="submit" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-200">Unfreeze</button>
                                                                </form>
                                                            @else
                                                                <form action="{{ route('documents.freeze', $log->document_id) }}" method="POST" class="freeze-form">
                                                                    @csrf
                                                                    <button type="submit" class="text-yellow-600 hover:text-yellow-900 dark:text-yellow-400 dark:hover:text-yellow-200">Freeze</button>
                                                                </form>
                                                            @endif

                                                            @if($log->document->status !== 'frozen')
                                                                <form action="{{ route('system.health.rebuild-chain', $log->id) }}" method="POST" class="rebuild-form">
                                                                    @csrf
                                                                    <button type="submit" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-200">Rebuild Chain</button>
                                                                </form>
                                                            @endif
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-4">
                                    {{ $mismatchedLogs->links() }}
                                </div>
                            </div>
                        @endif

                        <!-- Section: Admin Utilities -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow">
                            <h3 class="text-xl font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Admin Utilities</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                                <!-- Backup Manager Link -->
                                <a href="{{ route('system.backups.index') }}" class="block p-6 bg-white dark:bg-gray-800 rounded-lg shadow-inner hover:bg-gray-100 dark:hover:bg-gray-700 transition ease-in-out duration-150">
                                    <h4 class="text-lg font-bold mb-2 text-indigo-600 dark:text-indigo-400">Backup Manager &rarr;</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-300">
                                        Create, download, and manage database backups.
                                    </p>
                                </a>
                                <!-- Ratings Link -->
                                <a href="{{ route('system.ratings') }}" class="block p-6 bg-white dark:bg-gray-800 rounded-lg shadow-inner hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <h4 class="text-lg font-bold mb-2 text-indigo-600 dark:text-indigo-400">View Client Ratings &rarr;</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-300">
                                        View the 1-5 star ratings submitted for completed documents.
                                    </p>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @vite(['resources/js/system-health.js'])
    @endpush
</x-app-layout>
