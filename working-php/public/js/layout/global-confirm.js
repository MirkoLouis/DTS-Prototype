/**
 * Global confirmation: every form with the class `confirm-action` asks before it is sent.
 *
 *     <form class="confirm-action" data-message="Are you sure?"> ... </form>
 *
 * ONE delegated submit listener on document, bound once per full page load, so forms that arrive with an in-app
 * navigation (the router swaps #pjax-content) are covered too. It pauses the submission, shows the modal of the
 * layout (#global-confirmation-modal) and resumes the native submit flow when the user approves.
 */
(function () {
    'use strict';

    const confirmModal = document.getElementById('global-confirmation-modal');
    const confirmMessage = document.getElementById('global-confirmation-message');
    const confirmButton = document.getElementById('global-confirm-btn');
    if (!confirmModal || !confirmMessage || !confirmButton) return;

    // The form waiting for the answer
    let activeConfirmForm = null;

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('confirm-action')) return;

        // It was confirmed by the modal: let it pass through to other listeners or the native submit
        if (form.dataset.confirmed === 'true') {
            delete form.dataset.confirmed;
            return;
        }

        event.preventDefault();
        activeConfirmForm = form;
        confirmMessage.textContent = form.dataset.message || 'Are you sure you want to proceed?';

        // Open through the shared module: the button that submitted the form gets the focus back when the modal closes
        ModalDialog.open(confirmModal, { opener: event.submitter || document.activeElement });
    });

    // "Yes, Proceed": submit the form that was paused
    confirmButton.addEventListener('click', () => {
        const formToSubmit = activeConfirmForm;
        if (!formToSubmit) return;

        // The page is about to leave or re-handle the form, so the focus is not returned to the opener
        ModalDialog.close(confirmModal, { restoreFocus: false });
        formToSubmit.dataset.confirmed = 'true';

        // Dispatch the submit event so other listeners can intercept it, otherwise submit natively
        const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
        const wasCancelled = !formToSubmit.dispatchEvent(submitEvent);
        if (!wasCancelled) {
            formToSubmit.submit();
        }
    });

    // Cancel, the X button, Escape and a click outside all close through the shared module; forget the form it was for
    confirmModal.addEventListener('modaldialog:close', () => {
        activeConfirmForm = null;
    });
})();
