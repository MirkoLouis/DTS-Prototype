/**
 * User management (src/Views/admin/users/index.php): the Reset button of a row fills in the reset dialog and opens it.
 *
 * Sending the dialog's form asks for the administrator's Security PIN through components/sign-actions.js (the form
 * carries the class sign-action); Cancel is a .close-modal-btn handled by the shared modal module. Event delegation
 * on document, bound once per full page load.
 */
(function () {
    'use strict';

    document.addEventListener('click', (event) => {
        const resetButton = event.target instanceof Element ? event.target.closest('.reset-credentials-trigger') : null;
        if (!resetButton) return;

        const form = document.getElementById('reset-credentials-form');
        const modal = document.getElementById('reset-credentials-modal');
        if (!form || !modal) return;

        form.action = '/users/' + encodeURIComponent(resetButton.dataset.resetUserId) + '/reset-credentials';
        document.getElementById('reset-credentials-user-name').textContent = resetButton.dataset.resetUserName;
        form.reset();
        form.querySelector('.sign-pin-input').value = '';

        // The shared modal module focuses the first option and returns the focus to this Reset button on close
        ModalDialog.open(modal, { opener: resetButton });
    });
})();
