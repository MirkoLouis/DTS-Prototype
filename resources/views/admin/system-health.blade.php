<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('System Health Monitor') }}
        </h2>
    </x-slot>

    <div class="py-2">
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
                                <div class="flex items-center justify-between">
                                    <p class="text-3xl font-semibold @if($appHealthMetrics['failed_jobs_count'] > 0) text-red-500 @endif">{{ $appHealthMetrics['failed_jobs_count'] }}</p>
                                    @if($appHealthMetrics['failed_jobs_count'] > 0)
                                        <button id="view-failed-jobs" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline focus:outline-none">
                                            View Details
                                        </button>
                                    @endif
                                </div>
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

                        <!-- Section: Failed Jobs Details (Conditional) -->
                        @if($appHealthMetrics['failed_jobs_count'] > 0)
                            <div id="failed-jobs-details" class="bg-red-50 dark:bg-red-900/10 pt-3 px-5 pb-5 rounded-lg shadow hidden">
                                <div class="flex justify-between items-center mb-4 border-b border-red-200 dark:border-red-800 pb-2">
                                    <h3 class="text-xl font-bold text-red-700 dark:text-red-400">Failed Jobs Details</h3>
                                    <form action="{{ route('system.health.failed-jobs.delete-all') }}" method="POST" class="confirm-action" data-message="Are you sure you want to clear ALL failed jobs?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-red-600 dark:text-red-400 hover:underline">Clear All Jobs</button>
                                    </form>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-100 dark:bg-gray-800">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Job</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Failed At</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
                                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                            @foreach($appHealthMetrics['failed_jobs'] as $job)
                                                <tr>
                                                    <td class="px-4 py-2 text-sm font-medium dark:text-gray-200">{{ $job->display_name }}</td>
                                                    <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ \Carbon\Carbon::parse($job->failed_at)->diffForHumans() }}</td>
                                                    <td class="px-4 py-2 text-xs text-red-600 dark:text-red-400 truncate max-w-xs" title="{{ $job->exception }}">
                                                        {{ Str::limit($job->exception, 100) }}
                                                    </td>
                                                    <td class="px-4 py-2 text-right">
                                                        <form action="{{ route('system.health.failed-jobs.delete', $job->id) }}" method="POST" class="confirm-action" data-message="Are you sure you want to resolve this failed job? This will remove it from the list.">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded hover:bg-red-200 dark:bg-red-900/30 dark:text-red-300">Resolve</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif

                        <!-- Section: Database Performance -->
                        <div id="db-performance-chart-container" 
                             class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow"
                             data-url="{{ route('api.system-health.db-performance') }}">
                            <div class="flex justify-between items-center mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <h3 class="text-xl font-bold">Database Performance</h3>
                                <div class="flex items-center space-x-2">
                                    <select id="db-performance-period" class="form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm max-w-40">
                                        <option value="hourly">Hourly (Last 24 Hours)</option>
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
                                        <div class="text-5xl font-bold {{ $integrityCheckResult['verified_percentage'] == 100 && ($integrityCheckResult['live_state_errors_count'] ?? 0) == 0 ? 'text-green-500' : 'text-red-500' }}" id="verified-percentage">
                                            {{ $integrityCheckResult['verified_percentage'] }}%
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">Chain Integrity</div>
                                        
                                        @if(($integrityCheckResult['live_state_errors_count'] ?? 0) > 0)
                                            <div class="mt-4 p-2 bg-red-100 dark:bg-red-900/30 rounded-lg">
                                                <div class="text-xl font-bold text-red-600 dark:text-red-400">{{ $integrityCheckResult['live_state_errors_count'] }}</div>
                                                <div class="text-xs text-red-500 dark:text-red-300 uppercase font-bold">Live State Errors</div>
                                            </div>
                                        @else
                                            <div class="mt-4 p-2 bg-green-100 dark:bg-green-900/30 rounded-lg">
                                                <div class="text-xs text-green-600 dark:text-green-400 uppercase font-bold">Live State OK</div>
                                            </div>
                                        @endif

                                        <div class="text-xs text-gray-400 dark:text-gray-500 mt-3" id="last-checked-at">
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
                                        This tool provides a powerful way to verify the integrity of the document tracking system's data. It leverages a "hash chain" mechanism and an "Active State Comparison" to ensure that records are immutable and tamper-proof.
                                    </p>
                                    <br>
                                    <ol class="list-decimal list-inside text-sm text-gray-600 dark:text-gray-300 space-y-2">
                                        <li><strong>Hash-Chaining:</strong> When a document log is created, a unique digital signature (a "hash") is generated from its data and the hash of the previous log. This creates a linked chain of records.</li>
                                        <li><strong>Active State Comparison:</strong> The system compares the current live database state of every document against the state recorded in its last cryptographic log. Any unauthorized modification to document details (title, submitter, route) is immediately detected.</li>
                                        <li><strong>Verification Process:</strong> Clicking "Run Verification" triggers a system-wide check of both the historical log chain and the current live states.</li>
                                        <li><strong>Status Indication:</strong> If all historical logs and live states match their cryptographic hashes, the system is 100% verified.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Live State Mismatches -->
                        @if ($mismatchedDocuments->isNotEmpty())
                            <div class="bg-orange-50 dark:bg-orange-900/20 pt-3 px-5 pb-5 rounded-lg shadow">
                                <h3 class="text-xl font-bold mb-4 text-orange-600 dark:text-orange-400 border-b border-orange-200 dark:border-orange-700 pb-2">
                                    Live State Mismatches (Tampering Detected)
                                </h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                    The following documents have current database states that do not match their last recorded cryptographic log. This indicates the document details were modified without authorization or outside the normal application flow.
                                </p>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Tracking Code</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Title</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Last Modified</th>
                                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                            @foreach ($mismatchedDocuments as $doc)
                                                <tr>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{{ $doc->tracking_code }}</td>
                                                    <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300">{{ $doc->title }}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                                                        <x-status-badge :status="$doc->status" />
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">{{ $doc->updated_at->diffForHumans() }}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <div class="flex items-center justify-end space-x-3">
                                                            <a href="{{ route('documents.show', $doc->tracking_code) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Investigate</a>
                                                            @if($doc->status !== 'frozen')
                                                                <form action="{{ route('documents.freeze', $doc->tracking_code) }}" method="POST">
                                                                    @csrf
                                                                    <button type="submit" class="text-red-600 hover:text-red-900 dark:text-red-400">Freeze</button>
                                                                </form>
                                                            @else
                                                                <form action="{{ route('documents.unfreeze', $doc->tracking_code) }}" method="POST">
                                                                    @csrf
                                                                    <button type="submit" class="text-green-600 hover:text-green-900 dark:text-green-400">Unfreeze</button>
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
                                    {{ $mismatchedDocuments->links() }}
                                </div>
                            </div>
                        @endif

                        <!-- Section: Mismatched Logs -->
                        @if ($mismatchedLogs->isNotEmpty())
                            <div class="bg-red-50 dark:bg-red-900/20 pt-3 px-5 pb-5 rounded-lg shadow">
                                <h3 class="text-xl font-bold mb-4 text-red-600 dark:text-red-400 border-b border-red-200 dark:border-red-700 pb-2">Mismatched Integrity Logs (Chain Corruption)</h3>
                                <form action="{{ route('system.health') }}" method="GET" class="mb-4">
                                    <div class="flex items-center space-x-4">
                                        <input type="text" name="search" placeholder="Search by tracking code" class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" value="{{ request('search') }}">
                                        <input type="text" name="user" placeholder="Search by user" class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" value="{{ request('user') }}">
                                        <input type="date" name="date" class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" value="{{ request('date') }}">
                                        <select name="per_page" class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                            <option value="10" @if(request('per_page', 10) == 10) selected @endif>10 per page</option>
                                            <option value="25" @if(request('per_page') == 25) selected @endif>25 per page</option>
                                            <option value="50" @if(request('per_page') == 50) selected @endif>50 per page</option>
                                        </select>
                                        <a href="{{ route('system.health') }}" class="text-gray-500 hover:text-gray-700">Clear</a>
                                    </div>
                                </form>
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
                                                            <button type="button" 
                                                                    class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 debug-log-btn" 
                                                                    data-url="{{ route('system.health.debug-log', $log->id) }}">
                                                                Debug
                                                            </button>
                                                            <a href="{{ route('documents.show', $log->document->tracking_code) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200">View</a>
                                                            
                                                            @if($log->document->status === 'frozen')
                                                                <form action="{{ route('documents.unfreeze', $log->document->tracking_code) }}" method="POST" class="unfreeze-form">
                                                                    @csrf
                                                                    <button type="submit" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-200">Unfreeze</button>
                                                                </form>
                                                            @else
                                                                <form action="{{ route('documents.freeze', $log->document->tracking_code) }}" method="POST" class="freeze-form">
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
<div id="debug-hash-modal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-gray-900/75 transition-opacity">
    <div class="relative z-10 w-full max-w-4xl p-6 bg-white dark:bg-gray-800 rounded-lg shadow-xl mx-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4 border-b dark:border-gray-700 pb-2">
            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Hash Integrity Debugger</h3>
            <button id="close-debug-modal" class="text-gray-400 hover:text-gray-500">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                    <h4 class="text-sm font-bold text-red-700 dark:text-red-400 uppercase mb-2">Stored in Database</h4>
                    <p id="stored-hash-val" class="font-mono text-xs break-all dark:text-red-300"></p>
                </div>
                <div class="p-4 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-lg">
                    <h4 class="text-sm font-bold text-indigo-700 dark:text-indigo-400 uppercase mb-2">Recalculated Now</h4>
                    <p id="recalculated-hash-val" class="font-mono text-xs break-all dark:text-indigo-300"></p>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">Hash Formula Components</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Field</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Value used for Hashing</th>
                            </tr>
                        </thead>
                        <tbody id="debug-components-body" class="divide-y divide-gray-200 dark:divide-gray-700 font-mono text-xs">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">Final Concatenated String (SHA-256 Input)</h4>
                <div class="p-4 bg-gray-100 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                    <p id="raw-data-string-val" class="font-mono text-xs break-all dark:text-gray-400"></p>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    @vite(['resources/js/system-health.js'])
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const debugModal = document.getElementById('debug-hash-modal');
            const closeDebugBtn = document.getElementById('close-debug-modal');
            const componentsBody = document.getElementById('debug-components-body');
            const storedHashVal = document.getElementById('stored-hash-val');
            const recalculatedHashVal = document.getElementById('recalculated-hash-val');
            const rawDataStringVal = document.getElementById('raw-data-string-val');

            document.querySelectorAll('.debug-log-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const url = btn.dataset.url;
                    btn.disabled = true;
                    btn.textContent = '...';

                    try {
                        const response = await fetch(url);
                        const data = await response.json();

                        storedHashVal.textContent = data.stored_hash;
                        recalculatedHashVal.textContent = data.recalculated_hash;
                        rawDataStringVal.textContent = data.raw_data_string;

                        componentsBody.innerHTML = '';
                        for (const [key, value] of Object.entries(data.components)) {
                            componentsBody.innerHTML += `
                                <tr>
                                    <td class="px-4 py-2 font-bold text-indigo-600 dark:text-indigo-400">${key}</td>
                                    <td class="px-4 py-2 break-all dark:text-gray-300">${value === null ? '<span class="text-red-500 italic">null</span>' : value}</td>
                                </tr>
                            `;
                        }

                        debugModal.style.display = 'flex';
                    } catch (error) {
                        console.error('Debug error:', error);
                        alert('Failed to fetch debug info.');
                    } finally {
                        btn.disabled = false;
                        btn.textContent = 'Debug';
                    }
                });
            });

            if (closeDebugBtn) closeDebugBtn.addEventListener('click', () => debugModal.style.display = 'none');

            // ... rest of scripts ...

                const viewBtn = document.getElementById('view-failed-jobs');
                const detailsSection = document.getElementById('failed-jobs-details');
                if (viewBtn && detailsSection) {
                    viewBtn.addEventListener('click', () => {
                        detailsSection.classList.toggle('hidden');
                        viewBtn.textContent = detailsSection.classList.contains('hidden') ? 'View Details' : 'Hide Details';
                    });
                }

                // Confirmation Modal Logic
                const confirmForms = document.querySelectorAll('.confirm-action');
                const modal = document.getElementById('confirmation-modal');
                const modalMessage = document.getElementById('confirmation-message');
                const confirmBtn = document.getElementById('confirm-btn');
                const cancelBtn = document.getElementById('cancel-btn');
                let currentForm = null;

                confirmForms.forEach(form => {
                    form.addEventListener('submit', (e) => {
                        e.preventDefault();
                        currentForm = form;
                        modalMessage.textContent = form.dataset.message || 'Are you sure you want to perform this action?';
                        modal.style.display = 'flex';
                    });
                });

                if (confirmBtn) {
                    confirmBtn.addEventListener('click', () => {
                        if (currentForm) currentForm.submit();
                    });
                }

                if (cancelBtn) {
                    cancelBtn.addEventListener('click', () => {
                        modal.style.display = 'none';
                        currentForm = null;
                    });
                }

                // Close modal on click outside
                window.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        modal.style.display = 'none';
                        currentForm = null;
                    }
                });

                const filterForm = document.querySelector('form[action="{{ route("system.health") }}"]');
                if (filterForm) {
                    const inputs = filterForm.querySelectorAll('input, select');
                    inputs.forEach(input => {
                        input.addEventListener('change', () => {
                            filterForm.submit();
                        });
                    });
                }
            });
        </script>
    @endpush

    <!-- Confirmation Modal (Similar to QR Scanner Modal) -->
    <div id="confirmation-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-gray-900/75 transition-opacity">
        <div class="relative z-10 w-full max-w-md p-4 bg-white dark:bg-gray-800 rounded-lg shadow-xl mx-4">
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
                <button id="confirm-btn" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:border-red-700 focus:ring focus:ring-red-200 transition">
                    Yes, Proceed
                </button>
            </div>
        </div>
    </div>
</x-app-layout>
