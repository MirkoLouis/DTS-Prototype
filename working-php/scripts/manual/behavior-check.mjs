// Behaviour and script-policy pass of the "all scripts in files" slice (S9a), driven through playwright-core. Started by the
// one launcher scripts/manual/page-screenshots.php after the layout and accessibility checks; argument: context JSON file.
//
// Part 1 (policy): every page is loaded (hard load) and every page of every role is also reached by in-app navigation
// (a click on a link, the router swaps the content); the Content Security Policy is ENFORCED (S9b): a blocked resource also
// breaks behaviour, and any violation (securitypolicyviolation event, console message) fails the line. One line per page:
// OK, or the violations (directive, blocked resource, file and line), console errors and uncaught exceptions.
// Part 3 (enforcement): the response headers, and proof that the policy really blocks: an inline script, an inline event
// handler, a javascript: URL and code from a string (eval) do not run and are reported (those deliberate violations are excluded from
// the failure count), and a page of the app cannot be framed from another origin.
// Part 2 (behaviour): the features that moved from inline scripts to script files still work, each on a page reached by in-app
// navigation AND by hard load: theme toggle, notification list, global confirm, signing PIN prompt, Users reset, backups
// restore, charts and their filters, route builder, tracking page (card, 429 back-off), copy buttons, QR dialogs (camera
// stubbed), pagination. After in-app navigation across several pages, one click must cause exactly one change or request.
// Exit 1 when any line fails.
import { allowConsoleError, context, createSessions, ignoredSummary, launchBrowser, pages, takeDiagnostics } from './browser-common.mjs';

// The 404 page is loaded on purpose: the browser logs the failed document request
allowConsoleError(/status of 404/, 'the 404 page is a page of this check');
// The 429 back-off check answers the status poll with 429 on purpose
allowConsoleError(/status of 429/, 'the 429 back-off check');

const VIEWPORT = { width: 1280, height: 900 };
// The Security PIN of the fixture accounts (written by the launcher)
const PIN = context.pin;
const browser = await launchBrowser();
const sessions = createSessions(browser, VIEWPORT);
let failureCount = 0;

// Print one result line; policy violations, console errors and uncaught exceptions since the last line fail it
function report(label, failures) {
    const problems = [...failures, ...takeDiagnostics()];
    if (problems.length > 0) failureCount++;
    console.log(`${label.padEnd(52)} ${problems.length === 0 ? 'OK' : 'FAIL ' + problems.join('; ')}`);
}

// Pages whose requests a check intercepts (see recordPosts); released when the check ends, whatever its outcome
const interceptedPages = new Set();

// Run one check and turn an exception into a failure line
async function guarded(label, check) {
    try {
        report(label, await check());
    } catch (error) {
        report(label, [`ERROR ${String(error.message).split('\n')[0]}`]);
    } finally {
        for (const page of interceptedPages) await page.unroute('**/*').catch(() => {});
        interceptedPages.clear();
    }
}

// Load a route with a hard load
async function hardLoad(page, route) {
    await page.goto(context.baseUrl + route, { waitUntil: 'networkidle' });
    await page.waitForTimeout(250);
}

// Marker that survives in-app navigation but not a hard load
const markDocument = (page) => page.evaluate(() => { window.__dtsDocumentMarker = 1; });
const isSameDocument = (page) => page.evaluate(() => window.__dtsDocumentMarker === 1);

// Reach a route by in-app navigation: load `startRoute`, then click the link to `route` (the router swaps the content)
async function inAppNavigate(page, startRoute, route) {
    await hardLoad(page, startRoute);
    await markDocument(page);
    await clickLinkTo(page, route);
    if (!(await isSameDocument(page))) throw new Error(`${route} was loaded by a hard load, not by in-app navigation`);
}

// Click the first link to a path and wait until the router has shown it
async function clickLinkTo(page, route) {
    const href = route.split('?')[0];
    // A link to the page that is shown is not intercepted by the router (the browser would reload): nothing to click
    if (new URL(page.url()).pathname === href && !route.includes('?')) return href;
    await page.evaluate((target) => document.querySelector(`a[href="${target}"]`).click(), route);
    await page.waitForFunction((target) => location.pathname + location.search === target || location.pathname === target.split('?')[0], route, { timeout: 8000 });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(400);
    return href;
}

// Hop through several pages by in-app navigation so a listener bound on every page would now be bound several times
async function hopThroughPages(page, startRoute, routes) {
    await hardLoad(page, startRoute);
    await markDocument(page);
    for (const route of routes) await clickLinkTo(page, route);
    if (!(await isSameDocument(page))) throw new Error('a hop was a hard load, not in-app navigation');
}

// The modes every behaviour check runs in: [name, prepare(page, route)] (prepare leaves the page on `route`)
const HOP_ROUTES = { admin: ['/all-documents', '/system-overview', '/users', '/admin-dashboard'], 'records.unit': ['/tasks', '/releasing', '/statistics', '/intake'], 'cash.unit': ['/statistics', '/tasks/completed', '/tasks'] };
// `via`: pages to click through last when the page is linked only from another page (for example System Overview links Backups)
const modesFor = (role, route, startFrom, via = []) => [
    ['hard load', async (page) => { await hardLoad(page, route); }],
    ['in-app', async (page) => {
        // Visit several pages first, then arrive at the page by clicking its link
        await hopThroughPages(page, startFrom, [...HOP_ROUTES[role].filter((hop) => hop !== route && !via.includes(hop)), ...via, route]);
        if (!(await isSameDocument(page))) throw new Error(`${route} was loaded by a hard load`);
    }],
];

// Count the POST requests while a check runs; they are recorded and aborted so nothing reaches the server
// With { attachment: true } the POST is answered like a file download (the page stays open, as it does for a real download)
// instead of being aborted (an aborted form submit leaves the browser's error page behind)
async function recordPosts(page, { attachment = false } = {}) {
    const posts = [];
    interceptedPages.add(page);
    await page.route('**/*', (route) => {
        const request = route.request();
        if (request.method() !== 'POST') return route.continue();
        posts.push({ url: request.url(), body: request.postData() || '' });
        if (attachment) {
            return route.fulfill({ status: 200, headers: { 'Content-Type': 'application/zip', 'Content-Disposition': 'attachment; filename="check.zip"' }, body: 'x' });
        }
        return route.abort();
    });
    return posts;
}

