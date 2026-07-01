<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Document Intake') }}
        </h2>
    </x-slot>

    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Find Document Section --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h3 class="text-2xl font-bold mb-4">Add Document by Tracking Code</h3>
                    <p class="mb-6 text-gray-600 dark:text-gray-400">Enter the tracking code from the client's QR code or receipt to begin processing.</p>

                    <form action="{{ route('intake.find') }}" method="POST">
                        @csrf
                        <div>
                            <label for="tracking_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tracking Code</label>
                            <div class="mt-1 flex rounded-md shadow-sm">
                                <input type="text" name="tracking_code" id="tracking_code" class="block w-full rounded-none rounded-l-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="DEPED-XXXXXXXXXX" required>
                                <button type="submit" class="relative -ml-px inline-flex items-center space-x-2 rounded-r-md border border-gray-300 bg-gray-50 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 dark:border-gray-600">
                                    <span>Find</span>
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <button id="scan-qr-button" class="mt-4 inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:border-indigo-700 focus:ring focus:ring-indigo-200 disabled:opacity-25 transition">
                        Scan QR Code
                    </button>
                </div>
            </div>

            {{-- Recently Added Documents Section --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg" id="documents-section">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="mb-6 space-y-4">
                        <h3 class="text-2xl font-bold">Recently Added Documents</h3>
                        {{-- Filters and Search --}}
                        <div class="flex flex-row items-end gap-2 pb-4 border-b border-gray-100 dark:border-gray-700 w-full">
                            <div class="flex-grow flex-shrink-0" style="flex-basis: 12%;">
                                <label for="date-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Date Handled</label>
                                <input type="date" id="date-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm sm:text-sm">
                            </div>
                            <div class="flex-grow flex-shrink-0" style="flex-basis: 15%;">
                                <label for="status-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                                <select id="status-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm sm:text-sm">
                                    <option value="all">All Statuses</option>
                                    @foreach($statuses as $status)
                                        <option value="{{ $status }}">{{ ucfirst($status) }}</option>
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
                                <label for="table-search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Search using Tracking Number or Title</label>
                                <input type="text" id="table-search" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm sm:text-sm" placeholder="Search...">
                            </div>
                            <button id="clear-filters-btn" class="flex-shrink-0 inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-500 active:bg-gray-700 focus:outline-none focus:border-gray-700 focus:ring focus:ring-gray-200 disabled:opacity-25 transition">
                                Clear
                            </button>
                        </div>
                    </div>
                    
                    <div id="documents-container" class="overflow-x-auto">
                        @include('general.partials.intake-table', ['handledLogs' => $handledLogs])
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <x-qr-scanner-modal />

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const documentsContainer = document.getElementById('documents-container');

            // Function to handle fetching and updating the table
            const fetchDocuments = async (url) => {
                try {
                    const response = await fetch(url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    if (!response.ok) throw new Error('Network response was not ok.');
                    
                    const html = await response.text();
                    documentsContainer.innerHTML = html;
                    history.pushState(null, '', url);
                    // Center the section in view if it's not already well-positioned
                    document.getElementById("documents-section").scrollIntoView({ behavior: "smooth"});
                } catch (error) {
                    console.error('Fetch error:', error);
                    documentsContainer.innerHTML = '<tr><td colspan="6" class="text-center py-4">Failed to load documents. Please try again.</td></tr>';
                }
            };

            // Combined search and filter logic
            const searchInput = document.getElementById('table-search');
            const statusFilter = document.getElementById('status-filter');
            const purposeFilter = document.getElementById('purpose-filter');
            const submitterFilter = document.getElementById('submitter-filter');
            const dateFilter = document.getElementById('date-filter');
            const clearFiltersBtn = document.getElementById('clear-filters-btn');

            let debounceTimer;

            function handleFilterChange() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    const searchTerm = searchInput.value;
                    const status = statusFilter.value;
                    const purpose = purposeFilter.value;
                    const submitter = submitterFilter.value;
                    const date = dateFilter.value;
                    
                    const url = new URL('{{ route("intake") }}');
                    if (searchTerm) url.searchParams.set('search', searchTerm);
                    if (status && status !== 'all') url.searchParams.set('status', status);
                    if (purpose && purpose !== 'all') url.searchParams.set('purpose', purpose);
                    if (submitter) url.searchParams.set('submitter', submitter);
                    if (date) url.searchParams.set('date_handled', date);
                    url.searchParams.set('page', '1'); // Reset to page 1 on new search/filter

                    fetchDocuments(url.toString());
                }, 300); // 300ms debounce
            }

            function clearFilters() {
                searchInput.value = '';
                statusFilter.value = 'all';
                purposeFilter.value = 'all';
                submitterFilter.value = '';
                dateFilter.value = '';
                handleFilterChange();
            }

            searchInput.addEventListener('keyup', handleFilterChange);
            statusFilter.addEventListener('change', handleFilterChange);
            purposeFilter.addEventListener('change', handleFilterChange);
            submitterFilter.addEventListener('keyup', handleFilterChange);
            dateFilter.addEventListener('change', handleFilterChange);
            clearFiltersBtn.addEventListener('click', clearFilters);


            // AJAX pagination and route-toggle logic using event delegation
            documentsContainer.addEventListener('click', (e) => {
                // Handle clicks on pagination links
                const paginationLink = e.target.closest('#pagination-links a');
                if (paginationLink) {
                    e.preventDefault();
                    const url = paginationLink.getAttribute('href');
                    if (url && url !== '#') {
                        fetchDocuments(url);
                    }
                    return;
                }

                // Handle clicks on route toggle buttons
                if (e.target.classList.contains('route-toggle-btn')) {
                    const targetId = e.target.getAttribute('data-target-id');
                    const targetRow = document.getElementById(targetId);
                    
                    if (targetRow) {
                        const isHidden = targetRow.style.display === 'none';

                        // Close all other open rows
                        documentsContainer.querySelectorAll('.details-row').forEach(openRow => {
                            if (openRow.id !== targetId) {
                                openRow.style.display = 'none';
                                const otherButton = documentsContainer.querySelector(`[data-target-id="${openRow.id}"]`);
                                if (otherButton) otherButton.textContent = 'View Route';
                            }
                        });

                        // Toggle the target row
                        targetRow.style.display = isHidden ? 'table-row' : 'none';
                        e.target.textContent = isHidden ? 'Hide Route' : 'View Route';
                    }
                }
            });

            // AJAX polling for live updates
            const POLLING_INTERVAL = 60000; // 60 seconds
            setInterval(() => {
                // Only poll if the user is not actively typing in the search box or date picker
                if (document.activeElement !== searchInput && document.activeElement !== dateFilter) {
                    // Re-apply filters on poll
                    handleFilterChange();
                }
            }, POLLING_INTERVAL);

            // QR Code Scanning Logic
            const scanQrButton = document.getElementById('scan-qr-button');
            const qrScannerModal = document.getElementById('qr-scanner-modal');
            const closeQrModal = document.getElementById('close-qr-modal');
            const qrReaderDiv = document.getElementById('qr-reader');
            const trackingCodeInput = document.getElementById('tracking_code');
            const intakeForm = document.querySelector('form[action="{{ route('intake.find') }}"]');
            let html5QrCode = null;

            function onScanSuccess(decodedText, decodedResult) {
                trackingCodeInput.value = decodedText;
                stopQrCodeScanner();
                intakeForm.submit();
            }

            function onScanError(errorMessage) {
                // handle scan error as you like
                console.warn(`QR Code Scan Error: ${errorMessage}`);
            }

            function startQrCodeScanner() {
                qrScannerModal.style.display = 'block';
                if (!html5QrCode) {
                    html5QrCode = new Html5Qrcode("qr-reader");
                }
                html5QrCode.start(
                    { facingMode: "environment" },
                    { fps: 10, qrbox: { width: 250, height: 250 } },
                    onScanSuccess,
                    onScanError
                ).catch(err => {
                    alert("Error starting QR scanner. Please ensure camera access is granted and refresh the page.");
                    stopQrCodeScanner();
                });
            }

            function stopQrCodeScanner() {
                if (html5QrCode && html5QrCode.isScanning) {
                    html5QrCode.stop().catch(err => {
                        console.error("Error stopping the QR scanner.", err);
                    });
                }
                qrScannerModal.style.display = 'none';
            }

            scanQrButton.addEventListener('click', startQrCodeScanner);
            closeQrModal.addEventListener('click', stopQrCodeScanner);

            // Close modal if user clicks on the overlay
            window.addEventListener('click', function(event) {
                if (event.target == qrScannerModal) {
                    stopQrCodeScanner();
                }
            });
        });
    </script>
</x-app-layout>
