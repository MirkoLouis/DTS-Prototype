// Keyboard and markup pass of the accessibility slice, driven through playwright-core. Started by the one launcher
// scripts/manual/page-screenshots.php after the layout check; argument: context JSON file.
//
// For EACH modal dialog (global confirm, signing PIN alone and on top of another modal, Users reset, backups restore,
// scan QR, report progress, integrity progress, verify hash, past reports, average TAT, decline, guest track and guest QR):
// open it with the keyboard from its opener (a few use a plain test button that calls the page function: see the spec list),
// assert focus is inside, press Tab and Shift+Tab at least (number of controls + 2) times and assert focus never leaves it
// and wraps in both directions, assert the page behind is inert or hidden from assistive technology while a toast stays
// live, press Escape and assert it closed and focus is back on the opener. For a stack, after the top one closes the lower
// one's trap and inert background are checked again, and a separate case closes the lower dialog first. The mandatory
// Security PIN prompt must survive Escape and a backdrop click, and show a refused PIN inside itself. After an in-app
// navigation with a modal open no modal may be open, nothing may be inert and Tab must move through the page; after an
// in-app navigation a confirm-action form must still ask before it sends anything. The report flow must not start a second job.
// On every page: exactly one h1, no skipped heading level, every button and link has an accessible name with at least one
// letter or digit, every form control has a label, no duplicate ids, every role="dialog" is complete. One line per
// page/modal; exit 1 on a failure.
import { allowConsoleError, context, createSessions, launchBrowser, pages, takeDiagnostics } from './browser-common.mjs';

// The 404 page is loaded on purpose, and the refused-PIN check provokes one refused request: the browser logs both
allowConsoleError(/status of 404/, 'the 404 page is a page of this check');
allowConsoleError(/status of (401|403|422|429)/, 'the refused PIN check');

const viewports = [{ width: 1280, height: 900 }, { width: 360, height: 800 }];

// Elements the keyboard can reach (shared by the in-page helpers below)
const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