// Is the element visible and not `hidden`?
const visible = (page, selector) => page.locator(selector).first().isVisible();

// Type the PIN into the signing prompt and confirm
async function confirmPin(page) {
    await page.fill('#signing-modal-pin', PIN);
    await page.click('#signing-modal-confirm');
    await page.waitForTimeout(300);
}

// ---------------------------------------------------------------------------------------------------------------------------
// Part 1: policy, one line per page (hard load) and per page reached by in-app navigation
// ---------------------------------------------------------------------------------------------------------------------------
for (const [name, role, route] of pages) {
    await guarded(`policy hard-load   ${name}`, async () => {
        const page = await sessions.pageFor(role);
        await hardLoad(page, route);
        return [];
    });
}

// In-app navigation: from the first page of a role to every other page of its navigation and to pages linked from them
const navigationChains = [
    ['admin', '/admin-dashboard', ['/all-documents', '/system-overview', '/users', '/users/create', '/system/backups'].map((route) => route)],
    ['records.unit', '/intake', ['/tasks', '/tasks/completed', '/releasing', '/statistics', '/account/password']],
    ['cash.unit', '/tasks', ['/tasks/completed', '/statistics', '/account/password']],
];
for (const [role, startRoute, routes] of navigationChains) {
    for (const route of routes) {
        await guarded(`policy in-app       ${role} -> ${route}`, async () => {
            const page = await sessions.pageFor(role);
            await hardLoad(page, startRoute);
            await markDocument(page);
            // The page may link its target only from the account menu or a page body: reach it by its first link in the document
            const hasLink = await page.evaluate((target) => !!document.querySelector(`a[href="${target}"]`), route);
            if (!hasLink) {
                // Not linked from the first page: hop through the role's navigation until a page links it
                for (const hop of HOP_ROUTES[role]) {
                    if (await page.evaluate((target) => !!document.querySelector(`a[href="${target}"]`), route)) break;
                    await clickLinkTo(page, hop);
                }
            }
            await clickLinkTo(page, route);
            return (await isSameDocument(page)) ? [] : ['loaded by a hard load, not in-app'];
        });
    }
}

// Pages that are only reached through a link in a list: document view, manage route, hash chain
await guarded('policy in-app       document view, hash chain, manage', async () => {
    const page = await sessions.pageFor('records.unit');
    await hardLoad(page, '/releasing');
    await markDocument(page);
    for (const route of [`/documents/${context.documentCode}`, `/documents/${context.documentCode}/hash-chain`]) {
        const linked = await page.evaluate((target) => !!document.querySelector(`a[href="${target}"]`), route);
        if (!linked) {
            // Navigate with the history API as the router would after a click on such a link
            await page.evaluate((target) => { const link = document.createElement('a'); link.href = target; document.body.appendChild(link); link.click(); link.remove(); }, route);
            await page.waitForFunction((target) => location.pathname === target, route, { timeout: 8000 });
            await page.waitForTimeout(500);
        } else {
            await clickLinkTo(page, route);
        }
    }
    return (await isSameDocument(page)) ? [] : ['loaded by a hard load, not in-app'];
});

// ---------------------------------------------------------------------------------------------------------------------------
// Part 2: behaviour
// ---------------------------------------------------------------------------------------------------------------------------

// Theme toggle: one click changes the theme once (a listener bound on every page would change it twice, i.e. not at all)
for (const [role, route, startFrom] of [['admin', '/users', '/admin-dashboard'], ['records.unit', '/statistics', '/intake']]) {
    for (const [mode, prepare] of modesFor(role, route, startFrom)) {
        await guarded(`behaviour theme-toggle ${role} ${route} ${mode}`, async () => {
            const page = await sessions.pageFor(role);
            await prepare(page);
            const isDark = () => page.evaluate(() => document.documentElement.classList.contains('dark'));
            const before = await isDark();
            await page.click('#theme-toggle');
            const failures = [];
            if ((await isDark()) === before) failures.push('one click did not change the theme');
            if ((await page.evaluate(() => localStorage.getItem('theme'))) !== (before ? 'light' : 'dark')) failures.push('the choice was not saved');
            // The icons follow: exactly one of them is shown
            const shownIcons = await page.evaluate(() => ['theme-toggle-light-icon', 'theme-toggle-dark-icon'].filter((id) => !document.getElementById(id).classList.contains('hidden')).length);
            if (shownIcons !== 1) failures.push(`${shownIcons} theme icons shown`);
            await page.click('#theme-toggle');
            if ((await isDark()) !== before) failures.push('a second click did not change it back');
            return failures;
        });
    }
}

// Notification list: opens with one click, closes with the next (aria-expanded follows)
for (const [mode, prepare] of modesFor('admin', '/all-documents', '/admin-dashboard')) {
    await guarded(`behaviour notifications ${mode}`, async () => {
        const page = await sessions.pageFor('admin');
        await prepare(page);
        const failures = [];
        await page.click('#notification-menu-button');
        if (!(await visible(page, '#notification-dropdown-menu'))) failures.push('the list did not open');
        if ((await page.getAttribute('#notification-menu-button', 'aria-expanded')) !== 'true') failures.push('aria-expanded is not true');
        await page.click('#notification-menu-button');
        if (await visible(page, '#notification-dropdown-menu')) failures.push('the second click did not close it');
        return failures;
    });
}

// Global confirm: the form asks first, then exactly one request
for (const [mode, prepare] of modesFor('admin', '/admin-dashboard', '/users')) {
    await guarded(`behaviour confirm-action ${mode}`, async () => {
        const page = await sessions.pageFor('admin');
        await prepare(page);
        const posts = await recordPosts(page);
        const failures = [];
        await page.locator('form.confirm-action button[type="submit"]').first().click();
        await page.waitForTimeout(300);
        if (!(await visible(page, '#global-confirmation-modal'))) failures.push('the confirmation did not open');
        if (posts.length !== 0) failures.push(`${posts.length} request(s) before the answer`);
        await page.click('#global-confirm-btn');
        await page.waitForTimeout(500);
        if (posts.length !== 1) failures.push(`${posts.length} request(s) after one confirmation (expected 1)`);
        await page.unroute('**/*');
        return failures;
    });
}

