/**
 * DtsPage: the ONE registration mechanism for page and component scripts.
 *
 * Every script file is loaded once per full page load (deferred, from the layout). A script that needs to set up
 * elements of a page registers an initializer:
 *
 *     DtsPage.onLoad('[data-page="backups"]', function (root) {
 *         // bind to elements inside root, start timers ...
 *         return function teardown() { // optional: stop timers and pending work
 *         };
 *     });
 *
 * The initializer runs when the page's root element (found with the selector) is present:
 *   - on the first load (DOMContentLoaded), and
 *   - after every in-app navigation (`dts:page-loaded`, dispatched by pjax-router.js after it swapped #pjax-content).
 * The teardown it returns runs before the content is swapped (`dts:before-swap`), so timers and polling of the page
 * that is left stop. An initializer never runs twice for the same root element, so listeners are never bound twice.
 *
 * Behaviour that needs no per-page setup (for example a click on a data-attribute button) does not register at all:
 * it uses event delegation on document, bound once when the file loads.
 */
(function () {
    'use strict';

    // Every registration: { selector, init, initializedRoots }
    const registrations = [];

    // Teardown functions of the page that is currently shown
    let activeTeardowns = [];

    // True once the first page load has been initialized (registrations made later run at once)
    let started = document.readyState === 'complete';

    // Run one registration when its root element exists and has not been initialized yet
    function runRegistration(registration) {
        const root = document.querySelector(registration.selector);
        if (!root || registration.initializedRoots.has(root)) return;
        registration.initializedRoots.add(root);

        try {
            const teardown = registration.init(root);
            if (typeof teardown === 'function') activeTeardowns.push(teardown);
        } catch (error) {
            // One failing page script must not stop the others
            console.error('[DtsPage] Initializer failed for ' + registration.selector, error);
        }
    }

    // Run every registration whose root element is on the page
    function runAll() {
        registrations.forEach(runRegistration);
    }

    // Stop the page that is about to be replaced
    function teardownAll() {
        const teardowns = activeTeardowns;
        activeTeardowns = [];
        teardowns.forEach((teardown) => {
            try {
                teardown();
            } catch (error) {
                console.error('[DtsPage] Teardown failed', error);
            }
        });
    }

    window.DtsPage = {
        /**
         * Register an initializer for the page (or component) whose root element matches the selector.
         * init(rootElement) may return a teardown function.
         */
        onLoad(selector, init) {
            const registration = { selector, init, initializedRoots: new WeakSet() };
            registrations.push(registration);
            // A script that loads after the first initialization is initialized at once
            if (started) runRegistration(registration);
        },
    };

    // First load: initialize after every deferred script has registered
    document.addEventListener('DOMContentLoaded', () => {
        started = true;
        runAll();
    });

    // In-app navigation: stop the old page, then initialize the new one
    document.addEventListener('dts:before-swap', teardownAll);
    document.addEventListener('dts:page-loaded', runAll);
})();
