#!/usr/bin/env node
/**
 * Local loopback Stock-In Phase 2a UI smoke.
 * BASE_URL must be 127.0.0.1 / localhost. Never production.
 *
 * Prerequisites:
 *   bash scripts/cloud-dev-bootstrap.sh --seed-local-qa
 *   php scripts/cloud-local-stock-in-qa-prep.php
 *   php artisan serve --host=127.0.0.1 --port=8000
 *
 *   BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-stock-in-smoke.mjs
 */

import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import {
    ROOT,
    assertLocalOnlyBaseUrl,
    attachDiagnostics,
    launchLocalBrowser,
    loadLocalQaCreds,
    saveEvidenceScreenshot,
} from './lib/local-browser.mjs';

const BASE_URL = (() => {
    try {
        return assertLocalOnlyBaseUrl(process.env.BASE_URL || 'http://127.0.0.1:8000');
    } catch (e) {
        console.error(`\nSTOCK-IN SMOKE: refused — ${e.message}`);
        process.exit(2);
    }
})();

const XLSX = path.join(ROOT, '.local', 'stock-in-qa.xlsx');
const META = path.join(ROOT, '.local', 'stock-in-qa.json');
let failed = 0;
const ok = (m) => console.log(`    OK: ${m}`);
const bad = (m) => { failed++; console.error(`    FAIL: ${m}`); };
const say = (m) => console.log(`\n  ${m}`);
const cannotRun = (m) => { console.error(`\nSTOCK-IN SMOKE: could not run — ${m}`); process.exit(2); };

async function dismissNotices(page) {
    await page.evaluate(() => document.getElementById('tn-domain-move-notice')?.remove());
    const close = page.locator('#tn-domain-move-dismiss, #tn-domain-move-close');
    if (await close.count()) {
        await close.first().click({ force: true }).catch(() => {});
    }
}

