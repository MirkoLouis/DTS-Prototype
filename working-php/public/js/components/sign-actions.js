/**
 * Actions that ask for the Security PIN before they are sent, by event delegation on document (bound once per full
 * page load, so elements that arrive with an in-app navigation are covered too). The prompt itself is
 * components/signing-modal.js.
 *
 *   <form class="sign-action" data-message="Enter your Security PIN to ..." [data-keeps-page="true"]>
 *       <input type="hidden" name="pin" class="sign-pin-input">
 *   </form>
 *       The form pauses on submit, asks for the PIN, stores it in .sign-pin-input and submits. With data-keeps-page the
 *       PIN is cleared afterwards (the form stays on the page, for example a download or a modal that is used again).
 *
 *   <form class="complete-form"> <input type="hidden" name="pin" class="complete-pin-input">
 *       <button type="button" class="complete-btn" data-message="Enter your Security PIN to ..."> </form>
 *       The button asks for the PIN and submits its form (task completion and releasing).
 */
(function () {
    'use strict';

    // Message shown when a form gives none
    const DEFAULT_PIN_MESSAGE = 'Enter your Security PIN to sign this action.';

    // A form.sign-action without a PIN yet: pause it, ask for the PIN, then send it
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('sign-action')) return;

        const pinInput = form.querySelector('.sign-pin-input');
        if (!pinInput || pinInput.value) return;

        event.preventDefault();
        // A download or a reused modal leaves the page open, so the PIN must not stay in the form for the next use
        window.SigningModal.submitWithPin(form, pinInput, form.dataset.message || DEFAULT_PIN_MESSAGE, event.submitter, {
            clearAfter: form.dataset.keepsPage === 'true',
        });
    });

    // A complete button asks for the PIN and submits the form around it
    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('.complete-btn') : null;
        if (!button) return;

        const form = button.closest('.complete-form');
        if (!form) return;

        window.SigningModal.submitWithPin(form, form.querySelector('.complete-pin-input'), button.dataset.message || DEFAULT_PIN_MESSAGE, button);
    });
})();
