/**
 * The mandatory Security PIN setup prompt (#security-key-modal, rendered by the layout only for an account without a
 * signing key). Opened once per full page load: it takes the focus and keeps it (focus trap); the shared module
 * ignores Escape and clicks outside because the dialog is not dismissible.
 */
(function () {
    'use strict';

    // Open the prompt when the page has been parsed (this file is deferred, so the modal element exists)
    function openPrompt() {
        const setupModal = document.getElementById('security-key-modal');
        if (!setupModal) return;

        ModalDialog.open(setupModal, { dismissible: false });
        document.body.style.overflow = 'hidden';
    }

    document.addEventListener('DOMContentLoaded', openPrompt);
})();