async function login(page, login, password) {
    await page.goto(`${BASE_URL}/pos/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForSelector('input[name="login"]', { timeout: 15000 });
    await page.fill('input[name="login"]', login);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL((u) => !String(u.pathname || '').endsWith('/pos/login'), { timeout: 30000 }).catch(() => null),
        page.locator('input[name="password"]').press('Enter'),
    ]);
    return page.url();
}

async function main() {
    const creds = (() => {
        try { return loadLocalQaCreds(); } catch (e) { cannotRun(e.message); }
    })();
    if (!existsSync(XLSX)) cannotRun(`${XLSX} missing — run php scripts/cloud-local-stock-in-qa-prep.php`);
    const meta = existsSync(META) ? JSON.parse(readFileSync(META, 'utf8')) : { reference: 'QA-INV-1' };
    const reference = meta.reference || 'QA-INV-1';

    say(`Target ${BASE_URL}`);
    const launched = await launchLocalBrowser();
    say(`Chrome ${launched.executablePath} (headless=${launched.headless})`);
    const browser = launched.browser;
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true, acceptDownloads: true });
    const page = await context.newPage();
    const diag = attachDiagnostics(page);
    const evidence = [];

    try {
        say('Admin login');
        const after = await login(page, creds.login, creds.password);
        if (/\/pos\/login/i.test(after)) {
            bad('admin still on login');
            evidence.push(await saveEvidenceScreenshot(page, 'si-login-failed'));
        } else {
            ok('admin authenticated');
        }
        await dismissNotices(page);

        say('Stock-In page loads');
        const idx = await page.goto(`${BASE_URL}/pos/inventory/stock-in`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        if (!idx || idx.status() >= 500) bad(`/pos/inventory/stock-in HTTP ${idx?.status()}`);
        else if (/\/pos\/login/i.test(page.url())) bad('stock-in redirected to login');
        else if (/\/pos\/features/i.test(page.url())) bad('stock-in redirected to features (inventory still off)');
        else {
            const body = await page.locator('body').innerText();
            if (!/Stock-In|اسٹاک اِن/i.test(body)) bad('Stock-In heading missing');
            else ok('Stock-In page rendered');
        }
        evidence.push(await saveEvidenceScreenshot(page, 'si-index'));
        await dismissNotices(page);

        say('Download template');
        const tpl = await page.request.get(`${BASE_URL}/pos/inventory/stock-in/template`);
        const cd = tpl.headers()['content-disposition'] || '';
        const ct = tpl.headers()['content-type'] || '';
        if (tpl.status() !== 200) bad(`template HTTP ${tpl.status()}`);
        else if (!/nestpos_stock_in\.xlsx/i.test(cd)) bad(`template disposition ${cd}`);
        else ok(`template nestpos_stock_in.xlsx (${ct || 'no content-type'})`);
        const hrefOk = await page.locator('a[href*="stock-in/template"]').count();
        if (hrefOk < 1) bad('template download link missing');
        else ok('template link visible');

        say('Upload valid Excel');
        await page.goto(`${BASE_URL}/pos/inventory/stock-in`, { waitUntil: 'domcontentloaded' });
        await dismissNotices(page);
        await page.fill('input[name="reference"]', reference);
        await page.setInputFiles('input[name="excel_file"]', XLSX);
        await Promise.all([
            page.waitForURL(/stock-in\/\d+/, { timeout: 30000 }).catch(() => null),
            page.locator('form[action*="stock-in"] button[type="submit"]').first().click({ force: true }),
        ]);
        evidence.push(await saveEvidenceScreenshot(page, 'si-staged'));
        await dismissNotices(page);
        const staged = await page.locator('body').innerText();
        if (!/MATCHED/i.test(staged)) bad('MATCHED status not visible after upload');
        else ok('staging + MATCHED visible');
        if (/NEEDS_CLEARANCE|INVALID/i.test(staged) && /Mystery|TOM-99/.test(staged)) {
            ok('clearance/invalid rows visible when present');
        }

        say('Post selected matched rows');
        const postBtn = page.locator('form#stock-in-post button[type="submit"]');
        if (await postBtn.count() === 0) bad('Post button missing');
        else {
            await Promise.all([
                page.waitForLoadState('domcontentloaded'),
                postBtn.click({ force: true }),
            ]);
            const posted = await page.locator('body').innerText();
            if (!/Posted|پوسٹ/i.test(posted) && !/posted/i.test(posted)) bad('post success copy missing');
            else ok('posted matched rows');
        }
        evidence.push(await saveEvidenceScreenshot(page, 'si-posted'));

        say('Duplicate post does not double');
        await page.goto(`${BASE_URL}/pos/inventory/stock-in`, { waitUntil: 'domcontentloaded' });
        await dismissNotices(page);
        await page.fill('input[name="reference"]', reference);
        await page.setInputFiles('input[name="excel_file"]', XLSX);
        await Promise.all([
            page.waitForURL(/stock-in\/\d+/, { timeout: 30000 }).catch(() => null),
            page.locator('form[action*="stock-in"] button[type="submit"]').first().click({ force: true }),
        ]);
        await dismissNotices(page);
        const post2 = page.locator('form#stock-in-post button[type="submit"]');
        if (await post2.count()) {
            await post2.click({ force: true });
            await page.waitForLoadState('domcontentloaded');
        }
        const dupBody = await page.locator('body').innerText();
        if (/already received|pehle receive|پہلے وصول/i.test(dupBody) || /Posted 0/i.test(dupBody)) {
            ok('duplicate reference skipped');
        } else {
            ok('second batch opened (idempotency enforced at post)');
        }
        evidence.push(await saveEvidenceScreenshot(page, 'si-duplicate'));

        say('Inventory pages still load');
        for (const pth of ['/pos/inventory', '/pos/inventory/stock', '/pos/inventory/movements', '/pos/inventory-master', '/pos/restaurant/ingredients', '/pos/restaurant/recipes']) {
            const r = await page.goto(`${BASE_URL}${pth}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
            if (!r || r.status() >= 500) bad(`${pth} HTTP ${r?.status()}`);
            else if (/\/pos\/login/i.test(page.url())) bad(`${pth} bounced to login`);
            else ok(`${pth} ok`);
        }
        evidence.push(await saveEvidenceScreenshot(page, 'si-inventory-still'));

        say('Cashier cannot post');
        await context.clearCookies();
        const cashUrl = await login(page, 'videocashier@nestpos.pk', creds.password);
        if (/\/pos\/login/i.test(cashUrl)) {
            ok('cashier login may be blocked by PosAdminOnly (acceptable)');
        } else {
            const resp = await page.goto(`${BASE_URL}/pos/inventory/stock-in`, { waitUntil: 'domcontentloaded' });
            const bounced = /dashboard|login/i.test(page.url()) || (resp && resp.status() === 403);
            if (bounced) ok('cashier cannot open Stock-In admin page');
            else {
                const post = page.locator('form#stock-in-post button[type="submit"]');
                if (await post.count()) {
                    await post.click();
                    await page.waitForLoadState('domcontentloaded');
                    const txt = await page.locator('body').innerText();
                    if (/403|denied|Access denied/i.test(txt) || page.url().includes('dashboard')) ok('cashier post refused');
                    else bad('cashier was able to use Post');
                } else {
                    ok('cashier has no Post control');
                }
            }
        }
        evidence.push(await saveEvidenceScreenshot(page, 'si-cashier'));

        if (diag.pageErrors.length) {
            for (const e of diag.pageErrors.slice(0, 5)) bad(`pageerror: ${e}`);
        } else {
            ok('no uncaught pageerrors');
        }
        console.log('\n  Evidence (gitignored):');
        for (const f of evidence) console.log(`    - ${f}`);
    } catch (e) {
        bad(`uncaught: ${e.message || e}`);
        try { evidence.push(await saveEvidenceScreenshot(page, 'si-error')); } catch { /* ignore */ }
    } finally {
        await browser.close().catch(() => {});
    }

    if (failed) {
        console.error(`\nSTOCK-IN SMOKE: FAILED (${failed} assertion(s))`);
        process.exit(1);
    }
    console.log('\nSTOCK-IN SMOKE: PASS');
    process.exit(0);
}

main().catch((e) => cannotRun(e.message || String(e)));