// Markup audit of the whole document (runs inside the page): returns a list of problems
function auditDocument() {
    const problems = [];
    const describe = (element) => `${element.tagName.toLowerCase()}${element.id ? '#' + element.id : ''}${element.textContent ? ` "${element.textContent.replace(/\s+/g, ' ').trim().slice(0, 30)}"` : ''}`;

    // Text a node contributes to an accessible name: skips aria-hidden subtrees, uses img alt and svg title
    const textOf = (node) => {
        if (node.nodeType === 3) return node.textContent;
        if (node.nodeType !== 1 || node.getAttribute('aria-hidden') === 'true') return '';
        if (node.tagName === 'IMG') return node.getAttribute('alt') || '';
        return [...node.childNodes].map(textOf).join(' ');
    };
    const labelledByText = (element) => (element.getAttribute('aria-labelledby') || '')
        .split(/\s+/).filter(Boolean).map((id) => (document.getElementById(id) ? textOf(document.getElementById(id)) : '')).join(' ').trim();
    const nameOf = (element) => {
        const labelledBy = labelledByText(element);
        if (labelledBy) return labelledBy;
        const ariaLabel = (element.getAttribute('aria-label') || '').trim();
        if (ariaLabel) return ariaLabel;
        if (element.tagName === 'INPUT' && ['submit', 'button', 'reset'].includes(element.type)) return element.value.trim();
        const content = textOf(element).replace(/\s+/g, ' ').trim();
        if (content) return content;
        return (element.getAttribute('title') || '').trim();
    };
    const exposed = (element) => !element.closest('[aria-hidden="true"]');

    // Exactly one h1
    const headingCount = document.querySelectorAll('h1').length;
    if (headingCount !== 1) problems.push(`${headingCount} h1 elements`);

    // Heading order: the page (dialogs excluded) may not skip a level; every dialog starts at h2 and may not skip either
    const checkOrder = (headings, firstAllowed, label) => {
        let previous = 0;
        for (const heading of headings) {
            const level = Number(heading.tagName[1]);
            const skipped = previous > 0 ? level > previous + 1 : level > firstAllowed;
            if (skipped) problems.push(`${label} heading skips ${previous > 0 ? 'h' + previous : 'to'} h${level} ("${heading.textContent.trim().slice(0, 30)}")`);
            previous = level;
        }
    };
    const pageHeadings = [...document.querySelectorAll('h1, h2, h3, h4, h5, h6')].filter((heading) => !heading.closest('[role="dialog"]'));
    checkOrder(pageHeadings, 2, 'page');
    for (const dialog of document.querySelectorAll('[role="dialog"]')) {
        const dialogHeadings = [...dialog.querySelectorAll('h1, h2, h3, h4, h5, h6')];
        if (dialogHeadings.some((heading) => heading.tagName === 'H1')) problems.push(`dialog #${dialog.id} contains an h1`);
        checkOrder(dialogHeadings, 2, `dialog #${dialog.id}`);
    }

    // Every button and link has an accessible name
    for (const control of document.querySelectorAll('a[href], button, [role="button"], input[type="submit"], input[type="button"]')) {
        // A name made only of symbols or punctuation (×, «, », …, +) does not count: it needs a letter or a digit
        if (exposed(control) && !/[\p{L}\p{N}]/u.test(nameOf(control))) problems.push(`no accessible name: ${describe(control)} (${control.outerHTML.slice(0, 60).replace(/\s+/g, ' ')})`);
    }

    // Every form control has a label
    for (const field of document.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]), select, textarea')) {
        if (!exposed(field)) continue;
        const labels = field.labels ? [...field.labels].map((label) => textOf(label).trim()).filter(Boolean) : [];
        const named = labels.length > 0 || (field.getAttribute('aria-label') || '').trim() !== '' || labelledByText(field) !== '';
        if (!named) problems.push(`no label: ${field.tagName.toLowerCase()}#${field.id}[name=${field.getAttribute('name')}]`);
    }

    // No duplicate ids
    const seen = new Map();
    for (const element of document.querySelectorAll('[id]')) seen.set(element.id, (seen.get(element.id) || 0) + 1);
    for (const [id, count] of seen) if (count > 1) problems.push(`duplicate id "${id}" (${count}x)`);

    // Every dialog is complete: aria-modal and a label that resolves
    for (const dialog of document.querySelectorAll('[role="dialog"]')) {
        if (dialog.getAttribute('aria-modal') !== 'true') problems.push(`dialog #${dialog.id} lacks aria-modal="true"`);
        if (labelledByText(dialog) === '' && (dialog.getAttribute('aria-label') || '').trim() === '') problems.push(`dialog #${dialog.id} has no resolvable label`);
    }
    return problems;
}

// Is the element rendered (not display none, not zero-sized)? (runs inside the page)
function isRendered(selector) {
    const element = document.querySelector(selector);
    return !!element && element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden';
}

