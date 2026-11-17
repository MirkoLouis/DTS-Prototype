/**
 * Notification bell, notification list and toasts of the app layout.
 *
 * Bound once per full page load (the bell, list and toast container sit outside #pjax-content; the router copies the
 * new page's bell and list into them). All clicks are handled by ONE delegated listener on document.
 *
 *   - toasts close themselves after 5 seconds, or on their dismiss / mark-as-read button
 *   - a poll every 30 seconds (and after every in-app navigation) shows new unread notifications as toasts
 *   - the bell's accessible name carries the unread count (there is no visual badge)
 */
(function () {
    'use strict';

    // How long a toast stays before it fades out, and how often the server is asked for new notifications
    const TOAST_VISIBLE_MILLISECONDS = 5000;
    const TOAST_FADE_MILLISECONDS = 500;
    const POLL_INTERVAL_MILLISECONDS = 30000;

    // Only an internal path ("/x": no "//host", whitespace, control characters or backslashes) is linked from a toast
    const INTERNAL_PATH_PATTERN = /^\/(?!\/)[^\x00-\x20\x7f\\]*$/;

    // Colors and icon of a toast by notification type (the static shell below is built from these constants only)
    const TOAST_STYLES = {
        info: {
            border: 'border-blue-500', background: 'bg-blue-50 dark:bg-blue-900', icon: 'text-blue-700 dark:text-blue-400',
            title: 'text-blue-800 dark:text-blue-300', text: 'text-blue-700 dark:text-blue-400',
            iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />',
        },
        success: {
            border: 'border-green-500', background: 'bg-green-50 dark:bg-green-900', icon: 'text-green-700 dark:text-green-400',
            title: 'text-green-800 dark:text-green-300', text: 'text-green-700 dark:text-green-400',
            iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />',
        },
        error: {
            border: 'border-red-500', background: 'bg-red-50 dark:bg-red-900', icon: 'text-red-700 dark:text-red-400',
            title: 'text-red-800 dark:text-red-300', text: 'text-red-700 dark:text-red-400',
            iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />',
        },
        warning: {
            border: 'border-yellow-500', background: 'bg-yellow-50 dark:bg-yellow-900', icon: 'text-yellow-700 dark:text-yellow-400',
            title: 'text-yellow-800 dark:text-yellow-300', text: 'text-yellow-700 dark:text-yellow-400',
            iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />',
        },
    };

    // Ids of notifications that already had their toast (survives in-app navigation)
    window.__shownNotifIds = window.__shownNotifIds || new Set();

    // The ids of the unread notifications (seeded from the list on each page, replaced by every poll, shrunk when read)
    const unreadNotificationIds = new Set();

    console.log('[DTS Notif System] Initialized. Existing known notifications count:', window.__shownNotifIds.size);

    // The ONE place that updates the bell's accessible name from the unread count
    function refreshNotificationButtonText() {
        const text = document.getElementById('notification-button-text');
        if (!text) return;
        const unreadCount = unreadNotificationIds.size;
        text.textContent = unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications';
    }

    // Show or hide the notification list and mirror the state in the bell's aria-expanded
    function setNotificationDropdownOpen(isOpen) {
        const dropdown = document.getElementById('notification-dropdown-menu');
        const bell = document.getElementById('notification-menu-button');
        if (!dropdown) return;
        dropdown.classList.toggle('hidden', !isOpen);
        if (bell) bell.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    // Tell the server that one notification (id) or all of them (no id) were read; resolves to true on success
    async function markNotificationsRead(notificationId) {
        const body = notificationId === undefined ? {} : { id: notificationId };
        const response = await fetch('/api/notifications/mark-read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': DtsUtil.csrfToken(),
            },
            body: JSON.stringify(body),
        });
        const data = await response.json();
        return Boolean(data.success);
    }

    // Auto-dismiss toast messages after 5 seconds
    function setupToastAutoDismiss() {
        const toasts = document.querySelectorAll('#toast-container .toast-message');
        console.log(`[DTS Notif System] setupToastAutoDismiss running. Found ${toasts.length} toast message(s).`);

        toasts.forEach((toast, index) => {
            if (toast.dataset.dismissScheduled) return;
            toast.dataset.dismissScheduled = 'true';
            console.log(`[DTS Notif System] Toast #${index + 1} scheduled for auto-dismiss in 5s.`);

            setTimeout(() => {
                if (!toast.parentNode) return;
                console.log(`[DTS Notif System] Fading out Toast #${index + 1}.`);
                toast.style.transition = 'opacity 0.5s ease-out';
                toast.style.opacity = '0';
                setTimeout(() => {
                    toast.remove();
                    console.log(`[DTS Notif System] Removed Toast #${index + 1} from DOM.`);
                }, TOAST_FADE_MILLISECONDS);
            }, TOAST_VISIBLE_MILLISECONDS);
        });
    }

    // Seed known unread notification IDs from the DOM on load
    function syncShownNotificationIds() {
        const items = document.querySelectorAll('#notification-list .mark-notification-read');
        items.forEach((button) => {
            if (button.dataset.id) {
                window.__shownNotifIds.add(String(button.dataset.id));
            }
        });

        // The list rendered by the server is the truth for this page: rebuild the unread set and the bell's name from it
        unreadNotificationIds.clear();
        items.forEach((button) => {
            if (button.dataset.id) unreadNotificationIds.add(String(button.dataset.id));
        });
        refreshNotificationButtonText();
        console.log(`[DTS Notif System] Synced ${items.length} unread notification IDs into memory set.`);
    }

    // Build the toast element for a notification: a static shell, then the server text inserted with textContent so it never parses as HTML
    function buildToast(notification, notificationId) {
        const style = TOAST_STYLES[notification.type] || TOAST_STYLES.info;
        const role = notification.type === 'error' || notification.type === 'warning' ? 'alert' : 'status';

        // Only constants (classes, icon path, role) are placed in this markup
        const holder = document.createElement('div');
        holder.innerHTML = `
            <div role="${role}" data-notification-item class="rounded-md border ${style.border} ${style.background} p-4 shadow-sm mb-3 relative toast-message pointer-events-auto animate-slide-in-right">
                <div class="flex items-start gap-4">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="-mt-0.5 w-6 h-6 ${style.icon}">
                        ${style.iconPath}
                    </svg>
                    <div class="flex-1 pr-6">
                        <strong class="toast-title block leading-tight font-medium ${style.title}"></strong>
                        <p class="toast-text mt-0.5 text-sm ${style.text}"></p>
                        <a class="toast-link notification-link mt-2 inline-block text-sm font-medium underline ${style.text}"></a>
                    </div>
                    <button type="button" class="mark-notification-read absolute top-4 right-4 rounded text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none focus:ring-2 focus:ring-accent-1 transition" title="Dismiss" aria-label="Dismiss notification">
                        <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
            </div>
        `;
        const toastElement = holder.firstElementChild;
        toastElement.querySelector('.toast-title').textContent = String(notification.title ?? '');
        toastElement.querySelector('.toast-text').textContent = String(notification.message ?? '');

        // Show the optional link only for an internal path
        const toastLink = toastElement.querySelector('.toast-link');
        const linkPath = typeof notification.link === 'string' ? notification.link : '';
        if (INTERNAL_PATH_PATTERN.test(linkPath)) {
            toastLink.setAttribute('href', linkPath);
            toastLink.textContent = String(notification.link_label || 'Open');
        } else {
            toastLink.remove();
        }

        toastElement.querySelector('.mark-notification-read').dataset.id = notificationId;
        return toastElement;
    }

    // Pop a toast for every notification that has not had one yet
    function showNewNotificationToasts(notifications) {
        const toastContainer = document.getElementById('toast-container');
        notifications.forEach((notification) => {
            const notificationId = String(notification.id);
            if (window.__shownNotifIds.has(notificationId)) return;
            window.__shownNotifIds.add(notificationId);
            console.log(`[DTS Notif System] New unread notification detected (ID ${notification.id}): "${notification.title}" - popping toast modal.`);

            if (!toastContainer) return;
            toastContainer.appendChild(buildToast(notification, notificationId));
            setupToastAutoDismiss();
        });
    }

    // Fetch unread notifications from the API to capture background job updates
    async function pollUnreadNotifications() {
        console.log('[DTS Notif System] Polling /api/notifications/unread...');
        try {
            const response = await fetch('/api/notifications/unread');

            // The session ended (signed out or account deactivated): show the login page and its message
            if (response.status === 401) {
                window.location.href = '/login';
                return;
            }
            if (!response.ok) {
                console.warn('[DTS Notif System] Poll response non-OK status:', response.status);
                return;
            }

            const data = await response.json();
            console.log('[DTS Notif System] Poll response received:', data);
            if (!data.success || !Array.isArray(data.notifications)) return;

            // The poll lists every unread notification: it is the truth for the bell's name
            unreadNotificationIds.clear();
            data.notifications.forEach((notification) => unreadNotificationIds.add(String(notification.id)));
            refreshNotificationButtonText();

            showNewNotificationToasts(data.notifications);
        } catch (error) {
            console.error('[DTS Notif System] Error polling unread notifications:', error);
        }
    }

    // The bell toggles the list; only one overlay at a time, so opening it closes the navigation menu
    function handleBellClick(event, bell) {
        event.stopPropagation();
        document.dispatchEvent(new CustomEvent('dts:close-overlays', { detail: { keep: 'notification-dropdown' } }));
        const dropdown = document.getElementById('notification-dropdown-menu');
        if (!dropdown) return;
        setNotificationDropdownOpen(dropdown.classList.contains('hidden'));
        console.log('[DTS Notif System] Toggled dropdown menu. Hidden:', dropdown.classList.contains('hidden'));
    }

    // One notification was read: remove it and update the count
    async function handleMarkRead(event, button) {
        event.stopPropagation();
        const notificationId = button.dataset.id;
        if (!notificationId) return;

        console.log(`[DTS Notif System] Marking notification ID ${notificationId} as read.`);
        try {
            if (!(await markNotificationsRead(notificationId))) return;
            console.log(`[DTS Notif System] Notification ID ${notificationId} marked read successfully.`);
            button.closest('[data-notification-item]')?.remove();
            unreadNotificationIds.delete(String(notificationId));
            refreshNotificationButtonText();
        } catch (error) {
            console.error('[DTS Notif System] Error marking notification read:', error);
        }
    }

    // "Clear Notifications": every notification was read
    async function handleMarkAllRead(event, button) {
        event.stopPropagation();
        console.log('[DTS Notif System] Marking ALL notifications as read.');
        try {
            if (!(await markNotificationsRead())) return;
            console.log('[DTS Notif System] All notifications marked read successfully.');
            const list = document.getElementById('notification-list');
            if (list) {
                // A constant message (no data) replaces the list
                list.innerHTML = '<p class="text-sm text-gray-500 dark:text-gray-400 p-4 text-center">No new notifications.</p>';
            }
            button.remove();
            unreadNotificationIds.clear();
            refreshNotificationButtonText();
        } catch (error) {
            console.error('[DTS Notif System] Error marking all notifications read:', error);
        }
    }

    // Close the list when a click lands outside it
    function closeListOnOutsideClick(event) {
        const dropdown = document.getElementById('notification-dropdown-menu');
        if (!dropdown || dropdown.contains(event.target) || event.target.closest('#notification-menu-button')) return;
        if (dropdown.classList.contains('hidden')) return;
        setNotificationDropdownOpen(false);
        console.log('[DTS Notif System] Closed dropdown menu on outside click.');
    }

    // Global event delegation for the bell, dismiss and mark-as-read buttons
    document.addEventListener('click', (event) => {
        const bell = event.target.closest('#notification-menu-button');
        if (bell) {
            handleBellClick(event, bell);
            return;
        }

        closeListOnOutsideClick(event);

        const markReadButton = event.target.closest('.mark-notification-read');
        if (markReadButton) {
            handleMarkRead(event, markReadButton);
            return;
        }

        const dismissButton = event.target.closest('.dismiss-alert');
        if (dismissButton) {
            event.stopPropagation();
            console.log('[DTS Notif System] Dismissing alert manually.');
            dismissButton.closest('[data-notification-item]')?.remove();
            return;
        }

        const markAllButton = event.target.closest('#mark-all-read');
        if (markAllButton) {
            handleMarkAllRead(event, markAllButton);
        }
    });

    // Escape closes the open list and returns the focus to the bell (a modal dialog handles Escape itself)
    document.addEventListener('keydown', (event) => {
        // One Escape closes one layer: ignore the one a dialog already handled (it set defaultPrevented)
        if (event.key !== 'Escape' || event.defaultPrevented || (window.ModalDialog && window.ModalDialog.hasOpen())) return;
        const dropdown = document.getElementById('notification-dropdown-menu');
        if (!dropdown || dropdown.classList.contains('hidden')) return;
        setNotificationDropdownOpen(false);
        document.getElementById('notification-menu-button')?.focus();
    });

    // Another part of the page asks the overlays to close (unless one is named to stay)
    document.addEventListener('dts:close-overlays', (event) => {
        if (event.detail?.keep !== 'notification-dropdown') setNotificationDropdownOpen(false);
    });

    // Lifecycle: first load, then every in-app navigation
    document.addEventListener('DOMContentLoaded', () => {
        console.log('[DTS Notif System] Lifecycle: DOMContentLoaded');
        setupToastAutoDismiss();
        syncShownNotificationIds();
    });

    document.addEventListener('dts:page-loaded', () => {
        console.log('[DTS Notif System] Lifecycle: dts:page-loaded');
        setupToastAutoDismiss();
        syncShownNotificationIds();
        pollUnreadNotifications();
    });

    // Periodically poll for background notifications
    setInterval(pollUnreadNotifications, POLL_INTERVAL_MILLISECONDS);
})();
