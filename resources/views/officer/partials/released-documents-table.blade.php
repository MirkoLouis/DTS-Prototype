<div id="documents-table-body">
    {{-- Desktop Table View --}}
    <div class="overflow-x-auto hidden md:block">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-fixed">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider w-[25%]">Tracking Code</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider w-[20%]">Submitter</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider w-[20%]">Purpose</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider w-[15%]">District</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider w-[20%]">Date Released</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($releasedDocuments as $document)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900 dark:text-gray-100 break-all">
                            <div>{{ $document->tracking_code }}</div>
                            <a href="{{ route('documents.show', $document) }}" target="_blank" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200 text-xs">View Details</a>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 submitter-name">{{ $document->guest_info['name'] ?? 'N/A' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $document->purpose->name }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $document->district }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $document->updated_at->format('M d, Y h:i A') }}</td>
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
                    <p><strong>District:</strong> {{ $document->district }}</p>
                    <p><strong>Released:</strong> {{ $document->updated_at->format('M d, Y h:i A') }}</p>
                </div>
                <div class="text-right">
                    <a href="{{ route('documents.show', $document) }}" target="_blank" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200">View Details</a>
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
