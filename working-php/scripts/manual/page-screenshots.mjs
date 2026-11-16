// Drives headless Chromium through playwright-core: screenshots every main page at three widths and reports layout overflow.
// Started by scripts/manual/page-screenshots.php (which builds the test database and the server); argument: context JSON file.
// playwright-core is installed outside package.json: npm install --prefix working-php/.test/browser playwright-core
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { context, createSessions, launchBrowser, pages } from './browser-common.mjs';

// Widths to check: phone, a width just below Tailwind's xl breakpoint (the wide margin is off), and desktop
const widths = [{ width: 360, height: 800 }, { width: 1024, height: 768 }, { width: 1280, height: 900 }];
const PHONE_WIDTH = 360;

// Opened-state screenshots taken at the phone width only: name, role, path, action that opens the state
const phoneStates = [
    ['modal-global-confirm', 'admin', '/admin-dashboard', async (page) => { await page.locator('form.confirm-action button[type="submit"]').first().click(); }],
    ['modal-signing-pin', 'admin', `/documents/${context.documentCode}`, async (page) => { await page.locator('form.sign-action button[type="submit"]').first().click(); }],
    ['modal-users-reset', 'admin', '/users', async (page) => { await page.locator('.reset-credentials-trigger').first().click(); }],
    ['modal-backups-restore', 'admin', '/system/backups', async (page) => { await page.evaluate(() => openRestoreModal('example-backup.sql')); }],
    ['toast', 'admin', '/admin-dashboard', async (page) => {
        // Clearing the cache confirms in the global modal, then the redirect shows a flash toast
        await page.locator('form.confirm-action button[type="submit"]').first().click();
        await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#global-confirm-btn')]);
        await page.waitForSelector('#toast-container .toast-message', { timeout: 5000 });
        await page.waitForTimeout(500);
    }],
];

// Find layout overflow (runs inside the page). Content inside a deliberately scrollable container
// (overflow-x auto or scroll, such as a table wrapper) is exempt; content clipped by overflow hidden or
// positioned fixed is not. Reports the worst element past the viewport and the worst element past its card.
function findOverflows() {
    const describe = (element) => `${element.tagName.toLowerCase()}#${element.id}.${String(element.className).trim().replace(/\s+/g, '.').slice(0, 70)}`;
    let viewportWorst = null;
    let cardWorst = null;
    for (const element of document.querySelectorAll('body *')) {
        const rect = element.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0 || getComputedStyle(element).visibility === 'hidden') continue;
        // Skip content of a scrollable container: it scrolls inside its own box
        let insideScroller = false;
        for (let parent = element.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
            if (/(auto|scroll)/.test(getComputedStyle(parent).overflowX)) { insideScroller = true; break; }
        }
        if (insideScroller) continue;
        // Past the viewport edge
        const pastViewport = rect.right - window.innerWidth;
        if (pastViewport > 0.5 && (!viewportWorst || pastViewport > viewportWorst.by)) {
            viewportWorst = { by: Math.round(pastViewport), label: describe(element) };
        }
        // Past the content box of the nearest padded panel (a card or the padded block inside it), so content
        // that sticks out of its card or eats into the card's padding is caught
        // Fixed elements (dropdowns, modals) are placed against the viewport, not their card; the viewport check covers them
        if (getComputedStyle(element).position === 'fixed') continue;
        let card = element.parentElement;
        while (card && card !== document.body && parseFloat(getComputedStyle(card).paddingRight) < 12) card = card.parentElement;
        if (!card || card === document.body) continue;
        const cardStyle = getComputedStyle(card);
        const cardContentRight = card.getBoundingClientRect().right - parseFloat(cardStyle.paddingRight) - parseFloat(cardStyle.borderRightWidth);
        const pastCard = rect.right - cardContentRight;
        if (pastCard > 1 && (!cardWorst || pastCard > cardWorst.by)) {
            cardWorst = { by: Math.round(pastCard), label: `${describe(element)} in ${describe(card)}`.replace(/\.(border|dark|rounded|shadow|focus|text|bg)[^.]*/g, '').slice(0, 140) };
        }
    }
    return {
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth,
        viewportWorst,
        cardWorst,
        // Narrowest page container, to catch fixed side margins that squeeze the content
        narrowest: Math.min(...[...document.querySelectorAll('#pjax-content > div > div, #pjax-header > div, body > .container')]
            .map((element) => Math.round(element.getBoundingClientRect().width)).filter((width) => width > 0), 99999),
    };
}