// Signing PIN prompt of a form.sign-action (freeze a document): the prompt opens, the PIN reaches exactly one request
for (const [mode, prepare] of modesFor('admin', '/all-documents', '/admin-dashboard')) {
    await guarded(`behaviour sign-action (freeze) ${mode}`, async () => {
        const page = await sessions.pageFor('admin');
        await prepare(page);
        // The document page is reached through its link in the list
        await clickLinkTo(page, `/documents/${context.documentCode}`);
        const posts = await recordPosts(page);
        const failures = [];
        await page.locator('form.sign-action button[type="submit"]').first().click();
        await page.waitForTimeout(300);
        if (!(await visible(page, '#signing-modal'))) failures.push('the PIN prompt did not open');
        if (posts.length !== 0) failures.push(`${posts.length} request(s) before the PIN`);
        await confirmPin(page);
        if (posts.length !== 1) failures.push(`${posts.length} request(s) after one PIN entry (expected 1)`);
        else if (!/pin=/.test(posts[0].body) || !/\/freeze$/.test(posts[0].url)) failures.push(`unexpected request ${posts[0].url} ${posts[0].body.slice(0, 40)}`);
        await page.unroute('**/*');
        return failures;
    });
}

// Users reset dialog: opens for a row, the chosen option and the PIN reach the request
for (const [mode, prepare] of modesFor('admin', '/users', '/admin-dashboard')) {
    await guarded(`behaviour users-reset ${mode}`, async () => {
        const page = await sessions.pageFor('admin');
        await prepare(page);
        const posts = await recordPosts(page);
        const failures = [];
        await page.locator('.reset-credentials-trigger').first().click();
        await page.waitForTimeout(300);
        if (!(await visible(page, '#reset-credentials-modal'))) failures.push('the reset dialog did not open');
        if (!(await page.textContent('#reset-credentials-user-name'))?.trim()) failures.push('the user name was not filled in');
        await page.check('#reset-credentials-form input[value="pin"]');
        await page.click('#reset-credentials-form button[type="submit"]');
        await page.waitForTimeout(300);
        if (!(await visible(page, '#signing-modal'))) failures.push('the PIN prompt did not open');
        await confirmPin(page);
        if (posts.length !== 1) failures.push(`${posts.length} request(s) (expected 1)`);
        else if (!/target=pin/.test(posts[0].body) || !/pin=/.test(posts[0].body) || !/\/reset-credentials$/.test(posts[0].url)) failures.push(`unexpected request ${posts[0].url} ${posts[0].body.slice(0, 60)}`);
        await page.unroute('**/*');
        return failures;
    });
}

// Backups: the Restore button opens the dialog for its file, whose form then asks for the PIN
for (const [mode, prepare] of modesFor('admin', '/system/backups', '/admin-dashboard', ['/system-overview'])) {
    await guarded(`behaviour backups-restore ${mode}`, async () => {
        const page = await sessions.pageFor('admin');
        await prepare(page);
        const posts = await recordPosts(page);
        const failures = [];
        await page.locator('.restore-backup-trigger').first().click();
        await page.waitForTimeout(300);
        if (!(await visible(page, '#restore-modal'))) failures.push('the restore dialog did not open');
        if ((await page.textContent('#restore-file-name'))?.trim() !== 'example-backup.zip') failures.push('the file name was not filled in');
        if (!/\/system\/backups\/restore\/example-backup\.zip$/.test(await page.getAttribute('#restore-form', 'action'))) failures.push('the form action does not name the file');
        await page.click('#restore-form button[type="submit"]');
        await page.waitForTimeout(300);
        if (!(await visible(page, '#signing-modal'))) failures.push('the PIN prompt did not open');
        await confirmPin(page);
        if (posts.length !== 1) failures.push(`${posts.length} request(s) (expected 1)`);
        await page.unroute('**/*');
        return failures;
    });
}

// Decline dialog (opened by .open-modal-btn, closed by .close-modal-btn) and its PIN prompt
for (const [mode, prepare] of modesFor('records.unit', `/documents/${context.documentId}/manage`, '/intake')) {
    await guarded(`behaviour decline-dialog ${mode}`, async () => {
        const page = await sessions.pageFor('records.unit');
        if (mode === 'in-app') {
            await hopThroughPages(page, '/intake', ['/tasks', '/releasing']);
            await page.evaluate((target) => { const link = document.createElement('a'); link.href = target; document.body.appendChild(link); link.click(); link.remove(); }, `/documents/${context.documentId}/manage`);
            await page.waitForFunction((target) => location.pathname === target, `/documents/${context.documentId}/manage`, { timeout: 8000 });
            await page.waitForTimeout(500);
        } else {
            await prepare(page);
        }
        const posts = await recordPosts(page);
        const failures = [];
        await page.locator('button[data-modal="declineModal"]').click();
        await page.waitForTimeout(300);
        if (!(await visible(page, '#declineModal'))) failures.push('the decline dialog did not open');
        await page.click('#declineModal .close-modal-btn');
        await page.waitForTimeout(300);
        if (await visible(page, '#declineModal')) failures.push('Cancel did not close it');
        await page.locator('button[data-modal="declineModal"]').click();
        await page.fill('#declineModal textarea[name="reason"]', 'Behaviour check');
        await page.click('#declineModal button[type="submit"]');
        await page.waitForTimeout(300);
        if (!(await visible(page, '#signing-modal'))) failures.push('the PIN prompt did not open');
        await confirmPin(page);
        if (posts.length !== 1) failures.push(`${posts.length} request(s) (expected 1)`);
        else if (!/pin=/.test(posts[0].body) || !/reason=Behaviour/.test(posts[0].body)) failures.push('the request lacks the PIN or the reason');
        await page.unroute('**/*');
        return failures;
    });
}

// Task completion button: the PIN prompt opens with the document's message and sends exactly one request
for (const [mode, prepare] of modesFor('cash.unit', '/tasks', '/statistics')) {
    await guarded(`behaviour complete-task ${mode}`, async () => {
        const page = await sessions.pageFor('cash.unit');
        await prepare(page);
        const failures = [];
        const buttons = await page.locator('.complete-btn').count();
        if (buttons === 0) return ['no complete button on the page (fixture)'];
        const posts = await recordPosts(page);
        await page.locator('.complete-btn').first().click();
        await page.waitForTimeout(300);
        if (!(await visible(page, '#signing-modal'))) failures.push('the PIN prompt did not open');
        if (!/completion of document: DEPED-/.test((await page.textContent('#signing-modal-message')) || '')) failures.push('the prompt does not name the document');
        await confirmPin(page);
        if (posts.length !== 1) failures.push(`${posts.length} request(s) (expected 1)`);
        await page.unroute('**/*');
        return failures;
    });
}

