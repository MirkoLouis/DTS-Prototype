/**
 * DtsPinToken: the encrypted Security PIN cache token of this browser tab, in session storage.
 *
 * The ONE place that knows the storage key. The PIN itself is never stored. Written by layout/server-handoff.js, read by
 * components/signing-modal.js, discarded by pages/login.js. Every method tolerates a storage that is not available.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'dts_pin_token';

    window.DtsPinToken = {
        // The token, or null
        read() {
            try {
                return sessionStorage.getItem(STORAGE_KEY);
            } catch (error) {
                return null;
            }
        },

        // Keep a token the server issued
        store(token) {
            try {
                sessionStorage.setItem(STORAGE_KEY, token);
            } catch (error) {
                // Session storage is not available: the PIN is asked for every time
            }
        },

        // Remove the plaintext PIN that earlier builds stored
        clearLegacyPin() {
            try {
                sessionStorage.removeItem('dts_security_pin');
            } catch (error) {
                // Nothing to discard
            }
        },

        // Discard the token (and the plaintext PIN that earlier builds stored)
        clear() {
            try {
                sessionStorage.removeItem(STORAGE_KEY);
            } catch (error) {
                // Nothing to discard
            }
            this.clearLegacyPin();
        },
    };
})();
