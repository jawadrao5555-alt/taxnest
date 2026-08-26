#!/usr/bin/env node
/**
 * Summary X/Z thermal regression check.
 *
 * This is deliberately a browser check rather than a Blade string check:
 * the receipt printer receives the browser's print layout, where 80mm CSS
 * width, font shaping, RTL text, and wrapping all interact.
 *
 * It checks both compact reports in both POS script locales:
 *   - Roman Urdu (rur)
 *   - Urdu script (ur)
 * For every thermal page it emulates print media at 80mm (302 CSS px) and
 * fails on horizontal overflow or a missing semantic report-state header.
 * It also fetches both A4 endpoints and keeps their download prefixes stable.
 *
 * Usage:
 *   node scripts/summary-print-check.mjs
 *   BASE_URL=https://taxnest.com.pk POS_CHECK_LOGIN=... \
 *     POS_CHECK_PASSWORD=... node scripts/summary-print-check.mjs
 *   SUMMARY_X_URL=... SUMMARY_Z_URL=... node scripts/summary-print-check.mjs
 *
 * Credentials may also be supplied through the untracked
 * .local/qa-creds.env (DEV_POS_LOGIN / DEV_POS_PASS). No credentials are
 * printed by this script.
 *
 * Exit codes: 0 = pass, 1 = regression, 2 = server/browser/fixture unavailable.
 */

import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import pw from 'playwright-core';

const { chromium } = pw;
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BASE_URL = (process.env.BASE_URL || 'http://127.0.0.1:5000').replace(/\/+$/, '');
const PRINT_WIDTH = 302; // 80mm at 96 CSS px/in, rounded down.
const HEADLESS = process.env.POS_CHECK_HEADED !== '1';
const LOCALES = [
    { key: 'rur', label: 'Roman Urdu' },
    { key: 'ur', label: 'Urdu script' },
];

let failures = 0;
const ok = (message) => console.log(`    OK: ${message}`);
const fail = (message) => { failures++; console.error(`    FAIL: ${message}`); };
const cannotRun = (message) => {
    console.error(`\nSUMMARY PRINT CHECK: could not run — ${message}`);
    process.exit(2);
};

