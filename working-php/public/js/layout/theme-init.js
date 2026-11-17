/**
 * Applies the saved theme (light, dark, or the device setting) before the first paint.
 *
 * Loaded as a blocking script in the head of every page (app, guest, login and error pages), so the page never
 * flashes in the wrong theme. The toggle button is public/js/layout/theme-toggle.js.
 */
(function () {
    'use strict';

    // The saved choice, or null when there is none (or the storage is not available)
    let savedTheme = null;
    try {
        savedTheme = localStorage.getItem('theme');
    } catch (error) {
        savedTheme = null;
    }

    // Dark when chosen, or when nothing is chosen and the device prefers dark
    const isDark = savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', isDark);
})();
