/**
 * Login page (src/Views/auth/login.php).
 *
 * A fresh login must never reuse a PIN-cache token from a previous session, so the stored token (and the plaintext PIN
 * of earlier builds) is discarded when the page opens.
 */
DtsPage.onLoad('[data-page="login"]', function initLoginPage() {
    'use strict';

    DtsPinToken.clear();
});
