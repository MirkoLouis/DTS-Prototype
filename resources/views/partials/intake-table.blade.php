<div id="documents-table-body">
    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
        <thead class="bg-gray-50 dark:bg-gray-700">
            <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Tracking Code</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Submitter</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Purpose</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date Handled</th>
                <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            @forelse($handledLogs as $log)
                @php $document = $log->document; @endphp
                <tr id="document-row-{{ $document->id }}">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900 dark:text-gray-100">{{ $document->tracking_code }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $document->guest_info['name'] }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $document->purpose->name }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                            @switch($document->status)
                                @case('pending') bg-yellow-100 text-yellow-800 @break
                                @case('processing') bg-blue-100 text-blue-800 @break
                                @case('completed') bg-green-100 text-green-800 @break
                                @case('rejected') bg-red-100 text-red-800 @break
                                @default bg-gray-100 text-gray-800
                            @endswitch
                        ">
                            {{ ucfirst($document->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $log->created_at->format('M d, Y h:i A') }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button type="button" class="route-toggle-btn text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200"
                                data-target-id="details-row-{{ $document->id }}">
                            View Route
                        </button>
                    </td>
                </tr>
                <tr id="details-row-{{ $document->id }}" class="details-row" style="display: none;">
                    <td colspan="6" class="p-4 bg-gray-50 dark:bg-gray-900/50">
                        <h4 class="font-bold mb-2 text-gray-800 dark:text-gray-200">Route for {{ $document->tracking_code }}:</h4>
                        @if($document->finalized_route)
                            <x-tracker-subway-map :finalized_route="$document->finalized_route" :current_step="$document->current_step" />
                        @else
                            <p class="text-gray-500 dark:text-gray-400">No finalized route for this document.</p>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No documents match your search.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div id="pagination-links" class="mt-4">
    {{ $handledLogs->links() }}
</div>
