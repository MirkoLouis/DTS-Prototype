/**
 * Copy buttons: a button with a data-copy-text attribute copies that text to the clipboard and shows a check mark
 * for two seconds (see the copy_button() helper in src/Common/Utils/helpers.php, which writes the markup).
 *
 * ONE delegated click listener on document, bound once per full page load, so buttons that arrive with an in-app
 * navigation work too.
 */
(function () {
    'use strict';

    // How long the check mark replaces the copy icon
    const CHECK_VISIBLE_MILLISECONDS = 2000;

    // Show the check icon instead of the copy icon, and switch back later
    function flashCheckIcon(button) {
        const copyIcon = button.querySelector('.copy-icon');
        const checkIcon = button.querySelector('.check-icon');
        if (!copyIcon || !checkIcon) return;

        copyIcon.style.display = 'none';
        checkIcon.style.display = 'block';
        setTimeout(() => {
            copyIcon.style.display = 'block';
            checkIcon.style.display = 'none';
        }, CHECK_VISIBLE_MILLISECONDS);
    }

    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-copy-text]') : null;
        if (!button) return;

        flashCheckIcon(button);
        // The clipboard may be missing or refuse (insecure context, no permission): the check mark is shown either way, as before
        navigator.clipboard?.writeText(button.dataset.copyText)?.catch(() => {});
    });
})();
