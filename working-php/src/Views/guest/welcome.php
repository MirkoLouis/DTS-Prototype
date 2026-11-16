<div class="bg-white dark:bg-gray-800 shadow-lg rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700">
    <div class="bg-primary text-white p-4 font-semibold text-lg">
        Start a New Document Request
    </div>
    <div class="p-6">
        <form method="POST" action="/submit-document">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            
            <div class="mb-5">
                <label for="guest_name" class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Your Full Name</label>
                <input type="text" class="w-full px-4 py-2 border <?= field_error_class('guest_name') ?> rounded-md focus:outline-none focus:ring-2 focus:ring-primary dark:bg-gray-700 dark:text-white" id="guest_name" name="guest_name" autocomplete="name" <?= field_error_attributes('guest_name') ?> value="<?= old('guest_name') ?>" required>
                <?php if (has_error('guest_name')): ?><p id="<?= field_error_id('guest_name') ?>" class="mt-1 text-xs text-red-600 dark:text-red-400 font-medium"><?= error_msg('guest_name') ?></p><?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                <div>
                    <label for="guest_email" class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Your Email Address (Optional)</label>
                    <input type="email" class="w-full px-4 py-2 border <?= field_error_class('guest_email') ?> rounded-md focus:outline-none focus:ring-2 focus:ring-primary dark:bg-gray-700 dark:text-white" id="guest_email" name="guest_email" autocomplete="email" inputmode="email" <?= field_error_attributes('guest_email') ?> value="<?= old('guest_email') ?>">
                    <?php if (has_error('guest_email')): ?><p id="<?= field_error_id('guest_email') ?>" class="mt-1 text-xs text-red-600 dark:text-red-400 font-medium"><?= error_msg('guest_email') ?></p><?php endif; ?>
                </div>
                <div>
                    <label for="guest_phone" class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Your Phone Number</label>
                    <input type="tel" class="w-full px-4 py-2 border <?= field_error_class('guest_phone') ?> rounded-md focus:outline-none focus:ring-2 focus:ring-primary dark:bg-gray-700 dark:text-white" id="guest_phone" name="guest_phone" autocomplete="tel" inputmode="numeric" <?= field_error_attributes('guest_phone') ?> value="<?= old('guest_phone') ?>" required>
                    <?php if (has_error('guest_phone')): ?><p id="<?= field_error_id('guest_phone') ?>" class="mt-1 text-xs text-red-600 dark:text-red-400 font-medium"><?= error_msg('guest_phone') ?></p><?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                <div>
                    <label for="district" class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">District</label>
                    <select class="w-full px-4 py-2 border <?= field_error_class('district') ?> rounded-md focus:outline-none focus:ring-2 focus:ring-primary dark:bg-gray-700 dark:text-white" id="district" name="district" <?= field_error_attributes('district') ?> required>
                        <option <?= !old('district') ? 'selected' : '' ?> disabled value="">Choose a district...</option>
                        <option value="East I District" <?= old('district') === 'East I District' ? 'selected' : '' ?>>East I District</option>
                        <option value="East II District" <?= old('district') === 'East II District' ? 'selected' : '' ?>>East II District</option>
                        <option value="South I District" <?= old('district') === 'South I District' ? 'selected' : '' ?>>South I District</option>
                        <option value="South II District" <?= old('district') === 'South II District' ? 'selected' : '' ?>>South II District</option>
                        <option value="West I District" <?= old('district') === 'West I District' ? 'selected' : '' ?>>West I District</option>
                        <option value="West II District" <?= old('district') === 'West II District' ? 'selected' : '' ?>>West II District</option>
                        <option value="North I District" <?= old('district') === 'North I District' ? 'selected' : '' ?>>North I District</option>
                        <option value="North II District" <?= old('district') === 'North II District' ? 'selected' : '' ?>>North II District</option>
                        <option value="North III District" <?= old('district') === 'North III District' ? 'selected' : '' ?>>North III District</option>
                        <option value="City Central District" <?= old('district') === 'City Central District' ? 'selected' : '' ?>>City Central District</option>
                        <option value="N/A" <?= old('district') === 'N/A' ? 'selected' : '' ?>>N/A</option>
                    </select>
                    <?php if (has_error('district')): ?><p id="<?= field_error_id('district') ?>" class="mt-1 text-xs text-red-600 dark:text-red-400 font-medium"><?= error_msg('district') ?></p><?php endif; ?>
                </div>
                <div>
                    <label for="department" class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Unit/Department</label>
                    <select class="w-full px-4 py-2 border <?= field_error_class('department') ?> rounded-md focus:outline-none focus:ring-2 focus:ring-primary dark:bg-gray-700 dark:text-white" id="department" name="department" <?= field_error_attributes('department') ?> required>
                        <option <?= !old('department') ? 'selected' : '' ?> disabled value="">Choose a unit/department...</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept['name']) ?>" <?= old('department') === $dept['name'] ? 'selected' : '' ?>><?= htmlspecialchars($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (has_error('department')): ?><p id="<?= field_error_id('department') ?>" class="mt-1 text-xs text-red-600 dark:text-red-400 font-medium"><?= error_msg('department') ?></p><?php endif; ?>
                </div>
            </div>

            <div class="mb-5">
                <label for="title" class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Document Title</label>
                <input type="text" class="w-full px-4 py-2 border <?= field_error_class('title') ?> rounded-md focus:outline-none focus:ring-2 focus:ring-primary dark:bg-gray-700 dark:text-white" id="title" name="title" autocomplete="off" <?= field_error_attributes('title') ?> value="<?= old('title') ?>" required placeholder="N/A if inapplicable.">
                <?php if (has_error('title')): ?><p id="<?= field_error_id('title') ?>" class="mt-1 text-xs text-red-600 dark:text-red-400 font-medium"><?= error_msg('title') ?></p><?php endif; ?>
            </div>

            <div class="mb-5">
                <label for="purpose-select" class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">1. Select Purpose of Request</label>
                <select class="w-full px-4 py-2 border <?= field_error_class('purpose_id') ?> rounded-md focus:outline-none focus:ring-2 focus:ring-primary dark:bg-gray-700 dark:text-white" id="purpose-select" name="purpose_id" <?= field_error_attributes('purpose_id') ?> required>
                    <option <?= !old('purpose_id') ? 'selected' : '' ?> disabled value="">Choose an option...</option>
                    <?php foreach ($purposes as $purpose): ?>
                        <option value="<?= $purpose['id'] ?>" <?= old('purpose_id') == $purpose['id'] ? 'selected' : '' ?> data-requirements="<?= htmlspecialchars(json_encode($purpose['requirements'])) ?>">
                            <?= htmlspecialchars($purpose['name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="0" <?= old('purpose_id') === '0' ? 'selected' : '' ?>>Other (Please specify)</option>
                </select>
                <?php if (has_error('purpose_id')): ?><p id="<?= field_error_id('purpose_id') ?>" class="mt-1 text-xs text-red-600 dark:text-red-400 font-medium"><?= error_msg('purpose_id') ?></p><?php endif; ?>
            </div>

            <div class="mb-5 hidden" id="other-purpose-input">
                <label for="other_purpose_text" class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Please Specify Your Purpose</label>
                <input type="text" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:outline-none focus:ring-2 focus:ring-primary dark:bg-gray-700 dark:text-white" id="other_purpose_text" name="other_purpose_text" value="<?= old('other_purpose_text') ?>">
            </div>

            <div id="requirements-section" class="mb-5 hidden">
                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">2. Requirements</label>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Please prepare the following documents.</p>
                <ul id="requirements-list" class="space-y-2">
                    <!-- Requirements injected here -->
                </ul>
            </div>

            <hr class="my-6 border-gray-200 dark:border-gray-700">
            
            <div class="space-y-4">
                <button type="submit" class="w-full bg-primary hover:bg-blue-700 text-white font-bold py-3 px-4 rounded transition duration-200 shadow-md">
                    Submit Request
                </button>
                <button type="button" data-modal="track-modal" class="open-modal-btn w-full bg-gray-500 hover:bg-accent-2 text-white font-bold py-3 px-4 rounded transition duration-200 shadow-md focus:outline-none focus:ring-2 focus:ring-primary">
                    Track a Document
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Track Modal -->
<div id="track-modal" role="dialog" aria-modal="true" aria-labelledby="track-modal-title" class="hidden fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
    <div class="relative mx-auto p-5 border w-96 shadow-lg rounded-md bg-white dark:bg-gray-800 dark:border-gray-700">
        <div class="mt-3 text-center">
            <h2 id="track-modal-title" class="text-lg leading-6 font-medium text-gray-900 dark:text-white">Track a Document</h2>
            <form id="track-document-form" class="mt-4">
                <div class="mb-4 text-left">
                    <label for="tracking_code_input" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Enter Tracking Code:</label>
                    <input type="text" id="tracking_code_input" autocomplete="off" aria-describedby="track-error-message" class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary dark:bg-gray-700 dark:text-white sm:text-sm" placeholder="e.g., DEPED-A1B2C3D4E5" required>
                </div>

                <div id="track-error-message" role="alert" class="hidden bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 text-sm text-left"></div>

                <div class="mb-4">
                    <button type="button" id="scan-qr-button" class="w-full border border-primary text-primary hover:bg-primary hover:text-white font-medium py-2 px-4 rounded transition duration-200 flex items-center justify-center dark:border-blue-400 dark:text-blue-400 dark:hover:bg-blue-600 dark:hover:text-white focus:outline-none focus:ring-2 focus:ring-primary">
                        <svg aria-hidden="true" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm14 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                        Scan QR Code
                    </button>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" class="close-modal-btn bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-accent-2 text-gray-800 dark:text-gray-200 font-medium py-2 px-4 rounded focus:outline-none focus:ring-2 focus:ring-primary">
                        Close
                    </button>
                    <button type="submit" class="bg-primary hover:bg-blue-700 text-white font-medium py-2 px-4 rounded focus:outline-none focus:ring-2 focus:ring-primary">
                        Track
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- QR Scanner Modal -->
<div id="qr-scanner-modal" role="dialog" aria-modal="true" aria-labelledby="qr-scanner-modal-title" class="qr-modal hidden">
    <div class="qr-modal-content">
        <div class="flex justify-between items-center mb-4">
            <h2 id="qr-scanner-modal-title" class="text-lg font-medium">Scan QR Code</h2>
            <button type="button" id="close-qr-modal" aria-label="Close" class="rounded text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary">
                <svg aria-hidden="true" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div id="qr-reader" style="width: 100%;"></div>
    </div>
</div>

<script src="/js/html5-qrcode.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const purposeSelect = document.getElementById('purpose-select');
        const otherPurposeInput = document.getElementById('other-purpose-input');
        const otherPurposeTextField = document.getElementById('other_purpose_text');
        const requirementsSection = document.getElementById('requirements-section');
        const requirementsList = document.getElementById('requirements-list');

        // Dynamically reveal or hide the "Requirements" section based on the user's selected purpose dropdown.
        function updatePurposeFields() {
            const selectedOptionValue = purposeSelect.value;
            const selectedOption = purposeSelect.options[purposeSelect.selectedIndex];

            if (selectedOptionValue === '0') {
                otherPurposeInput.classList.remove('hidden');
                otherPurposeTextField.setAttribute('required', 'required');
                requirementsSection.classList.add('hidden');
                requirementsList.innerHTML = '';
            } else if (selectedOptionValue) {
                otherPurposeInput.classList.add('hidden');
                otherPurposeTextField.removeAttribute('required');
                otherPurposeTextField.value = '';

                const requirements = selectedOption.dataset.requirements ? JSON.parse(selectedOption.dataset.requirements) : [];
                requirementsList.innerHTML = '';

                if (requirements.length > 0) {
                    requirements.forEach(req => {
                        const li = document.createElement('li');
                        li.textContent = req;
                        li.className = 'bg-gray-100 dark:bg-gray-700 px-4 py-2 rounded-md text-sm';
                        requirementsList.appendChild(li);
                    });
                    requirementsSection.classList.remove('hidden');
                } else {
                    requirementsSection.classList.add('hidden');
                }
            } else {
                otherPurposeInput.classList.add('hidden');
                requirementsSection.classList.add('hidden');
            }
        }

        if (purposeSelect) {
            updatePurposeFields();
            purposeSelect.addEventListener('change', updatePurposeFields);
        }

        // Numeric-only restriction for Phone Number
        const phoneInput = document.getElementById('guest_phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }

        // Track Logic
        const trackForm = document.getElementById('track-document-form');
        const trackingCodeInput = document.getElementById('tracking_code_input');
        const trackErrorMessage = document.getElementById('track-error-message');

        // Show the error in the live region and mark the field invalid (the field already points at the message with aria-describedby)
        function displayError(message) {
            if(trackErrorMessage) {
                trackErrorMessage.textContent = message;
                trackErrorMessage.classList.remove('hidden');
                trackingCodeInput.setAttribute('aria-invalid', 'true');
            }
        }

        function clearError() {
            if(trackErrorMessage) {
                trackErrorMessage.classList.add('hidden');
                trackErrorMessage.textContent = '';
                trackingCodeInput.removeAttribute('aria-invalid');
            }
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

        // QR Scanner Logic
        const scanQrButton = document.getElementById('scan-qr-button');
        const qrScannerModal = document.getElementById('qr-scanner-modal');
        const closeQrModal = document.getElementById('close-qr-modal');
        const trackModal = document.getElementById('track-modal');
        let html5QrCode = null;

        function onScanSuccess(decodedText, decodedResult) {
            stopQrCodeScanner();
            trackAndRedirect(decodedText);
        }

        function onScanError(errorMessage) {
            // Ignore minor errors while scanning
        }

        // scannerClosed: the dialog was closed (a pending start must stop as soon as it resolves); cameraStarting: start() is pending
        let scannerClosed = true;
        let cameraStarting = false;

        function startQrCodeScanner() {
            // The tracking modal makes room for the scanner (as before); the scanner takes over its opener, so Escape or Close returns the focus to the button that opened the tracking modal
            const trackModalOpener = ModalDialog.close(trackModal, { restoreFocus: false });
            ModalDialog.open(qrScannerModal, { opener: trackModalOpener });

            scannerClosed = false;
            if (!window.Html5Qrcode) {
                alert("QR Scanner library not loaded.");
                return;
            }
            if (!html5QrCode) html5QrCode = new window.Html5Qrcode("qr-reader");
            // A start that is still pending, or a scanner that already runs, is not started twice
            if (cameraStarting || html5QrCode.isScanning) return;
            cameraStarting = true;
            html5QrCode.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: { width: 250, height: 250 } },
                onScanSuccess,
                onScanError
            ).then(() => {
                cameraStarting = false;
                // The dialog was closed while the camera was still starting: stop it now
                if (scannerClosed) releaseCamera();
            }).catch(err => {
                cameraStarting = false;
                if (scannerClosed) return;
                console.error("Could not start QR scanner.", err);
                alert("Could not start QR scanner. Please ensure you are using HTTPS and grant camera permissions.");
                stopQrCodeScanner();
            });
        }

        // Release the camera (also when the dialog closes before start() has resolved: the then() above stops it)
        function releaseCamera() {
            scannerClosed = true;
            if (html5QrCode && html5QrCode.isScanning) {
                html5QrCode.stop().catch(err => {
                    console.error("Error stopping the QR scanner.", err);
                });
            }
        }

        // Close the scanner modal; the close event below releases the camera, whichever way the modal closes
        function stopQrCodeScanner() {
            ModalDialog.close(qrScannerModal);
        }

        if (qrScannerModal) {
            qrScannerModal.addEventListener('modaldialog:close', releaseCamera);
        }
        if (scanQrButton) {
            scanQrButton.addEventListener('click', startQrCodeScanner);
        }
        if (closeQrModal) {
            closeQrModal.addEventListener('click', stopQrCodeScanner);
        }
    });
</script>
<?php clear_form_flash(); ?>
