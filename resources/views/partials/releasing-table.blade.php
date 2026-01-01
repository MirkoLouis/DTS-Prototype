{{-- Desktop Table View --}}
<div class="overflow-x-auto hidden md:block">
    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
        <thead class="bg-gray-50 dark:bg-gray-700">
            <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Tracking Code
                </th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Submitter
                </th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Purpose
                </th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Status
                </th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Completed Processing
                </th>
                <th scope="col" class="relative px-6 py-3">
                    <span class="sr-only">Actions</span>
                </th>
            </tr>
        </thead>
        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            @forelse ($documents as $document)
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ $document->tracking_code }}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300 break-words">
                        {{ $document->guest_info['name'] }}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300 break-words max-w-xs">
                        {{ $document->purpose->name }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                            Awaiting Release
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                        {{ $document->updated_at->format('M d, Y h:i A') }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <form method="POST" action="{{ route('releasing.complete', $document) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                                Release Document
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300 text-center">
                        No documents are currently awaiting release.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Mobile Card View --}}
<div class="grid grid-cols-1 md:hidden gap-4">
    @forelse ($documents as $document)
        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg shadow">
            <div class="flex justify-between items-start mb-3">
                <div>
                    <div class="font-bold text-lg text-gray-900 dark:text-gray-100">{{ $document->tracking_code }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $document->purpose->name }}</div>
                </div>
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300">
                    Awaiting Release
                </span>
            </div>
            
            <div class="border-t border-gray-200 dark:border-gray-600 pt-3 text-sm text-gray-500 dark:text-gray-400 space-y-2">
                <p><strong>Submitter:</strong> {{ $document->guest_info['name'] }}</p>
                <p><strong>Completed Processing:</strong> {{ $document->updated_at->format('M d, Y h:i A') }}</p>
            </div>

            <div class="mt-4">
                <form method="POST" action="{{ route('releasing.complete', $document) }}">
                    @csrf
                    <button type="submit" class="w-full inline-flex items-center justify-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                        Release Document
                    </button>
                </form>
            </div>
        </div>
    @empty
        <div class="text-center py-4 text-gray-500 dark:text-gray-300">
            No documents are currently awaiting release.
        </div>
    @endforelse
</div>
<div class="mt-4">
    {{ $documents->links() }}
</div>