// Charts: drawn (not empty) and redrawn when a filter changes (a new data request, the chart takes the new data)
const chartPages = [
    { role: 'admin', route: '/admin-dashboard', startFrom: '/users', canvases: ['statusDistributionChart', 'returnDeclineChart', 'throughputChart', 'processingHotspotsChart'], filter: '#returnDeclinePeriod', urlPart: 'decline-trends' },
    { role: 'records.unit', route: '/statistics', startFrom: '/intake', canvases: ['currentLoadChart', 'throughputChart', 'avgProcessingTimeChart'], filter: '#throughputPeriod', urlPart: 'throughput' },
    { role: 'cash.unit', route: '/statistics', startFrom: '/tasks', canvases: ['currentLoadChart', 'throughputChart', 'avgProcessingTimeChart'], filter: '#currentLoadPeriod', urlPart: 'current-load' },
];
for (const spec of chartPages) {
    for (const [mode, prepare] of modesFor(spec.role, spec.route, spec.startFrom)) {
        await guarded(`behaviour charts ${spec.role} ${spec.route} ${mode}`, async () => {
            const page = await sessions.pageFor(spec.role);
            await prepare(page);
            await page.waitForTimeout(1500);
            const failures = [];
            // A canvas is drawn when it has a Chart instance and at least one painted pixel
            const drawn = (ids) => page.evaluate((canvasIds) => canvasIds.map((id) => {
                const canvas = document.getElementById(id);
                if (!canvas || !window.Chart || !window.Chart.getChart(canvas)) return `${id}: no chart`;
                const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
                for (let index = 3; index < data.length; index += 4) if (data[index] !== 0) return null;
                return `${id}: empty canvas`;
            }).filter(Boolean), ids);
            failures.push(...await drawn(spec.canvases));

            // Change the filter: a data request for the new period goes out and its answer reaches the chart
            const options = await page.$$eval(`${spec.filter} option`, (elements) => elements.map((element) => element.value));
            const current = await page.inputValue(spec.filter);
            const next = options.find((value) => value !== current);
            const responsePromise = page.waitForResponse((response) => response.url().includes(spec.urlPart) && response.url().includes(`period=${encodeURIComponent(next)}`), { timeout: 8000 });
            await page.selectOption(spec.filter, next);
            const response = await responsePromise;
            if (!response.ok()) failures.push(`the data request answered ${response.status()}`);
            await page.waitForTimeout(600);
            failures.push(...await drawn(spec.canvases));
            return failures;
        });
    }
}

// Route builder: remove and add a step; one click changes the list by exactly one step (and a hostile unit name stays text)
for (const [mode, prepare] of modesFor('records.unit', `/documents/${context.documentId}/manage`, '/intake')) {
    await guarded(`behaviour route-builder ${mode}`, async () => {
        const page = await sessions.pageFor('records.unit');
        if (mode === 'in-app') {
            await hopThroughPages(page, '/intake', ['/tasks', '/releasing', '/statistics']);
            await page.evaluate((target) => { const link = document.createElement('a'); link.href = target; document.body.appendChild(link); link.click(); link.remove(); }, `/documents/${context.documentId}/manage`);
            await page.waitForFunction((target) => location.pathname === target, `/documents/${context.documentId}/manage`, { timeout: 8000 });
            await page.waitForTimeout(500);
        } else {
            await prepare(page);
        }
        const failures = [];
        const steps = () => page.locator('#route-list .route-step').count();
        const before = await steps();
        if (before === 0) return ['no route steps on the page (fixture)'];
        await page.locator('#route-list .delete-step-btn').first().click();
        if ((await steps()) !== before - 1) failures.push(`one click on remove changed ${before} steps to ${await steps()}`);
        await page.selectOption('#department-select', { index: 1 });
        await page.click('#add-step-btn');
        if ((await steps()) !== before) failures.push(`add did not add exactly one step (${await steps()} steps)`);
        // The numbers follow the order
        const numbers = await page.$$eval('#route-list .step-number', (elements) => elements.map((element) => element.textContent.trim()).join(','));
        if (numbers !== Array.from({ length: before }, (_, index) => index + 1).join(',')) failures.push(`step numbers ${numbers}`);
        // A unit name with markup is inserted as text: simulate by adding a step whose option value is markup
        await page.evaluate(() => {
            const option = document.createElement('option');
            option.value = '<img src=x onerror="window.__xss=1">';
            option.textContent = 'Hostile unit';
            document.getElementById('department-select').appendChild(option);
        });
        await page.selectOption('#department-select', { label: 'Hostile unit' });
        await page.click('#add-step-btn');
        await page.waitForTimeout(200);
        if (await page.evaluate(() => window.__xss === 1 || !!document.querySelector('#route-list img'))) failures.push('a unit name with markup was parsed as HTML');
        // Finalize asks for the PIN and sends the route once
        const posts = await recordPosts(page);
        await page.click('#route-form button[type="submit"]');
        await page.waitForTimeout(300);
        if (!(await visible(page, '#signing-modal'))) failures.push('the PIN prompt did not open');
        await confirmPin(page);
        if (posts.length !== 1) failures.push(`${posts.length} request(s) (expected 1)`);
        else if (!/final_route=/.test(posts[0].body)) failures.push('the request lacks the route');
        await page.unroute('**/*');
        return failures;
    });
}

