// Shared setup of the two browser checks (page-screenshots.mjs and accessibility-check.mjs): context file, Chromium,
// the page list and the sign-in helper. Both run through the one launcher scripts/manual/page-screenshots.php.
// playwright-core is installed outside package.json: npm install --prefix working-php/.test/browser playwright-core
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
export const projectRoot = path.resolve(scriptDirectory, '..', '..');
const require = createRequire(path.join(projectRoot, '.test', 'browser', 'package.json'));
const { chromium } = require('playwright-core');

// Facts written by the launcher (base URL, fixture codes, ids); the first argument is the context JSON file
export const context = JSON.parse(readFileSync(process.argv[2], 'utf8'));

const executablePath = process.env.CHROMIUM_PATH
    || path.join(homedir(), '.cache/ms-playwright/chromium_headless_shell-1248/chrome-headless-shell-linux64/chrome-headless-shell');

// Start the headless browser
export function launchBrowser() {
    return chromium.launch({ executablePath, args: ['--no-sandbox', '--disable-gpu'] });
}

// Pages: name, role (null = guest, no sign-in; 'keyless' = account without a signing key), path
export const pages = [
    ['guest-welcome', null, '/'],
    ['guest-track', null, `/track?codes=${context.trackingCode}`],
    ['guest-success', null, `/success?tracking_code=${context.trackingCode}&document_id=${context.documentId}`],
    ['login', null, '/login'],
    ['error-404', null, '/this-page-does-not-exist'],
    ['security-pin-prompt', 'keyless', '/tasks'],
    ['intake', 'records', '/intake'],
    ['tasks-staff', 'cash', '/tasks'],
    ['tasks-completed', 'records', '/tasks/completed'],
    ['manage-documents', 'records', `/documents/${context.documentId}/manage`],
    ['releasing', 'records', '/releasing'],
    ['document-view', 'records', `/documents/${context.documentCode}`],
    ['hash-chain', 'records', `/documents/${context.documentCode}/hash-chain`],
    ['statistics', 'records', '/statistics'],
    ['account-password', 'records', '/account/password'],
    ['admin-dashboard', 'admin', '/admin-dashboard'],
    ['system-overview', 'admin', '/system-overview'],
    ['all-documents', 'admin', '/all-documents'],
    ['users-index', 'admin', '/users'],
    ['users-create', 'admin', '/users/create'],
    ['users-edit', 'admin', `/users/${context.editUserId}/edit`],
    ['backups', 'admin', '/system/backups'],
];

// What the pages reported since the last call: Content Security Policy violations (the policy is enforced since S9b), console
// errors and uncaught exceptions. Every browser page created by createSessions() feeds this list; each check drains it
// with takeDiagnostics() and fails when it is not empty.
const diagnostics = [];

// Console messages that are expected and therefore not a failure: [pattern, reason]. Add a pattern with allowConsoleError().
const allowedConsoleErrors = [];

// How many messages each allowed pattern swallowed (reason => count), so nothing is ignored silently
const ignoredMessageCounts = new Map();

// One line naming what was ignored and why
export function ignoredSummary() {
    return [...ignoredMessageCounts].map(([reason, count]) => `${count} x ${reason}`).join('; ') || 'nothing';
}

// Allow one more kind of console message (used by a check that provokes it on purpose, e.g. a 404 page)
export function allowConsoleError(pattern, reason) {
    allowedConsoleErrors.push([pattern, reason]);
}

// Drain the recorded diagnostics (one string each)
export function takeDiagnostics() {
    return diagnostics.splice(0);
}

// Shorten a source URL to its path for the report lines
function shortSource(source) {
    if (!source) return 'inline/unknown';
    try {
        return new URL(source).pathname;
    } catch (error) {
        return String(source).slice(0, 60);
    }
}

// Record policy violations, console errors and uncaught exceptions of one browser page
function watchPage(page) {
    // Console errors, and the messages Chromium prints about the policy ("Refused to ...")
    page.on('console', (message) => {
        const text = message.text();
        const isPolicyMessage = /Content Security Policy|Content-Security-Policy|\[Report Only\]/i.test(text);
        if (!isPolicyMessage && message.type() !== 'error') return;
        const allowed = allowedConsoleErrors.find(([pattern]) => pattern.test(text));
        if (allowed) {
            ignoredMessageCounts.set(allowed[1], (ignoredMessageCounts.get(allowed[1]) ?? 0) + 1);
            return;
        }
        const location = message.location();
        diagnostics.push(`${isPolicyMessage ? 'policy message' : 'console error'}: ${text.replace(/\s+/g, ' ').slice(0, 220)} (${shortSource(location.url)}:${location.lineNumber ?? '?'})`);
    });
    page.on('pageerror', (error) => diagnostics.push(`uncaught exception: ${String(error.message).split('\n')[0].slice(0, 220)}`));
}

// Report securitypolicyviolation events from the document itself (survives hard navigations: it is an init script)
async function watchContext(browserContext) {
    await browserContext.exposeFunction('__dtsReportViolation', (violation) => {
        diagnostics.push(`policy violation (${violation.disposition}): ${violation.directive} blocked ${shortSource(violation.blocked) === 'inline/unknown' ? violation.blocked || 'inline' : shortSource(violation.blocked)} from ${shortSource(violation.source)}:${violation.line} "${String(violation.sample || '').slice(0, 40)}"`);
    });
    await browserContext.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
            window.__dtsReportViolation({
                disposition: event.disposition,
                directive: event.violatedDirective,
                blocked: event.blockedURI,
                source: event.sourceFile,
                line: event.lineNumber,
                sample: event.sample,
            });
        });
    });
}

// Sign in through the real login form so the session cookie belongs to the browser context
export async function signIn(page, username) {
    await page.goto(`${context.baseUrl}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="email"]', username);
    await page.fill('input[name="password"]', context.password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type="submit"]')]);
}

// One signed-in browser page per role, created on first use; the returned object also closes them all
export function createSessions(browser, viewport) {
    const sessions = new Map();
    return {
        async pageFor(role) {
            if (!sessions.has(role)) {
                const browserContext = await browser.newContext({ viewport });
                await watchContext(browserContext);
                const page = await browserContext.newPage();
                // BROWSER_ACTION_TIMEOUT_MS shortens the wait for an element (default 30 s), e.g. to run against an older build
                if (Number(process.env.BROWSER_ACTION_TIMEOUT_MS) > 0) page.setDefaultTimeout(Number(process.env.BROWSER_ACTION_TIMEOUT_MS));
                watchPage(page);
                if (role) await signIn(page, role === 'keyless' ? context.keylessUser : role);
                // The sign-in itself is not part of any check
                takeDiagnostics();
                sessions.set(role, page);
            }
            return sessions.get(role);
        },
        async closeAll() {
            for (const page of sessions.values()) await page.context().close();
            sessions.clear();
        },
    };
}
