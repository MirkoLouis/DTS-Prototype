/**
 * Tracking code finder of the staff pages (src/Views/components/qr-scanner.php) and its QR scan dialog
 * (src/Views/partials/scan-qr-modal.php), by event delegation on document (bound once per full page load; the form,
 * button and dialog are new elements after every in-app navigation).
 *
 *   <form data-tracking-finder data-code-field="tracking_code" [data-pin-prompt="Enter your Security PIN to ...: "]>
 *       Before it is sent the code is trimmed. With data-pin-prompt the Security PIN is asked for first (the prompt text
 *       is followed by the code) and sent as the hidden field "pin".
 *
 *   <button data-qr-modal="qr-modal">
 *       Opens the dialog and starts the scanner (html5-qrcode); a decoded code is put into the form and the form is sent.
 *
 * The guest pages scan with the camera directly: components/guest-qr-scanner.js.
 */
(function () {
    'use strict';

    // The scanner widget of each dialog element (created on the first scan, reused afterwards)
    const scannerByModal = new WeakMap();

    // The ONE hidden field that carries the PIN of the finder form (created when the form is first submitted)
    function pinFieldOf(form) {
        let pinInput = form.querySelector('.scan-pin-input');
        if (pinInput) return pinInput;

        pinInput = document.createElement('input');
        pinInput.type = 'hidden';
        pinInput.name = 'pin';
        pinInput.className = 'scan-pin-input';
        form.appendChild(pinInput);
        return pinInput;
    }

    // Trim the code, and ask for the Security PIN when the form needs one
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-tracking-finder')) return;

        // Trim the code (a pasted or scanned code often carries spaces)
        const codeField = form.elements[form.dataset.codeField];
        if (codeField) codeField.value = codeField.value.trim();

        // A finder without a PIN prompt is sent as it is
        if (form.dataset.pinPrompt === undefined) return;

        const pinInput = pinFieldOf(form);
        if (pinInput.value) return;

        event.preventDefault();
        const trackingCode = codeField ? codeField.value : '';
        if (!trackingCode) return;

        window.SigningModal.submitWithPin(form, pinInput, form.dataset.pinPrompt + trackingCode, event.submitter);
    });

    // Stop the camera and remove the scanner UI; never throws (clear() rejects when the scanner never started)
    function stopScanner(scanner) {
        if (!scanner) return Promise.resolve();
        return Promise.resolve(scanner.clear()).catch(() => {});
    }

    // A code was decoded: release the camera, close the dialog and send the finder form with the code
    function handleScanSuccess(modal, scanner, decodedText) {
        const form = document.querySelector('[data-tracking-finder]');
        stopScanner(scanner).then(() => {
            // The page submits the form next, so the focus is not returned to the scan button
            ModalDialog.close(modal, { restoreFocus: false });

            const codeField = form ? form.elements[form.dataset.codeField] : null;
            if (!form || !codeField) return;
            codeField.value = decodedText;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }
            if (form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))) {
                form.submit();
            }
        });
    }

    // The scan button opens the dialog and starts the scanner
    document.addEventListener('click', (event) => {
        const scanButton = event.target instanceof Element ? event.target.closest('[data-qr-modal]') : null;
        if (!scanButton) return;

        const modal = document.getElementById(scanButton.dataset.qrModal);
        if (!modal) return;

        // The shared module opens it (focus trap, Escape, inert page behind) and returns the focus to this button on close
        ModalDialog.open(modal, { opener: scanButton });

        let scanner = scannerByModal.get(modal);
        if (!scanner) {
            scanner = new Html5QrcodeScanner('qr-reader', { fps: 10, qrbox: 250 });
            scannerByModal.set(modal, scanner);
        }
        scanner.render((decodedText) => handleScanSuccess(modal, scanner, decodedText));
    });

    // However the dialog closes (Cancel, Escape, a click outside, a scan, in-app navigation) the camera is released
    document.addEventListener('modaldialog:close', (event) => {
        stopScanner(scannerByModal.get(event.target));
    });
})();
