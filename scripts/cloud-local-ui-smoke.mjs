#!/usr/bin/env node
/**
 * Cloud Agent / local NestPOS UI smoke — real Chrome against loopback only.
 *
 * WHAT IT ASSERTS (smallest meaningful NestPOS PRA path):
 *   1. /pos/login renders
 *   2. fictional local demo shop can sign in (videodemo@nestpos.pk)
 *   3. dashboard loads after login
 *   4. New Sale / universal invoice screen renders data-tn-sale-root
 *   5. no hard page errors; console/network failures are reported
 *
 * FAIL-CLOSED SAFETY:
 *   - BASE_URL must be 127.0.0.1 / localhost / ::1 (see scripts/lib/local-browser.mjs)
 *   - Refuses production hosts and live QA login identities
 *   - Credentials from env / .local/qa-creds.env only (never hardcoded secrets)
 *
 * Prerequisites:
 *   bash scripts/cloud-dev-bootstrap.sh
 *   bash scripts/cloud-local-qa-seed.sh
 *   php artisan serve --host=127.0.0.1 --port=8000
 *
 * Usage:
 *   BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-ui-smoke.mjs
 *   CLOUD_LOCAL_QA_HEADED=1 node scripts/cloud-local-ui-smoke.mjs
 *
 * Exit: 0 pass, 1 FAIL (UI regression), 2 could not run (env/server/chrome)
 */

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
        console.error(`\nCLOUD LOCAL UI SMOKE: refused — ${e.message}`);
        process.exit(2);
    }
})();

let failed = 0;
const ok = (m) => console.log(`    OK: ${m}`);
const bad = (m) => { failed++; console.error(`    FAIL: ${m}`); };
const say = (m) => console.log(`\n  ${m}`);
const cannotRun = (m) => { console.error(`\nCLOUD LOCAL UI SMOKE: could not run — ${m}`); process.exit(2); };

