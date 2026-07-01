<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Documents</title>

    <!-- Theme Detection Script -->
    <script>
        (function() {
            const theme = localStorage.getItem('theme');
            const isDark = theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.documentElement.classList.add('dark');
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        })();
    </script>

    @vite(['resources/scss/bootstrap.scss', 'resources/js/bootstrap_public.js'])
    <style>
        html {
            overflow-y: scroll;
        }
        .subway-map-wrapper { padding-top: 1rem; padding-bottom: 1rem; }
        .theme-switcher-fixed {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1050;
        }
    </style>
</head>
<body>
    <div class="theme-switcher-fixed">
        <x-theme-switcher />
    </div>
    <div class="container-lg mt-5 mb-5">
        <div class="text-center mb-4">
            <img src="{{ asset('images/logoipsum-411.png') }}" alt="DepEd Logo" style="height: 80px;">
        </div>

        <div id="tracked-documents-container">
            @forelse($documents as $document)
                <x-document-card :document="$document" />
            @empty
                <div class="alert alert-info text-center card shadow-sm p-4">
                    <h4 class="alert-heading">No documents are being tracked yet.</h4>
                    <p>Enter a tracking code below to get started.</p>
                </div>
            @endforelse
        </div>

        <div class="text-center mt-4 bg-light p-3 rounded shadow-sm">
            <div class="d-grid gap-2">
                <a href="{{ route('welcome') }}" class="btn btn-secondary">Submit a New Document</a>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#trackAnotherModal">
                    Track Another Document
                </button>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="trackAnotherModal" tabindex="-1" aria-labelledby="trackAnotherModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="track-another-form">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5" id="trackAnotherModalLabel">Track Another Document</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="tracking_code_input" class="form-label">Enter Tracking Code:</label>
                            <input type="text" class="form-control" id="tracking_code_input" placeholder="e.g., DEPED-A1B2C3D4E5" required>
                        </div>
                        <div id="track-error-message" class="alert alert-danger d-none" role="alert"></div>
                        
                        <div class="text-center my-3">
                            <button type="button" id="scan-qr-button" class="btn btn-outline-primary">
                                <i class="bi bi-qr-code-scan"></i> Scan QR Code
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Track</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- QR Scanner Modal --}}
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
            z-index: 1056; /* Higher than Bootstrap modal z-index */
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            overflow: auto; 
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
    
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const trackForm = document.getElementById('track-another-form');
            const trackAnotherModal = new bootstrap.Modal(document.getElementById('trackAnotherModal'));
            const trackingCodeInput = document.getElementById('tracking_code_input');
            const trackedDocumentsContainer = document.getElementById('tracked-documents-container');
            const trackErrorMessage = document.getElementById('track-error-message');

            // QR Scanner Elements
            const scanQrButton = document.getElementById('scan-qr-button');
            const qrScannerModal = document.getElementById('qr-scanner-modal');
            const closeQrModal = document.getElementById('close-qr-modal');
            let html5QrCode = null;

            function displayError(message) {
                trackErrorMessage.textContent = message;
                trackErrorMessage.classList.remove('d-none');
            }

            function clearError() {
                trackErrorMessage.classList.add('d-none');
                trackErrorMessage.textContent = '';
            }

            // Clear error message when modal is opened or input changes
            document.getElementById('trackAnotherModal').addEventListener('show.bs.modal', clearError);
            trackingCodeInput.addEventListener('input', clearError);

            async function trackDocument(trackingCode) {
                clearError();
                if (!trackingCode) {
                    displayError('Please enter a tracking code.');
                    return;
                }

                const urlParams = new URLSearchParams(window.location.search);
                let currentCodes = urlParams.get('codes') ? urlParams.get('codes').split(',') : [];
                
                if (currentCodes.includes(trackingCode)) {
                    displayError(`Document ${trackingCode} is already being tracked on this page.`);
                    trackAnotherModal.hide();
                    return;
                }

                currentCodes.push(trackingCode);
                urlParams.set('codes', currentCodes.join(','));
                history.pushState(null, '', `?${urlParams.toString()}`);

                try {
                    const response = await fetch(`/api/track-document/${trackingCode}`);
                    if (!response.ok) {
                        if (response.status === 404) {
                            displayError(`Document with tracking code ${trackingCode} not found.`);
                        } else {
                            displayError('Error tracking document. Please try again.');
                        }
                        // Remove the bad code from URL
                        const failedCodeIndex = currentCodes.indexOf(trackingCode);
                        if(failedCodeIndex > -1) {
                            currentCodes.splice(failedCodeIndex, 1);
                            urlParams.set('codes', currentCodes.join(','));
                            history.pushState(null, '', `?${urlParams.toString()}`);
                        }
                        return; // Important: stop execution if document not found
                    }
                    const htmlContent = await response.text();
                    
                    const noDocumentsAlert = trackedDocumentsContainer.querySelector('.alert.alert-info');
                    if (noDocumentsAlert) {
                        noDocumentsAlert.remove();
                    }
                    trackedDocumentsContainer.insertAdjacentHTML('beforeend', htmlContent);

                } catch (error) {
                    console.error('Error fetching document module:', error);
                    displayError('Network error. Please try again.');
                } finally {
                    trackAnotherModal.hide();
                    trackingCodeInput.value = '';
                }
            }


            if (trackForm) {
                trackForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const trackingCode = trackingCodeInput.value.trim();
                    trackDocument(trackingCode);
                });
            }

            // --- QR Code Scanner Logic ---
            function onScanSuccess(decodedText, decodedResult) {
                stopQrCodeScanner();
                trackDocument(decodedText);
            }

            function onScanError(errorMessage) {
                // handle scan error as you like
            }

            function startQrCodeScanner() {
                trackAnotherModal.hide(); // Hide the first modal
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
                    alert("Could not start QR scanner. Please grant camera permissions and refresh.");
                    stopQrCodeScanner();
                });
            }

            function stopQrCodeScanner() {
                if (html5QrCode && html5QrCode.isScanning) {
                    html5QrCode.stop().catch(err => {
                        // errors are fine, scanner might already be stopping
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
        });
    </script>
    <script>
        // Real-time Polling Logic
        const POLLING_INTERVAL = 60000; // 60 seconds

        async function pollForUpdates() {
            const documentCards = document.querySelectorAll('.document-card');
            if (documentCards.length === 0) {
                return; // No documents to track
            }

            const trackingCodes = Array.from(documentCards).map(card => card.dataset.trackingCode);
            
            try {
                const response = await fetch(`/api/document-status?codes=${trackingCodes.join(',')}`);
                if (!response.ok) {
                    console.error('Polling request failed.');
                    return;
                }
                const statuses = await response.json();

                for (const update of statuses) {
                    const card = document.querySelector(`.document-card[data-tracking-code="${update.tracking_code}"]`);
                    if (card) {
                        const isChanged = card.dataset.status !== update.status || card.dataset.currentStep != update.current_step;

                        if (isChanged) {
                            console.log(`Change detected for ${update.tracking_code}. Refreshing card.`);
                            // Fetch the updated card HTML and replace the old one
                            const cardResponse = await fetch(`/api/track-document/${update.tracking_code}`);
                            const newCardHtml = await cardResponse.text();
                            card.outerHTML = newCardHtml;
                        }
                    }
                }

            } catch (error) {
                console.error('Error during polling:', error);
            }
        }

        setInterval(pollForUpdates, POLLING_INTERVAL);
    </script>
</body>
</html>
