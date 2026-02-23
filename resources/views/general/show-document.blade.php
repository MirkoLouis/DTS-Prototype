<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Document Details') }}: {{ $document->tracking_code }}
        </h2>
    </x-slot>

    <div class="py-2">
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
                                <p><strong>District:</strong> {{ $document->district }}</p>
                                <p><strong>Purpose:</strong> {{ $document->purpose->name }}</p>
                                <p><strong>Status:</strong> <x-status-badge :status="$document->status" /></p>
                                <p><strong>Submitted At:</strong> {{ $document->created_at->format('M d, Y h:i A') }}</p>
                            </div>
                        </div>
                        <!-- Routing Information -->
                        <div>
                            <h3 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Routing</h3>
                            
                            <p class="mb-2"><strong>Status:</strong> <x-status-badge :status="$document->status" /></p>

                            @if($document->status == 'declined')
                                <p class="mt-2"><strong>Reason:</strong> {{ $document->decline_reason }}</p>
                            @elseif($document->status == 'pending')
                                <p class="mt-2 text-gray-500 dark:text-gray-400">The route will be finalized upon intake.</p>
                            @else
                                {{-- For all other statuses, including 'completed', show the route that was taken --}}
                                @php
                                    $displayStep = $document->status === 'completed' 
                                        ? count($document->display_route_objects) + 1 
                                        : $document->display_current_step;
                                @endphp
                                <h4 class="font-semibold text-md text-gray-700 dark:text-gray-300 mt-4">Document Path:</h4>
                                <div class="mt-2">
                                    <x-tracker-subway-map :route_objects="$document->display_route_objects" :current_step="$displayStep" />
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Document Logs -->
                    <div class="mt-8">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-2xl font-bold">Document History</h3>
                            <a href="{{ $backUrl ?? route('integrity-monitor') }}" class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-500 active:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                                Back
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
                                            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300">{!! $log->formatted_remarks !!}</td>
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
