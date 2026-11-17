/**
 * DtsData: reads server data from a <script type="application/json" id="..."> block.
 *
 * The block is written by App\Common\Utils\JsonDataBlock::render() with "<", ">", "&" and quotes escaped, and the
 * browser never executes it. It is the only way server data reaches a script besides data-* attributes.
 *
 *     const config = DtsData.read('dts-user-config', {});
 */
(function () {
    'use strict';

    window.DtsData = {
        /**
         * Parse the block with the given id. Returns the fallback when the block is missing, is not a JSON block
         * or does not contain valid JSON.
         */
        read(elementId, fallback = null) {
            const element = document.getElementById(elementId);
            // Only a JSON data block is read; any other element (an executable script, an attribute carrier) is refused
            if (!element || element.tagName !== 'SCRIPT' || element.type !== 'application/json') return fallback;

            try {
                return JSON.parse(element.textContent);
            } catch (error) {
                console.error('[DtsData] Invalid JSON in #' + elementId, error);
                return fallback;
            }
        },
    };
})();
