#!/usr/bin/env node
/**
 * Chromium acceptance: Admin → Manage as Company → Hotel Front Desk + Outlet.
 *
 * Desktop 1280×800 and mobile 390×844. Captures exact console/network failures
 * with URL + resource type (not just bare "403 Forbidden" text).
 *
 * Prerequisites:
 *   VIDEO_PIPELINE_ALLOW=1 php scripts/cloud-local-hotel-admin-qa-seed.php
 *   php artisan serve --host=127.0.0.1 --port=8000
 *
 *   BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-hotel-manage-as-smoke.mjs
 */

import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import {
    ROOT,
    assertLocalOnlyBaseUrl,
    launchLocalBrowser,
    parseEnvFile,
    saveEvidenceScreenshot,
} from './lib/local-browser.mjs';

const BASE_URL = (() => {
    try {
        return assertLocalOnlyBaseUrl(process.env.BASE_URL || 'http://127.0.0.1:8000');
    } catch (e) {
        console.error(`\nMANAGE-AS SMOKE: refused — ${e.message}`);
        process.exit(2);
    }
})();

let failed = 0;
const ok = (m) => console.log(`    OK: ${m}`);
const bad = (m) => { failed++; console.error(`    FAIL: ${m}`); };
const say = (m) => console.log(`\n  ${m}`);
const cannotRun = (m) => { console.error(`\nMANAGE-AS SMOKE: could not run — ${m}`); process.exit(2); };

function loadCreds() {
    const file = path.join(ROOT, '.local/hotel-admin-qa-creds.env');
    const fromFile = parseEnvFile(file);
    const adminLogin = process.env.HOTEL_ADMIN_QA_LOGIN || fromFile.HOTEL_ADMIN_QA_LOGIN || '';
    const adminPass = process.env.HOTEL_ADMIN_QA_PASS || fromFile.HOTEL_ADMIN_QA_PASS || '';
    const companyId = process.env.HOTEL_QA_COMPANY_ID || fromFile.HOTEL_QA_COMPANY_ID || '';
    if (!adminLogin || !adminPass || !companyId) {
        throw new Error('Missing admin QA creds — run: VIDEO_PIPELINE_ALLOW=1 php scripts/cloud-local-hotel-admin-qa-seed.php');
    }
    if (/taxnest\.com\.pk|taxnest\.pk/i.test(adminLogin)) {
        throw new Error(`REFUSED: admin login looks like production (${adminLogin})`);
    }
    return { adminLogin, adminPass, companyId, source: existsSync(file) ? '.local/hotel-admin-qa-creds.env' : 'env' };
}

/** Richer diagnostics: console errors include location URL; HTTP ≥400 responses listed. */
function attachRichDiagnostics(page) {
    const consoleErrors = [];
    const pageErrors = [];
    const failedRequests = [];
    const httpErrors = [];

    page.on('console', (msg) => {
        if (msg.type() !== 'error') return;
        const loc = msg.location();
        const locStr = loc?.url ? `${loc.url}${loc.lineNumber != null ? ':' + loc.lineNumber : ''}` : '';
        consoleErrors.push({
            text: msg.text(),
            location: locStr,
            args: msg.args()?.length || 0,
        });
    });
    page.on('pageerror', (err) => {
        pageErrors.push(String(err?.message || err));
    });
    page.on('requestfailed', (req) => {
        const failure = req.failure();
        const errText = failure?.errorText || '';
        if (/NS_BINDING_ABORTED|net::ERR_ABORTED/i.test(errText)) return;
        failedRequests.push({
            method: req.method(),
            url: req.url(),
            resourceType: req.resourceType(),
            errorText: errText,
        });
    });
    page.on('response', (res) => {
        const status = res.status();
        if (status < 400) return;
        const req = res.request();
        // Ignore expected auth probes / favicon noise.
        const url = res.url();
        if (/\/favicon\.ico($|\?)/i.test(url)) return;
        httpErrors.push({
            status,
            method: req.method(),
            url,
            resourceType: req.resourceType(),
            fromPage: page.url(),
        });
    });

    return {
        consoleErrors,
        pageErrors,
        failedRequests,
        httpErrors,
        summary() {
            const parts = [];
            if (pageErrors.length) parts.push(`pageerrors=${pageErrors.length}`);
            if (consoleErrors.length) parts.push(`console_errors=${consoleErrors.length}`);
            if (failedRequests.length) parts.push(`failed_requests=${failedRequests.length}`);
            if (httpErrors.length) parts.push(`http_errors=${httpErrors.length}`);
            return parts.length ? parts.join(', ') : 'no browser errors collected';
        },
        /** PR acceptance: ignore known pre-existing 403 noise that are not Hotel chrome. */
        prCausedConsoleErrors() {
            return consoleErrors.filter((e) => {
                const t = e.text || '';
                // Bare Chrome resource failures are correlated via httpErrors.
                if (/Failed to load resource:.*403/i.test(t)) return false;
                return true;
            });
        },
        prCausedHttpErrors() {
            return httpErrors.filter((e) => {
                const u = e.url || '';
                // Pre-existing / unrelated: survey, push, madadgar probes, PWA, etc.
                // Keep Hotel / outlet / manage-as path failures as PR-caused.
                if (/\/pos\/survey|\/pos\/push|\/pos\/madadgar|\/sw\.js|\/manifest|browserconfig/i.test(u)) {
                    return false;
                }
                if (/\/pos\/whats-new|\/pos\/feature-suggestions|\/pos\/apk/i.test(u)) {
                    return false;
                }
                return true;
            });
        },
    };
}

