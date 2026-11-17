/**
 * Theme toggle button (sun and moon icon) of the app, guest and login layouts.
 *
 * The toggle sits outside #pjax-content, so it is bound once per full page load. The saved choice is applied before
 * the first paint by public/js/layout/theme-init.js.
 */
(function () {
    'use strict';

    const toggleButton = document.getElementById('theme-toggle');
    const darkIcon = document.getElementById('theme-toggle-dark-icon');
    const lightIcon = document.getElementById('theme-toggle-light-icon');
    if (!toggleButton || !darkIcon || !lightIcon) return;

    // The saved choice, or null when there is none (or the storage is not available)
    function readSavedTheme() {
        try {
            return localStorage.getItem('theme');
        } catch (error) {
            return null;
        }
    }

    // Save the choice; a storage that refuses writes only costs the persistence
    function saveTheme(theme) {
        try {
            localStorage.setItem('theme', theme);
        } catch (error) {
            // The theme still changes for this page
        }
    }

    // The sun icon is shown in dark mode (click for light), the moon icon in light mode
    const startsDark = readSavedTheme() === 'dark'
        || (readSavedTheme() === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
    lightIcon.classList.toggle('hidden', !startsDark);
    darkIcon.classList.toggle('hidden', startsDark);

    toggleButton.addEventListener('click', () => {
        darkIcon.classList.toggle('hidden');
        lightIcon.classList.toggle('hidden');

        // With a saved choice the opposite is chosen; without one the current appearance is inverted
        const savedTheme = readSavedTheme();
        const makeDark = savedTheme ? savedTheme === 'light' : !document.documentElement.classList.contains('dark');
        document.documentElement.classList.toggle('dark', makeDark);
        saveTheme(makeDark ? 'dark' : 'light');
    });
})();