// Turn a measurement into a result line and a pass/fail flag
function summarize(measured, width) {
    const problems = [];
    const pageOverflow = measured.scrollWidth - measured.innerWidth;
    if (pageOverflow > 0) problems.push(`OVERFLOW by ${pageOverflow} px page scroll`);
    if (measured.viewportWorst) problems.push(`OVERFLOW by ${measured.viewportWorst.by} px (${measured.viewportWorst.label})`);
    if (measured.cardWorst) problems.push(`CLIPPED by ${measured.cardWorst.by} px (${measured.cardWorst.label})`);
    if (width === PHONE_WIDTH && measured.narrowest < 280) problems.push(`SQUEEZED container ${measured.narrowest} px`);
    return { ok: problems.length === 0, text: problems.length ? problems.join('; ') : 'OK' };
}

const browser = await launchBrowser();
let failureCount = 0;

// Print one result line and count failures
function report(width, name, summary, extra = '') {
    if (!summary.ok) failureCount++;
    console.log(`${String(width).padStart(4)} ${name.padEnd(22)} ${summary.text}${extra}`);
}

// One pass per viewport width
for (const viewport of widths) {
    const directory = path.join(context.screensDirectory, String(viewport.width));
    mkdirSync(directory, { recursive: true });
    // One signed-in browser context per role, created on first use
    const sessions = createSessions(browser, viewport);
    const sessionFor = (role) => sessions.pageFor(role);

    for (const [name, role, route] of pages) {
        const page = await sessionFor(role);
        try {
            await page.goto(context.baseUrl + route, { waitUntil: 'networkidle' });
            await page.waitForTimeout(400);
            const summary = summarize(await page.evaluate(findOverflows), viewport.width);
            await page.screenshot({ path: path.join(directory, `${name}.png`), fullPage: true });
            let extra = '';

            // Phone only: open the navigation menu and the notification dropdown and screenshot them
            if (viewport.width === PHONE_WIDTH && role && role !== 'keyless') {
                const menuButton = page.locator('#mobile-menu-button');
                if (await menuButton.isVisible()) {
                    await menuButton.click();
                    await page.screenshot({ path: path.join(directory, `${name}--menu-open.png`) });
                    await menuButton.click();
                } else {
                    extra += ' [no menu button]';
                }
                const bell = page.locator('#notification-menu-button');
                if (await bell.isVisible()) {
                    await bell.click();
                    const open = await page.evaluate(findOverflows);
                    if (summarize(open, viewport.width).text !== 'OK') extra += ` [notifications open: ${summarize(open, viewport.width).text}]`;
                    await page.screenshot({ path: path.join(directory, `${name}--notifications-open.png`) });
                    await bell.click();
                } else {
                    extra += ' [bell hidden]';
                }
            }
            report(viewport.width, name, summary, extra);
        } catch (error) {
            report(viewport.width, name, { ok: false, text: `ERROR ${String(error.message).split('\n')[0]}` });
        }
    }

    // Phone only: opened modals and a toast
    if (viewport.width !== PHONE_WIDTH) {
        await sessions.closeAll();
        continue;
    }
    for (const [name, role, route, open] of phoneStates) {
        const page = await sessionFor(role);
        try {
            await page.goto(context.baseUrl + route, { waitUntil: 'networkidle' });
            await open(page);
            await page.waitForTimeout(400);
            const summary = summarize(await page.evaluate(findOverflows), viewport.width);
            await page.screenshot({ path: path.join(directory, `${name}.png`) });
            report(viewport.width, name, summary);
        } catch (error) {
            report(viewport.width, name, { ok: false, text: `ERROR ${String(error.message).split('\n')[0]}` });
        }
    }
    // Menu and notification list close each other, and the menu closes when the viewport grows past lg
    const page = await sessionFor('records.unit');
    try {
        await page.goto(`${context.baseUrl}/intake`, { waitUntil: 'networkidle' });
        const menuOpen = () => page.locator('#mobile-menu-button').getAttribute('aria-expanded');
        const bellOpen = async () => !(await page.locator('#notification-dropdown-menu').evaluate((element) => element.classList.contains('hidden')));
        const failures = [];
        await page.click('#mobile-menu-button');
        await page.click('#notification-menu-button');
        if ((await menuOpen()) !== 'false' || !(await bellOpen())) failures.push('bell click did not swap the menu for the list');
        await page.click('#mobile-menu-button');
        if ((await menuOpen()) !== 'true' || (await bellOpen())) failures.push('menu button did not swap the list for the menu');
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.waitForTimeout(200);
        if ((await menuOpen()) !== 'false') failures.push('menu stayed open after growing past lg');
        await page.setViewportSize(viewport);
        report(viewport.width, 'menu-interactions', { ok: failures.length === 0, text: failures.length ? failures.join('; ') : 'OK' });
    } catch (error) {
        report(viewport.width, 'menu-interactions', { ok: false, text: `ERROR ${String(error.message).split('\n')[0]}` });
    }
    await sessions.closeAll();
}

await browser.close();
console.log(failureCount === 0 ? 'All pages OK at 360, 1024 and 1280 px.' : `${failureCount} check(s) failed.`);
process.exit(failureCount === 0 ? 0 : 1);