// Copy buttons: the text reaches the clipboard (stubbed), once per click, and the check mark shows
const clipboardStub = () => {
    window.__clipboardWrites = [];
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: (text) => { window.__clipboardWrites.push(text); return Promise.resolve(); } }, configurable: true });
};
for (const [role, route, startFrom] of [['admin', '/users', '/admin-dashboard'], ['records.unit', '/releasing', '/intake'], ['admin', '/system-overview', '/users']]) {
    for (const [mode, prepare] of modesFor(role, route, startFrom)) {
        await guarded(`behaviour copy-button ${role} ${route} ${mode}`, async () => {
            const page = await sessions.pageFor(role);
            await prepare(page);
            // System Overview keeps the failed jobs (with their copy buttons) folded away
            if (await page.locator('#view-failed-jobs').count()) await page.click('#view-failed-jobs');
            await page.evaluate(clipboardStub);
            const button = page.locator('[data-copy-text]').first();
            if ((await button.count()) === 0) return ['no copy button on the page (fixture)'];
            const text = await button.getAttribute('data-copy-text');
            await button.click();
            const failures = [];
            const writes = await page.evaluate(() => window.__clipboardWrites);
            if (writes.length !== 1 || writes[0] !== text) failures.push(`clipboard got ${JSON.stringify(writes)} (expected one write of ${JSON.stringify(text)})`);
            if (!(await button.locator('.check-icon').isVisible())) failures.push('the check mark is not shown');
            await page.waitForTimeout(2300);
            if (!(await button.locator('.copy-icon').isVisible())) failures.push('the copy icon did not come back');
            return failures;
        });
    }
}
// The hash chain page copies a hash (reached from the document page)
await guarded('behaviour copy-button hash-chain hard load', async () => {
    const page = await sessions.pageFor('records.unit');
    await hardLoad(page, `/documents/${context.documentCode}/hash-chain`);
    await page.evaluate(clipboardStub);
    const button = page.locator('[data-copy-text]').first();
    await button.click();
    const writes = await page.evaluate(() => window.__clipboardWrites);
    return writes.length === 1 && writes[0] === (await button.getAttribute('data-copy-text')) ? [] : [`clipboard got ${JSON.stringify(writes)}`];
});

// Pagination: a page link navigates (by in-app navigation when the page was reached that way) and shows other rows
for (const [mode, prepare] of modesFor('admin', '/all-documents', '/admin-dashboard')) {
    await guarded(`behaviour pagination ${mode}`, async () => {
        const page = await sessions.pageFor('admin');
        await prepare(page);
        // (a link visible at this width: the phone and the desktop variant of the navigation both exist in the page)
        const link = page.locator('nav[aria-label="Pagination Navigation"] a[href*="page="], nav[aria-label="Pagination Navigation"] a[href*="cursor="]').filter({ visible: true }).first();
        if ((await link.count()) === 0) return ['no pagination link (fixture needs more than one page)'];
        const firstRowBefore = await page.locator('tbody tr').first().textContent();
        const href = await link.getAttribute('href');
        await markDocument(page);
        await link.click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(600);
        const failures = [];
        // (The router does not swap the content for these links: their query-only address is resolved against the site root,
        // so it falls back to a full page load; unchanged by this slice, so the link only has to navigate)
        if (!page.url().includes(href.split('?')[1].split('&')[0])) failures.push(`the address is ${page.url()} (link ${href})`);
        if ((await page.locator('tbody tr').first().textContent()) === firstRowBefore) failures.push('the rows did not change');
        return failures;
    });
}

// Toasts and notifications after in-app navigation: a confirmed action shows its toast once, also after more navigation
await guarded('behaviour toast after confirm (no duplicate)', async () => {
    const page = await sessions.pageFor('admin');
    await hopThroughPages(page, '/users', ['/all-documents', '/admin-dashboard']);
    await page.locator('form.confirm-action button[type="submit"]').first().click();
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#global-confirm-btn')]);
    await page.waitForSelector('#toast-container .toast-message', { timeout: 5000 });
    const failures = [];
    const toasts = await page.locator('#toast-container .toast-message').count();
    if (toasts !== 1) failures.push(`${toasts} toasts after one action (expected 1)`);
    await markDocument(page);
    await clickLinkTo(page, '/users');
    await page.waitForTimeout(500);
    const after = await page.locator('#toast-container .toast-message').count();
    if (after > 1) failures.push(`${after} toasts after navigating on (expected at most 1)`);
    return failures;
});

// Staff QR dialog (html5-qrcode stubbed): opens, a decoded code is trimmed and asks for the PIN on the tasks page
for (const [mode, prepare] of modesFor('cash.unit', '/tasks', '/statistics')) {
    await guarded(`behaviour staff-qr-dialog ${mode}`, async () => {
        const page = await sessions.pageFor('cash.unit');
        await prepare(page);
        await page.evaluate(() => {
            window.__scannerRenders = 0;
            window.Html5QrcodeScanner = class {
                render(callback) { window.__scannerRenders++; window.__scanCallback = callback; }
                clear() { return Promise.resolve(); }
            };
        });
        const failures = [];
        await page.click('#scan-qr-btn');
        await page.waitForTimeout(300);
        if (!(await visible(page, '#qr-modal'))) failures.push('the scan dialog did not open');
        if ((await page.evaluate(() => window.__scannerRenders)) !== 1) failures.push('the scanner was not started exactly once');
        await page.evaluate(() => window.__scanCallback('  DEPED-0000000001  '));
        await page.waitForTimeout(500);
        if (await visible(page, '#qr-modal')) failures.push('the dialog stayed open after a scan');
        if ((await page.inputValue('#tracking_code')) !== 'DEPED-0000000001') failures.push('the scanned code was not trimmed into the field');
        if (!(await visible(page, '#signing-modal'))) failures.push('the PIN prompt did not open for the scanned code');
        return failures;
    });
}

// Guest pages (no in-app navigation there): tracking card, camera dialog, 429 back-off
await guarded('behaviour guest-track: add a document card', async () => {
    const page = await sessions.pageFor(null);
    await hardLoad(page, `/track?codes=${context.trackingCode}`);
    const failures = [];
    if ((await page.locator('.document-card').count()) !== 1) failures.push('the page does not start with one card');
    await page.click('button[data-modal="track-modal"]');
    await page.fill('#tracking_code_input', context.documentCode);
    await page.click('#track-another-form button[type="submit"]');
    await page.waitForTimeout(1000);
    if ((await page.locator('.document-card').count()) !== 2) failures.push('the second card was not added');
    if (await visible(page, '#track-modal')) failures.push('the dialog stayed open');
    // The same code again is refused, not added twice
    await page.click('button[data-modal="track-modal"]');
    await page.fill('#tracking_code_input', context.documentCode);
    await page.click('#track-another-form button[type="submit"]');
    await page.waitForTimeout(500);
    if ((await page.locator('.document-card').count()) !== 2) failures.push('a duplicate card was added');
    if (!(await page.textContent('#track-error-message'))?.includes('already being tracked')) failures.push('no message for the duplicate');
    return failures;
});

