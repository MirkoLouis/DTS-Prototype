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

                    <!-- Page Heading -->
                    <h3 class="text-2xl font-bold mb-6">System Health & Analytics</h3>

                    <!-- App Health Metrics -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
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
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <h4 class="text-lg font-bold mb-2">Avg. Processing Time</h4>
                            <p class="text-3xl font-semibold">{!! $formattedTime !!}</p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <h4 class="text-lg font-bold mb-2">Failed Jobs</h4>
                            <p class="text-3xl font-semibold @if($appHealthMetrics['failed_jobs_count'] > 0) text-red-500 @endif">{{ $appHealthMetrics['failed_jobs_count'] }}</p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <h4 class="text-lg font-bold mb-2">Cache Status</h4>
                            @if($appHealthMetrics['cache_status'])
                                <p class="text-3xl font-semibold text-green-500">Operational</p>
                            @else
                                <p class="text-3xl font-semibold text-red-500">Not Responding</p>
                            @endif
                        </div>
                    </div>

                    <!-- Database Integrity Section -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="md:col-span-1 bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <h3 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Database Integrity</h3>
                            <div id="integrity-status-container" class="text-center">
                                <div class="text-5xl font-bold text-green-500" id="verified-percentage">{{ $integrityCheckResult['verified_percentage'] }}%</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">Verification Status</div>
                                <div class="text-xs text-gray-400 dark:text-gray-500 mt-1" id="last-checked-at">
                                    Last checked: {{ $integrityCheckResult['last_checked'] instanceof \Carbon\Carbon ? $integrityCheckResult['last_checked']->diffForHumans() : $integrityCheckResult['last_checked'] }}
                                </div>
                            </div>
                            <div class="mt-6 text-center">
                                <button id="run-integrity-check" class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    <svg id="button-spinner" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span id="button-text">Run Verification</span>
                                </button>
                            </div>
                        </div>
                        <div class="md:col-span-2 bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <h3 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">How it Works</h3>
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

                    @if ($mismatchedLogs->isNotEmpty())
                        <div class="mt-8">
                            <h3 class="text-2xl font-bold text-red-500 mb-4">Mismatched Integrity Logs</h3>
                            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                                <div class="p-6 text-gray-900 dark:text-gray-100">
                                    <div class="overflow-x-auto">
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
                                                    <tr class="bg-red-100/10">
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
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Run Integrity Check
            const runCheckButton = document.getElementById('run-integrity-check');
            if (runCheckButton) {
                const buttonSpinner = document.getElementById('button-spinner');
                const buttonText = document.getElementById('button-text');

                runCheckButton.addEventListener('click', function() {
                    buttonSpinner.classList.remove('hidden');
                    buttonText.textContent = 'Verifying...';
                    runCheckButton.disabled = true;

                    fetch('{{ route('system.health.run-check') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            window.location.reload();
                        } else {
                            alert('An error occurred while starting the integrity check.');
                            resetButton();
                        }
                    })
                    .catch(error => {
                        console.error('Error running integrity check:', error);
                        resetButton();
                    });
                });

                function resetButton() {
                    buttonSpinner.classList.add('hidden');
                    buttonText.textContent = 'Run Verification';
                    runCheckButton.disabled = false;
                }
            }

            // Handle form submissions for actions
            document.querySelectorAll('.rebuild-form, .freeze-form, .unfreeze-form').forEach(form => {
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    
                    let confirmationMessage = 'Are you sure you want to proceed with this action?';
                    if (form.classList.contains('rebuild-form')) {
                        confirmationMessage = 'Are you sure you want to rebuild the hash chain from this point? This action cannot be undone and will create a log entry.';
                    } else if (form.classList.contains('freeze-form')) {
                        confirmationMessage = 'Are you sure you want to freeze this document? This will prevent any further actions on it.';
                    } else if (form.classList.contains('unfreeze-form')) {
                        confirmationMessage = 'Are you sure you want to unfreeze this document?';
                    }

                    if (confirm(confirmationMessage)) {
                        submitForm(this);
                    }
                });
            });

            function submitForm(form) {
                const button = form.querySelector('button[type="submit"]');
                button.disabled = true;
                button.textContent = 'Processing...';

                fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' },
                })
                .then(response => response.json().then(data => ({ status: response.status, body: data })))
                .then(response => {
                    alert(response.body.message || (response.status === 200 ? 'Action completed successfully.' : 'An error occurred.'));
                    if (response.status === 200) {
                        window.location.reload();
                    } else {
                        button.disabled = false;
                        // Reset text based on original form class
                        if (form.classList.contains('rebuild-form')) button.textContent = 'Rebuild Chain';
                        else if (form.classList.contains('freeze-form')) button.textContent = 'Freeze';
                        else if (form.classList.contains('unfreeze-form')) button.textContent = 'Unfreeze';
                    }
                })
                .catch(error => {
                    console.error('Form submission error:', error);
                    alert('A network error occurred. Please try again.');
                    button.disabled = false;
                    if (form.classList.contains('rebuild-form')) button.textContent = 'Rebuild Chain';
                    else if (form.classList.contains('freeze-form')) button.textContent = 'Freeze';
                    else if (form.classList.contains('unfreeze-form')) button.textContent = 'Unfreeze';
                });
            }
        });
    </script>
    @endpush
</x-app-layout>