// Rendered focusable elements inside a container (runs inside the page)
function tabbableCount({ selector, focusable }) {
    const container = document.querySelector(selector);
    if (!container) return 0;
    return [...container.querySelectorAll(focusable)].filter((element) => element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden').length;
}

// Is the focus inside the container? (runs inside the page)
function focusIsInside(selector) {
    const container = document.querySelector(selector);
    return !!container && container.contains(document.activeElement) && document.activeElement !== container.ownerDocument.body;
}

// Put the focus on the first or last control of a dialog (runs inside the page)
function focusEnd({ selector, focusable, which }) {
    const list = [...document.querySelector(selector).querySelectorAll(focusable)].filter((el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden');
    list[which === 'first' ? 0 : list.length - 1]?.focus();
}

// Is the focus on the first or last control of a dialog? A dialog with a single control always passes (runs inside the page)
function focusIsAtEnd({ selector, focusable, which }) {
    const list = [...document.querySelector(selector).querySelectorAll(focusable)].filter((el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden');
    return list.length < 2 || document.activeElement === list[which === 'first' ? 0 : list.length - 1];
}

// Insert a test toast and check it is not inert, is rendered and is what a pointer hits at its centre; returns a problem or ''
function toastStaysLive() {
    const container = document.getElementById('toast-container');
    if (!container) return '';
    const toast = document.createElement('div');
    toast.setAttribute('role', 'status');
    toast.className = 'toast-message pointer-events-auto';
    toast.style.cssText = 'background:#fff;padding:8px 16px;';
    toast.textContent = 'Accessibility check toast';
    container.appendChild(toast);
    const rect = toast.getBoundingClientRect();
    const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
    const problem = container.closest('[inert]') || toast.closest('[inert]') ? 'the toast container is inert while a dialog is open'
        : (rect.width === 0 || !hit || !toast.contains(hit)) ? 'a toast is not visible above the open dialog' : '';
    toast.remove();
    return problem;
}

// What outside the top dialog is still reachable or exposed? (runs inside the page)
function reachableBehind({ selector, focusable }) {
    const top = document.querySelector(selector);
    const leaks = [];
    const hidden = (element) => element.closest('[inert], [aria-hidden="true"]') !== null;
    for (const element of document.querySelectorAll(focusable)) {
        // The toast container is a live region that stays live on purpose
        if (top.contains(element) || element.getClientRects().length === 0 || element.closest('[data-modal-live-region]')) continue;
        if (!hidden(element)) leaks.push(`${element.tagName.toLowerCase()}#${element.id}.${String(element.className).slice(0, 25)}`);
    }
    // Landmarks that do not contain the dialog must be hidden from assistive technology too
    for (const landmark of document.querySelectorAll('nav, header, main, footer')) {
        // A landmark that holds the dialog or the live region stays live; its other children are inert (checked above)
        if (landmark.contains(top) || landmark.querySelector('[data-modal-live-region]') || landmark.getClientRects().length === 0) continue;
        if (!hidden(landmark)) leaks.push(`landmark ${landmark.tagName.toLowerCase()}`);
    }
    return leaks.slice(0, 3);
}

// Wait until a selector is rendered, up to a limit; returns whether it appeared
async function waitRendered(page, selector, milliseconds = 4000) {
    try {
        await page.waitForFunction(isRendered, selector, { timeout: milliseconds });
        return true;
    } catch (error) {
        return false;
    }
}

// Check one open dialog: focus inside, Tab and Shift+Tab stay inside, the page behind is unreachable
async function checkOpenDialog(page, selector, failures) {
    if (!(await page.evaluate(focusIsInside, selector))) failures.push(`${selector}: focus not inside after opening`);
    const count = await page.evaluate(tabbableCount, { selector, focusable: FOCUSABLE_SELECTOR });
    // Tab and Shift+Tab at least (controls + 2) times each: the focus must wrap and never leave
    for (let press = 0; press < count + 2; press++) {
        await page.keyboard.press('Tab');
        if (!(await page.evaluate(focusIsInside, selector))) { failures.push(`${selector}: Tab ${press + 1} left the dialog (${count} focusable inside)`); break; }
    }
    for (let press = 0; press < count + 2; press++) {
        await page.keyboard.press('Shift+Tab');
        if (!(await page.evaluate(focusIsInside, selector))) { failures.push(`${selector}: Shift+Tab ${press + 1} left the dialog`); break; }
    }
    // The wrap itself: Shift+Tab on the first control lands on the last, Tab on the last lands on the first
    for (const [from, key, to] of [['first', 'Shift+Tab', 'last'], ['last', 'Tab', 'first']]) {
        await page.evaluate(focusEnd, { selector, focusable: FOCUSABLE_SELECTOR, which: from });
        await page.keyboard.press(key);
        if (!(await page.evaluate(focusIsAtEnd, { selector, focusable: FOCUSABLE_SELECTOR, which: to }))) failures.push(`${selector}: ${key} from the ${from} control did not wrap to the ${to}`);
    }
    // A toast must stay visible, announced and not inert while the dialog is open
    const toastProblem = await page.evaluate(toastStaysLive);
    if (toastProblem) failures.push(`${selector}: ${toastProblem}`);
    const leaks = await page.evaluate(reachableBehind, { selector, focusable: FOCUSABLE_SELECTOR });
    if (leaks.length > 0) failures.push(`${selector}: page behind not inert/hidden (${leaks.join(', ')})`);
}

// Open a dialog with the keyboard from its opener, check it, then close the whole stack with Escape and check the focus returns
async function runDialogSpec(page, spec) {
    const failures = [];
    await page.goto(context.baseUrl + spec.route, { waitUntil: 'networkidle' });
    if (spec.before) await spec.before(page);
    const openers = [];
    for (const step of spec.steps) {
        if (step.prepare) await step.prepare(page);
        await page.locator(step.opener).first().focus();
        openers.push(await page.evaluateHandle(() => document.activeElement));
        await page.keyboard.press('Enter');
        if (!(await waitRendered(page, step.modal))) { failures.push(`${step.modal} did not open from the keyboard`); return failures; }
        await page.waitForTimeout(150);
        await checkOpenDialog(page, step.modal, failures);
    }
    // Escape closes only the top dialog and returns the focus to the control that opened it
    // (a step with replacesPrevious closed the modal below it when it opened, so the focus goes back to that modal's opener)
    for (let index = spec.steps.length - 1; index >= 0; index--) {
        const step = spec.steps[index];
        const replaced = step.replacesPrevious === true;
        await page.keyboard.press('Escape');
        await page.waitForTimeout(150);
        if (await page.evaluate(isRendered, step.modal)) { failures.push(`${step.modal}: Escape did not close it`); break; }
        const expectedOpener = replaced ? openers[index - 1] : openers[index];
        if (!(await page.evaluate((opener) => document.activeElement === opener, expectedOpener))) failures.push(`${step.modal}: focus did not return to the opener`);
        if (replaced) { index--; continue; }
        if (index > 0 && !(await page.evaluate(isRendered, spec.steps[index - 1].modal))) { failures.push(`${step.modal}: Escape also closed ${spec.steps[index - 1].modal}`); continue; }
        // The dialog below is the top one now: its focus trap and inert background must work again
        if (index > 0) await checkOpenDialog(page, spec.steps[index - 1].modal, failures);
    }
    const stillInert = await page.evaluate(() => document.querySelectorAll('[inert]').length);
    if (stillInert > 0) failures.push(`${stillInert} element(s) still inert after closing`);
    if (spec.after) failures.push(...(await spec.after(page)));
    return failures;
}

// The guest QR dialog is closed while the camera is still starting: the camera must be stopped as soon as start() resolves
async function runPendingCameraSpec(page, route) {
    await page.goto(context.baseUrl + route, { waitUntil: 'networkidle' });
    await stubQrLibrary(true)(page);
    await page.locator('button:has-text("Track a Document"), button:has-text("Track Another Document")').first().focus();
    await page.keyboard.press('Enter');
    await page.locator('#scan-qr-button').focus();
    await page.keyboard.press('Enter');
    if (!(await waitRendered(page, '#qr-scanner-modal'))) return ['the scanner dialog did not open'];
    await page.keyboard.press('Escape');
    await page.waitForTimeout(150);
    await page.evaluate(() => window.__qrResolveStart());
    await page.waitForTimeout(200);
    return expectCameraStopped(page);
}

// Report flow: start a report, press Escape, activate Generate again: no second job request, the dialog opens again
async function runReportSecondClickSpec(page) {
    const requests = [];
    const recordStart = (request) => { if (request.method() === 'POST' && /\/statistics\/report(\?|$)/.test(request.url())) requests.push(request.url()); };
    page.on('request', recordStart);
    try {
        await page.goto(`${context.baseUrl}/statistics`, { waitUntil: 'networkidle' });
        await page.locator('#generate-report-btn').focus();
        await page.keyboard.press('Enter');
        if (!(await waitRendered(page, '#report-progress-modal'))) return ['the progress dialog did not open'];
        await page.keyboard.press('Escape');
        await page.waitForTimeout(150);
        await page.locator('#generate-report-btn').focus();
        await page.keyboard.press('Enter');
        await page.waitForTimeout(500);
        const failures = [];
        if (requests.length !== 1) failures.push(`${requests.length} job request(s) sent for two activations of Generate (expected 1)`);
        if (!(await page.evaluate(isRendered, '#report-progress-modal'))) failures.push('the progress dialog did not open again');
        return failures;
    } finally {
        page.off('request', recordStart);
    }
}

// A confirm-action form reached by in-app navigation must still ask first: no request until "Yes, Proceed", then exactly one.
// Every POST is recorded and aborted, so nothing reaches the server.
async function runConfirmAfterNavigationSpec(page, spec) {
    const failures = [];
    const posts = [];
    const handler = (route) => {
        if (route.request().method() !== 'POST') return route.continue();
        posts.push(route.request().url());
        return route.abort();
    };
    await page.route('**/*', handler);
    try {
        await page.goto(context.baseUrl + spec.route, { waitUntil: 'networkidle' });
        await page.evaluate((target) => document.querySelector(`a[href="${target}"]`).click(), spec.navigateTo);
        await page.waitForFunction((target) => location.pathname === target, spec.navigateTo, { timeout: 5000 });
        await page.waitForTimeout(800);
        if (spec.reveal) {
            await page.locator(spec.reveal).focus();
            await page.keyboard.press('Enter');
        }
        await page.locator(spec.opener).first().focus();
        await page.keyboard.press('Enter');
        if (!(await waitRendered(page, '#global-confirmation-modal'))) return ['the confirm dialog did not open after in-app navigation'];
        if (posts.length > 0) failures.push('a request was sent before the confirmation');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(200);
        if (posts.length > 0) failures.push('a request was sent after Cancel (Escape)');
        // Ask again and confirm with the keyboard: exactly one request
        await page.locator(spec.opener).first().focus();
        await page.keyboard.press('Enter');
        await waitRendered(page, '#global-confirmation-modal');
        await page.locator('#global-confirm-btn').focus();
        await page.keyboard.press('Enter');
        await page.waitForTimeout(500);
        if (posts.length !== 1) failures.push(`${posts.length} request(s) sent after "Yes, Proceed" (expected exactly 1)`);
        return failures;
    } finally {
        await page.unroute('**/*', handler);
    }
}

// A stack whose LOWER dialog is closed first (by the page, through the module): the top one must stay open, trapped and
// inert-protected, and closing it afterwards must leave nothing inert
async function runCloseLowerFirstSpec(page, spec) {
    const failures = [];
    await page.goto(context.baseUrl + spec.route, { waitUntil: 'networkidle' });
    for (const step of spec.steps) {
        if (step.prepare) await step.prepare(page);
        await page.locator(step.opener).first().focus();
        await page.keyboard.press('Enter');
        if (!(await waitRendered(page, step.modal))) return [`${step.modal} did not open`];
    }
    const [lower, top] = spec.steps.map((step) => step.modal);
    await page.evaluate((selector) => ModalDialog.close(document.querySelector(selector)), lower);
    await page.waitForTimeout(150);
    if (await page.evaluate(isRendered, lower)) failures.push(`${lower} did not close`);
    if (!(await page.evaluate(isRendered, top))) return [...failures, `${top} closed together with ${lower}`];
    await checkOpenDialog(page, top, failures);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(150);
    if (await page.evaluate(isRendered, top)) failures.push(`${top}: Escape did not close it`);
    const stillInert = await page.evaluate(() => document.querySelectorAll('[inert]').length);
    if (stillInert > 0) failures.push(`${stillInert} element(s) still inert after both closed`);
    return failures;
}

// The mandatory Security PIN prompt: not dismissible, takes focus, keeps Log Out reachable
async function runMandatoryPromptSpec(page) {
    const failures = [];
    const selector = '#security-key-modal';
    await page.goto(`${context.baseUrl}/tasks`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(300);
    if (!(await page.evaluate(isRendered, selector))) return ['prompt is not shown'];
    await checkOpenDialog(page, selector, failures);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(150);
    if (!(await page.evaluate(isRendered, selector))) failures.push('Escape closed the mandatory prompt');
    await page.mouse.click(4, 4);
    await page.waitForTimeout(150);
    if (!(await page.evaluate(isRendered, selector))) failures.push('a backdrop click closed the mandatory prompt');
    // Log Out must be reachable by Tab inside the prompt
    let reachedLogOut = false;
    for (let press = 0; press < 8 && !reachedLogOut; press++) {
        await page.keyboard.press('Tab');
        reachedLogOut = await page.evaluate(() => document.activeElement?.textContent.trim() === 'Log Out' && !!document.activeElement.closest('#security-key-modal'));
    }
    if (!reachedLogOut) failures.push('Log Out is not reachable by Tab inside the prompt');

    // A refused PIN (the two entries differ) is explained inside the prompt itself, and the error toast is visible above it
    await page.fill('#pin', 'Long-Enough-Pin-1');
    await page.fill('#pin_confirm', 'Different-Pin-22');
    await page.locator('button[form="security-key-form"]').focus();
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.keyboard.press('Enter')]);
    await page.waitForTimeout(400);
    const refusal = await page.evaluate(() => {
        const alert = document.querySelector('#security-key-modal #pin-setup-error[role="alert"]');
        const describedBy = (id) => (document.getElementById(id)?.getAttribute('aria-describedby') || '').includes('pin-setup-error') && document.getElementById(id).getAttribute('aria-invalid') === 'true';
        const toast = document.querySelector('#toast-container .toast-message');
        const rect = toast?.getBoundingClientRect();
        const hit = rect ? document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2) : null;
        return {
            shown: !!alert && /do not match/.test(alert.textContent),
            linked: describedBy('pin') && describedBy('pin_confirm'),
            promptOpen: !!document.querySelector('#security-key-modal') && document.querySelector('#security-key-modal').getClientRects().length > 0,
            toastLive: !!toast && !toast.closest('[inert]') && rect.width > 0 && !!hit && toast.contains(hit),
        };
    });
    if (!refusal.shown) failures.push('a refused PIN is not explained inside the prompt (role="alert")');
    if (!refusal.linked) failures.push('the PIN fields are not linked to the refusal (aria-describedby, aria-invalid)');
    if (!refusal.promptOpen) failures.push('the prompt is not open after a refusal');
    if (!refusal.toastLive) failures.push('the refusal toast is not visible above the prompt');
    return failures;
}

// After an in-app navigation with a modal open: no modal open, nothing inert, Tab moves through the page
async function runNavigationSpec(page, spec) {
    const failures = [];
    await page.goto(context.baseUrl + spec.route, { waitUntil: 'networkidle' });
    for (const step of spec.steps) {
        if (step.prepare) await step.prepare(page);
        await page.locator(step.opener).first().focus();
        await page.keyboard.press('Enter');
        if (!(await waitRendered(page, step.modal))) return [`${step.modal} did not open`];
    }
    // Navigate through the router (a DOM click reaches the link although it is behind the modal)
    await page.evaluate((target) => document.querySelector(`a[href="${target}"]`).click(), spec.navigateTo);
    await page.waitForFunction((target) => location.pathname === target, spec.navigateTo, { timeout: 5000 });
    await page.waitForTimeout(800);
    const state = await page.evaluate(() => ({
        openDialogs: [...document.querySelectorAll('[role="dialog"]')].filter((dialog) => dialog.getClientRects().length > 0).map((dialog) => dialog.id),
        inert: document.querySelectorAll('[inert]').length,
    }));
    if (state.openDialogs.length > 0) failures.push(`dialog(s) still open after navigation: ${state.openDialogs.join(', ')}`);
    if (state.inert > 0) failures.push(`${state.inert} element(s) inert after navigation`);
    await page.keyboard.press('Tab');
    const focusedPage = await page.evaluate(() => document.activeElement && document.activeElement !== document.body && !document.activeElement.closest('[role="dialog"]'));
    if (!focusedPage) failures.push('Tab does not move through the page after navigation');
    return failures;
}

const documentPath = `/documents/${context.documentCode}`;
// Replaces the page's Html5Qrcode with a stub (there is no camera): start() resolves and the scanner then reports isScanning,
// stop() is counted in window.__qrStopCalls. With deferred = true start() stays pending until window.__qrResolveStart() is called.
const stubQrLibrary = (deferred = false) => (page) => page.evaluate((isDeferred) => {
    window.__qrStopCalls = 0;
    window.Html5Qrcode = class {
        constructor() { this.scanning = false; }
        start() {
            return new Promise((resolve) => {
                const finish = () => { this.scanning = true; resolve(); };
                if (isDeferred) window.__qrResolveStart = finish; else finish();
            });
        }
        stop() { window.__qrStopCalls++; this.scanning = false; return Promise.resolve(); }
        get isScanning() { return this.scanning; }
    };
}, deferred);
// The camera must have been stopped after the dialog closed
const expectCameraStopped = async (page) => (await page.evaluate(() => window.__qrStopCalls)) >= 1 ? [] : ['the camera was not stopped when the dialog closed'];

// Dialog specs: name, role, path, steps (opener selector, dialog selector, optional preparation)
const dialogSpecs = [
    { name: 'dialog-global-confirm', role: 'admin', route: '/admin-dashboard', steps: [{ opener: 'form.confirm-action button[type="submit"]', modal: '#global-confirmation-modal' }] },
    { name: 'dialog-signing-pin', role: 'admin', route: documentPath, steps: [{ opener: 'form.sign-action button[type="submit"]', modal: '#signing-modal' }] },
    { name: 'dialog-users-reset', role: 'admin', route: '/users', steps: [{ opener: '.reset-credentials-trigger', modal: '#reset-credentials-modal' }] },
    { name: 'dialog-users-reset+signing', role: 'admin', route: '/users', steps: [
        { opener: '.reset-credentials-trigger', modal: '#reset-credentials-modal' },
        { opener: '#reset-credentials-form button[type="submit"]', modal: '#signing-modal', prepare: (page) => page.check('#reset-credentials-form input[value="password"]') },
    ] },
    { name: 'dialog-backups-restore', role: 'admin', route: '/system/backups', steps: [{ opener: '.restore-backup-trigger', modal: '#restore-modal' }] },
    { name: 'dialog-backups-restore+signing', role: 'admin', route: '/system/backups', steps: [
        { opener: '.restore-backup-trigger', modal: '#restore-modal' },
        { opener: '#restore-form button[type="submit"]', modal: '#signing-modal' },
    ] },
    { name: 'dialog-scan-qr-intake', role: 'records', route: '/intake', steps: [{ opener: '#scan-qr-btn', modal: '#qr-modal' }] },
    { name: 'dialog-scan-qr-releasing', role: 'records', route: '/releasing', steps: [{ opener: '#scan-qr-btn', modal: '#qr-modal' }] },
    { name: 'dialog-scan-qr-tasks', role: 'cash', route: '/tasks', steps: [{ opener: '#scan-qr-btn', modal: '#qr-modal' }] },
    { name: 'dialog-report-progress', role: 'records', route: '/statistics', steps: [{ opener: '#generate-report-btn', modal: '#report-progress-modal' }] },
    { name: 'dialog-past-reports', role: 'records', route: '/statistics', steps: [{ opener: '.open-modal-btn[data-modal="past-reports-modal"]', modal: '#past-reports-modal' }] },
    { name: 'dialog-integrity-progress', role: 'admin', route: '/system-overview', steps: [{ opener: '#run-integrity-check', modal: '#integrity-progress-modal' }] },
    // The launcher stores an integrity result naming one log entry, so System Overview lists a real "Verify Hash" button
    { name: 'dialog-verify-hash', role: 'admin', route: '/system-overview', steps: [{ opener: '.debug-log-btn', modal: '#debug-hash-modal' }] },
    { name: 'dialog-average-tat', role: 'admin', route: '/admin-dashboard', steps: [{ opener: '#view-all-avg-tat-btn', modal: '#avg-tat-modal' }] },
    { name: 'dialog-decline', role: 'records', route: `/documents/${context.documentId}/manage`, steps: [{ opener: 'button[type="button"]:has-text("Decline Document")', modal: '#declineModal' }] },
    { name: 'dialog-guest-track', role: null, route: '/', steps: [{ opener: 'button:has-text("Track a Document")', modal: '#track-modal' }] },
    { name: 'dialog-guest-track-again', role: null, route: `/track?codes=${context.trackingCode}`, steps: [{ opener: 'button:has-text("Track Another Document")', modal: '#track-modal' }] },
    { name: 'dialog-guest-qr', role: null, route: '/', before: stubQrLibrary(), after: expectCameraStopped, steps: [
        { opener: 'button:has-text("Track a Document")', modal: '#track-modal' },
        // The scanner replaces the tracking modal (the page closes it as the scanner opens) and returns the focus to the first opener
        { opener: '#scan-qr-button', modal: '#qr-scanner-modal', replacesPrevious: true },
    ] },
];

// Navigation specs: a modal (or a stack) is open while the router swaps the page
const navigationSpecs = [
    { name: 'navigation-with-confirm-open', role: 'admin', route: '/admin-dashboard', navigateTo: '/all-documents', steps: [{ opener: 'form.confirm-action button[type="submit"]', modal: '#global-confirmation-modal' }] },
    { name: 'navigation-with-reset-open', role: 'admin', route: '/users', navigateTo: '/all-documents', steps: [{ opener: '.reset-credentials-trigger', modal: '#reset-credentials-modal' }] },
    { name: 'navigation-with-stack-open', role: 'admin', route: '/users', navigateTo: '/all-documents', steps: [
        { opener: '.reset-credentials-trigger', modal: '#reset-credentials-modal' },
        { opener: '#reset-credentials-form button[type="submit"]', modal: '#signing-modal', prepare: (page) => page.check('#reset-credentials-form input[value="password"]') },
    ] },
];

// Confirm-action forms reached after an in-app navigation (see runConfirmAfterNavigationSpec)
const confirmSpecs = [
    { name: 'confirm-after-navigation-users', role: 'admin', route: '/admin-dashboard', navigateTo: '/users', opener: 'form.confirm-action button[type="submit"]' },
    { name: 'confirm-after-navigation-failed-jobs', role: 'admin', route: '/admin-dashboard', navigateTo: '/system-overview', reveal: '#view-failed-jobs', opener: '#failed-jobs-details form[action$="delete-all"] button[type="submit"]' },
];

const browser = await launchBrowser();
let failureCount = 0;

// Print one result line and count failures
function report(width, name, failures) {
    // Policy violations, console errors and uncaught exceptions since the last line fail this one
    const problems = [...failures, ...takeDiagnostics()];
    if (problems.length > 0) failureCount++;
    console.log(`${String(width).padStart(4)} ${name.padEnd(34)} ${problems.length === 0 ? 'OK' : 'FAIL ' + problems.join('; ')}`);
}

// Run one check and turn an exception into a failure line
async function guarded(width, name, check) {
    try {
        report(width, name, await check());
    } catch (error) {
        report(width, name, [`ERROR ${String(error.message).split('\n')[0]}`]);
    }
}

for (const viewport of viewports) {
    const sessions = createSessions(browser, viewport);

    // Markup audit of every page
    for (const [name, role, route] of pages) {
        await guarded(viewport.width, `page-${name}`, async () => {
            const page = await sessions.pageFor(role);
            await page.goto(context.baseUrl + route, { waitUntil: 'networkidle' });
            await page.waitForTimeout(300);
            return page.evaluate(auditDocument);
        });
    }

    // Keyboard pass of every dialog, each on a fresh load of its page
    await guarded(viewport.width, 'dialog-mandatory-pin-prompt', async () => runMandatoryPromptSpec(await sessions.pageFor('keyless')));
    for (const spec of dialogSpecs) {
        await guarded(viewport.width, spec.name, async () => runDialogSpec(await sessions.pageFor(spec.role), spec));
    }
    // Stacks closed lower-first
    for (const spec of dialogSpecs.filter((candidate) => candidate.steps.length === 2 && !candidate.steps[1].replacesPrevious)) {
        await guarded(viewport.width, `${spec.name}:lower-first`, async () => runCloseLowerFirstSpec(await sessions.pageFor(spec.role), spec));
    }
    for (const spec of navigationSpecs) {
        await guarded(viewport.width, spec.name, async () => runNavigationSpec(await sessions.pageFor(spec.role), spec));
    }
    for (const spec of confirmSpecs) {
        await guarded(viewport.width, spec.name, async () => runConfirmAfterNavigationSpec(await sessions.pageFor(spec.role), spec));
    }
    await guarded(viewport.width, 'report-second-activation', async () => runReportSecondClickSpec(await sessions.pageFor('records')));
    await guarded(viewport.width, 'guest-qr-closed-while-starting', async () => runPendingCameraSpec(await sessions.pageFor(null), '/'));
    await guarded(viewport.width, 'guest-track-qr-closed-while-starting', async () => runPendingCameraSpec(await sessions.pageFor(null), `/track?codes=${context.trackingCode}`));
    await sessions.closeAll();
}

await browser.close();
console.log(failureCount === 0 ? 'Accessibility check: all OK at 1280 and 360 px.' : `Accessibility check: ${failureCount} line(s) failed.`);
process.exit(failureCount === 0 ? 0 : 1);