await guarded('behaviour guest-track: tracking polls back off on 429', async () => {
    // A page of its own: its clock is faked, which must not leak into the other guest checks
    const clockSessions = createSessions(browser, VIEWPORT);
    const page = await clockSessions.pageFor(null);
    let statusRequests = 0;
    await page.route('**/api/track/status**', (route) => { statusRequests++; return route.fulfill({ status: 429, headers: { 'Retry-After': '150' }, body: '{}' }); });
    await page.clock.install();
    await hardLoad(page, `/track?codes=${context.trackingCode}`);
    const failures = [];
    await page.clock.fastForward(61000);
    await page.waitForTimeout(300);
    if (statusRequests !== 1) failures.push(`${statusRequests} status request(s) after the first interval (expected 1)`);
    // The server asked for 150 seconds: the polls at 120 s and 180 s (relative) are held back until the pause is over
    await page.clock.fastForward(61000);
    await page.waitForTimeout(300);
    if (statusRequests !== 1) failures.push(`polling was not held back during the pause (${statusRequests} requests)`);
    await page.clock.fastForward(120000);
    await page.waitForTimeout(300);
    if (statusRequests < 2) failures.push('polling did not resume after the pause');
    await clockSessions.closeAll();
    return failures;
});

await guarded('behaviour guest-qr-dialog (camera stubbed)', async () => {
    const page = await sessions.pageFor(null);
    await hardLoad(page, '/');
    await page.evaluate(() => {
        window.__cameraStarts = 0;
        window.__cameraStops = 0;
        window.Html5Qrcode = class {
            constructor() { this.scanning = false; }
            start(camera, config, onSuccess) { window.__cameraStarts++; window.__onScan = onSuccess; this.scanning = true; return Promise.resolve(); }
            stop() { window.__cameraStops++; this.scanning = false; return Promise.resolve(); }
            get isScanning() { return this.scanning; }
        };
    });
    const failures = [];
    await page.click('button[data-modal="track-modal"]');
    await page.click('#scan-qr-button');
    await page.waitForTimeout(300);
    if (!(await visible(page, '#qr-scanner-modal'))) failures.push('the scanner dialog did not open');
    if (await visible(page, '#track-modal')) failures.push('the track dialog stayed open under the scanner');
    if ((await page.evaluate(() => window.__cameraStarts)) !== 1) failures.push('the camera was not started exactly once');
    await page.click('#close-qr-modal');
    await page.waitForTimeout(300);
    if ((await page.evaluate(() => window.__cameraStops)) < 1) failures.push('the camera was not stopped on close');
    // A decoded code opens the tracking page for it
    await page.click('button[data-modal="track-modal"]');
    await page.click('#scan-qr-button');
    await page.waitForTimeout(200);
    await Promise.all([page.waitForURL(/\/track\?codes=DEPED-SCANNED/, { timeout: 8000 }), page.evaluate(() => window.__onScan('DEPED-SCANNED'))]);
    return failures;
});

await guarded('behaviour guest-welcome: purpose requirements', async () => {
    const page = await sessions.pageFor(null);
    await hardLoad(page, '/');
    const failures = [];
    const purposes = await page.$$eval('#purpose-select option', (options) => options.map((option) => option.value).filter((value) => value && value !== '0'));
    if (purposes.length === 0) return ['no purposes on the page'];
    await page.selectOption('#purpose-select', '0');
    if (await page.locator('#other-purpose-input.hidden').count()) failures.push('"Other" did not show the purpose field');
    await page.selectOption('#purpose-select', purposes[0]);
    if (!(await page.locator('#other-purpose-input.hidden').count())) failures.push('the purpose field stayed visible for a listed purpose');
    // The phone field accepts digits only
    await page.fill('#guest_phone', 'abc0917-123x');
    if ((await page.inputValue('#guest_phone')) !== '0917123') failures.push('the phone field accepted non-digits');
    return failures;
});

// Login and error pages: the theme toggle and the stale PIN token cleanup
await guarded('behaviour login: clears the cached PIN token, theme toggle', async () => {
    const page = await sessions.pageFor(null);
    await page.goto(`${context.baseUrl}/login`, { waitUntil: 'networkidle' });
    await page.evaluate(() => sessionStorage.setItem('dts_pin_token', 'stale'));
    await page.goto(`${context.baseUrl}/login`, { waitUntil: 'networkidle' });
    const failures = [];
    if ((await page.evaluate(() => sessionStorage.getItem('dts_pin_token'))) !== null) failures.push('the stale PIN token was kept');
    const before = await page.evaluate(() => document.documentElement.classList.contains('dark'));
    await page.click('#theme-toggle');
    if ((await page.evaluate(() => document.documentElement.classList.contains('dark'))) === before) failures.push('the toggle did nothing');
    await page.click('#theme-toggle');
    return failures;
});

// ---------------------------------------------------------------------------------------------------------------------------
// Client half of the Security PIN cache token, the keeps-page clearing and the reset options
// ---------------------------------------------------------------------------------------------------------------------------

// POST a form-encoded request from inside the page (the session cookie and CSRF token of the page are used)
const postFromPage = (page, path, fields) => page.evaluate(async ({ path, fields }) => {
    const body = new URLSearchParams({ csrf_token: document.querySelector('meta[name="csrf-token"]').content, ...fields });
    const response = await fetch(path, { method: 'POST', body, redirect: 'manual' });
    return response.status;
}, { path, fields });

// A token issued by the server after a signed action is stored in sessionStorage by the page that carries it
await guarded('behaviour pin-token: an issued token is stored', async () => {
    const page = await sessions.pageFor('cash.unit');
    await hardLoad(page, '/tasks');
    await page.evaluate(() => sessionStorage.clear());
    // A typed PIN on a signed action (receiving the in-transit document) makes the server issue the token for the next page
    await postFromPage(page, '/documents/scan', { tracking_code: context.documentCode, pin: PIN });
    await hardLoad(page, '/tasks');
    const token = await page.evaluate(() => sessionStorage.getItem('dts_pin_token'));
    return token && token.startsWith('pt1.') ? [] : [`sessionStorage holds ${JSON.stringify(token)} (expected a pt1. token)`];
});

