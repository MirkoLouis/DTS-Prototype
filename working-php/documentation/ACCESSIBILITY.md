<a id="top"></a>

# Accessibility

How the `working-php` interface works with a keyboard and a screen reader.

The interface is built to be operated without a mouse and read by a screen reader:

- **Modal dialogs** (confirm, signing PIN, backups restore, Users reset, QR scanner, report progress, decline and the guest tracking dialogs) all use one shared module, `public/js/modal-dialog.js`, following the WAI-ARIA modal dialog pattern: they carry `role="dialog"`, `aria-modal="true"` and a label; focus moves into the dialog on open and back to the control that opened it on close; Tab and Shift+Tab stay inside (focus trap); the page behind is `inert`; Escape or a click outside closes the top dialog (stacked dialogs close one at a time). The mandatory Security PIN prompt is the exception: it cannot be dismissed, it keeps the focus and its Log Out button stays reachable, and a refused PIN is explained inside it (an alert linked to both fields). Dialogs that could not be dismissed by Escape or an outside click before and can now: report progress, integrity progress (the job keeps running; the Run button re-opens its progress), the guest track dialog, backups restore and Users reset. Toasts stay visible and live above an open dialog (they are not made inert); they can be dismissed with the pointer, and Tab stays inside the dialog.
- **Menus**: the user menu and the notification list are real buttons with `aria-expanded` and `aria-controls`, and close on Escape with the focus back on their button. The unread count is part of the bell's accessible name.
- **Names and labels**: icon-only buttons and links have an accessible name, decorative icons are hidden from assistive technology, every form control has a label, and failed fields point at their message (`aria-invalid`, `aria-describedby`). Forms carry `autocomplete` hints (login, guest form, password change, Security PIN).
- **Structure and announcements**: every page has exactly one `h1` (the header bar title, the system name on guest pages, the status title on error pages) and no skipped heading level. Toasts and flash messages are live regions (`role="alert"` for errors and warnings, `role="status"` for success and information); while a dialog is open they stay live, and a refused Security PIN is also shown inside the prompt.
- **Forms and password managers**: `autocomplete` is a hint, browsers and password managers decide. The signing PIN field uses `off` and the setup fields `new-password`, but a password manager may still offer to fill or save a Security PIN.
- **Focus** is always visible: no control removes its outline without a focus ring.

Checked by `composer test:accessibility-markup` (HTML level) and by the keyboard pass of the browser check described under "Manual tests" in [TESTING.md](TESTING.md#manual-tests-you-start-these). It has not been tested with a real screen reader.
