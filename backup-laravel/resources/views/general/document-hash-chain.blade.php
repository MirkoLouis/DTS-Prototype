<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Hash Chain for Document') }}: {{ $document->tracking_code }}
        </h2>
    </x-slot>

    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="mb-6 flex justify-between items-center">
                        <h3 class="text-lg font-bold">Document: {{ $document->tracking_code }}</h3>
                        <a href="{{ route('documents.show', ['document' => $document, 'back_to' => $back_to]) }}" class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-500 active:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                            Back to Document
                        </a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Timestamp</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Action</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Performed By</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Previous Hash</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Current Hash</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse ($logs as $log)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">{{ $log->created_at->format('M d, Y h:i A') }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300">{{ $log->action }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">{{ $log->user->name ?? 'System' }}</td>
                                        <td class="px-6 py-4 text-sm font-mono text-gray-500 dark:text-gray-300 break-all">
                                            <div class="flex items-center">
                                                <span id="prev-hash-{{ $log->id }}">{{ $log->previous_hash }}</span>
                                                <button type="button" class="copy-btn ml-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" data-clipboard-target="#prev-hash-{{ $log->id }}">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm font-mono text-gray-500 dark:text-gray-300 break-all">
                                            <div class="flex items-center">
                                                <span id="curr-hash-{{ $log->id }}">{{ $log->hash }}</span>
                                                <button type="button" class="copy-btn ml-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" data-clipboard-target="#curr-hash-{{ $log->id }}">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500 dark:text-gray-300">No hash chain history found for this document.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.body.addEventListener('click', function(e) {
                if (e.target.closest('.copy-btn')) {
                    const button = e.target.closest('.copy-btn');
                    handleCopy(button);
                }
            });

            function handleCopy(button) {
                const targetSelector = button.dataset.clipboardTarget;
                const textToCopy = document.querySelector(targetSelector).innerText;

                if (!navigator.clipboard) {
                    fallbackCopyTextToClipboard(textToCopy);
                    showCopySuccess(button);
                    return;
                }

                navigator.clipboard.writeText(textToCopy).then(() => {
                    showCopySuccess(button);
                }).catch(err => {
                    console.error('Could not copy text: ', err);
                });
            }

            function fallbackCopyTextToClipboard(text) {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed"; // Avoid scrolling to bottom
                textArea.style.opacity = 0;
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                } catch (err) {
                    console.error('Fallback: Oops, unable to copy', err);
                }
                document.body.removeChild(textArea);
            }

            function showCopySuccess(button) {
                const originalIcon = button.innerHTML;
                button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>';
                setTimeout(() => {
                    button.innerHTML = originalIcon;
                }, 2000);
            }
        });
    </script>
    @endpush
</x-app-layout>