// A token the server cannot open is answered with a "clear" block, which removes it from sessionStorage
await guarded('behaviour pin-token: a refused token is cleared', async () => {
    const page = await sessions.pageFor('cash.unit');
    await hardLoad(page, '/tasks');
    await page.evaluate(() => sessionStorage.setItem('dts_pin_token', 'pt1.not-a-real-token'));
    await postFromPage(page, '/documents/scan', { tracking_code: context.documentCode, pin: 'pt1.not-a-real-token' });
    await hardLoad(page, '/tasks');
    const token = await page.evaluate(() => sessionStorage.getItem('dts_pin_token'));
    return token === null ? [] : [`the refused token is still stored: ${token}`];
});

// With a cached token a .sign-action form submits without the prompt and carries the token; without one the prompt shows
await guarded('behaviour pin-token: cached token skips the prompt, none shows it', async () => {
    const page = await sessions.pageFor('admin');
    await hardLoad(page, `/documents/${context.documentCode}`);
    const posts = await recordPosts(page);
    const failures = [];
    await page.evaluate(() => sessionStorage.removeItem('dts_pin_token'));
    await page.locator('form.sign-action button[type="submit"]').first().click();
    await page.waitForTimeout(300);
    if (!(await visible(page, '#signing-modal'))) failures.push('without a token the prompt did not show');
    if (posts.length !== 0) failures.push('a request was sent before the PIN');
    await page.click('#signing-modal-cancel');
    await page.evaluate(() => sessionStorage.setItem('dts_pin_token', 'pt1.cached-token'));
    await page.locator('form.sign-action button[type="submit"]').first().click();
    await page.waitForTimeout(400);
    if (await visible(page, '#signing-modal')) failures.push('with a cached token the prompt showed');
    if (posts.length !== 1 || !/pin=pt1\.cached-token/.test(posts[0].body)) failures.push(`expected one request carrying the cached token, got ${JSON.stringify(posts.map((post) => post.body.slice(0, 50)))}`);
    // The aborted form submit left the browser's error page: load a real page again before touching the storage
    await page.unroute('**/*');
    await hardLoad(page, '/admin-dashboard');
    await page.evaluate(() => sessionStorage.removeItem('dts_pin_token'));
    return failures;
});

