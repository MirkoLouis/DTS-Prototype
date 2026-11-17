/**
 * Navigation menu (below the lg breakpoint) and the user menu of the app layout.
 *
 * Both menus sit outside #pjax-content, so they are bound once per full page load. Overlays close each other through
 * the `dts:close-overlays` event: dispatch it with detail.keep set to the overlay that stays open (or nothing to close
 * all). The router dispatches it after in-app navigation; notifications.js listens too.
 */
(function () {
    'use strict';

    const mobileMenuButton = document.getElementById('mobile-menu-button');
    const mobileMenu = document.getElementById('mobile-menu');
    const userMenuButton = document.getElementById('user-menu-button');
    const userDropdownMenu = document.getElementById('user-dropdown-menu');

    // Tailwind's lg breakpoint (1024px): from here up the desktop bar replaces the menu
    const DESKTOP_NAV_MEDIA_QUERY = '(min-width: 1024px)';

    // Hide the navigation menu and reset the button state and icons
    function closeMobileMenu() {
        if (!mobileMenu) return;
        mobileMenu.classList.add('hidden');
        mobileMenuButton.setAttribute('aria-expanded', 'false');
        document.getElementById('mobile-menu-open-icon').classList.remove('hidden');
        document.getElementById('mobile-menu-close-icon').classList.add('hidden');
    }

    // Show or hide the user menu and mirror the state in aria-expanded
    function setUserMenuOpen(isOpen) {
        userDropdownMenu.classList.toggle('hidden', !isOpen);
        userMenuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    // Crossing into desktop width closes the menu so aria-expanded and the icon never go stale
    window.matchMedia(DESKTOP_NAV_MEDIA_QUERY).addEventListener('change', (event) => {
        if (event.matches) closeMobileMenu();
    });

    // Another part of the page asks the overlays to close (unless one is named to stay)
    document.addEventListener('dts:close-overlays', (event) => {
        if (event.detail?.keep !== 'mobile-menu') closeMobileMenu();
    });

    // The button toggles the menu and mirrors the state in aria-expanded
    if (mobileMenuButton && mobileMenu) {
        mobileMenuButton.addEventListener('click', () => {
            // Only one overlay at a time: opening the menu closes the notification list
            document.dispatchEvent(new CustomEvent('dts:close-overlays', { detail: { keep: 'mobile-menu' } }));
            const isOpen = !mobileMenu.classList.toggle('hidden');
            mobileMenuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            document.getElementById('mobile-menu-open-icon').classList.toggle('hidden', isOpen);
            document.getElementById('mobile-menu-close-icon').classList.toggle('hidden', !isOpen);
        });
    }

    if (userMenuButton && userDropdownMenu) {
        userMenuButton.addEventListener('click', (event) => {
            event.stopPropagation();
            setUserMenuOpen(userDropdownMenu.classList.contains('hidden'));
        });

        // A click anywhere else closes the user menu
        document.addEventListener('click', (event) => {
            if (!userMenuButton.contains(event.target) && !userDropdownMenu.contains(event.target)) {
                setUserMenuOpen(false);
            }
        });
    }

    // Escape closes the open user menu or navigation menu and returns the focus to the button that opened it
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        // A modal dialog handles Escape itself (public/js/modal-dialog.js, which marks it defaultPrevented): one Escape closes one layer
        if (event.defaultPrevented || (window.ModalDialog && window.ModalDialog.hasOpen())) return;

        if (userDropdownMenu && !userDropdownMenu.classList.contains('hidden')) {
            setUserMenuOpen(false);
            userMenuButton.focus();
            return;
        }
        if (mobileMenu && !mobileMenu.classList.contains('hidden')) {
            closeMobileMenu();
            mobileMenuButton.focus();
        }
    });
})();
