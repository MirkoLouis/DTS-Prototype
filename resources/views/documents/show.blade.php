<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Document Details') }}: {{ $document->tracking_code }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Document Information -->
                        <div>
                            <h3 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Document Information</h3>
                            <div class="space-y-4">
                                <p><strong>Tracking Code:</strong> {{ $document->tracking_code }}</p>
                                <p><strong>Submitter Name:</strong> {{ $document->guest_info['name'] }}</p>
                                <p><strong>Submitter Email:</strong> {{ $document->guest_info['email'] }}</p>
                                <p><strong>Purpose:</strong> {{ $document->purpose->name }}</p>
                                <p><strong>Status:</strong> <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">{{ $document->status }}</span></p>
                                <p><strong>Submitted At:</strong> {{ $document->created_at->format('M d, Y h:i A') }}</p>
                            </div>
                        </div>
                        <!-- Routing Information -->
                        <div>
                            <h3 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Routing</h3>
                            <div class="space-y-4">
                                <p><strong>Current Step:</strong> {{ $document->current_step ?? 'N/A' }} of {{ count($document->finalized_route ?? []) }}</p>
                                <div>
                                    <strong>Finalized Route:</strong>
                                    <ol class="list-decimal list-inside mt-2">
                                        @forelse ($document->finalized_route ?? [] as $step)
                                            <li>{{ $step }}</li>
                                        @empty
                                            <li>No route finalized.</li>
                                        @endforelse
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Document Logs -->
                    <div class="mt-8">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-2xl font-bold">Document History</h3>
                            <a href="{{ route('dashboard') }}" class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-500 active:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                                Back to Dashboard
                            </a>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Timestamp</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Action</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Performed By</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Remarks</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    @forelse ($document->logs as $log)
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">{{ $log->created_at->format('M d, Y h:i A') }}</td>
                                            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300">{{ $log->action }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">{{ $log->user->name ?? 'System' }}</td>
                                            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300">{{ $log->remarks }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500 dark:text-gray-300">No history found.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="mt-8 flex justify-end space-x-4">
                        @if($document->status === 'frozen')
                            <form action="{{ route('documents.unfreeze', $document) }}" method="POST" id="unfreeze-form">
                                @csrf
                                <x-danger-button type="submit">
                                    Unfreeze Document
                                </x-danger-button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const unfreezeForm = document.getElementById('unfreeze-form');
                if (unfreezeForm) {
                    unfreezeForm.addEventListener('submit', function(event) {
                        event.preventDefault();
                        if (confirm('Are you sure you want to unfreeze this document?')) {
                            const button = unfreezeForm.querySelector('button[type="submit"]');
                            button.disabled = true;
                            button.textContent = 'Unfreezing...';

                            fetch(unfreezeForm.action, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' },
                            })
                            .then(response => response.json().then(data => ({ status: response.status, body: data })))
                            .then(response => {
                                alert(response.body.message || (response.status === 200 ? 'Action completed.' : 'An error occurred.'));
                                if (response.status === 200) {
                                    window.location.reload();
                                } else {
                                    button.disabled = false;
                                    button.textContent = 'Unfreeze Document';
                                }
                            })
                            .catch(error => {
                                console.error('Unfreeze error:', error);
                                alert('A network error occurred. Please try again.');
                                button.disabled = false;
                                button.textContent = 'Unfreeze Document';
                            });
                        }
                    });
                }
            });
        </script>
    @endpush
</x-app-layout>