// A download keeps the page open, so its PIN field is empty again after the submit
await guarded('behaviour sign-action: download form clears the PIN after submit', async () => {
    const page = await sessions.pageFor('admin');
    await hardLoad(page, '/system/backups');
    const posts = await recordPosts(page, { attachment: true });
    const failures = [];
    const form = page.locator('form.sign-action[data-keeps-page="true"]').first();
    await form.locator('button[type="submit"]').click();
    await page.waitForTimeout(300);
    await confirmPin(page);
    if (posts.length !== 1 || !/pin=/.test(posts[0].body) || !/\/download\//.test(posts[0].url)) failures.push('the download request did not carry the PIN');
    if ((await form.locator('.sign-pin-input').inputValue()) !== '') failures.push('the PIN stayed in the form');
    return failures;
});

// Users reset: each of the three options reaches the request (the form clears its PIN after every submit)
await guarded('behaviour users-reset: password, pin and both reach the request', async () => {
    const page = await sessions.pageFor('admin');
    await hardLoad(page, '/users');
    const posts = await recordPosts(page, { attachment: true });
    const failures = [];
    await page.locator('.reset-credentials-trigger').first().click();
    for (const target of ['password', 'pin', 'both']) {
        await page.check(`#reset-credentials-form input[value="${target}"]`);
        await page.click('#reset-credentials-form button[type="submit"]');
        await page.waitForTimeout(300);
        await confirmPin(page);
        const last = posts[posts.length - 1];
        if (!last || !new RegExp(`target=${target}(&|$)`).test(last.body)) failures.push(`"${target}" did not reach the request (${last ? last.body.slice(0, 50) : 'no request'})`);
        if ((await page.locator('#reset-credentials-form .sign-pin-input').inputValue()) !== '') failures.push(`the PIN stayed in the form after "${target}"`);
    }
    if (posts.length !== 3) failures.push(`${posts.length} requests for three submits`);
    return failures;
});

// Polling stops after in-app navigation: with a fake clock, no chart request is sent after leaving the page, and no Chart remains
for (const [role, route, leaveTo, apiPart, startFrom] of [['records.unit', '/statistics', '/tasks', '/api/statistics/', '/intake'], ['admin', '/admin-dashboard', '/users', '/api/admin-dashboard/', '/all-documents']]) {
    await guarded(`behaviour polling stops after in-app navigation ${route}`, async () => {
        // A page of its own: its clock is faked
        const clockSessions = createSessions(browser, VIEWPORT);
        try {
            const page = await clockSessions.pageFor(role);
            await page.clock.install();
            let chartRequests = 0;
            page.on('request', (request) => { if (request.url().includes(apiPart)) chartRequests++; });
            await hardLoad(page, startFrom);
            await markDocument(page);
            await clickLinkTo(page, route);
            await page.waitForTimeout(1500);
            const failures = [];
            // The poll works while the page is shown (otherwise this check proves nothing)
            const before = chartRequests;
            await page.clock.fastForward(61000);
            await page.waitForTimeout(800);
            if (chartRequests <= before) failures.push('the poll did not run while the page was shown');
            // Leave by in-app navigation, let the interval pass several times
            await clickLinkTo(page, leaveTo);
            if (!(await isSameDocument(page))) failures.push('leaving was a hard load');
            await page.waitForTimeout(500);
            const afterLeaving = chartRequests;
            for (let step = 0; step < 3; step++) {
                await page.clock.fastForward(61000);
                await page.waitForTimeout(400);
            }
            if (chartRequests !== afterLeaving) failures.push(`${chartRequests - afterLeaving} chart request(s) after leaving the page`);
            const remaining = await page.evaluate(() => (window.Chart ? Object.keys(window.Chart.instances).length : 0));
            if (remaining !== 0) failures.push(`${remaining} Chart instance(s) remain`);
            return failures;
        } finally {
            await clockSessions.closeAll();
        }
    });
}

// The mandatory Security PIN prompt opens by itself for an account without a key
await guarded('behaviour pin-setup prompt opens on load', async () => {
    const page = await sessions.pageFor('keyless');
    await hardLoad(page, '/tasks');
    return (await visible(page, '#security-key-modal')) ? [] : ['the mandatory prompt is not shown'];
});

// ---------------------------------------------------------------------------------------------------------------------------
// Part 3: the policy is ENFORCED (S9b). The checks below provoke violations on purpose; those, and only those, are taken out of the
// failure count (each check lists the messages it expects), so any other violation, console error or exception still fails a line.
// ---------------------------------------------------------------------------------------------------------------------------

// Drain the diagnostics and return those that are NOT one of the expected messages of the check
function takeUnexpectedDiagnostics(expectedPatterns) {
    return takeDiagnostics().filter((line) => !expectedPatterns.some((pattern) => pattern.test(line)));
}

// The security headers the server sends on a page, as the browser receives them
await guarded('enforcement response headers on a page', async () => {
    const page = await sessions.pageFor(null);
    const headers = (await page.request.get(`${context.baseUrl}/login`)).headers();
    const failures = [];
    const expected = {
        'content-security-policy': "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        'x-frame-options': 'DENY',
        'x-content-type-options': 'nosniff',
        'referrer-policy': 'same-origin',
    };
    for (const [name, value] of Object.entries(expected)) {
        if (headers[name] !== value) failures.push(`${name}: ${headers[name] ?? 'missing'}`);
    }
    for (const name of ['content-security-policy-report-only', 'strict-transport-security', 'x-powered-by']) {
        if (name in headers) failures.push(`${name} must not be sent`);
    }
    return failures;
});

// An inline script, an inline event handler and eval are really blocked (and reported), not just reported
await guarded('enforcement inline script, handler and eval blocked', async () => {
    const page = await sessions.pageFor(null);
    await hardLoad(page, '/login');
    takeDiagnostics();
    // Record the violation events of the page itself, so the check proves they are reported
    await page.evaluate(() => {
        window.__violations = [];
        document.addEventListener('securitypolicyviolation', (event) => window.__violations.push(`${event.disposition}:${event.violatedDirective}`));
    });
    const outcome = await page.evaluate(async () => {
        // An inline script element
        const script = document.createElement('script');
        script.textContent = 'window.__inlineScriptRan = true';
        document.body.appendChild(script);
        // An inline event handler on an element that is clicked
        const holder = document.createElement('div');
        holder.innerHTML = '<button id="enforcement-probe" onclick="window.__inlineHandlerRan = true">probe</button>';
        document.body.appendChild(holder);
        document.getElementById('enforcement-probe').click();
        // A javascript: URL is followed by a click as well
        const link = document.createElement('a');
        link.href = 'javascript:window.__javascriptUrlRan = true';
        document.body.appendChild(link);
        link.click();
        // Code from a string (what eval does): a string given to setTimeout is run by the page's own world and needs
        // 'unsafe-eval'. (A direct eval() inside this function would not prove anything: the test driver's own evaluation
        // context is exempt from the page's policy, as the first version of this check showed.)
        window.setTimeout('window.__evalRan = true', 0);
        // Give the violation events time to arrive
        await new Promise((resolve) => setTimeout(resolve, 300));
        return {
            ran: ['__inlineScriptRan', '__inlineHandlerRan', '__javascriptUrlRan', '__evalRan'].filter((name) => window[name] === true),
            violations: window.__violations,
        };
    });
    const failures = [];
    if (outcome.ran.length > 0) failures.push(`ran despite the policy: ${outcome.ran.join(', ')}`);
    // Each kind of violation is reported, with the "enforce" disposition (not "report")
    for (const directive of ['script-src-elem', 'script-src-attr', 'script-src']) {
        if (!outcome.violations.includes(`enforce:${directive}`)) failures.push(`no enforced ${directive} violation was reported (${outcome.violations.join(', ') || 'none'})`);
    }
    // Only the deliberate violations are excluded: the console and violation lines about script-src and its refusals
    return [...failures, ...takeUnexpectedDiagnostics([/script-src/, /Refused to (execute|evaluate|run)/, /Content Security Policy directive/])];
});

// A page of the app cannot be framed from another origin (X-Frame-Options: DENY and frame-ancestors 'none')
await guarded('enforcement page cannot be framed', async () => {
    const page = await sessions.pageFor(null);
    // Another origin that does not exist: its page is answered here and frames the app
    await page.route('http://framing-site.test/**', (route) => route.fulfill({
        contentType: 'text/html',
        body: `<!doctype html><title>framing</title><iframe id="victim" src="${context.baseUrl}/login" width="600" height="400"></iframe>`,
    }));
    try {
        await page.goto('http://framing-site.test/', { waitUntil: 'load' });
        await page.waitForTimeout(1000);
        const failures = [];
        const frame = page.frames().find((candidate) => candidate !== page.mainFrame());
        if (!frame) failures.push('the test page has no frame');
        else {
            // A refused frame shows the browser's error page, never the app's login form
            const framedUrl = frame.url();
            const framedText = await frame.evaluate(() => document.body ? document.body.innerHTML : '').catch(() => '');
            if (!framedUrl.startsWith('chrome-error:')) failures.push(`the frame was not refused (url ${framedUrl})`);
            if (framedText.includes('name="email"')) failures.push('the login form was shown inside the frame');
        }
        // The same page as a top-level page still works (no regression of the check itself)
        await hardLoad(page, '/login');
        if (!(await visible(page, 'input[name="email"]'))) failures.push('the login page does not load on its own');
        return [...failures, ...takeUnexpectedDiagnostics([/X-Frame-Options/, /frame-ancestors/, /Refused to (display|frame)/, /Failed to load resource/])];
    } finally {
        await page.unroute('http://framing-site.test/**').catch(() => {});
    }
});

await sessions.closeAll();
await browser.close();
console.log(`Expected console messages that were not counted as failures: ${ignoredSummary()}.`);
console.log(failureCount === 0 ? 'Policy and behaviour check: all OK.' : `Policy and behaviour check: ${failureCount} line(s) failed.`);
process.exit(failureCount === 0 ? 0 : 1);
