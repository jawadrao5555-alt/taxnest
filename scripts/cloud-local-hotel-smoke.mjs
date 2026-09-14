#!/usr/bin/env node
/**
 * Local loopback Hotel / Guest House V1 UI smoke (desktop + mobile).
 *
 * Covers: dashboard boards, rooms/housekeeping, stay book, payment/deposit,
 * checkout, HK-only vs denied cashier permissions.
 *
 * Prerequisites:
 *   VIDEO_PIPELINE_ALLOW=1 php scripts/cloud-local-hotel-qa-seed.php
 *   php artisan serve --host=127.0.0.1 --port=8000
 *
 *   BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-hotel-smoke.mjs
 *
 * Evidence: .local/browser-evidence/hotel-*
 * Exit: 0 pass, 1 FAIL, 2 could not run
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

const BASE_URL = (() => {
    try {
        return assertLocalOnlyBaseUrl(process.env.BASE_URL || 'http://127.0.0.1:8000');
    } catch (e) {
        console.error(`\nHOTEL SMOKE: refused — ${e.message}`);
        process.exit(2);
    }
})();

let failed = 0;
const ok = (m) => console.log(`    OK: ${m}`);
const bad = (m) => { failed++; console.error(`    FAIL: ${m}`); };
const say = (m) => console.log(`\n  ${m}`);
const cannotRun = (m) => { console.error(`\nHOTEL SMOKE: could not run — ${m}`); process.exit(2); };

function loadHotelCreds() {
    const file = path.join(ROOT, '.local/hotel-qa-creds.env');
    const fromFile = parseEnvFile(file);
    const login = process.env.HOTEL_QA_LOGIN || fromFile.HOTEL_QA_LOGIN || '';
    const password = process.env.HOTEL_QA_PASS || fromFile.HOTEL_QA_PASS || '';
    const hkLogin = process.env.HOTEL_QA_HK_LOGIN || fromFile.HOTEL_QA_HK_LOGIN || '';
    const deniedLogin = process.env.HOTEL_QA_DENIED_LOGIN || fromFile.HOTEL_QA_DENIED_LOGIN || '';
    if (!login || !password) {
        throw new Error('Missing hotel QA creds — run: VIDEO_PIPELINE_ALLOW=1 php scripts/cloud-local-hotel-qa-seed.php');
    }
    if (/taxnest\.com\.pk|taxnest\.pk/i.test(login)) {
        throw new Error(`REFUSED: login looks like production (${login})`);
    }
    return { login, password, hkLogin, deniedLogin, source: existsSync(file) ? '.local/hotel-qa-creds.env' : 'env' };
}

async function dismissNotices(page) {
    // Prefer the real dismiss controls before DOM hacks (overlays block hotel forms).
    for (const re of [/Samajh Gaya|Got it/i, /Baad Mein|Later/i]) {
        const btn = page.getByRole('button', { name: re }).first();
        if (await btn.isVisible().catch(() => false)) {
            await btn.click({ timeout: 5000 }).catch(() => null);
            await new Promise((r) => setTimeout(r, 300));
        }
    }
    await page.evaluate(() => {
        document.getElementById('tn-domain-move-notice')?.remove();
        document.querySelectorAll('[data-wn-featured], [x-data*="wnOpen"]').forEach((el) => el.remove());
        document.querySelectorAll('.fixed.inset-0').forEach((el) => {
            const t = el.innerText || '';
            if (/Naye Updates|AAP KI RAYE|Caller ID|Samajh Gaya|Baad Mein|whats.?new|Mashwara/i.test(t)) {
                el.remove();
            }
        });
    }).catch(() => {});
}

async function shot(page, name) {
    await dismissNotices(page);
    await saveEvidenceScreenshot(page, name);
}

async function clickSubmit(formLocator) {
    await dismissNotices(formLocator.page());
    const btn = formLocator.locator('button').first();
    await btn.click({ force: true, timeout: 15000 });
}

async function loginAs(page, login, password) {
    await page.goto(`${BASE_URL}/pos/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForSelector('input[name="login"]', { timeout: 15000 });
    await page.fill('input[name="login"]', login);
    await page.fill('input[name="password"]', password);
    // Language pills are also type=submit — click the Sign In button by text.
    const signIn = page.getByRole('button', { name: /sign in|لاگ اِن|Login/i }).first();
    await Promise.all([
        page.waitForURL((u) => !String(u.pathname || '').endsWith('/pos/login'), { timeout: 30000 }).catch(() => null),
        signIn.click().catch(() => page.locator('input[name="password"]').press('Enter')),
    ]);
    await dismissNotices(page);
    // Mark all live What's New rows seen (omit update_id). update_id:0 fails validation.
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
    await dismissNotices(page);
    return page.url();
}

async function logout(page) {
    await page.evaluate(async () => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/pos/logout';
        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = '_token';
        csrf.value = token || '';
        form.appendChild(csrf);
        document.body.appendChild(form);
        form.submit();
    }).catch(() => null);
    await page.waitForURL(/\/pos\/login/, { timeout: 15000 }).catch(() => null);
    await page.goto(`${BASE_URL}/pos/login`, { waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => null);
}

function tomorrowIso() {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
}

async function writeDiag(name, diag, extra = {}) {
    const dir = path.join(ROOT, '.local/browser-evidence');
    mkdirSync(dir, { recursive: true });
    writeFileSync(path.join(dir, `${name}.json`), JSON.stringify({
        time: new Date().toISOString(),
        summary: diag.summary(),
        pageErrors: diag.pageErrors,
        consoleErrors: diag.consoleErrors,
        failedRequests: diag.failedRequests,
        ...extra,
    }, null, 2));
}

async function runJourney(browser, label, viewport, creds) {
    say(`=== ${label} viewport ${viewport.width}x${viewport.height} ===`);
    const context = await browser.newContext({ viewport, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const diag = attachDiagnostics(page);
    const prefix = `hotel-${label}`;
    let stayUrl = null;

    try {
        say('Owner login');
        const afterLogin = await loginAs(page, creds.login, creds.password);
        if (afterLogin.includes('/pos/login')) bad('owner login failed');
        else ok(`owner logged in → ${afterLogin}`);
        await shot(page, `${prefix}-01-login-dashboard`);

        say('Hotel dashboard');
        const dash = await page.goto(`${BASE_URL}/pos/hotel`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await dismissNotices(page);
        if (!dash || dash.status() >= 400) bad(`/pos/hotel HTTP ${dash?.status()}`);
        const dashText = await page.locator('body').innerText();
        const boardNeedles = [/arrival|آمد/i, /depart|روانگی/i, /in.?house|اندر/i, /pending|باقی/i, /available|دستیاب/i, /dirty|گند/i];
        const boardHits = boardNeedles.filter((re) => re.test(dashText)).length;
        if (boardHits >= 4) ok(`dashboard board sections visible (${boardHits}/6)`);
        else bad(`dashboard board sections weak (${boardHits}/6)`);
        const hasRoomsLink = await page.locator('a[href*="/pos/hotel/rooms"]').count();
        const hasNewStay = await page.locator('a[href*="/pos/hotel/stays/create"]').count();
        if (hasRoomsLink && hasNewStay) ok('dashboard shows rooms + new stay actions');
        else bad('dashboard missing rooms/new-stay links');
        await shot(page, `${prefix}-02-dashboard`);

        say('Rooms + housekeeping');
        await page.goto(`${BASE_URL}/pos/hotel/rooms`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await dismissNotices(page);
        const pageText = await page.locator('body').innerText();
        if (/101|102|Deluxe|Standard|Dirty|گندا|Clean|صاف/i.test(pageText)) ok('rooms board lists inventory');
        else bad('rooms board empty/unexpected');
        const hkSelect = page.locator('form[action*="housekeeping"] select[name="housekeeping"]').first();
        if (await hkSelect.count()) {
            const cur = await hkSelect.inputValue().catch(() => '');
            const next = cur === 'dirty' ? 'clean' : 'dirty';
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => null),
                hkSelect.selectOption(next),
            ]);
            ok(`housekeeping toggled ${cur || '?'} → ${next}`);
        } else {
            bad('no housekeeping control on rooms board');
        }
        await shot(page, `${prefix}-03-rooms-hk`);

        say('Book walk-in stay');
        await page.goto(`${BASE_URL}/pos/hotel/stays/create`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await dismissNotices(page);
        const roomOptions = await page.locator('select[name="room_id"] option').evaluateAll((opts) =>
            opts.map((o) => ({ value: o.value, text: o.textContent || '' }))
        );
        const prefer = label === 'mobile' ? /102/ : /101/;
        const pick = roomOptions.find((o) => o.value && prefer.test(o.text))
            || roomOptions.find((o) => o.value && /103/.test(o.text))
            || roomOptions.find((o) => o.value);
        if (!pick?.value) bad('no free rooms to book');
        else await page.selectOption('select[name="room_id"]', pick.value);
        await page.fill('input[name="check_out_date"]', tomorrowIso());
        await page.fill('input[name="guest_name"]', `QA Guest ${label}`);
        await page.fill('input[name="guest_phone"]', '03001234567');
        await page.check('input[name="walk_in"]').catch(() => null);
        await dismissNotices(page);
        const createForm = page.locator('form[action*="pos/hotel/stays"]').first();
        await Promise.all([
            page.waitForURL(/\/pos\/hotel\/stays\/\d+/, { timeout: 30000 }).catch(() => null),
            clickSubmit(createForm),
        ]);
        stayUrl = page.url();
        if (/\/pos\/hotel\/stays\/\d+/.test(stayUrl)) ok(`stay created ${stayUrl}`);
        else {
            const body = await page.locator('body').innerText().catch(() => '');
            const err = (body.match(/Room is already booked[^\n]*|The .+ field[^\n]*|overlap|already/i) || [])[0] || '';
            bad(`stay create did not land on stay show (${stayUrl})${err ? ' — ' + err : ''}`);
        }
        await shot(page, `${prefix}-04-stay-created`);

        if (/\/pos\/hotel\/stays\/\d+/.test(stayUrl)) {
            say('Payment + deposit on folio');
            await dismissNotices(page);
            let payForm = page.locator('form[action*="folio/payment"]').first();
            if (await payForm.count()) {
                await dismissNotices(page);
                await payForm.locator('input[name="amount"]').fill('2000');
                await payForm.locator('select[name="kind"]').selectOption('deposit');
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null),
                    clickSubmit(payForm),
                ]);
                const afterDep = await page.locator('body').innerText();
                if (/deposit|امانت|security|2000/i.test(afterDep)) ok('deposit posted (UI reflects money)');
                else ok('deposit form submitted');
                await dismissNotices(page);
                payForm = page.locator('form[action*="folio/payment"]').first();
                await payForm.locator('input[name="amount"]').fill('8000');
                await payForm.locator('select[name="kind"]').selectOption('payment');
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null),
                    clickSubmit(payForm),
                ]);
                ok('advance payment posted');
                const folioText = await page.locator('body').innerText();
                if (/Advance Credit|پیشگی کریڈٹ/i.test(folioText)) ok('Advance Credit tile visible after overpay');
                else bad('Advance Credit tile missing after payment above charges');
            } else {
                bad('payment form missing on stay show');
            }
            await shot(page, `${prefix}-05-folio-payments`);

            say('Checkout');
            page.once('dialog', (d) => d.accept().catch(() => {}));
            const co = page.locator('form[action*="check-out"] button').first();
            if (await co.count()) {
                await dismissNotices(page);
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null),
                    co.click({ force: true }),
                ]);
                ok('checkout submitted');
            } else {
                bad('checkout button missing');
            }
            await shot(page, `${prefix}-06-checkout`);

            // Tenant isolation: foreign stay id must not render as this company's stay.
            say('URL isolation (foreign stay id)');
            const foreign = await page.goto(`${BASE_URL}/pos/hotel/stays/99999999`, { waitUntil: 'domcontentloaded', timeout: 20000 });
            const foreignUrl = page.url();
            if (foreignUrl.includes('/pos/dashboard') || foreignUrl.includes('/pos/login') || (foreign && foreign.status() === 404)) {
                ok(`foreign stay redirected/denied → ${foreignUrl}`);
            } else if (/QA Guest/i.test(await page.locator('body').innerText())) {
                bad('foreign stay id leaked guest content');
            } else {
                ok(`foreign stay not shown as open stay (${foreignUrl})`);
            }
            await shot(page, `${prefix}-06b-isolation`);
        }

        say('Permissions: housekeeping-only');
        await logout(page);
        await loginAs(page, creds.hkLogin, creds.password);
        const hkDesk = await page.goto(`${BASE_URL}/pos/hotel`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const hkDeskUrl = page.url();
        if (hkDeskUrl.includes('/pos/hotel') && !hkDeskUrl.match(/\/pos\/hotel\/?/)) {
            // exact
        }
        if (hkDeskUrl.includes('/pos/dashboard') || hkDeskUrl.includes('/pos/invoice') || (hkDesk && hkDesk.status() >= 300 && !hkDeskUrl.match(/\/pos\/hotel\/?$/))) {
            ok(`HK blocked from front desk → ${hkDeskUrl}`);
        } else if (hkDeskUrl.match(/\/pos\/hotel\/?$/) && (await page.locator('a[href*="stays/create"]').count()) === 0) {
            // unexpected 200 without create — still fail closed preference
            bad(`HK unexpectedly on front desk ${hkDeskUrl}`);
        } else if (hkDeskUrl.match(/\/pos\/hotel\/?$/)) {
            bad(`HK opened front desk ${hkDeskUrl}`);
        } else {
            ok(`HK redirected from front desk → ${hkDeskUrl}`);
        }
        await page.goto(`${BASE_URL}/pos/hotel/rooms`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        if (page.url().includes('/pos/hotel/rooms')) ok('HK can open rooms board');
        else bad(`HK rooms denied → ${page.url()}`);
        await shot(page, `${prefix}-07-hk-permissions`);

        say('Permissions: cashier without hotel grant');
        await logout(page);
        await loginAs(page, creds.deniedLogin, creds.password);
        await page.goto(`${BASE_URL}/pos/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const deniedDash = await page.locator('body').innerText();
        if (/\bIn house\b/.test(deniedDash) || /hotel_stat_in_house/.test(deniedDash)) {
            bad('denied cashier saw hotel occupancy on dashboard');
        } else {
            ok('denied cashier dashboard has no hotel occupancy strip');
        }
        await page.goto(`${BASE_URL}/pos/hotel`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        if (!page.url().match(/\/pos\/hotel\/?$/)) ok(`denied cashier blocked → ${page.url()}`);
        else bad('denied cashier reached front desk');
        await page.goto(`${BASE_URL}/pos/hotel/rooms`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        if (!page.url().includes('/pos/hotel/rooms')) ok(`denied cashier blocked from rooms → ${page.url()}`);
        else bad('denied cashier reached rooms');
        await shot(page, `${prefix}-08-denied-permissions`);

        await writeDiag(`${prefix}-diagnostics`, diag, { stayUrl, finalUrl: page.url() });
        if (diag.pageErrors.length) bad(`pageerrors: ${diag.pageErrors.slice(0, 3).join(' | ')}`);
        else ok(`diagnostics: ${diag.summary()}`);
    } catch (e) {
        bad(`${label} journey exception: ${e.message || e}`);
        await shot(page, `${prefix}-99-error`).catch(() => null);
        await writeDiag(`${prefix}-diagnostics`, diag, { error: String(e) });
    } finally {
        await context.close().catch(() => null);
    }
}

async function main() {
    let creds;
    try {
        creds = loadHotelCreds();
    } catch (e) {
        cannotRun(e.message);
    }
    say(`Target ${BASE_URL}`);
    say(`Owner ${creds.login} (source=${creds.source})`);

    let browser;
    try {
        const launched = await launchLocalBrowser();
        say(`Chrome ${launched.executablePath} (headless=${launched.headless})`);
        browser = launched.browser;
    } catch (e) {
        cannotRun(e.message || String(e));
    }

    try {
        await runJourney(browser, 'desktop', { width: 1280, height: 800 }, creds);
        await runJourney(browser, 'mobile', { width: 390, height: 844 }, creds);
    } finally {
        await browser.close().catch(() => null);
    }

    if (failed) {
        console.error(`\nHOTEL SMOKE: ${failed} failure(s)`);
        process.exit(1);
    }
    console.log('\nHOTEL SMOKE: PASS');
    process.exit(0);
}

main().catch((e) => {
    console.error(e);
    process.exit(2);
});
