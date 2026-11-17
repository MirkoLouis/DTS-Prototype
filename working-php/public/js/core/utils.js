/**
 * DtsUtil: small helpers shared by several scripts.
 */
(function () {
    'use strict';

    window.DtsUtil = {
        // The CSRF token of the current page (the router keeps the meta tag current after in-app navigation)
        csrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        },

        // "Soon...", "42s" or "3m 5s" for a number of seconds still to wait (report and integrity progress)
        formatRemainingTime(seconds) {
            if (seconds < 1) return 'Soon...';
            if (seconds < 60) return `${Math.round(seconds)}s`;
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = Math.round(seconds % 60);
            return `${minutes}m ${remainingSeconds}s`;
        },
    };
})();
