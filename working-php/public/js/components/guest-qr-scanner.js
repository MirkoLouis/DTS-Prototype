/**
 * Camera QR scanner dialog of the guest pages (welcome and track), shared by both.
 *
 *   <button data-qr-camera-modal="qr-scanner-modal">     inside the dialog that is to make room for the scanner
 *       (optional data-qr-missing-library="ignore": do nothing instead of alerting when the library did not load)
 *   <div id="qr-scanner-modal" role="dialog">             the scanner dialog, containing
 *       <div id="qr-reader" data-qr-reader>               the element html5-qrcode draws the camera image into
 *
 * Clicking the button closes its own dialog, opens the scanner dialog and starts the camera. A decoded code closes the
 * scanner dialog and is announced with a `qrcamera:scanned` event on it (bubbles; event.detail.text), which the page
 * handles. However the scanner dialog closes (Close button, Escape, a click outside, a scan) the camera is released.
 *
 * Event delegation on document, bound once per page load. The staff pages use components/qr-scanner.js instead.
 */
(function () {
    'use strict';

    // Scanner state of each scanner dialog: { camera, dialogClosed, cameraStarting }
    //   dialogClosed:   the dialog was closed (a pending start must stop as soon as it resolves)
    //   cameraStarting: start() is pending
    const stateByModal = new WeakMap();

    // The state of a dialog (created on the first scan)
    function stateOf(scannerModal) {
        if (!stateByModal.has(scannerModal)) {
            stateByModal.set(scannerModal, { camera: null, dialogClosed: true, cameraStarting: false });
        }
        return stateByModal.get(scannerModal);
    }

    // Release the camera (also when the dialog closes before start() has resolved: the start handler stops it)
    function releaseCamera(scannerModal) {
        const state = stateOf(scannerModal);
        state.dialogClosed = true;
        if (state.camera && state.camera.isScanning) {
            state.camera.stop().catch((error) => {
                // A failed stop is not an error for the user (it was ignored before)
                console.warn('Could not stop the QR scanner.', error);
            });
        }
    }

    // A code was decoded: close the dialog (which releases the camera) and announce the code
    function handleScan(scannerModal, decodedText) {
        ModalDialog.close(scannerModal);
        scannerModal.dispatchEvent(new CustomEvent('qrcamera:scanned', { bubbles: true, detail: { text: decodedText } }));
    }

    // Start the camera of an open scanner dialog
    function startCamera(scannerModal, silentWhenLibraryMissing) {
        const state = stateOf(scannerModal);
        state.dialogClosed = false;

        if (!window.Html5Qrcode) {
            // The track page always returned silently here; the welcome page tells the user
            if (!silentWhenLibraryMissing) alert('QR Scanner library not loaded.');
            return;
        }
        if (!state.camera) {
            state.camera = new window.Html5Qrcode(scannerModal.querySelector('[data-qr-reader]').id);
        }

        // A start that is still pending, or a scanner that already runs, is not started twice
        if (state.cameraStarting || state.camera.isScanning) return;
        state.cameraStarting = true;

        state.camera.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            (decodedText) => handleScan(scannerModal, decodedText),
            () => {
                // Ignore minor errors while scanning
            }
        ).then(() => {
            state.cameraStarting = false;
            // The dialog was closed while the camera was still starting: stop it now
            if (state.dialogClosed) releaseCamera(scannerModal);
        }).catch((error) => {
            state.cameraStarting = false;
            if (state.dialogClosed) return;
            console.error('Could not start QR scanner.', error);
            alert('Could not start QR scanner. Please ensure you are using HTTPS and grant camera permissions.');
            ModalDialog.close(scannerModal);
        });
    }

    // The scan button: its own dialog makes room for the scanner, which takes over its opener, so Escape or Close
    // returns the focus to the button that opened the first dialog
    document.addEventListener('click', (event) => {
        const scanButton = event.target instanceof Element ? event.target.closest('[data-qr-camera-modal]') : null;
        if (!scanButton) return;

        const scannerModal = document.getElementById(scanButton.dataset.qrCameraModal);
        if (!scannerModal) return;

        const ownDialog = scanButton.closest('[role="dialog"]');
        const opener = ownDialog ? ModalDialog.close(ownDialog, { restoreFocus: false }) : scanButton;
        ModalDialog.open(scannerModal, { opener: opener });
        startCamera(scannerModal, scanButton.dataset.qrMissingLibrary === 'ignore');
    });

    // However the scanner dialog closes, the camera is released
    document.addEventListener('modaldialog:close', (event) => {
        if (stateByModal.has(event.target)) releaseCamera(event.target);
    });
})();
