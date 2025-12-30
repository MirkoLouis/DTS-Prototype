<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Document Intake') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Find Document Section --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h3 class="text-2xl font-bold mb-4">Find Document by Tracking Code</h3>
                    <p class="mb-6 text-gray-600 dark:text-gray-400">Enter the tracking code from the client's QR code or receipt to begin processing.</p>

                    {{-- Session Messages --}}
                    @if (session('error'))
                        <div id="intake-error-alert" class="mb-4 p-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400 transition-opacity duration-500 ease-out" role="alert">
                            <span class="font-medium">Error!</span> {{ session('error') }}
                        </div>
                    @endif
                    @if (session('success'))
                        <div id="intake-success-alert" class="mb-4 p-4 text-sm text-green-800 rounded-lg bg-green-50 dark:bg-gray-800 dark:text-green-400 transition-opacity duration-500 ease-out" role="alert">
                            <span class="font-medium">Success!</span> {{ session('success') }}
                        </div>
                    @endif

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

            {{-- Recently Handled Documents Section --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-2xl font-bold">Recently Handled Documents</h3>
                        <div class="w-1/3">
                            <label for="table-search" class="sr-only">Search</label>
                            <input type="text" id="table-search" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" placeholder="Search...">
                        </div>
                    </div>
                    
                    <div id="documents-container" class="overflow-x-auto">
                        @include('partials.intake-table', ['handledLogs' => $handledLogs])
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div id="qr-scanner-modal" class="qr-modal">
        <div class="qr-modal-content">
            <span id="close-qr-modal" class="qr-modal-close">&times;</span>
            <div id="qr-reader" style="width: 100%;"></div>
        </div>
    </div>

    <style>
        .qr-modal {
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgb(0,0,0); 
            background-color: rgba(0,0,0,0.4);
        }
        .qr-modal-content {
            background-color: #fefefe;
            margin: 15% auto; 
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            position: relative;
        }
        .qr-modal-close {
            color: #aaa;
            float: right;
            font-size: 36px;
            font-weight: bold;
            position: absolute;
            top: -15px;
            right: 0px;
        }
        .qr-modal-close:hover,
        .qr-modal-close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
    </style>

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
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
                } catch (error) {
                    console.error('Fetch error:', error);
                    documentsContainer.innerHTML = '<tr><td colspan="6" class="text-center py-4">Failed to load documents. Please try again.</td></tr>';
                }
            };

            // Live search logic
            const searchInput = document.getElementById('table-search');
            let debounceTimer;
            searchInput.addEventListener('keyup', (e) => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    const searchTerm = e.target.value;
                    const url = new URL('{{ route("intake") }}'); // Use route for base URL
                    url.searchParams.set('search', searchTerm);
                    url.searchParams.set('page', '1'); // Reset to page 1 on new search
                    fetchDocuments(url.toString());
                }, 300); // 300ms debounce
            });

            // AJAX pagination and route-toggle logic using event delegation
            documentsContainer.addEventListener('click', (e) => {
                // Handle clicks on pagination links
                if (e.target.tagName === 'A' && e.target.closest('.pagination')) {
                    e.preventDefault();
                    const url = e.target.getAttribute('href');
                    if (url) {
                        fetchDocuments(url);
                    }
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
            const POLLING_INTERVAL = 15000; // 15 seconds
            setInterval(() => {
                // Only poll if the user is not actively typing in the search box
                if (document.activeElement !== searchInput) {
                    fetchDocuments(window.location.href);
                }
            }, POLLING_INTERVAL);

            // Auto-hide session error alerts
            const errorAlert = document.getElementById('intake-error-alert');
            if (errorAlert) {
                setTimeout(() => {
                    errorAlert.style.opacity = '0';
                    // Remove from DOM after transition
                    setTimeout(() => {
                        errorAlert.remove();
                    }, 500); // Must match transition duration
                }, 2000); // 2 seconds
            }

            // Auto-hide session success alerts
            const successAlert = document.getElementById('intake-success-alert');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.opacity = '0';
                    // Remove from DOM after transition
                    setTimeout(() => {
                        successAlert.remove();
                    }, 500); // Must match transition duration
                }, 2000); // 2 seconds
            }

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
