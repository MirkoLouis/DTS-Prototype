<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DepEd Iligan - Document Tracking System</title>

    @vite(['resources/scss/bootstrap.scss', 'resources/js/bootstrap_public.js'])

    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            max-width: 800px;
        }
        .card-header {
            background-color: #004281;
            color: white;
        }
        #requirements-list {
            list-style-type: none;
            padding-left: 0;
        }
        #requirements-list li {
            background-color: #e9ecef;
            padding: 8px 15px;
            border-radius: 4px;
            margin-bottom: 5px;
        }
        .other-purpose-input {
            display: none; /* Hidden by default */
        }
    </style>
</head>
<body class="antialiased">
    <div class="container mt-5">
        <div class="text-center mb-4">
            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/3/33/DepEd_logo.svg/1200px-DepEd_logo.svg.png" alt="DepEd Logo" style="height: 80px;">
            <h1 class="mt-3">Document Tracking System</h1>
            <p class="lead">DepEd Division of Iligan City</p>
        </div>

        <div class="card">
            <div class="card-header">
                Start a New Document Request
            </div>
            <div class="card-body">
                <form method="POST" action="/submit-document">
                    @csrf
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="guest_name" class="form-label"><strong>Your Full Name</strong></label>
                            <input type="text" class="form-control" id="guest_name" name="guest_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="guest_email" class="form-label"><strong>Your Email Address</strong></label>
                            <input type="email" class="form-control" id="guest_email" name="guest_email" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="purpose_id" class="form-label"><strong>1. Select Purpose of Request</strong></label>
                        <select class="form-select" id="purpose-select" name="purpose_id" required>
                            <option selected disabled value="">Choose an option...</option>
                            @foreach ($purposes as $purpose)
                                <option value="{{ $purpose->id }}" data-requirements="{{ json_encode($purpose->requirements) }}">
                                    {{ $purpose->name }}
                                </option>
                            @endforeach
                            <option value="0">Other (Please specify)</option>
                        </select>
                    </div>

                    <div class="mb-3 other-purpose-input">
                        <label for="other_purpose_text" class="form-label"><strong>Please Specify Your Purpose</strong></label>
                        <input type="text" class="form-control" id="other_purpose_text" name="other_purpose_text">
                    </div>

                    <div id="requirements-section" class="mb-3" style="display: none;">
                        <label class="form-label"><strong>2. Requirements</strong></label>
                        <p class="text-muted small">Please prepare the following documents.</p>
                        <ul id="requirements-list">
                            <!-- Requirements will be injected here by JavaScript -->
                        </ul>
                    </div>

                    <hr>
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary btn-lg">Submit Request</button>
                    </div>
                    <div class="d-grid">
                        <button type="button" class="btn btn-secondary btn-lg" data-bs-toggle="modal" data-bs-target="#trackAnotherModal">
                            Track a Document
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal for Track Another Document -->
    <div class="modal fade" id="trackAnotherModal" tabindex="-1" aria-labelledby="trackAnotherModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="track-document-form">
                    @csrf
                    <div class="modal-header">
                        <h1 class="modal-title fs-5" id="trackAnotherModalLabel">Track a Document</h1>
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

    <script>
        const purposeSelect = document.getElementById('purpose-select');
        const otherPurposeInput = document.querySelector('.other-purpose-input');
        const otherPurposeTextField = document.getElementById('other_purpose_text');
        const requirementsSection = document.getElementById('requirements-section');
        const requirementsList = document.getElementById('requirements-list');

        function updatePurposeFields() {
            const selectedOptionValue = purposeSelect.value;
            const selectedOption = purposeSelect.options[purposeSelect.selectedIndex];

            // Handle "Other" purpose input visibility
            if (selectedOptionValue === '0') {
                otherPurposeInput.style.display = 'block';
                otherPurposeTextField.setAttribute('required', 'required');
                requirementsSection.style.display = 'none'; // Hide requirements for "Other"
                requirementsList.innerHTML = '';
            } else {
                otherPurposeInput.style.display = 'none';
                otherPurposeTextField.removeAttribute('required');
                otherPurposeTextField.value = ''; // Clear input if not "Other"

                // Handle requirements display for specific purposes
                const requirements = JSON.parse(selectedOption.dataset.requirements || '[]');
                requirementsList.innerHTML = ''; // Clear previous list

                if (requirements.length > 0) {
                    requirements.forEach(req => {
                        const li = document.createElement('li');
                        li.textContent = req;
                        requirementsList.appendChild(li);
                    });
                    requirementsSection.style.display = 'block';
                } else {
                    requirementsSection.style.display = 'none';
                }
            }
        }

        // Initial call to set up the form correctly on page load
        updatePurposeFields();

        // Add event listener for changes
        purposeSelect.addEventListener('change', updatePurposeFields);

        // --- Track Document Modal Logic ---
        const trackForm = document.getElementById('track-document-form');
        const trackDocumentModal = new bootstrap.Modal(document.getElementById('trackAnotherModal')); // Using the same ID as the modal
        const trackingCodeInput = document.getElementById('tracking_code_input');
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

        async function trackAndRedirect(trackingCode) {
            clearError();
            if (!trackingCode) {
                displayError('Please enter a tracking code.');
                return;
            }

            // Redirect to the track page with the tracking code
            window.location.href = `/track?codes=${trackingCode}`;
        }

        if (trackForm) {
            trackForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const trackingCode = trackingCodeInput.value.trim();
                trackAndRedirect(trackingCode);
            });
        }

        // --- QR Code Scanner Logic ---
        function onScanSuccess(decodedText, decodedResult) {
            stopQrCodeScanner();
            trackAndRedirect(decodedText);
        }

        function onScanError(errorMessage) {
            // handle scan error as you like
        }

        function startQrCodeScanner() {
            trackDocumentModal.hide(); // Hide the first modal
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
    </script>
