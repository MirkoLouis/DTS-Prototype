<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Integrity Monitor') }}
        </h2>
    </x-slot>

    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg" id="documents-section">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="mb-6 space-y-4">
                        <h2 class="text-2xl font-bold">All Documents</h2>
                        {{-- Filters and Search --}}
                        <div class="flex flex-row items-end gap-2 pb-4 border-b border-gray-100 dark:border-gray-700 w-full">
                            <div class="flex-grow flex-shrink-0" style="flex-basis: 12%;">
                                <label for="date-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Date Created</label>
                                <input type="date" id="date-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm sm:text-sm">
                            </div>
                            <div class="flex-grow flex-shrink-0" style="flex-basis: 15%;">
                                <label for="status-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                                <select id="status-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm sm:text-sm">
                                    <option value="all">All Statuses</option>
                                    @foreach($statuses as $status)
                                        <option value="{{ $status }}">{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex-grow flex-shrink-0" style="flex-basis: 22%;">
                                <label for="purpose-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Purpose</label>
                                <select id="purpose-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm sm:text-sm">
                                    <option value="all">All Purposes</option>
                                    @foreach($purposes as $purpose)
                                        <option value="{{ $purpose->name }}">{{ $purpose->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex-grow flex-shrink-0" style="flex-basis: 18%;">
                                <label for="submitter-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Submitter Name</label>
                                <input type="text" id="submitter-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm sm:text-sm" placeholder="Search submitter...">
                            </div>
                            <div class="flex-grow flex-shrink-0" style="flex-basis: 22%;">
                                <label for="log-search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Search using Tracking Number or Title</label>
                                <input type="text" id="log-search" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm sm:text-sm" placeholder="Search..." value="{{ request('search') }}">
                            </div>
                            <button id="clear-filters-btn" class="flex-shrink-0 inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-500 active:bg-gray-700 focus:outline-none focus:border-gray-700 focus:ring focus:ring-gray-200 disabled:opacity-25 transition">
                                Clear
                            </button>
                        </div>
                    </div>

                    <div id="log-table-container">
                        @include('general.partials.document-list-table', ['documents' => $documents])
                    </div>

                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            let searchTimeout;

            const logTableContainer = document.getElementById('log-table-container');
            const searchInput = document.getElementById('log-search');
            const statusFilter = document.getElementById('status-filter');
            const purposeFilter = document.getElementById('purpose-filter');
            const submitterFilter = document.getElementById('submitter-filter');
            const dateFilter = document.getElementById('date-filter');
            const clearFiltersBtn = document.getElementById('clear-filters-btn');

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

            function handleFilterChange() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function () {
                    const searchTerm = searchInput.value;
                    const status = statusFilter.value;
                    const purpose = purposeFilter.value;
                    const submitter = submitterFilter.value;
                    const date = dateFilter.value;
                    
                    const url = new URL('{{ route("integrity-monitor") }}');
                    if (searchTerm) url.searchParams.set('search', searchTerm);
                    if (status && status !== 'all') url.searchParams.set('status', status);
                    if (purpose && purpose !== 'all') url.searchParams.set('purpose', purpose);
                    if (submitter) url.searchParams.set('submitter', submitter);
                    if (date) url.searchParams.set('date', date);
                    url.searchParams.set('page', '1');

                    fetchDocuments(url.toString());
                }, 300);
            }

            function clearFilters() {
                searchInput.value = '';
                statusFilter.value = 'all';
                purposeFilter.value = 'all';
                submitterFilter.value = '';
                dateFilter.value = '';
                handleFilterChange();
            }

            function fetchDocuments(url) {
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(response => response.text())
                .then(html => {
                    logTableContainer.innerHTML = html;
                    history.pushState(null, '', url);
                    document.getElementById("documents-section").scrollIntoView({ behavior: "smooth" });
                })
                .catch(error => console.error('Error fetching documents:', error));
            }

            searchInput.addEventListener('keyup', handleFilterChange);
            statusFilter.addEventListener('change', handleFilterChange);
            purposeFilter.addEventListener('change', handleFilterChange);
            submitterFilter.addEventListener('keyup', handleFilterChange);
            dateFilter.addEventListener('change', handleFilterChange);
            clearFiltersBtn.addEventListener('click', clearFilters);

            // AJAX Pagination
            logTableContainer.addEventListener('click', function(event) {
                if (event.target.tagName === 'A' && event.target.closest('.pagination')) {
                    event.preventDefault();
                    const url = event.target.getAttribute('href');
                    if (url) {
                        fetchDocuments(url);
                    }
                }
            });
        });
    </script>
    @endpush
</x-app-layout>
