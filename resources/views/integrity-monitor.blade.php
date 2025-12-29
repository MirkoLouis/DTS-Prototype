<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Integrity Monitor') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="text-2xl font-bold mb-4">Document Log Integrity</h2>
                    
                    {{-- Desktop Table View --}}
                    <div class="overflow-x-auto hidden md:block">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                        Document
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                        Action
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                        Performed By
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                        Hash
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                        Previous Hash
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse ($logs as $log)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100 align-top">
                                            {{ $log->document->tracking_code }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300 align-top">
                                            {{ $log->action }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300 align-top">
                                            {{ $log->user->name ?? 'System' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300 font-mono break-all max-w-xs">
                                            <div class="flex items-start space-x-2">
                                                <span id="hash-{{ $log->id }}">{{ $log->hash }}</span>
                                                <button type="button" class="copy-btn shrink-0 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" data-clipboard-target="#hash-{{ $log->id }}" title="Copy hash">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" /><path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z" /></svg>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300 font-mono break-all max-w-xs">
                                             <div class="flex items-start space-x-2">
                                                <span id="prev-hash-{{ $log->id }}">{{ $log->previous_hash }}</span>
                                                <button type="button" class="copy-btn shrink-0 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" data-clipboard-target="#prev-hash-{{ $log->id }}" title="Copy previous hash">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" /><path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z" /></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300 text-center">
                                            No document logs found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Mobile Card View --}}
                    <div class="grid grid-cols-1 gap-4 md:hidden">
                        @forelse ($logs as $log)
                            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg shadow space-y-3">
                                <div class="space-y-1">
                                    <div class="font-bold text-gray-900 dark:text-gray-100">{{ $log->document->tracking_code }}</div>
                                    <div class="text-sm text-gray-600 dark:text-gray-300">{{ $log->action }}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        <strong>Performed By:</strong> {{ $log->user->name ?? 'System' }}
                                    </div>
                                </div>
                                <div class="border-t border-gray-200 dark:border-gray-600 pt-3 font-mono text-xs">
                                    <div class="flex justify-between items-start">
                                        <div class="text-gray-500 dark:text-gray-400"><strong>Hash:</strong></div>
                                        <button type="button" class="copy-btn shrink-0 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" data-clipboard-target="#hash-mobile-{{ $log->id }}" title="Copy hash">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" /><path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z" /></svg>
                                        </button>
                                    </div>
                                    <div id="hash-mobile-{{ $log->id }}" class="text-gray-800 dark:text-gray-200 break-all">{{ $log->hash }}</div>
                                </div>
                                <div class="border-t border-gray-200 dark:border-gray-600 pt-2 font-mono text-xs">
                                    <div class="flex justify-between items-start">
                                        <div class="text-gray-500 dark:text-gray-400"><strong>Previous Hash:</strong></div>
                                         <button type="button" class="copy-btn shrink-0 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" data-clipboard-target="#prev-hash-mobile-{{ $log->id }}" title="Copy previous hash">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" /><path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z" /></svg>
                                        </button>
                                    </div>
                                    <div id="prev-hash-mobile-{{ $log->id }}" class="text-gray-800 dark:text-gray-200 break-all">{{ $log->previous_hash }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-4 text-gray-500 dark:text-gray-300">
                                No document logs found.
                            </div>
                        @endforelse
                    </div>

                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const copyButtons = document.querySelectorAll('.copy-btn');

            copyButtons.forEach(button => {
                button.addEventListener('click', function () {
                    const targetSelector = this.dataset.clipboardTarget;
                    const textToCopy = document.querySelector(targetSelector).innerText;

                    if (!navigator.clipboard) {
                        // Fallback for older/insecure browsers
                        const textArea = document.createElement("textarea");
                        textArea.value = textToCopy;
                        textArea.style.position = "fixed";
                        textArea.style.left = "-9999px";
                        document.body.appendChild(textArea);
                        textArea.focus();
                        textArea.select();
                        try {
                            document.execCommand('copy');
                        } catch (err) {
                            console.error('Fallback: Oops, unable to copy', err);
                        }
                        document.body.removeChild(textArea);
                        return;
                    }

                    navigator.clipboard.writeText(textToCopy).then(() => {
                        const originalIcon = this.innerHTML;
                        // Checkmark icon
                        this.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>';
                        setTimeout(() => {
                            this.innerHTML = originalIcon;
                        }, 2000); // Revert back after 2 seconds
                    }).catch(err => {
                        console.error('Could not copy text: ', err);
                    });
                });
            });
        });
    </script>
</x-app-layout>
