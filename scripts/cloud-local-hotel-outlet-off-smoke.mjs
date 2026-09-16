#!/usr/bin/env node
/**
 * Feature-OFF denial evidence for Hotel Restaurant Outlet (fictional QA only).
 * Temporarily clears restaurant_mode on the disposable Hotel QA company, proves
 * outlet + sale URLs redirect, then restores the saved ON value.
 */
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import {
    ROOT,
    assertLocalOnlyBaseUrl,
    attachDiagnostics,
    launchLocalBrowser,
    parseEnvFile,
    saveEvidenceScreenshot,
} from './lib/local-browser.mjs';

const BASE_URL = assertLocalOnlyBaseUrl(process.env.BASE_URL || 'http://127.0.0.1:8000');
const creds = parseEnvFile(path.join(ROOT, '.local/hotel-qa-creds.env'));
const login = creds.HOTEL_QA_LOGIN;
const password = creds.HOTEL_QA_PASS;
if (!login || !password) {
    console.error('Missing hotel QA creds');
    process.exit(2);
}

function setRestaurantMode(on) {
    const php = `
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$c = App\\Models\\Company::where('email', ${JSON.stringify(login)})->first();
if (!$c) { fwrite(STDERR, "missing company\\n"); exit(1); }
$c->forceFill(['restaurant_mode' => ${on ? 'true' : 'false'}])->save();
echo $c->fresh()->restaurant_mode ? '1' : '0';
`;
    const r = spawnSync('php', ['-r', php], { cwd: ROOT, encoding: 'utf8' });
    if (r.status !== 0) {
        throw new Error(r.stderr || r.stdout || 'php failed');
    }
    return String(r.stdout || '').trim();
}

const { browser } = await launchLocalBrowser();
const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, ignoreHTTPSErrors: true });
const page = await context.newPage();
const diag = attachDiagnostics(page);
let failed = 0;
try {
    const before = setRestaurantMode(false);
    console.log(`restaurant_mode now ${before} (expect 0)`);
    await page.goto(`${BASE_URL}/pos/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="login"]', login);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL((u) => !String(u).includes('/pos/login'), { timeout: 30000 }).catch(() => null),
        page.getByRole('button', { name: /sign in|لاگ اِن|Login/i }).first().click(),
    ]);
    await page.goto(`${BASE_URL}/pos/hotel/restaurant`, { waitUntil: 'domcontentloaded' });
    const outletUrl = page.url();
    const outletBody = await page.locator('body').innerText();
    if (outletUrl.includes('/pos/invoice/create')) {
        console.error('FAIL: outlet opened sale while restaurant_mode OFF');
        failed++;
    } else {
        console.log(`OK: outlet denied → ${outletUrl}`);
    }
    await saveEvidenceScreenshot(page, 'hotel-feature-off-outlet-denied');
    await page.goto(`${BASE_URL}/pos/invoice/create`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    // Bust NestPOS service-worker cache so a prior ON-mode sale HTML cannot mask the 302.
    await page.evaluate(async () => {
        if (!('serviceWorker' in navigator)) return;
        const regs = await navigator.serviceWorker.getRegistrations();
        await Promise.all(regs.map((r) => r.unregister()));
        if (window.caches) {
            const keys = await caches.keys();
            await Promise.all(keys.map((k) => caches.delete(k)));
        }
    }).catch(() => {});
    await page.goto(`${BASE_URL}/pos/invoice/create?hotel_outlet_off=${Date.now()}`, {
        waitUntil: 'domcontentloaded',
        timeout: 30000,
    });
    const saleUrl = page.url();
    if (saleUrl.includes('/pos/invoice/create') && !saleUrl.includes('/pos/hotel')) {
        console.error(`FAIL: direct sale still open while restaurant_mode OFF (${saleUrl})`);
        failed++;
    } else {
        console.log(`OK: direct sale denied → ${saleUrl}`);
    }
    await saveEvidenceScreenshot(page, 'hotel-feature-off-sale-denied');
    const after = setRestaurantMode(true);
    console.log(`restaurant_mode restored ${after} (expect 1)`);
    mkdirSync(path.join(ROOT, '.local/browser-evidence'), { recursive: true });
    writeFileSync(path.join(ROOT, '.local/browser-evidence/hotel-feature-off-diagnostics.json'), JSON.stringify({
        outletUrl, saleUrl: page.url(), diag: diag.summary(), failed,
    }, null, 2));
} finally {
    await context.close().catch(() => null);
    await browser.close().catch(() => null);
    try { setRestaurantMode(true); } catch { /* restore best-effort */ }
}
process.exit(failed ? 1 : 0);