async function main() {
    let creds;
    try {
        creds = loadLocalQaCreds();
    } catch (e) {
        cannotRun(e.message);
    }

    say(`Target ${BASE_URL} (loopback-only)`);
    say(`Login ${creds.login} (source=${creds.source})`);

    let browser;
    let page;
    let diag;
    try {
        const launched = await launchLocalBrowser();
        say(`Chrome ${launched.executablePath} (headless=${launched.headless})`);
        browser = launched.browser;
        const context = await browser.newContext({
            viewport: { width: 1280, height: 800 },
            ignoreHTTPSErrors: true,
        });
        page = await context.newPage();
        diag = attachDiagnostics(page);
    } catch (e) {
        cannotRun(e.message || String(e));
    }

    const evidence = [];
    try {
        // 1) Login page
        say('Open /pos/login');
        const loginResp = await page.goto(`${BASE_URL}/pos/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        if (!loginResp || loginResp.status() >= 500) {
            cannotRun(`/pos/login HTTP ${loginResp?.status()}`);
        }
        await page.waitForSelector('input#login, input[name="login"]', { timeout: 15000 });
        ok('login form visible');
        evidence.push(await saveEvidenceScreenshot(page, '01-login'));

        // 2) Authenticate
        say('Submit NestPOS login');
        // Language switcher pills are also button[type=submit] — must not click those.
        // Prefer pressing Enter in the password field (same pattern as pos-caller-dial-check.mjs).
        await page.fill('input[name="login"]', creds.login);
        await page.fill('input[name="password"]', creds.password);
        await Promise.all([
            page.waitForURL((u) => !String(u.pathname || '').endsWith('/pos/login'), { timeout: 30000 }).catch(() => null),
            page.locator('input[name="password"]').press('Enter'),
        ]);

        const afterLogin = page.url();
        if (/\/pos\/login/i.test(afterLogin)) {
            const errText = await page.locator('.text-red-600, .text-red-500, [role="alert"], .invalid-feedback').first().textContent().catch(() => '');
            bad(`still on login after submit (${afterLogin})${errText ? ` — ${errText.trim()}` : ''}`);
            evidence.push(await saveEvidenceScreenshot(page, '02-login-failed'));
        } else {
            ok(`authenticated → ${afterLogin.replace(BASE_URL, '')}`);
            evidence.push(await saveEvidenceScreenshot(page, '02-after-login'));
        }

        // 3) Dashboard (or land on sale — both OK after login)
        say('Reach dashboard / NestPOS home');
        if (!/\/pos\//i.test(page.url())) {
            bad(`unexpected post-login URL ${page.url()}`);
        } else {
            ok(`still in /pos/* (${page.url().replace(BASE_URL, '')})`);
        }

        // Prefer visiting dashboard explicitly for a stable marker.
        const dashResp = await page.goto(`${BASE_URL}/pos/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        if (!dashResp || dashResp.status() >= 500) {
            bad(`/pos/dashboard HTTP ${dashResp?.status()}`);
        } else if (/\/pos\/login/i.test(page.url())) {
            bad('dashboard redirected back to login (session not established)');
        } else {
            // Dashboard has Open POS / New Sale CTA (route pos.v2.invoice.create)
            const openPos = page.locator('a[href*="/pos/v2/invoice/create"], a[href*="invoice/create"]').first();
            const hasCta = await openPos.count().then((n) => n > 0);
            check(hasCta || /dashboard/i.test(await page.title()), 'dashboard rendered', `title=${await page.title()}`);
            evidence.push(await saveEvidenceScreenshot(page, '03-dashboard'));
        }

        // 4) Universal sale screen
        say('Open New Sale (universal invoice)');
        const saleResp = await page.goto(`${BASE_URL}/pos/v2/invoice/create`, { waitUntil: 'domcontentloaded', timeout: 45000 });
        if (!saleResp || saleResp.status() >= 500) {
            bad(`/pos/v2/invoice/create HTTP ${saleResp?.status()}`);
        } else if (/\/pos\/login/i.test(page.url())) {
            bad('sale screen redirected to login');
        } else {
            // Wait for Alpine root used by NestPOS PRA sale screen
            try {
                await page.waitForSelector('[data-tn-sale-root]', { timeout: 20000 });
                ok('sale screen data-tn-sale-root present');
            } catch {
                // Some companies may land on create-invoice.blade.php instead of universal —
                // accept either a known sale marker.
                const alt = await page.locator('[x-data*="pos"], form#invoiceForm, [data-tn-sale-document]').count();
                check(alt > 0, 'sale screen marker present (fallback)', `url=${page.url()}`);
            }
            evidence.push(await saveEvidenceScreenshot(page, '04-sale'));
        }

        // 5) Diagnostics (soft-fail on noisy third-party console; hard-fail on pageerrors)
        say(`Browser diagnostics (${diag.summary()})`);
        if (diag.pageErrors.length) {
            for (const e of diag.pageErrors.slice(0, 5)) bad(`pageerror: ${e}`);
        } else {
            ok('no uncaught pageerrors');
        }
        // Console errors: report but only fail on clearly app-breaking ones
        const seriousConsole = diag.consoleErrors.filter((t) =>
            /TypeError|ReferenceError|Alpine|Uncaught/i.test(t) && !/favicon|chrome-extension/i.test(t)
        );
        if (seriousConsole.length) {
            for (const e of seriousConsole.slice(0, 5)) bad(`console: ${e}`);
        } else {
            ok(`console clean enough (${diag.consoleErrors.length} total error-level messages)`);
        }
        if (diag.failedRequests.length) {
            console.log(`    note: ${diag.failedRequests.length} failed request(s) (first: ${diag.failedRequests[0]})`);
        }

        console.log('\n  Evidence (gitignored):');
        for (const f of evidence) console.log(`    - ${f}`);
    } catch (e) {
        bad(`uncaught: ${e.message || e}`);
        try { evidence.push(await saveEvidenceScreenshot(page, '99-error')); } catch { /* ignore */ }
    } finally {
        await browser?.close().catch(() => {});
    }

    if (failed) {
        console.error(`\nCLOUD LOCAL UI SMOKE: FAILED (${failed} assertion(s))`);
        process.exit(1);
    }
    console.log('\nCLOUD LOCAL UI SMOKE: PASS');
    process.exit(0);
}

function check(cond, m, detail) {
    return cond ? ok(m) : bad(`${m}${detail ? ` — ${detail}` : ''}`);
}

main().catch((e) => cannotRun(e.message || String(e)));
