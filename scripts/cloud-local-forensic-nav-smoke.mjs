#!/usr/bin/env node
/**
 * Local Chrome forensic smoke for NestPOS nav + customer What's New titles.
 * Loopback only. Fictional QA tenant. Never production.
 *
 * Asserts:
 *   1. Dashboard still has one static New Sale (previously working)
 *   2. /pos/v2/invoice/create (dashboard Open POS) has ZERO static New Sale
 *      at desktop and with the mobile drawer open
 *   3. Sale screen still has its own newSale() action
 *   4. A local AppUpdate titled with [deploy {sha}] does not show the SHA
 */

import { writeFileSync } from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
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
        console.error(`\nFORENSIC NAV SMOKE: refused — ${e.message}`);
        process.exit(2);
    }
})();

const SHA = 'aac4820f29cfa0432ff39e0fb1b4e51f305edb36';
const TITLE = `Kitchen printing is more reliable [deploy ${SHA}]`;

let failed = 0;
const ok = (m) => console.log(`    OK: ${m}`);
const bad = (m) => { failed++; console.error(`    FAIL: ${m}`); };
const say = (m) => console.log(`\n  ${m}`);
const cannotRun = (m) => { console.error(`\nFORENSIC NAV SMOKE: could not run — ${m}`); process.exit(2); };

function seedLocalElaan() {
    const execute = `
        if ((string) config('database.connections.'.config('database.default').'.database') !== 'taxnest_dev') { fwrite(STDERR, "REFUSED: not taxnest_dev\\n"); exit(2); }
        $update = \\App\\Models\\AppUpdate::query()->updateOrCreate(
            ['title' => ${JSON.stringify(TITLE)}],
            [
                'points' => ['Shared kitchen printers stay on the saved station.'],
                'audience' => 'pos',
                'type' => 'improvement',
                'is_published' => true,
                'is_featured' => false,
            ]
        );
        echo $update->id;
    `;
    return String(execFileSync('php', ['artisan', 'tinker', '--execute', execute], { cwd: ROOT, timeout: 45000 }));
}

