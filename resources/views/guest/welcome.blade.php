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
                        <div class="col-md-12 mb-3">
                            <label for="guest_name" class="form-label"><strong>Your Full Name</strong></label>
                            <input type="text" class="form-control" id="guest_name" name="guest_name" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="guest_email" class="form-label"><strong>Your Email Address (Optional)</strong></label>
                            <input type="email" class="form-control" id="guest_email" name="guest_email">
                            @error('guest_email')
                                <div class="text-danger mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="guest_phone" class="form-label"><strong>Your Phone Number</strong></label>
                            <input type="text" class="form-control" id="guest_phone" name="guest_phone" inputmode="numeric">
                            @error('guest_phone')
                                <div class="text-danger mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="district" class="form-label"><strong>District</strong></label>
                            <select class="form-select" id="district" name="district" required>
                                <option selected disabled value="">Choose a district...</option>
                                <option value="East I District">East I District</option>
                                <option value="East II District">East II District</option>
                                <option value="South I District">South I District</option>
                                <option value="South II District">South II District</option>
                                <option value="West I District">West I District</option>
                                <option value="West II District">West II District</option>
                                <option value="North I District">North I District</option>
                                <option value="North II District">North II District</option>
                                <option value="North III District">North III District</option>
                                <option value="City Central District">City Central District</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="department" class="form-label"><strong>Unit/Department</strong></label>
                            <select class="form-select" id="department" name="department" required>
                                <option selected disabled value="">Choose a unit/department...</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->name }}">{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="title" class="form-label"><strong>Document Title</strong></label>
                        <input type="text" class="form-control" id="title" name="title" required placeholder="N/A if inapplicable.">
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
        document.addEventListener('DOMContentLoaded', function () {
            console.log("Attempting to use QR Code Scanner. Html5Qrcode:", window.Html5Qrcode);

            const purposeSelect = document.getElementById('purpose-select');
            const otherPurposeInput = document.querySelector('.other-purpose-input');
            const otherPurposeTextField = document.getElementById('other_purpose_text');
            const requirementsSection = document.getElementById('requirements-section');
            const requirementsList = document.getElementById('requirements-list');

            function updatePurposeFields() {
                const selectedOptionValue = purposeSelect.value;
                const selectedOption = purposeSelect.options[purposeSelect.selectedIndex];

                if (selectedOptionValue === '0') {
                    otherPurposeInput.style.display = 'block';
                    otherPurposeTextField.setAttribute('required', 'required');
                    requirementsSection.style.display = 'none';
                    requirementsList.innerHTML = '';
                } else {
                    otherPurposeInput.style.display = 'none';
                    otherPurposeTextField.removeAttribute('required');
                    otherPurposeTextField.value = '';

                    const requirements = selectedOption.dataset.requirements ? JSON.parse(selectedOption.dataset.requirements) : [];
                    requirementsList.innerHTML = '';

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

            if (purposeSelect) {
                updatePurposeFields();
                purposeSelect.addEventListener('change', updatePurposeFields);
            }

            // Numeric-only restriction for Phone Number
            const phoneInput = document.getElementById('guest_phone');
            if (phoneInput) {
                phoneInput.addEventListener('input', function (e) {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            }

            const trackForm = document.getElementById('track-document-form');
            const trackDocumentModalEl = document.getElementById('trackAnotherModal');
            const trackDocumentModal = trackDocumentModalEl ? new bootstrap.Modal(trackDocumentModalEl) : null;
            const trackingCodeInput = document.getElementById('tracking_code_input');
            const trackErrorMessage = document.getElementById('track-error-message');

            const scanQrButton = document.getElementById('scan-qr-button');
            const qrScannerModal = document.getElementById('qr-scanner-modal');
            const closeQrModal = document.getElementById('close-qr-modal');
            let html5QrCode = null;

            function displayError(message) {
                if(trackErrorMessage) {
                    trackErrorMessage.textContent = message;
                    trackErrorMessage.classList.remove('d-none');
                }
            }

            function clearError() {
                if(trackErrorMessage) {
                    trackErrorMessage.classList.add('d-none');
                    trackErrorMessage.textContent = '';
                }
            }

            if(trackDocumentModalEl) {
                trackDocumentModalEl.addEventListener('show.bs.modal', clearError);
            }
            if(trackingCodeInput) {
                trackingCodeInput.addEventListener('input', clearError);
            }
            
            function trackAndRedirect(trackingCode) {
                clearError();
                if (!trackingCode) {
                    displayError('Please enter a tracking code.');
                    return;
                }
                window.location.href = `/track?codes=${encodeURIComponent(trackingCode)}`;
            }

            if (trackForm) {
                trackForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const trackingCode = trackingCodeInput.value.trim();
                    trackAndRedirect(trackingCode);
                });
            }

            function onScanSuccess(decodedText, decodedResult) {
                stopQrCodeScanner();
                trackAndRedirect(decodedText);
            }

            function onScanError(errorMessage) {
                console.warn(`QR Code Scan Error: ${errorMessage}`);
            }

            function startQrCodeScanner() {
                if (trackDocumentModal) {
                    trackDocumentModal.hide();
                }
                if (qrScannerModal) {
                    qrScannerModal.style.display = 'block';
                }

                if (!html5QrCode && window.Html5Qrcode) {
                    html5QrCode = new window.Html5Qrcode("qr-reader");
                    html5QrCode.start(
                        { facingMode: "environment" },
                        { fps: 10, qrbox: { width: 250, height: 250 } },
                        onScanSuccess,
                        onScanError
                    ).catch(err => {
                        console.error("Could not start QR scanner.", err);
                        alert("Could not start QR scanner. Please grant camera permissions and refresh.");
                        stopQrCodeScanner();
                    });
                } else if (!window.Html5Qrcode) {
                    alert("QR Scanner library not loaded.");
                }
            }

            function stopQrCodeScanner() {
                if (html5QrCode && html5QrCode.isScanning) {
                    html5QrCode.stop().catch(err => {
                        console.error("Error stopping the QR scanner.", err);
                    });
                }
                if (qrScannerModal) {
                    qrScannerModal.style.display = 'none';
                }
            }
            
            if (scanQrButton) {
                scanQrButton.addEventListener('click', startQrCodeScanner);
            }
            if (closeQrModal) {
                closeQrModal.addEventListener('click', stopQrCodeScanner);
            }

            window.addEventListener('click', function(event) {
                if (event.target == qrScannerModal) {
                    stopQrCodeScanner();
                }
            });
        });
    </script>
</body>
</html>