async function dismissNotices(page) {
    for (const re of [/Samajh Gaya|Got it/i, /Baad Mein|Later/i, /Jawab Bhejein/i]) {
        const btn = page.getByRole('button', { name: re }).first();
        if (await btn.isVisible().catch(() => false)) {
            await btn.click({ timeout: 5000 }).catch(() => null);
            await page.waitForTimeout(200);
        }
    }
    await page.evaluate(() => {
        document.getElementById('tn-domain-move-notice')?.remove();
        document.querySelectorAll('[data-wn-featured], [x-data*="wnOpen"]').forEach((el) => el.remove());
        document.querySelectorAll('.fixed.inset-0').forEach((el) => {
            const t = el.innerText || '';
            if (/Naye Updates|AAP KI RAYE|Caller ID|Samajh Gaya|Baad Mein|whats.?new|Mashwara|Jawab Bhejein/i.test(t)) {
                el.remove();
            }
        });
    }).catch(() => {});
}

async function shot(page, name) {
    await dismissNotices(page);
    await saveEvidenceScreenshot(page, name);
}

function setRestaurantMode(on, companyId) {
    const php = `
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$c = App\\Models\\Company::find(${Number(companyId)});
if (!$c) { fwrite(STDERR, "missing company\\n"); exit(1); }
$c->forceFill(['restaurant_mode' => ${on ? 'true' : 'false'}])->save();
echo $c->fresh()->restaurant_mode ? '1' : '0';
`;
    const r = spawnSync('php', ['-r', php], { cwd: ROOT, encoding: 'utf8' });
    if (r.status !== 0) throw new Error(r.stderr || r.stdout || 'php failed');
    return String(r.stdout || '').trim();
}

async function adminLogin(page, login, password) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForSelector('input[name="email"], input[name="login"], input[type="email"]', { timeout: 15000 });
    const email = page.locator('input[name="email"], input[type="email"]').first();
    if (await email.count()) await email.fill(login);
    else await page.fill('input[name="login"]', login);
    const passwordInput = page.locator('input[name="password"]').first();
    await passwordInput.fill(password);
    // Admin Sign In uses hover transform animations that keep Playwright's
    // stability check failing — submit via Enter / requestSubmit instead.
    await Promise.all([
        page.waitForURL((u) => !String(u).includes('/admin/login'), { timeout: 30000 }).catch(() => null),
        passwordInput.press('Enter').catch(async () => {
            await page.evaluate(() => {
                const form = document.querySelector('form[action*="/admin/login"]');
                if (form) form.requestSubmit();
                else document.querySelector('button[type="submit"]')?.click();
            });
        }),
    ]);
    if (page.url().includes('/admin/login')) {
        await page.locator('button[type="submit"]').first().click({ force: true });
        await page.waitForURL((u) => !String(u).includes('/admin/login'), { timeout: 30000 }).catch(() => null);
    }
    return page.url();
}