function readQaCredentials() {
    const values = {};
    const file = path.join(ROOT, '.local/qa-creds.env');
    if (existsSync(file)) {
        for (const line of readFileSync(file, 'utf8').split('\n')) {
            const match = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
            if (match) values[match[1]] = match[2].replace(/^["']|["']$/g, '');
        }
    }
    return {
        login: process.env.POS_CHECK_LOGIN || values.DEV_POS_LOGIN || '',
        password: process.env.POS_CHECK_PASSWORD || values.DEV_POS_PASS || '',
    };
}

function chromiumPath() {
    const candidates = [];
    if (process.env.CHROMIUM_BIN) candidates.push(process.env.CHROMIUM_BIN);
    try { candidates.push(chromium.executablePath()); } catch { /* no downloaded browser */ }
    try {
        const nixCandidates = readdirSync('/nix/store')
            .filter((entry) => /-chromium-\d/.test(entry))
            .map((entry) => `/nix/store/${entry}/bin/chromium`)
            .filter((candidate) => existsSync(candidate))
            .sort();
        // Keep the newest Nix browser, matching the other live browser guards.
        candidates.push(...nixCandidates.reverse());
    } catch { /* not a Nix environment */ }
    return candidates.find((candidate) => candidate && existsSync(candidate));
}

function localizeUrl(href) {
    const parsed = new URL(href, BASE_URL);
    const base = new URL(BASE_URL);
    return `${base.origin}${parsed.pathname}${parsed.search}${parsed.hash}`;
}

function reportUrlsFromLinks(links) {
    const result = { xThermal: null, xPdf: null, zThermal: null, zPdf: null };
    for (const href of links) {
        const url = localizeUrl(href);
        if (/\/day-close\/x-report\/summary\/thermal(?:\?|$)/.test(url)) result.xThermal = url;
        if (/\/day-close\/x-report\/summary\/pdf(?:\?|$)/.test(url)) result.xPdf = url;
        if (/\/day-close\/\d+\/summary\/thermal(?:\?|$)/.test(url)) result.zThermal = url;
        if (/\/day-close\/\d+\/summary\/pdf(?:\?|$)/.test(url)) result.zPdf = url;
    }
    return result;
}

async function rewriteRedirects(page) {
    // Local Laravel can emit absolute HTTPS URLs when a production scheme is
    // forced. Keep browser checks against the selected BASE_URL.
    await page.route('**/*', async (route) => {
        let response;
        try { response = await route.fetch({ maxRedirects: 0 }); }
        catch { await route.continue(); return; }
        if ([301, 302, 303, 307, 308].includes(response.status())) {
            const location = response.headers().location || '';
            if (location) {
                const rewritten = location.startsWith('/')
                    ? `${BASE_URL}${location}`
                    : localizeUrl(location);
                await route.fulfill({
                    status: response.status(),
                    headers: { ...response.headers(), location: rewritten },
                    body: '',
                });
                return;
            }
        }
        await route.fulfill({ response });
    });
}

async function setLocale(page, locale) {
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    if (!csrf) throw new Error('POS page has no CSRF token');
    const response = await page.evaluate(async ({ locale, csrf }) => {
        const result = await fetch('/pos/set-language', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'text/html',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({ language: locale }).toString(),
        });
        return result.status;
    }, { locale, csrf });
    if (response < 200 || response >= 400) throw new Error(`language save returned HTTP ${response}`);
}

async function discoverReports(page) {
    const today = new Date();
    const discovered = { xThermal: null, xPdf: null, zThermal: null, zPdf: null };
    // Look back far enough to find one open X day and one closed Z day,
    // without mutating or creating any shop data.
    for (let offset = 0; offset <= 60 && (!discovered.xThermal || !discovered.zThermal); offset++) {
        const date = new Date(today);
        date.setDate(today.getDate() - offset);
        const iso = date.toISOString().slice(0, 10);
        await page.goto(`${BASE_URL}/pos/day-close?date=${iso}`, {
            waitUntil: 'domcontentloaded',
            timeout: 20000,
        });
        if (page.url().includes('/pos/login')) throw new Error('POS login session was not accepted');
        const links = await page.locator('a[href]').evaluateAll((anchors) => anchors.map((anchor) => anchor.href));
        const found = reportUrlsFromLinks(links);
        for (const key of Object.keys(discovered)) {
            if (!discovered[key] && found[key]) discovered[key] = found[key];
        }
    }
    return discovered;
}

async function inspectThermal(page, url, expectedState, locale) {
    await page.setViewportSize({ width: PRINT_WIDTH, height: 1200 });
    await page.emulateMedia({ media: 'print' });
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
    if (!response || response.status() >= 400) throw new Error(`thermal page returned HTTP ${response?.status() ?? 'unknown'}`);
    await page.evaluate(() => document.fonts?.ready);
    await page.waitForTimeout(250);
    const result = await page.evaluate(() => {
        const viewport = document.documentElement.clientWidth;
        const docWidth = document.documentElement.scrollWidth;
        const state = document.querySelector('[data-report-state]')?.dataset.reportState || null;
        const locale = document.documentElement.dataset.posLocale || null;
        const offenders = [];
        document.querySelectorAll('body *').forEach((element) => {
            const rect = element.getBoundingClientRect();
            if (rect.width > 0 && rect.right > viewport + 1) {
                offenders.push({
                    tag: element.tagName.toLowerCase(),
                    right: Math.round(rect.right),
                    width: Math.round(rect.width),
                });
            }
        });
        return {
            viewport,
            docWidth,
            state,
            locale,
            overflow: docWidth > viewport + 1 || offenders.length > 0,
            offenders: offenders.slice(0, 8),
        };
    });
    if (result.locale !== locale) fail(`${locale} ${expectedState} page did not render requested locale (got ${result.locale})`);
    if (result.state !== expectedState) fail(`${locale} ${expectedState} page is missing its ${expectedState} header`);
    if (result.overflow) {
        fail(`${locale} ${expectedState} page overflows 80mm (viewport ${result.viewport}px, scrollWidth ${result.docWidth}px)`);
        for (const offender of result.offenders) {
            console.error(`      ${offender.tag}: right ${offender.right}px, width ${offender.width}px`);
        }
    } else {
        ok(`${locale} ${expectedState} thermal fits 80mm (${result.viewport}px)`);
    }
}

async function checkDownload(context, url, expectedPrefix, locale) {
    const response = await context.request.get(url, { timeout: 25000 });
    if (response.status() >= 400) {
        fail(`${locale} ${expectedPrefix} A4 endpoint returned HTTP ${response.status()}`);
        return;
    }
    const disposition = response.headers()['content-disposition'] || '';
    const expected = new RegExp(`filename="?${expectedPrefix}-[^"]+\\.pdf`, 'i');
    if (!expected.test(disposition)) {
        fail(`${locale} ${expectedPrefix} filename changed (Content-Disposition: ${disposition || 'missing'})`);
    } else {
        ok(`${locale} ${expectedPrefix} A4 filename keeps ${expectedPrefix}`);
    }
}

(async () => {
    const credentials = readQaCredentials();
    if (!credentials.login || !credentials.password) cannotRun('credentials missing — set POS_CHECK_LOGIN/POS_CHECK_PASSWORD or .local/qa-creds.env');
    const executablePath = chromiumPath();
    if (!executablePath) cannotRun('no Chromium binary found — set CHROMIUM_BIN');

    const browser = await chromium.launch({
        executablePath,
        headless: HEADLESS,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    });
    const context = await browser.newContext({ viewport: { width: PRINT_WIDTH, height: 1200 } });
    const page = await context.newPage();
    await rewriteRedirects(page);

    try {
        await page.goto(`${BASE_URL}/pos/login`, { waitUntil: 'domcontentloaded', timeout: 20000 });
        await page.fill('input[name="login"]', credentials.login);
        await page.fill('input[name="password"]', credentials.password);
        await page.click('form[action="/pos/login"] button[type="submit"]');
        await page.waitForURL((url) => !url.pathname.endsWith('/pos/login'), { timeout: 25000 });
        if (page.url().includes('/pos/login')) throw new Error('login failed');
        ok(`logged in to ${BASE_URL}`);

        await page.goto(`${BASE_URL}/pos/day-close`, { waitUntil: 'domcontentloaded', timeout: 20000 });
        const explicit = {
            xThermal: process.env.SUMMARY_X_URL || null,
            xPdf: process.env.SUMMARY_X_PDF_URL || null,
            zThermal: process.env.SUMMARY_Z_URL || null,
            zPdf: process.env.SUMMARY_Z_PDF_URL || null,
        };
        const discovered = Object.values(explicit).some(Boolean) ? explicit : await discoverReports(page);
        if (!discovered.xThermal || !discovered.zThermal) {
            throw new Error('could not find both Summary X and Summary Z links in the last 60 days; set SUMMARY_X_URL and SUMMARY_Z_URL');
        }
        discovered.xPdf ||= discovered.xThermal.replace(/\/thermal(?:\?.*)?$/, '/pdf');
        discovered.zPdf ||= discovered.zThermal.replace(/\/thermal(?:\?.*)?$/, '/pdf');

        for (const locale of LOCALES) {
            await page.goto(`${BASE_URL}/pos/day-close`, { waitUntil: 'domcontentloaded', timeout: 20000 });
            await setLocale(page, locale.key);
            await inspectThermal(page, discovered.xThermal, 'provisional', locale.key);
            await checkDownload(context, discovered.xPdf, 'Summary-X-Report', locale.key);
            await inspectThermal(page, discovered.zThermal, 'frozen', locale.key);
            await checkDownload(context, discovered.zPdf, 'Summary-Z-Report', locale.key);
        }
    } catch (error) {
        fail(error?.message || String(error));
    } finally {
        await browser.close();
    }

    if (failures) {
        console.error(`\nSUMMARY PRINT CHECK: ${failures} failure(s).`);
        process.exit(1);
    }
    console.log('\nSUMMARY PRINT CHECK OK: Summary X/Z fit 80mm in Roman Urdu and Urdu, state headers present, A4 filenames stable.');
})().catch((error) => cannotRun(error?.message || String(error)));