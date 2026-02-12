<div id="documents-table-body">
    {{-- Desktop Table View --}}
    <div class="overflow-x-auto hidden md:block">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Tracking Code</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Submitter</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Purpose</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date Released</th>
                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($releasedDocuments as $document)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900 dark:text-gray-100 break-all max-w-xs">{{ $document->tracking_code }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 submitter-name">{{ $document->guest_info['name'] ?? 'N/A' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $document->purpose->name }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $document->updated_at->format('M d, Y h:i A') }}</td>
                        <td class="px-6 py-4 text-right text-sm font-medium">
                            <a href="{{ route('track', ['tracking_code' => $document->tracking_code]) }}" target="_blank" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No released documents match your search.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Mobile Card View --}}
    <div class="grid grid-cols-1 md:hidden gap-4">
        @forelse($releasedDocuments as $document)
            <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow space-y-2">
                <div class="font-bold text-lg text-gray-900 dark:text-gray-100">{{ $document->tracking_code }}</div>
                <div class="border-t border-gray-200 dark:border-gray-600 pt-2 mt-2 text-sm text-gray-500 dark:text-gray-400 space-y-1">
                    <p><strong>Submitter:</strong> <span class="submitter-name">{{ $document->guest_info['name'] ?? 'N/A' }}</span></p>
                    <p><strong>Purpose:</strong> {{ $document->purpose->name }}</p>
                    <p><strong>Released:</strong> {{ $document->updated_at->format('M d, Y h:i A') }}</p>
                </div>
                <div class="text-right">
                    <a href="{{ route('track', ['tracking_code' => $document->tracking_code]) }}" target="_blank" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200">View Details</a>
                </div>
            </div>
        @empty
            <div class="text-center py-4 text-gray-500 dark:text-gray-400">No released documents match your search.</div>
        @endforelse
    </div>
</div>

<div id="pagination-links" class="mt-4">
    {{ $releasedDocuments->links() }}
</div>
