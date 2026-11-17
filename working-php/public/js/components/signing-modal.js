/**
 * Digital signature prompt: window.SigningModal asks for the Security PIN in the modal rendered by
 * src/Views/partials/signing-modal.php (#signing-modal), then hands the PIN to a callback.
 *
 *     SigningModal.show('Enter your Security PIN to ...', function (pin) { ... }, openerElement);
 *
 * The modal belongs to a page (it is inside #pjax-content), so it is a new element after every in-app navigation.
 * The buttons are therefore handled by delegation on document (bound once per full page load) and the elements are
 * looked up when they are needed, never held across navigations. Forms and buttons that ask for the PIN are wired
 * by components/sign-actions.js.
 */
(function () {
    'use strict';

    // Remove the plaintext PIN left behind by earlier builds
    DtsPinToken.clearLegacyPin();

    window.SigningModal = {
        // The function waiting for the PIN
        callback: null,

        // opener: the control that asked (the submit event's submitter or the click's button); passed explicitly because
        // Safari and Firefox on macOS do not focus a button when it is clicked, so document.activeElement cannot be trusted
        show(message, callback, opener) {
            // Reuse the server-issued encrypted token instead of prompting; the PIN itself is never stored
            const cachedToken = DtsPinToken.read();
            if (cachedToken) {
                callback(cachedToken);
                return;
            }

            this.callback = callback;
            document.getElementById('signing-modal-message').innerText = message;
            const pinInput = document.getElementById('signing-modal-pin');
            pinInput.value = '';
            // The shared module shows it on top of any modal that is already open, focuses the PIN field and returns the focus to the control that asked
            ModalDialog.open(document.getElementById('signing-modal'), { opener: opener, initialFocus: pinInput });
        },

        /**
         * The ONE PIN-prompt flow of a form: ask for the PIN, store it in the form's hidden field, submit the form.
         *
         *     SigningModal.submitWithPin(form, pinInput, message, opener, { clearAfter: true });
         *
         * clearAfter empties the field after the submit (the form stays on the page, for example a download).
         */
        submitWithPin(form, pinInput, message, opener, options = {}) {
            this.show(message, (pin) => {
                pinInput.value = pin;
                form.submit();
                if (options.clearAfter) pinInput.value = '';
            }, opener);
        },

        // Close the prompt and forget the pending action
        hide() {
            ModalDialog.close(document.getElementById('signing-modal'));
            this.callback = null;
        },
    };

    // "Sign & Confirm": hand the typed PIN to the callback (the server answers with an encrypted cache token on the next page)
    function confirmPin() {
        const pinInput = document.getElementById('signing-modal-pin');
        const pin = pinInput.value;
        if (!pin) {
            alert('Please enter your Security PIN.');
            pinInput.focus();
            return;
        }

        if (!window.SigningModal.callback) return;
        const callback = window.SigningModal.callback;
        window.SigningModal.hide();
        callback(pin);
    }

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;

        if (event.target.closest('#signing-modal-confirm')) {
            confirmPin();
            return;
        }
        if (event.target.closest('#signing-modal-cancel')) {
            window.SigningModal.hide();
        }
    });

    // Enter in the PIN field confirms
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.target.id !== 'signing-modal-pin') return;
        event.preventDefault();
        confirmPin();
    });

    // Escape and a click outside close the modal through the shared module: drop the pending action and the typed PIN
    document.addEventListener('modaldialog:close', (event) => {
        if (event.target.id !== 'signing-modal') return;
        window.SigningModal.callback = null;
        document.getElementById('signing-modal-pin').value = '';
    });
})();