async function login(page, creds) {
    const loginResp = await page.goto(`${BASE_URL}/pos/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    if (!loginResp || loginResp.status() >= 500) cannotRun(`/pos/login HTTP ${loginResp?.status()}`);
    await page.waitForSelector('input[name="login"]', { timeout: 15000 });
    await page.fill('input[name="login"]', creds.login);
    await page.fill('input[name="password"]', creds.password);
    await Promise.all([
        page.waitForURL((u) => !String(u.pathname || '').endsWith('/pos/login'), { timeout: 30000 }).catch(() => null),
        page.locator('input[name="password"]').press('Enter'),
    ]);
    if (/\/pos\/login/i.test(page.url())) cannotRun('login failed for fictional local QA');
}

async function countVisible(page, selector) {
    return page.locator(selector).filter({ visible: true }).count();
}

async function dismissOverlays(page) {
    const html = await page.content();
    if (html.includes('Kitchen printing is more reliable') || html.includes('[deploy ')) {
        if (html.includes(SHA)) {
            bad('What\'s New markup contains deploy SHA');
        } else {
            ok('What\'s New markup has no deploy SHA');
        }
        if (html.includes('Kitchen printing is more reliable')) {
            ok('customer-facing title is present without provenance');
        }
    }
    await page.locator('[x-data*="wnOpen"] button').last().click({ force: true, timeout: 2000 }).catch(() => {});
    await page.locator('[data-pos-survey] button').last().click({ force: true, timeout: 2000 }).catch(() => {});
    await page.evaluate(() => {
        document.querySelectorAll('div.fixed.inset-0').forEach((el) => {
            el.style.display = 'none';
        });
    });
}

async function writeDiag(name, diag, extra = {}) {
    const file = path.join(ROOT, '.local/browser-evidence', `${name}.json`);
    writeFileSync(file, JSON.stringify({
        time: new Date().toISOString(),
        ...extra,
        pageErrors: diag.pageErrors,
        consoleErrors: diag.consoleErrors,
        failedRequests: diag.failedRequests,
    }, null, 2));
    return file;
}

async function main() {
    let creds;
    try { creds = loadLocalQaCreds(); } catch (e) { cannotRun(e.message); }

    let updateId = '';
    try {
        updateId = seedLocalElaan().trim();
        say(`Seeded local AppUpdate #${updateId}`);
    } catch (e) {
        cannotRun(`could not seed local AppUpdate: ${e.message || e}`);
    }

    let browser;
    const evidence = [];
    try {
        const launched = await launchLocalBrowser();
        browser = launched.browser;
        const desktop = await browser.newContext({ viewport: { width: 1400, height: 900 }, ignoreHTTPSErrors: true });
        const mobile = await browser.newContext({
            viewport: { width: 390, height: 844 },
            isMobile: true,
            hasTouch: true,
            ignoreHTTPSErrors: true,
        });
        const deskPage = await desktop.newPage();
        const mobPage = await mobile.newPage();
        const deskDiag = attachDiagnostics(deskPage);
        const mobDiag = attachDiagnostics(mobPage);

        await login(deskPage, creds);
        await login(mobPage, creds);

        say('Desktop dashboard — previously working static New Sale');
        await deskPage.goto(`${BASE_URL}/pos/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await dismissOverlays(deskPage);
        evidence.push(await saveEvidenceScreenshot(deskPage, 'forensic-desktop-whats-new'));
        const dashStatic = await countVisible(deskPage, '[data-nav-new-sale="static"]');
        dashStatic === 1 ? ok(`dashboard visible static New Sale count=${dashStatic}`) : bad(`dashboard visible static New Sale count=${dashStatic}`);
        evidence.push(await saveEvidenceScreenshot(deskPage, 'forensic-desktop-dashboard'));

        say('Desktop v2 sale screen must not keep the static New Sale');
        await deskPage.goto(`${BASE_URL}/pos/v2/invoice/create`, { waitUntil: 'domcontentloaded', timeout: 45000 });
        await deskPage.waitForSelector('[data-tn-sale-root], [x-data*="pos"]', { timeout: 20000 }).catch(() => null);
        await dismissOverlays(deskPage);
        const saleStatic = await countVisible(deskPage, '[data-nav-new-sale="static"]');
        saleStatic === 0 ? ok(`desktop v2 sale visible static New Sale count=${saleStatic}`) : bad(`desktop v2 sale visible static New Sale count=${saleStatic}`);
        const hasAction = await countVisible(deskPage, '[data-nav-new-sale="action"]');
        hasAction > 0 ? ok(`desktop sale action New Sale count=${hasAction}`) : bad('desktop sale action New Sale missing');
        const deskHtml = await deskPage.content();
        deskHtml.includes(SHA) ? bad('desktop sale HTML contains deploy SHA') : ok('desktop sale HTML has no deploy SHA');
        evidence.push(await saveEvidenceScreenshot(deskPage, 'forensic-desktop-sale'));
        evidence.push(await writeDiag('forensic-desktop-diag', deskDiag, { url: deskPage.url(), staticNewSale: saleStatic, actionNewSale: hasAction }));

        say('Mobile dashboard still has New Sale in the drawer');
        await mobPage.goto(`${BASE_URL}/pos/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await dismissOverlays(mobPage);
        const hamburger = mobPage.locator('header button.lg\\:hidden').first();
        if (await hamburger.count()) await hamburger.click({ force: true });
        await mobPage.evaluate(() => {
            const root = document.querySelector('[x-data*="mobileMenuOpen"]');
            if (root && window.Alpine) {
                window.Alpine.$data(root).mobileMenuOpen = true;
            }
        }).catch(() => {});
        await mobPage.waitForTimeout(400);
        const mobileDashStatic = await mobPage.locator('[data-nav-new-sale="static"]').count();
        mobileDashStatic >= 1 ? ok(`mobile dashboard static New Sale in DOM count=${mobileDashStatic}`) : bad(`mobile dashboard static New Sale in DOM count=${mobileDashStatic}`);
        evidence.push(await saveEvidenceScreenshot(mobPage, 'forensic-mobile-dashboard'));

        say('Mobile v2 sale screen drawer must not add a second New Sale');
        await mobPage.goto(`${BASE_URL}/pos/v2/invoice/create`, { waitUntil: 'domcontentloaded', timeout: 45000 });
        await mobPage.waitForSelector('[data-tn-sale-root], [x-data*="pos"]', { timeout: 20000 }).catch(() => null);
        await dismissOverlays(mobPage);
        const beforeOpen = await countVisible(mobPage, '[data-nav-new-sale="static"]');
        const menuBtn = mobPage.locator('header button.lg\\:hidden').first();
        if (await menuBtn.count()) await menuBtn.click({ force: true });
        const afterOpen = await countVisible(mobPage, '[data-nav-new-sale="static"]');
        beforeOpen === 0 && afterOpen === 0
            ? ok(`mobile v2 sale visible static New Sale closed=${beforeOpen} open=${afterOpen}`)
            : bad(`mobile v2 sale visible static New Sale closed=${beforeOpen} open=${afterOpen}`);
        const mobAction = await countVisible(mobPage, '[data-nav-new-sale="action"]');
        mobAction > 0 ? ok(`mobile sale action New Sale count=${mobAction}`) : bad('mobile sale action New Sale missing');
        const mobHtml = await mobPage.content();
        mobHtml.includes(SHA) ? bad('mobile sale HTML contains deploy SHA') : ok('mobile sale HTML has no deploy SHA');
        evidence.push(await saveEvidenceScreenshot(mobPage, 'forensic-mobile-sale'));
        evidence.push(await writeDiag('forensic-mobile-diag', mobDiag, { url: mobPage.url(), staticClosed: beforeOpen, staticOpen: afterOpen, actionNewSale: mobAction }));

        say(`Desktop diagnostics (${deskDiag.summary()})`);
        say(`Mobile diagnostics (${mobDiag.summary()})`);
        if (deskDiag.pageErrors.length || mobDiag.pageErrors.length) {
            for (const e of [...deskDiag.pageErrors, ...mobDiag.pageErrors].slice(0, 6)) bad(`pageerror: ${e}`);
        } else {
            ok('no uncaught pageerrors');
        }

        console.log('\n  Evidence (gitignored):');
        for (const f of evidence) console.log(`    - ${f}`);
    } catch (e) {
        bad(`uncaught: ${e.message || e}`);
    } finally {
        await browser?.close().catch(() => {});
    }

    if (failed) {
        console.error(`\nFORENSIC NAV SMOKE: FAILED (${failed} assertion(s))`);
        process.exit(1);
    }
    console.log('\nFORENSIC NAV SMOKE: PASS');
}

main().catch((e) => cannotRun(e.message || String(e)));
