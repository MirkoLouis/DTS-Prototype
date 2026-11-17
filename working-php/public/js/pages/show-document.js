/**
 * Document details page (src/Views/general/show-document.php): the Back button returns to the page the user came from.
 *
 * pjax-router.js remembers that page in sessionStorage ("dts_doc_origin") when a document page is entered from another
 * page. The freeze and unfreeze forms ask for the Security PIN through components/sign-actions.js.
 */
DtsPage.onLoad('[data-page="show-document"]', function initShowDocumentPage() {
    'use strict';

    const backButton = document.getElementById('doc-back-button');
    if (!backButton) return;

    // The remembered origin (not a document page itself, and not this page)
    let origin = null;
    try {
        origin = sessionStorage.getItem('dts_doc_origin');
    } catch (error) {
        origin = null;
    }

    if (origin && origin !== window.location.pathname && !origin.includes('/documents/')) {
        backButton.setAttribute('href', origin);
    }
});
