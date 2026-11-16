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
    ['intake', 'records.unit', '/intake'],
    ['tasks-staff', 'cash.unit', '/tasks'],
    ['tasks-completed', 'records.unit', '/tasks/completed'],
    ['manage-documents', 'records.unit', `/documents/${context.documentId}/manage`],
    ['releasing', 'records.unit', '/releasing'],
    ['document-view', 'records.unit', `/documents/${context.documentCode}`],
    ['hash-chain', 'records.unit', `/documents/${context.documentCode}/hash-chain`],
    ['statistics', 'records.unit', '/statistics'],
    ['account-password', 'records.unit', '/account/password'],
    ['admin-dashboard', 'admin', '/admin-dashboard'],
    ['system-overview', 'admin', '/system-overview'],
    ['all-documents', 'admin', '/all-documents'],
    ['users-index', 'admin', '/users'],
    ['users-create', 'admin', '/users/create'],
    ['users-edit', 'admin', `/users/${context.editUserId}/edit`],
    ['backups', 'admin', '/system/backups'],
];

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
                const page = await (await browser.newContext({ viewport })).newPage();
                if (role) await signIn(page, role === 'keyless' ? context.keylessUser : role);
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
