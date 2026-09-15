#!/usr/bin/env node
/**
 * Local-only category-native work-order journey (desktop + mobile).
 *
 * Prerequisites:
 *   php scripts/cloud-local-category-qa-seed.php
 *   php artisan serve --host=127.0.0.1 --port=8000
 *   BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-category-smoke.mjs
 *
 * Evidence is written under gitignored .local/browser-evidence/category-*.
 */

import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import {
    ROOT,
    assertLocalOnlyBaseUrl,
    attachDiagnostics,
    launchLocalBrowser,
    parseEnvFile,
    saveEvidenceScreenshot,
} from './lib/local-browser.mjs';

const BASE_URL = assertLocalOnlyBaseUrl(process.env.BASE_URL || 'http://127.0.0.1:8000');
const fromFile = parseEnvFile(path.join(ROOT, '.local/category-qa-creds.env'));
const login = process.env.CATEGORY_QA_LOGIN || fromFile.CATEGORY_QA_LOGIN || '';
const password = process.env.CATEGORY_QA_PASS || fromFile.CATEGORY_QA_PASS || '';
if (!login || !password || !/@category-lab\.invalid$/i.test(login)) {
    console.error('CATEGORY SMOKE: refused — run the fictional local category seed first.');
    process.exit(2);
}

let failures = 0;
const ok = (message) => console.log(`  OK: ${message}`);
const bad = (message) => { failures++; console.error(`  FAIL: ${message}`); };

async function dismissNotices(page) {
    for (const pattern of [/Samajh Gaya|Got it/i, /Baad Mein|Later/i]) {
        const button = page.getByRole('button', { name: pattern }).first();
        if (await button.isVisible().catch(() => false)) {
            await button.click({ timeout: 5000 }).catch(() => null);
            await page.waitForTimeout(300);
        }
    }
    await page.evaluate(() => {
        document.getElementById('tn-domain-move-notice')?.remove();
        document.querySelectorAll('[data-wn-featured], [x-data*="wnOpen"]').forEach((el) => el.remove());
        document.querySelectorAll('.fixed.inset-0').forEach((el) => {
            const t = el.innerText || '';
            if (/Naye Updates|AAP KI RAYE|Caller ID|Free Trial|Subscription expired|Payment Proof|Samajh Gaya|Baad Mein|whats.?new|Mashwara/i.test(t)) {
                el.remove();
            }
        });
    }).catch(() => {});
}

async function markWhatsNewSeen(page) {
    await page.evaluate(async () => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!token) return;
        try {
            await fetch('/pos/whats-new/seen', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({}),
            });
        } catch { /* ignore */ }
    }).catch(() => {});
}

async function signIn(page) {
    await page.goto(`${BASE_URL}/pos/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.locator('input[name="login"]').fill(login);
    await page.locator('input[name="password"]').fill(password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/pos/login'), { timeout: 30000 }),
        page.getByRole('button', { name: /sign in|login|لاگ اِن/i }).first().click(),
    ]);
    await dismissNotices(page);
    await markWhatsNewSeen(page);
    await dismissNotices(page);
}

async function saveDiagnostics(label, diag, extra) {
    const dir = path.join(ROOT, '.local/browser-evidence');
    mkdirSync(dir, { recursive: true });
    writeFileSync(path.join(dir, `category-${label}-diagnostics.json`), JSON.stringify({
        time: new Date().toISOString(),
        summary: diag.summary(),
        pageErrors: diag.pageErrors,
        consoleErrors: diag.consoleErrors,
        failedRequests: diag.failedRequests,
        ...extra,
    }, null, 2));
}

async function journey(browser, label, viewport) {
    const context = await browser.newContext({ viewport, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const diag = attachDiagnostics(page);
    let jobNumber = null;
    try {
        await signIn(page);
        await page.goto(`${BASE_URL}/pos/work-orders`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await dismissNotices(page);
        const board = await page.locator('[data-service-workflow="salon"]');
        if (await board.count()) ok(`${label}: Salon Appointment Board is category-native`);
        else bad(`${label}: Salon board marker missing`);
        await saveEvidenceScreenshot(page, `category-${label}-01-board`);

        await page.goto(`${BASE_URL}/pos/work-orders/create`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await dismissNotices(page);
        await page.locator('input[name="customer_name"]').fill(`Fictional ${label} Guest`);
        await page.locator('select[name="service_id"]').selectOption({ index: 1 });
        await page.locator('input[name="scheduled_at"]').fill('2030-01-15T10:30');
        await page.locator('input[name="details[staff]"]').fill('Fictional Stylist');
        await page.locator('input[name="details[station]"]').fill('Chair A');
        await page.locator('input[name="quantity"]').fill('1');
        await page.locator('input[name="unit_price"]').fill('1200');
        await dismissNotices(page);
        const createBtn = page.getByRole('button', { name: /Create Appointment/i }).first();
        await Promise.all([
            page.waitForURL(/\/pos\/work-orders\/\d+$/, { timeout: 30000 }).catch(() => null),
            createBtn.click({ force: true }),
        ]);
        if (!/\/pos\/work-orders\/\d+/.test(page.url())) {
            throw new Error(`create did not land on work-order show (${page.url()})`);
        }
        const marker = page.locator('[data-service-order]');
        jobNumber = await marker.getAttribute('data-service-order');
        if (/^SAL-\d{6}$/.test(jobNumber || '')) ok(`${label}: ${jobNumber} created`);
        else bad(`${label}: native SAL number missing`);
        if (/Booked/i.test(await page.locator('body').innerText())) ok(`${label}: initial Booked stage shown`);
        else bad(`${label}: initial stage missing`);
        await saveEvidenceScreenshot(page, `category-${label}-02-created`);

        await dismissNotices(page);
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.getByRole('button', { name: /Move to Checked In/i }).click({ force: true }),
        ]);
        await dismissNotices(page);
        if (/Checked In/i.test(await page.locator('body').innerText())) ok(`${label}: transition and timeline shown`);
        else bad(`${label}: transition not visible`);
        await saveEvidenceScreenshot(page, `category-${label}-03-transition`);

        if (diag.pageErrors.length || diag.consoleErrors.length || diag.failedRequests.length) {
            bad(`${label}: ${diag.summary()}`);
        } else {
            ok(`${label}: no page/console/network errors`);
        }
    } catch (error) {
        bad(`${label}: ${error?.message || error}`);
    } finally {
        await saveDiagnostics(label, diag, { viewport, jobNumber });
        await context.close();
    }
}

let launched;
try {
    launched = await launchLocalBrowser();
} catch (error) {
    console.error(`CATEGORY SMOKE: could not run — ${error.message}`);
    process.exit(2);
}

try {
    await journey(launched.browser, 'desktop', { width: 1280, height: 800 });
    await journey(launched.browser, 'mobile', { width: 390, height: 844 });
} finally {
    await launched.browser.close();
}

if (failures) {
    console.error(`CATEGORY SMOKE: FAIL (${failures})`);
    process.exit(1);
}
console.log('CATEGORY SMOKE: PASS');
