/**
 * Reads the data the layout hands over from the server as inert JSON blocks (see DtsData in core/json-data.js):
 *   - #dts-user-config   the signed-in user, exposed as window.UserConfig
 *   - #dts-pin-token     the encrypted Security PIN cache token to store, or an order to discard a stale one
 *
 * Runs once per full page load. The blocks are not part of #pjax-content, so in-app navigation does not repeat it.
 */
(function () {
    'use strict';

    // The signed-in user (id is null on pages without a session)
    window.UserConfig = DtsData.read('dts-user-config', { id: null });

    // Store or discard the PIN cache token (the PIN itself is never stored)
    const pinToken = DtsData.read('dts-pin-token', null);
    if (pinToken !== null) {
        if (pinToken.clear) DtsPinToken.clear();
        if (pinToken.issued) DtsPinToken.store(pinToken.issued);
    }
})();
