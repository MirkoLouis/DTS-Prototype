/**
 * Guest welcome page (src/Views/guest/welcome.php): the document request form and the "Track a Document" dialog.
 *
 * Server data: the requirements of every purpose in the JSON block #purpose-requirements ({purposeId: [text, ...]}).
 * The camera scan of a tracking code is components/guest-qr-scanner.js; a scanned code arrives as `qrcamera:scanned`.
 */
DtsPage.onLoad('[data-page="guest-welcome"]', function initGuestWelcomePage() {
    'use strict';

    // Requirements by purpose id
    const requirementsByPurposeId = DtsData.read('purpose-requirements', {});

    // --- Purpose selection: the "other purpose" field and the requirements list ---
    const purposeSelect = document.getElementById('purpose-select');
    const otherPurposeInput = document.getElementById('other-purpose-input');
    const otherPurposeTextField = document.getElementById('other_purpose_text');
    const requirementsSection = document.getElementById('requirements-section');
    const requirementsList = document.getElementById('requirements-list');

    // Fill the requirements list from the selected purpose (text only: textContent, never HTML)
    function showRequirements(requirements) {
        requirementsList.replaceChildren();
        if (requirements.length === 0) {
            requirementsSection.classList.add('hidden');
            return;
        }

        requirements.forEach((requirement) => {
            const item = document.createElement('li');
            item.textContent = requirement;
            item.className = 'bg-gray-100 dark:bg-gray-700 px-4 py-2 rounded-md text-sm';
            requirementsList.appendChild(item);
        });
        requirementsSection.classList.remove('hidden');
    }

    // Dynamically reveal or hide the "Requirements" section based on the user's selected purpose dropdown.
    function updatePurposeFields() {
        const selectedValue = purposeSelect.value;

        // "Other": ask for the purpose in words, no requirements
        if (selectedValue === '0') {
            otherPurposeInput.classList.remove('hidden');
            otherPurposeTextField.setAttribute('required', 'required');
            requirementsSection.classList.add('hidden');
            requirementsList.replaceChildren();
            return;
        }

        // A listed purpose: its requirements
        if (selectedValue) {
            otherPurposeInput.classList.add('hidden');
            otherPurposeTextField.removeAttribute('required');
            otherPurposeTextField.value = '';
            showRequirements(requirementsByPurposeId[selectedValue] || []);
            return;
        }

        // Nothing selected
        otherPurposeInput.classList.add('hidden');
        requirementsSection.classList.add('hidden');
    }

    if (purposeSelect) {
        updatePurposeFields();
        purposeSelect.addEventListener('change', updatePurposeFields);
    }

    // --- Numeric-only restriction for the phone number ---
    const phoneInput = document.getElementById('guest_phone');
    if (phoneInput) {
        phoneInput.addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    }

    // --- Track dialog ---
    const trackForm = document.getElementById('track-document-form');
    const trackingCodeInput = document.getElementById('tracking_code_input');
    const trackErrorMessage = document.getElementById('track-error-message');

    // Show the error in the live region and mark the field invalid (the field already points at the message with aria-describedby)
    function displayError(message) {
        if (!trackErrorMessage) return;
        trackErrorMessage.textContent = message;
        trackErrorMessage.classList.remove('hidden');
        trackingCodeInput.setAttribute('aria-invalid', 'true');
    }

    // Hide the error again
    function clearError() {
        if (!trackErrorMessage) return;
        trackErrorMessage.classList.add('hidden');
        trackErrorMessage.textContent = '';
        trackingCodeInput.removeAttribute('aria-invalid');
    }

    // Open the tracking page for a code
    function trackAndRedirect(trackingCode) {
        clearError();
        if (!trackingCode) {
            displayError('Please enter a tracking code.');
            return;
        }
        window.location.href = `/track?codes=${encodeURIComponent(trackingCode)}`;
    }

    if (trackingCodeInput) {
        trackingCodeInput.addEventListener('input', clearError);
    }

    if (trackForm) {
        trackForm.addEventListener('submit', function (event) {
            event.preventDefault();
            trackAndRedirect(trackingCodeInput.value.trim());
        });
    }

    // A code scanned with the camera
    const scannerModal = document.getElementById('qr-scanner-modal');
    if (scannerModal) {
        scannerModal.addEventListener('qrcamera:scanned', (event) => trackAndRedirect(event.detail.text));
    }
});
