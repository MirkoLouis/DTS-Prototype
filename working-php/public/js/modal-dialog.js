/**
 * ModalDialog: the one shared implementation of the WAI-ARIA modal dialog pattern for every modal of the app.
 *
 * Loaded once by both layouts (before any page script). Public API:
 *   ModalDialog.open(element, { opener, dismissible, initialFocus })   show a modal on top of the stack
 *   ModalDialog.close(element, { restoreFocus })                        hide it, returns the opener it belonged to
 *   ModalDialog.closeAll()                                              close every dismissible modal (used before a page swap)
 *   ModalDialog.isOpen(element) / ModalDialog.hasOpen()
 *
 * Declarative markup (works without any page script, also for content swapped in by pjax-router.js):
 *   .open-modal-btn[data-modal="<id>"]      opens the modal with that id
 *   .close-modal-btn                        closes the modal named in data-modal, else the modal it sits in
 *   .close-modal-backdrop                   a click on it dismisses its modal
 *   data-modal-dismissible="false"          Escape and backdrop clicks do nothing (mandatory prompts)
 *   data-modal-initial-focus                the control that gets the focus on open
 *
 * What it does: Escape closes the top modal; focus moves into the modal on open and back to the opener on close;
 * Tab and Shift+Tab cycle inside the top modal (focus trap); everything outside the top modal is made `inert`
 * (see makePageBehindInert); stacked modals close one at a time. The modal element gets a `modaldialog:close`
 * event when it closes, so a page can clean up (cancel a callback, stop a scanner) without wrapping close().
 *
 * Listeners are bound once on `document`, never on modal elements, so replacing a modal (pjax swap) cannot leave a
 * stale or double-bound listener. Entries whose element left the document are dropped on the next operation.
 */
