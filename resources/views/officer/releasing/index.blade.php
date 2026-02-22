<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Document Releasing') }}
        </h2>
    </x-slot>

    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Receive Document for Releasing Section --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h3 class="text-2xl font-bold mb-4">Receive Document for Releasing</h3>
                    <p class="mb-6 text-gray-600 dark:text-gray-400">Scan or enter the tracking code of a document that has completed its route to add it to the releasing queue.</p>

                    <form id="scan-form" action="{{ route('releasing.receive') }}" method="POST">
                        @csrf
                        <div>
                            <label for="tracking_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tracking Code</label>
                            <div class="mt-1 flex rounded-md shadow-sm">
                                <input type="text" name="tracking_code" id="tracking_code" class="block w-full rounded-none rounded-l-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="DEPED-XXXXXXXXXX" required>
                                <button type="submit" class="relative -ml-px inline-flex items-center space-x-2 rounded-r-md border border-gray-300 bg-gray-50 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 dark:border-gray-600">
                                    <span>Receive</span>
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <button id="scan-qr-button" class="mt-4 inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:border-indigo-700 focus:ring focus:ring-indigo-200 disabled:opacity-25 transition">
                        Scan QR Code
                    </button>
                </div>
            </div>
            
            {{-- Documents Awaiting Release Section --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg" id="releasing-section">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="text-2xl font-bold mb-4">Documents Awaiting Release</h2>
                    
                    {{-- This container will hold the table of documents --}}
                    <div id="releasing-container">
                        @include('general.partials.releasing-table', ['documents' => $documents])
                    </div>

                </div>
            </div>
        </div>
    </div>

    <x-qr-scanner-modal />

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // QR Code Scanning Logic
            const scanQrButton = document.getElementById('scan-qr-button');
            const qrScannerModal = document.getElementById('qr-scanner-modal');
            const closeQrModal = document.getElementById('close-qr-modal');
            const trackingCodeInput = document.getElementById('tracking_code');
            const scanForm = document.getElementById('scan-form');
            let html5QrCode = null;

            function onScanSuccess(decodedText, decodedResult) {
                trackingCodeInput.value = decodedText;
                stopQrCodeScanner();
                scanForm.submit();
            }

            function onScanError(errorMessage) {
                // handle scan error if needed
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
            window.addEventListener('click', function(event) {
                if (event.target == qrScannerModal) {
                    stopQrCodeScanner();
                }
            });

            // AJAX Fetching and Pagination Logic
            const releasingContainer = document.getElementById('releasing-container');

            const fetchReleasingDocuments = async (url) => {
                try {
                    const response = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!response.ok) throw new Error('Network response was not ok.');
                    
                    const html = await response.text();
                    releasingContainer.innerHTML = html;
                    
                    // Only push state if the URL is different from current to avoid redundant entries
                    if (window.location.href !== url) {
                        history.pushState(null, '', url);
                    }
                    
                    // Smooth scroll to the table section
                    document.getElementById("releasing-section").scrollIntoView({ behavior: "smooth"});
                } catch (error) {
                    console.error('Fetch error:', error);
                }
            };

            // Intercept pagination link clicks
            releasingContainer.addEventListener('click', (e) => {
                const paginationLink = e.target.closest('#pagination-links a');
                if (paginationLink) {
                    e.preventDefault();
                    const url = paginationLink.getAttribute('href');
                    if (url && url !== '#') {
                        fetchReleasingDocuments(url);
                    }
                }
            });

            // AJAX Polling for the releasing list
            const POLLING_INTERVAL = 60000; // 60 seconds
            setInterval(() => {
                // We refresh the current page, maintaining any page parameter in the URL
                fetchReleasingDocuments(window.location.href);
            }, POLLING_INTERVAL);
        });
    </script>
    @endpush
</x-app-layout>
