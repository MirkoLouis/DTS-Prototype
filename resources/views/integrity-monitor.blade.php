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
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-2xl font-bold">Document Log Integrity</h2>
                        <div class="w-1/3">
                            <form id="log-search-form">
                                <label for="log-search" class="sr-only">Search</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <input type="text" name="search" id="log-search" class="block w-full pl-10 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md leading-5 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-200 placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:placeholder-gray-400 dark:focus:placeholder-gray-500 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Search logs..." value="{{ request('search') }}">
                                </div>
                            </form>
                        </div>
                    </div>

                    <div id="log-table-container">
                        @include('partials.integrity-log-table', ['logs' => $logs])
                    </div>

                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            let searchTimeout;

            // Delegated event listener for copy buttons
            document.body.addEventListener('click', function(e) {
                if (e.target.closest('.copy-btn')) {
                    const button = e.target.closest('.copy-btn');
                    handleCopy(button);
                }
            });

            // Function to handle copy logic
            function handleCopy(button) {
                const targetSelector = button.dataset.clipboardTarget;
                const textToCopy = document.querySelector(targetSelector).innerText;

                if (!navigator.clipboard) {
                    const textArea = document.createElement("textarea");
                    textArea.value = textToCopy;
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    try {
                        document.execCommand('copy');
                        showCopySuccess(button);
                    } catch (err) {
                        console.error('Fallback: Oops, unable to copy', err);
                    }
                    document.body.removeChild(textArea);
                    return;
                }

                navigator.clipboard.writeText(textToCopy).then(() => {
                    showCopySuccess(button);
                }).catch(err => {
                    console.error('Could not copy text: ', err);
                });
            }

            function showCopySuccess(button) {
                const originalIcon = button.innerHTML;
                button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>';
                setTimeout(() => {
                    button.innerHTML = originalIcon;
                }, 2000);
            }

            // AJAX Search
            const searchInput = document.getElementById('log-search');
            searchInput.addEventListener('keyup', function () {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function () {
                    fetchLogs(searchInput.value);
                }, 300);
            });

            function fetchLogs(query) {
                const url = `{{ route('integrity-monitor') }}?search=${encodeURIComponent(query)}`;
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(response => response.text())
                .then(html => {
                    document.getElementById('log-table-container').innerHTML = html;
                })
                .catch(error => console.error('Error fetching logs:', error));
            }

            // AJAX Pagination
            document.body.addEventListener('click', function(event) {
                if (event.target.matches('.pagination a')) {
                    event.preventDefault();
                    const url = event.target.href;
                    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(response => response.text())
                    .then(html => {
                        document.getElementById('log-table-container').innerHTML = html;
                    })
                    .catch(error => console.error('Error fetching page:', error));
                }
            });
        });
    </script>
    @endpush
</x-app-layout>