(function () {
    'use strict';

    // A second load (for example a re-executed script tag) must not bind everything again
    if (window.ModalDialog) return;

    // Open modals, bottom first; each entry: { element, opener, dismissible }
    const stack = [];

    // Elements this module made inert, so exactly those are restored later
    let inertedElements = [];

    // Elements the keyboard can reach
    const FOCUSABLE_SELECTOR = 'a[href], area[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"]), [contenteditable="true"]';

    // Element types whose `inert` attribute would be pointless
    const NON_INTERACTIVE_TAGS = ['SCRIPT', 'STYLE', 'LINK', 'META', 'TEMPLATE'];

    // The innermost open modal, or null
    function topEntry() {
        return stack.length > 0 ? stack[stack.length - 1] : null;
    }

    // Is this element rendered and not hidden from the keyboard?
    function isRendered(element) {
        return element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden';
    }

    // Radio buttons form one tab stop per group: the checked one, or the first when none is checked
    function isRadioTabStop(radio, container) {
        if (!radio.name) return true;
        const group = [...container.querySelectorAll('input[type="radio"]')].filter((other) => other.name === radio.name && other.form === radio.form);
        const checked = group.find((other) => other.checked);
        return checked ? checked === radio : group[0] === radio;
    }

    // The keyboard tab stops inside a container, in document order
    function tabbableElements(container) {
        return [...container.querySelectorAll(FOCUSABLE_SELECTOR)].filter((element) => {
            if (!isRendered(element)) return false;
            if (element.type === 'radio') return isRadioTabStop(element, container);
            return true;
        });
    }

    // Choose what receives the focus when a modal opens: the marked control, a form field, any control, else the dialog itself
    function initialFocusTarget(element, requested) {
        if (requested && element.contains(requested)) return requested;
        const marked = element.querySelector('[data-modal-initial-focus], [autofocus]');
        if (marked && isRendered(marked)) return marked;
        const tabbable = tabbableElements(element);
        const firstField = tabbable.find((control) => ['INPUT', 'SELECT', 'TEXTAREA'].includes(control.tagName));
        return firstField || tabbable[0] || element;
    }

    // Move the focus to an element; the dialog container gets no visible outline (it is not a control)
    function focusElement(target, dialog) {
        if (target === dialog) {
            if (!dialog.hasAttribute('tabindex')) dialog.setAttribute('tabindex', '-1');
            dialog.style.outline = 'none';
        }
        target.focus({ preventScroll: false });
    }

    // Can this element take the focus right now?
    function canReceiveFocus(element) {
        return !!element && element.isConnected && !element.closest('[inert]') && isRendered(element) && !element.disabled;
    }

    // Remove every `inert` this module set
    function restoreInertElements() {
        inertedElements.forEach((element) => element.removeAttribute('inert'));
        inertedElements = [];
    }

    /**
     * Make everything outside the top modal inert.
     *
     * Modals sit in different places of the DOM: some are children of <body> (global confirm, Security PIN prompt),
     * others live inside #pjax-content, inside <main>, inside the page wrapper. A single `inert` on "the page" would
     * therefore also disable a modal nested in it. Instead the tree is walked from <body>: the ancestor chains of the top
     * modal and of every live region (`data-modal-live-region`, the toast container) are kept, and every other sibling along
     * those chains is made inert. The result is the same for any placement: the modal, the live regions and their ancestors
     * stay live; the rest of the page (navigation, header, content) and any modal underneath the top one is neither focusable
     * nor exposed to assistive technology. Live regions are exempt so a toast (for example a refused PIN) stays visible,
     * announced and dismissible with the pointer while a dialog is open; Tab cannot reach it because the trap wraps inside the
     * dialog. `inert` is used instead of aria-hidden because it also removes the content from the tab order and from pointer
     * events, which aria-hidden does not.
     */
    function makePageBehindInert() {
        restoreInertElements();
        const top = topEntry();
        if (!top) return;

        // Every ancestor-or-self of the top modal and of each live region
        const keep = new Set();
        for (const anchor of [top.element, ...document.querySelectorAll('[data-modal-live-region]')]) {
            for (let node = anchor; node && node !== document.body; node = node.parentElement) keep.add(node);
        }

        // Make inert what is not kept; descend only into kept ancestors (not into the modal or a live region itself)
        const walk = (parent) => {
            for (const child of parent.children) {
                if (NON_INTERACTIVE_TAGS.includes(child.tagName) || child.hasAttribute('inert')) continue;
                if (!keep.has(child)) {
                    child.setAttribute('inert', '');
                    inertedElements.push(child);
                    continue;
                }
                const isAnchor = child === top.element || child.hasAttribute('data-modal-live-region');
                if (!isAnchor) walk(child);
            }
        };
        walk(document.body);
    }

    // Drop entries whose element was removed from the document (a page swap replaced it)
    function pruneDetached() {
        let removed = false;
        for (let index = stack.length - 1; index >= 0; index--) {
            if (stack[index].element.isConnected) continue;
            stack.splice(index, 1);
            removed = true;
        }
        if (removed) makePageBehindInert();
    }

    // Show a modal element (every modal hides itself with the `hidden` class or an inline display)
    function show(element) {
        element.classList.remove('hidden');
        element.style.display = '';
    }

    // Hide a modal element
    function hide(element) {
        element.classList.add('hidden');
        element.style.display = '';
    }

    // Is this modal dismissible by Escape and by a click outside it?
    function isDismissible(entry) {
        return entry.dismissible;
    }

    /**
     * Open a modal on top of the stack.
     * options.opener: the control to return the focus to (default: the element focused right now)
     * options.dismissible: false for a mandatory prompt (default: the element's data-modal-dismissible, else true)
     * options.initialFocus: an element inside the modal to focus first
     */
    function open(element, options = {}) {
        if (!element) return;
        pruneDetached();
        if (stack.some((entry) => entry.element === element)) return;

        const dismissible = options.dismissible !== undefined
            ? options.dismissible
            : element.getAttribute('data-modal-dismissible') !== 'false';
        const opener = options.opener || (document.activeElement !== document.body ? document.activeElement : null);

        stack.push({ element, opener, dismissible });
        show(element);
        makePageBehindInert();
        focusElement(initialFocusTarget(element, options.initialFocus), element);
    }

    /**
     * Close a modal (and, when it is not the top one, only that one). Returns the opener it belonged to.
     * options.restoreFocus: false when the caller moves the focus itself (default true)
     */
    function close(element, options = {}) {
        pruneDetached();
        const index = stack.findIndex((entry) => entry.element === element);
        if (index === -1) return null;

        const [entry] = stack.splice(index, 1);
        const wasTop = index === stack.length;
        hide(element);
        makePageBehindInert();
        element.dispatchEvent(new CustomEvent('modaldialog:close', { bubbles: true }));

        if (options.restoreFocus === false || !wasTop) return entry.opener;
        restoreFocusAfterClose(entry);
        return entry.opener;
    }

    // Return the focus to the opener; when it is gone, to the modal below, else nowhere in particular
    function restoreFocusAfterClose(closedEntry) {
        if (canReceiveFocus(closedEntry.opener)) {
            closedEntry.opener.focus();
            return;
        }
        const below = topEntry();
        if (below) focusElement(initialFocusTarget(below.element), below.element);
    }

    // Close every dismissible modal without moving the focus (the page is about to be replaced)
    function closeAll() {
        pruneDetached();
        for (const entry of [...stack].reverse()) {
            if (isDismissible(entry)) close(entry.element, { restoreFocus: false });
        }
    }

    // Escape closes the top modal; Tab wraps inside it
    document.addEventListener('keydown', (event) => {
        // A dialog whose node left the document (page swap) must never keep trapping Tab
        pruneDetached();
        const top = topEntry();
        if (!top) return;
        if (event.key === 'Escape') {
            if (!isDismissible(top)) return;
            event.preventDefault();
            close(top.element);
            return;
        }
        if (event.key === 'Tab') trapTab(event, top);
    });

    // Focus trap: Tab on the last control goes to the first, Shift+Tab on the first goes to the last
    function trapTab(event, top) {
        const tabbable = tabbableElements(top.element);
        if (tabbable.length === 0) {
            event.preventDefault();
            focusElement(top.element, top.element);
            return;
        }
        const first = tabbable[0];
        const last = tabbable[tabbable.length - 1];
        const active = document.activeElement;
        if (!top.element.contains(active)) {
            event.preventDefault();
            first.focus();
            return;
        }
        if (event.shiftKey && (active === first || active === top.element)) {
            event.preventDefault();
            last.focus();
            return;
        }
        if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    // Safety net: focus that lands outside the top modal (for example from the browser UI) is pulled back in
    // Toasts are the exception: a pointer user can dismiss one, so focus inside the live region is left alone
    // (Tab cannot reach it: the trap wraps inside the dialog)
    document.addEventListener('focusin', (event) => {
        pruneDetached();
        const top = topEntry();
        if (!top || top.element.contains(event.target)) return;
        if (event.target instanceof Element && event.target.closest('[data-modal-live-region]')) return;
        focusElement(initialFocusTarget(top.element), top.element);
    });

    // A click outside only dismisses when the press started on the backdrop too (selecting text and releasing outside keeps the modal)
    let pressTarget = null;
    document.addEventListener('pointerdown', (event) => { pressTarget = event.target; });

    // Declarative open, close and backdrop handling, by delegation on document
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const openButton = target.closest('.open-modal-btn');
        if (openButton) {
            event.preventDefault();
            open(document.getElementById(openButton.dataset.modal), { opener: openButton });
            return;
        }

        const closeButton = target.closest('.close-modal-btn');
        if (closeButton) {
            const modal = closeButton.dataset.modal ? document.getElementById(closeButton.dataset.modal) : closeButton.closest('[role="dialog"]');
            if (modal) close(modal);
            return;
        }

        const top = topEntry();
        if (!top || !isDismissible(top)) return;
        const onBackdrop = target === top.element || (target.classList.contains('close-modal-backdrop') && top.element.contains(target));
        if (onBackdrop && pressTarget === target) close(top.element);
    });

    // After a page swap: forget modals that were replaced and make sure nothing stays inert
    document.addEventListener('dts:page-loaded', () => {
        pruneDetached();
        makePageBehindInert();
    });

    window.ModalDialog = {
        open,
        close,
        closeAll,
        isOpen: (element) => stack.some((entry) => entry.element === element),
        hasOpen: () => stack.length > 0,
    };
})();