async function manageAsCompany(page, companyId) {
    await page.goto(`${BASE_URL}/admin/companies/${companyId}`, { waitUntil: 'networkidle', timeout: 45000 }).catch(async () => {
        await page.goto(`${BASE_URL}/admin/companies/${companyId}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    });
    await dismissNotices(page);
    await page.waitForSelector(`form[action*="/companies/${companyId}/impersonate"]`, { timeout: 15000 });

    // Native confirm() + animated buttons are flaky on narrow viewports.
    // Accept dialogs and submit the full-access form via requestSubmit.
    page.on('dialog', (d) => d.accept().catch(() => {}));
    const submitted = await page.evaluate((id) => {
        const forms = Array.from(document.querySelectorAll(`form[action*="/companies/${id}/impersonate"]`));
        const full = forms.find((f) => f.querySelector('input[name="mode"][value="full"]')) || forms[0];
        if (!full) return false;
        // Bypass onsubmit confirm handlers — dialog listener still covers native confirm.
        full.onsubmit = null;
        full.requestSubmit();
        return true;
    }, String(companyId));
    if (!submitted) throw new Error('Manage as Company form missing');
    await page.waitForURL(/\/pos\//, { timeout: 45000 }).catch(() => null);
    // Impersonate may land on /pos/dashboard then redirect to /pos/hotel.
    if (page.url().includes('/pos/dashboard')) {
        await page.goto(`${BASE_URL}/pos/dashboard`, { waitUntil: 'domcontentloaded' });
        await page.waitForURL(/\/pos\/hotel/, { timeout: 15000 }).catch(() => null);
    }
    return page.url();
}

async function runViewport(browser, label, viewport, creds) {
    say(`=== ${label} ${viewport.width}x${viewport.height} ===`);
    const context = await browser.newContext({ viewport, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const diag = attachRichDiagnostics(page);
    const prefix = `hotel-manage-as-${label}`;

    try {
        say('Admin login');
        const afterAdmin = await adminLogin(page, creds.adminLogin, creds.adminPass);
        if (afterAdmin.includes('/admin/login')) bad('admin login failed');
        else ok(`admin logged in → ${afterAdmin}`);
        await shot(page, `${prefix}-01-admin`);

        say('Manage as Company');
        const afterImp = await manageAsCompany(page, creds.companyId);
        await dismissNotices(page);
        if (afterImp.includes('/pos/hotel') || page.url().match(/\/pos\/hotel\/?$/)) {
            ok(`Manage as Company landed on Front Desk → ${page.url()}`);
        } else if (page.url().includes('/pos/dashboard')) {
            // Follow redirect chain if any middleware bounced.
            await page.goto(`${BASE_URL}/pos/dashboard`, { waitUntil: 'domcontentloaded' });
            await dismissNotices(page);
            if (page.url().match(/\/pos\/hotel\/?$/)) ok(`dashboard redirected to Front Desk → ${page.url()}`);
            else bad(`Manage as Company did not land on /pos/hotel (got ${page.url()})`);
        } else {
            bad(`Manage as Company unexpected landing ${page.url()}`);
        }
        await shot(page, `${prefix}-02-front-desk`);

        const body = await page.locator('body').innerText();
        if (/Front Desk|فرنٹ ڈیسک/i.test(body) && /data-hotel-native-nav|Reservations|Housekeeping|Folio/i.test(await page.content())) {
            ok('Hotel Front Desk chrome visible');
        } else if (await page.locator('[data-hotel-native-nav="1"]').count()) {
            ok('Hotel native nav marker present');
        } else {
            bad('Hotel Front Desk chrome missing');
        }
        if (await page.locator('[data-nav-new-sale="static"]').count()) {
            bad('generic New Sale still in Hotel primary nav');
        } else {
            ok('no intermixed New Sale in Hotel primary nav');
        }
        if (await page.locator('[data-hotel-restaurant-outlet="1"]').count()) {
            ok('Restaurant Outlet entry present (restaurant_mode ON)');
        } else {
            bad('Restaurant Outlet entry missing');
        }

        say('Restaurant Outlet');
        await page.goto(`${BASE_URL}/pos/hotel/restaurant`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await dismissNotices(page);
        await page.waitForSelector('[data-hotel-back-front-desk="1"], [data-tn-sale-root]', { timeout: 30000 }).catch(() => null);
        await page.waitForTimeout(1500);
        await dismissNotices(page);
        if (page.url().includes('/pos/invoice/create') || page.url().includes('/pos/v2/invoice/create')) {
            ok(`outlet opened canonical sale → ${page.url()}`);
        } else {
            bad(`outlet did not open sale (${page.url()})`);
        }
        if (await page.locator('[data-hotel-back-front-desk="1"]').count()) {
            ok('Back to Front Desk banner visible');
        } else {
            bad('Back to Front Desk banner missing');
        }
        await shot(page, `${prefix}-03-outlet`);

        await page.goto(`${BASE_URL}/pos/hotel/restaurant/exit`, { waitUntil: 'domcontentloaded' });
        await dismissNotices(page);
        if (page.url().match(/\/pos\/hotel\/?$/)) ok('Back to Front Desk returned to Hotel');
        else bad(`exit landed on ${page.url()}`);
        await shot(page, `${prefix}-04-back-desk`);

        say('restaurant_mode OFF denial');
        const off = setRestaurantMode(false, creds.companyId);
        ok(`restaurant_mode forced OFF (${off})`);
        // Fresh session still impersonating — hit hotel then check menu + sale.
        await page.goto(`${BASE_URL}/pos/hotel`, { waitUntil: 'domcontentloaded' });
        await dismissNotices(page);
        if (await page.locator('[data-hotel-restaurant-outlet="1"]').count()) {
            bad('Restaurant Outlet still in menu while mode OFF');
        } else {
            ok('Restaurant Outlet absent from menu while mode OFF');
        }
        await page.evaluate(async () => {
            if (!('serviceWorker' in navigator)) return;
            const regs = await navigator.serviceWorker.getRegistrations();
            await Promise.all(regs.map((r) => r.unregister()));
            if (window.caches) {
                const keys = await caches.keys();
                await Promise.all(keys.map((k) => caches.delete(k)));
            }
        }).catch(() => {});
        await page.goto(`${BASE_URL}/pos/hotel/restaurant`, { waitUntil: 'domcontentloaded' });
        if (page.url().includes('/pos/invoice/create')) bad('outlet opened sale while mode OFF');
        else ok(`outlet denied → ${page.url()}`);
        await page.goto(`${BASE_URL}/pos/invoice/create?off=${Date.now()}`, { waitUntil: 'domcontentloaded' });
        if (page.url().includes('/pos/invoice/create') && !page.url().includes('/pos/hotel')) {
            bad(`direct sale still open while mode OFF (${page.url()})`);
        } else {
            ok(`direct sale denied → ${page.url()}`);
        }
        await shot(page, `${prefix}-05-mode-off`);
        const restored = setRestaurantMode(true, creds.companyId);
        ok(`restaurant_mode restored ON (${restored})`);

        // Diagnostics gate
        const prConsole = diag.prCausedConsoleErrors();
        const prHttp = diag.prCausedHttpErrors();
        say(`Diagnostics: ${diag.summary()}`);
        for (const e of diag.consoleErrors) {
            console.log(`    console: ${e.text}${e.location ? ' @ ' + e.location : ''}`);
        }
        for (const e of diag.httpErrors) {
            console.log(`    http ${e.status} ${e.method} ${e.resourceType} ${e.url} (from ${e.fromPage})`);
        }
        for (const e of diag.failedRequests) {
            console.log(`    requestfailed ${e.method} ${e.resourceType} ${e.url} — ${e.errorText}`);
        }
        if (prConsole.length) bad(`PR-caused console errors: ${prConsole.map((e) => e.text).join(' | ')}`);
        else ok('no PR-caused console errors');
        if (prHttp.length) bad(`PR-caused HTTP errors: ${prHttp.map((e) => e.status + ' ' + e.url).join(' | ')}`);
        else ok('no PR-caused failed network responses');
        if (diag.pageErrors.length) bad(`pageerrors: ${diag.pageErrors.join(' | ')}`);
        else ok('no pageerrors');

        const dir = path.join(ROOT, '.local/browser-evidence');
        mkdirSync(dir, { recursive: true });
        writeFileSync(path.join(dir, `${prefix}-diagnostics.json`), JSON.stringify({
            time: new Date().toISOString(),
            summary: diag.summary(),
            consoleErrors: diag.consoleErrors,
            pageErrors: diag.pageErrors,
            failedRequests: diag.failedRequests,
            httpErrors: diag.httpErrors,
            prCausedConsoleErrors: prConsole,
            prCausedHttpErrors: prHttp,
            finalUrl: page.url(),
        }, null, 2));
    } catch (e) {
        bad(`${label} exception: ${e.message || e}`);
        await shot(page, `${prefix}-99-error`).catch(() => null);
    } finally {
        try { setRestaurantMode(true, creds.companyId); } catch { /* best effort */ }
        await context.close().catch(() => null);
    }
}

async function main() {
    let creds;
    try {
        creds = loadCreds();
    } catch (e) {
        cannotRun(e.message);
    }
    say(`Target ${BASE_URL}`);
    say(`Admin ${creds.adminLogin} company_id=${creds.companyId} (source=${creds.source})`);

    let browser;
    try {
        const launched = await launchLocalBrowser();
        say(`Chrome ${launched.executablePath} (headless=${launched.headless})`);
        browser = launched.browser;
    } catch (e) {
        cannotRun(e.message || String(e));
    }

    try {
        await runViewport(browser, 'desktop', { width: 1280, height: 800 }, creds);
        await runViewport(browser, 'mobile', { width: 390, height: 844 }, creds);
    } finally {
        await browser.close().catch(() => null);
    }

    if (failed) {
        console.error(`\nMANAGE-AS SMOKE: ${failed} failure(s)`);
        process.exit(1);
    }
    console.log('\nMANAGE-AS SMOKE: PASS');
    process.exit(0);
}

main().catch((e) => {
    console.error(e);
    process.exit(2);
});
