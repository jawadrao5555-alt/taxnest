/**
 * Shared fail-closed helpers for Cloud Agent / local Chrome UI smoke tests.
 *
 * Rules:
 *   - BASE_URL must be loopback only (127.0.0.1 / localhost / ::1)
 *   - Never allow taxnest.pk, production IPs, or remote hosts
 *   - Credentials come from env or untracked .local/qa-creds.env — never hardcoded
 *   - Prefer google-chrome / CHROMIUM_BIN already on the VM
 */

import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import pw from 'playwright-core';

const { chromium } = pw;

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

/** Hostnames / host patterns that must never be targeted by local browser QA. */
const BLOCKED_HOST_RE = /^(?:.*\.)?taxnest\.pk$|115\.186\.164\.126|nayatel|cpanel|production/i;

/**
 * Parse KEY=VALUE lines from an untracked env file (e.g. .local/qa-creds.env).
 * @param {string} filePath
 * @returns {Record<string, string>}
 */
export function parseEnvFile(filePath) {
    const out = {};
    if (!existsSync(filePath)) return out;
    for (const line of readFileSync(filePath, 'utf8').split('\n')) {
        const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
        if (!m) continue;
        out[m[1]] = m[2].replace(/^["']|["']$/g, '');
    }
    return out;
}

/**
 * Fail closed: refuse any non-loopback BASE_URL.
 * @param {string} rawUrl
 * @returns {string} normalized base URL without trailing slash
 */
export function assertLocalOnlyBaseUrl(rawUrl) {
    const input = String(rawUrl || '').trim();
    if (!input) {
        throw new Error('BASE_URL is empty — set BASE_URL=http://127.0.0.1:8000');
    }
    let u;
    try {
        u = new URL(input);
    } catch {
        throw new Error(`BASE_URL is not a valid URL: ${input}`);
    }
    if (u.protocol !== 'http:' && u.protocol !== 'https:') {
        throw new Error(`BASE_URL protocol must be http(s), got ${u.protocol}`);
    }
    const host = u.hostname.toLowerCase();
    const loopback = host === '127.0.0.1' || host === 'localhost' || host === '::1' || host === '[::1]';
    if (!loopback) {
        throw new Error(
            `REFUSED: BASE_URL host '${u.hostname}' is not loopback. ` +
            'Cloud/local browser QA may only target 127.0.0.1 / localhost / ::1.'
        );
    }
    if (BLOCKED_HOST_RE.test(host) || BLOCKED_HOST_RE.test(u.host)) {
        throw new Error(`REFUSED: BASE_URL looks like production (${u.href})`);
    }
    // Extra belt: refuse if the string itself mentions production hosts even via weird URLs
    if (/taxnest\.pk|115\.186\.164\.126/i.test(input)) {
        throw new Error(`REFUSED: BASE_URL mentions a production host (${input})`);
    }
    return u.href.replace(/\/+$/, '');
}

/**
 * Resolve Chrome/Chromium binary for Playwright connect.
 * @returns {string|null}
 */
export function resolveChromiumPath() {
    const tries = [];
    if (process.env.CHROMIUM_BIN) tries.push(process.env.CHROMIUM_BIN);
    if (process.env.GOOGLE_CHROME_BIN) tries.push(process.env.GOOGLE_CHROME_BIN);
    tries.push('/usr/local/bin/google-chrome', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser');
    try { tries.push(chromium.executablePath()); } catch { /* browsers not downloaded */ }
    for (const p of tries) {
        if (p && existsSync(p)) return p;
    }
    try {
        const hits = readdirSync('/nix/store')
            .filter((d) => /-chromium-\d/.test(d))
            .map((d) => `/nix/store/${d}/bin/chromium`)
            .filter((p) => existsSync(p))
            .sort();
        if (hits.length) return hits[hits.length - 1];
    } catch { /* ignore */ }
    return null;
}

/**
 * Local NestPOS QA credentials. Never hardcode; never read production live QA.
 * Priority: CLOUD_LOCAL_QA_* env → POS_CHECK_* → VIDEO_DEMO_* → .local/qa-creds.env
 * @returns {{ login: string, password: string, source: string }}
 */
export function loadLocalQaCreds() {
    const fromFile = parseEnvFile(path.join(ROOT, '.local/qa-creds.env'));
    const login =
        process.env.CLOUD_LOCAL_QA_LOGIN ||
        process.env.POS_CHECK_LOGIN ||
        process.env.VIDEO_DEMO_LOGIN ||
        fromFile.CLOUD_LOCAL_QA_LOGIN ||
        fromFile.DEV_POS_LOGIN ||
        fromFile.VIDEO_DEMO_LOGIN ||
        'videodemo@nestpos.pk';
    const password =
        process.env.CLOUD_LOCAL_QA_PASSWORD ||
        process.env.POS_CHECK_PASSWORD ||
        process.env.VIDEO_DEMO_PASS ||
        fromFile.CLOUD_LOCAL_QA_PASSWORD ||
        fromFile.DEV_POS_PASS ||
        fromFile.VIDEO_DEMO_PASS ||
        '';
    // Refuse known live QA identity so Cloud Agents cannot accidentally reuse production accounts.
    const blockedLogins = [
        'qa.fullaudit@taxnest.com.pk',
        'qa@taxnest.pk',
    ];
    if (blockedLogins.includes(String(login).toLowerCase())) {
        throw new Error(
            `REFUSED: login '${login}' is a live/production QA identity. ` +
            'Use the fictional local demo account (videodemo@nestpos.pk) only.'
        );
    }
    if (!password) {
        throw new Error(
            'No local QA password. Run: bash scripts/cloud-local-qa-seed.sh ' +
            '(writes VIDEO_DEMO_PASS into untracked .local/qa-creds.env)'
        );
    }
    return { login, password, source: process.env.CLOUD_LOCAL_QA_PASSWORD ? 'env' : (fromFile.VIDEO_DEMO_PASS ? '.local/qa-creds.env' : 'env-or-file') };
}

/**
 * Attach console + pageerror + failed request collectors to a Playwright page.
 * @param {import('playwright-core').Page} page
 * @returns {{ consoleErrors: string[], pageErrors: string[], failedRequests: string[], summary: () => string }}
 */
export function attachDiagnostics(page) {
    const consoleErrors = [];
    const pageErrors = [];
    const failedRequests = [];

    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });
    page.on('pageerror', (err) => {
        pageErrors.push(String(err?.message || err));
    });
    page.on('requestfailed', (req) => {
        const failure = req.failure();
        // Ignore aborted navigations / cancelled assets; keep real failures.
        const errText = failure?.errorText || '';
        if (/NS_BINDING_ABORTED|net::ERR_ABORTED/i.test(errText)) return;
        failedRequests.push(`${req.method()} ${req.url()} — ${errText}`);
    });

    return {
        consoleErrors,
        pageErrors,
        failedRequests,
        summary() {
            const parts = [];
            if (pageErrors.length) parts.push(`pageerrors=${pageErrors.length}`);
            if (consoleErrors.length) parts.push(`console_errors=${consoleErrors.length}`);
            if (failedRequests.length) parts.push(`failed_requests=${failedRequests.length}`);
            return parts.length ? parts.join(', ') : 'no browser errors collected';
        },
    };
}

/**
 * Launch headed/headless Chrome for local smoke tests.
 * @param {{ headless?: boolean }} [opts]
 */
export async function launchLocalBrowser(opts = {}) {
    const exe = resolveChromiumPath();
    if (!exe) {
        throw new Error('No Chrome/Chromium binary found (set CHROMIUM_BIN or install google-chrome)');
    }
    const headless = opts.headless !== false && process.env.CLOUD_LOCAL_QA_HEADED !== '1';
    const browser = await chromium.launch({
        executablePath: exe,
        headless,
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
    });
    return { browser, executablePath: exe, headless };
}

/**
 * Write a screenshot under .local/browser-evidence/ (gitignored).
 * @param {import('playwright-core').Page} page
 * @param {string} name
 * @returns {string} absolute path
 */
export async function saveEvidenceScreenshot(page, name) {
    const dir = path.join(ROOT, '.local/browser-evidence');
    mkdirSync(dir, { recursive: true });
    const safe = String(name).replace(/[^a-zA-Z0-9._-]+/g, '_');
    const file = path.join(dir, `${safe}.png`);
    await page.screenshot({ path: file, fullPage: true });
    writeFileSync(path.join(dir, `${safe}.txt`), `screenshot=${file}\ntime=${new Date().toISOString()}\n`);
    return file;
}

export { chromium };
