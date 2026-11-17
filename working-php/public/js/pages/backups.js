/**
 * Backup manager (src/Views/admin/backups.php): the Restore button fills in and opens the restore dialog.
 *
 * Delete, restore and download ask for the Security PIN through components/sign-actions.js (their forms carry the
 * class sign-action). Event delegation on document, bound once per full page load.
 */
(function () {
    'use strict';

    document.addEventListener('click', (event) => {
        const restoreButton = event.target instanceof Element ? event.target.closest('.restore-backup-trigger') : null;
        if (!restoreButton) return;

        const fileName = restoreButton.dataset.restoreFileName;
        document.getElementById('restore-file-name').textContent = fileName;
        // The address is built by PHP exactly like the Download and Delete addresses of the same row
        document.getElementById('restore-form').action = restoreButton.dataset.restoreUrl;

        // The shared modal module focuses the first control and returns the focus to the Restore button on close
        // (the opener is passed explicitly: Safari and Firefox on macOS do not focus a clicked button)
        ModalDialog.open(document.getElementById('restore-modal'), { opener: restoreButton });
    });
})();
