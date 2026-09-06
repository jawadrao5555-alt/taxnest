#!/usr/bin/env node
/**
 * Browser-level check for the FBR Pharmacy Mode counter gaps: out-of-stock
 * alternatives + missed-sale capture on the FBR universal sale screen.
 *
 * WHY A REAL BROWSER: the endpoints (stock-check, missed-sales store) are
 * covered by tests/Feature/FbrPosPharmacyExpiryAndMissedSalesTest.php. The
 * half the cashier touches is TIMING inside resources/views/fbr-pos/
 * universal.blade.php: a barcode scanner's Enter, or a fast typist's Enter,
 * lands BEFORE the 60ms search debounce and the 150ms stock probe. If the
 * stock gate only reads what the probe already cached, a zero-stock strip is
 * billed exactly in the rapid workflows the feature exists for, while every
 * PHP test stays green. Every stock-check response is therefore DELAYED here
 * (600ms) so each assertion runs in the race the review flagged.
 *
 * WHAT IT ASSERTS:
 *   1. type + immediate Enter on a zero-stock brand: nothing is added, the
 *      alternatives panel opens once the stock is resolved, a second Enter
 *      while resolving does not double-add
 *   2. Enter inside the panel adds the same-salt alternative exactly once
 *   3. barcode scan (fast digits + Enter) of the zero-stock product: panel,
 *      not a cart row; "Phir bhi add karein" bills it once
 *   4. barcode scan of an in-stock product adds after its resolve
 *   5. already-cached zero stock: panel opens synchronously on Enter, no
 *      single-id resolve request is issued again
 *   6. OFFLINE: an unknown-stock product adds immediately (never blocked)
 *   7. a fast Enter on a typed name the shop DOES carry adds the product —
 *      it is never logged as a missed sale; a true no-match Enter IS logged
 *   8. keyboard rules intact: plain letters type into the empty search box;
 *      no uncaught JS errors
 *
 * Runs against the dev server + staging MySQL on the pharmacy demo shop.
 * Shop state is set up and torn down by scripts/fbr-pharmacy-counter-fixture.php.
 *
 * Usage:
 *   node scripts/fbr-pharmacy-counter-check.mjs
 *   BASE_URL=... POS_CHECK_LOGIN=... POS_CHECK_PASSWORD=... node scripts/...
 *
 * Exit codes: 0 = pass, 1 = FAIL, 2 = could not run (dev server / MySQL /
 * chromium / credentials missing) — deploy-live.sh treats 2 as a blocker too.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import pw from 'playwright-core';

const { chromium } = pw;
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BASE_URL = (process.env.BASE_URL || 'http://127.0.0.1:5000').replace(/\/+$/, '');
const cannotRun = (m) => { console.error(`\nPHARMACY COUNTER CHECK: could not run — ${m}`); process.exit(2); };

// ── credentials (env, else the untracked .local/qa-creds.env) — repo is PUBLIC ──
function creds() {
    const envFile = path.join(ROOT, '.local/qa-creds.env');
    const fromFile = {};
    if (existsSync(envFile)) {
        for (const line of readFileSync(envFile, 'utf8').split('\n')) {
            const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
            if (m) fromFile[m[1]] = m[2].replace(/^["']|["']$/g, '');
        }
    }
    return {
        login: process.env.POS_CHECK_LOGIN || 'pharmacydemo@nestpos.pk',
        password: process.env.POS_CHECK_PASSWORD || fromFile.VIDEO_DEMO_PASS || fromFile.DEV_POS_PASS || '',
    };
}

function chromiumPath() {
    const tries = [];
    if (process.env.CHROMIUM_BIN) tries.push(process.env.CHROMIUM_BIN);
    try { tries.push(chromium.executablePath()); } catch { /* not downloaded */ }
    for (const p of tries) if (p && existsSync(p)) return p;
    try {
        const hits = readdirSync('/nix/store').filter((d) => /-chromium-\d/.test(d))
            .map((d) => `/nix/store/${d}/bin/chromium`).filter((p) => existsSync(p)).sort();
        if (hits.length) return hits[hits.length - 1];
    } catch { /* ignore */ }
    return null;
}

const PG_VARS = ['DATABASE_URL', 'DB_CONNECTION', 'PGHOST', 'PGPORT', 'PGUSER', 'PGPASSWORD', 'PGDATABASE'];
function fixture(cmd, { soft = false } = {}) {
    const env = { ...process.env };
    for (const v of PG_VARS) delete env[v];
    env.POS_CHECK_LOGIN = creds().login;
    try {
        const raw = execFileSync('php', ['scripts/fbr-pharmacy-counter-fixture.php', cmd], {
            cwd: ROOT, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
        });
        return JSON.parse(raw);
    } catch (e) {
        const msg = (e.stderr || e.message || '').toString().trim().split('\n')[0];
        if (soft) { console.error(`    (fixture ${cmd} failed: ${msg})`); return null; }
        cannotRun(`fixture ${cmd} failed: ${msg}`);
    }
}

let pass = 0, fail = 0;
const ok = (c, m) => { c ? pass++ : fail++; console.log(`    ${c ? 'OK' : 'FAIL'}: ${m}`); };

const { login, password } = creds();
if (!login || !password) cannotRun('credentials missing — set POS_CHECK_LOGIN/POS_CHECK_PASSWORD or add VIDEO_DEMO_PASS to .local/qa-creds.env');
const bin = chromiumPath();
if (!bin) cannotRun('no chromium binary (set CHROMIUM_BIN)');

const fx = fixture('setup');
console.log(`  fixture: company ${fx.company_id}, zero-stock "${fx.name}" (#${fx.product_id}, barcode ${fx.barcode}), alternative "${fx.alternative}"`);

const browser = await chromium.launch({ executablePath: bin, args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + String(e && (e.stack || e.message || e)).slice(0, 300)));
page.on('console', (m) => { if (m.type() === 'error' && !/ERR_INTERNET_DISCONNECTED/.test(m.text())) errors.push('console: ' + m.text().slice(0, 200)); });

// Every stock-check answer arrives late, so Enter/scan ALWAYS beats the probe.
const stockUrls = [];
await page.route('**/fbr-pos/pharmacy/stock-check**', async (route) => {
    stockUrls.push(route.request().url().split('?')[1] || '');
    await new Promise((r) => setTimeout(r, 600));
    await route.continue();
});
const missedPosts = [];
await page.route('**/fbr-pos/pharmacy/missed-sales', async (route) => {
    if (route.request().method() === 'POST') { try { missedPosts.push(JSON.parse(route.request().postData() || '{}')); } catch { missedPosts.push({}); } }
    await route.continue();
});

try {
    await page.goto(`${BASE_URL}/fbr-pos/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
} catch (e) { await browser.close(); fixture('teardown', { soft: true }); cannotRun(`dev server unreachable at ${BASE_URL} (${e.message.split('\n')[0]})`); }
const kill = () => page.evaluate(() => document.getElementById('tn-domain-move-notice')?.remove());
await kill();
await page.fill('input[name="login"]', login);
await page.fill('input[name="password"]', password);
await page.keyboard.press('Enter');
await page.waitForTimeout(3000);
if (page.url().includes('/fbr-pos/login')) { await browser.close(); fixture('teardown', { soft: true }); cannotRun(`login failed for ${login} — reset the dev password or fix .local/qa-creds.env`); }

await page.goto(`${BASE_URL}/fbr-pos/create`, { waitUntil: 'networkidle' });
await page.waitForSelector('[data-tn-sale-root]', { timeout: 30000 });
await page.waitForTimeout(1500); await kill();

const state = () => page.evaluate(() => {
    const d = Alpine.$data(document.querySelector('[data-tn-sale-root]'));
    return { cart: d.cart.map((c) => c.item_name), alt: !!d.phAltOpen, altFor: d.phAltAsked?.name || null,
        rows: d.phAltRows.map((r) => r.name), known: Object.keys(d.phStock), pm: !!d.pharmacyMode, inv: !!d.pharmacyInventoryOn, q: d.searchQuery };
});
const reset = () => page.evaluate(() => {
    const d = Alpine.$data(document.querySelector('[data-tn-sale-root]'));
    d.cart = []; d.phStock = {}; d.phStockAt = {}; d.searchQuery = ''; d.searchSuggestions = []; d.showSearchDropdown = false;
    if (d.phAltOpen) d.phAltClose(false);
});
const inp = page.locator('input[x-ref="searchInput"]');
const FIX = fx.name, ALT = fx.alternative;

let s = await state();
ok(s.pm && s.inv, `sale screen booted in pharmacy mode with inventory ON (pm=${s.pm}, inv=${s.inv})`);
if (!(s.pm && s.inv)) { await browser.close(); fixture('teardown', { soft: true }); cannotRun('fixture did not switch pharmacy mode on'); }

console.log('\n  1. Fast keyboard Enter on a zero-stock brand');
await reset(); await inp.click(); await page.keyboard.type('Febrol', { delay: 5 }); await page.keyboard.press('Enter');
s = await state();
ok(!s.cart.includes(FIX) && !s.alt, 'right after Enter: nothing added yet (stock being resolved), panel not yet open');
await page.keyboard.press('Enter'); // second Enter while resolving
await page.waitForTimeout(1500); s = await state();
ok(!s.cart.includes(FIX), `zero-stock brand was NOT added (cart=${JSON.stringify(s.cart)})`);
ok(s.alt && s.altFor === FIX, `alternatives panel opened for the asked brand (${s.altFor})`);
ok(s.rows.includes(ALT) && !s.rows.includes(FIX), `same-salt in-stock rows offered: ${JSON.stringify(s.rows)}`);
ok(await page.locator('[data-testid="ph-alt-panel"]').isVisible(), 'panel is visible');

console.log('\n  2. Enter inside the panel');
await page.keyboard.press('Enter'); await page.keyboard.press('Enter'); await page.waitForTimeout(1500); s = await state();
ok(s.cart.length === 1 && s.cart[0] === ALT && !s.alt, `double Enter adds the alternative exactly once (cart=${JSON.stringify(s.cart)})`);
ok(missedPosts.some((p) => p.reason === 'out_of_stock' && p.term === FIX), 'picking an alternative logged the asked brand as out_of_stock');

console.log('\n  3. Barcode scan of the zero-stock product');
await reset(); await inp.click(); await page.keyboard.type(fx.barcode, { delay: 2 }); await page.keyboard.press('Enter');
await page.waitForTimeout(1500); s = await state();
ok(!s.cart.includes(FIX) && s.alt && s.altFor === FIX, `scan opens alternatives, adds nothing (cart=${JSON.stringify(s.cart)}, alt=${s.alt})`);
await page.locator('[data-testid="ph-alt-force"]').click(); await page.waitForTimeout(1200); s = await state();
ok(s.cart.length === 1 && s.cart[0] === FIX && !s.alt, `"Phir bhi add karein" bills the asked brand once (cart=${JSON.stringify(s.cart)})`);

console.log('\n  4. Barcode scan of an in-stock product');
await reset(); await inp.click(); await page.keyboard.type(fx.alternative_barcode, { delay: 2 }); await page.keyboard.press('Enter');
await page.waitForTimeout(1500); s = await state();
ok(s.cart.length === 1 && s.cart[0] === ALT && !s.alt, `in-stock scan adds after its resolve (cart=${JSON.stringify(s.cart)})`);

console.log('\n  5. Already-cached zero stock');
await reset(); await inp.click(); await page.keyboard.type('Febrol'); await page.waitForTimeout(1200);
s = await state(); ok(s.known.includes(String(fx.product_id)), `typing probe cached the product's stock (known=${JSON.stringify(s.known)})`);
const before = stockUrls.length; await inp.fill(''); await page.keyboard.type(fx.barcode, { delay: 2 }); await page.keyboard.press('Enter');
s = await state();
ok(s.alt && s.altFor === FIX && s.cart.length === 0, 'panel opens synchronously on Enter, nothing added');
await page.waitForTimeout(300);
ok(!stockUrls.slice(before).includes(`ids=${fx.product_id}`), `no single-id resolve request re-issued (${JSON.stringify(stockUrls.slice(before))})`);
await page.keyboard.press('Escape'); await page.waitForTimeout(200);

console.log('\n  6. Offline: never blocked');
await reset(); await ctx.setOffline(true); await inp.click(); await page.keyboard.type(fx.barcode, { delay: 2 }); await page.keyboard.press('Enter');
await page.waitForTimeout(1200); s = await state();
ok(s.cart.length === 1 && s.cart[0] === FIX && !s.alt, `offline scan of an unknown-stock product adds immediately (cart=${JSON.stringify(s.cart)})`);
await ctx.setOffline(false); await page.waitForTimeout(500);

console.log('\n  7. Fast Enter on a typed name: carried = added, not carried = missed sale');
await reset(); const mBefore = missedPosts.length;
await inp.click(); await page.keyboard.type(ALT.split(' ')[0], { delay: 3 }); await page.keyboard.press('Enter');
await page.waitForTimeout(1500); s = await state();
ok(s.cart.length === 1 && s.cart[0] === ALT, `Enter before the debounce still adds the carried product (cart=${JSON.stringify(s.cart)})`);
ok(missedPosts.length === mBefore, 'a carried product was NOT logged as a missed sale');
await reset(); await inp.click(); await page.keyboard.type(fx.term, { delay: 3 }); await page.keyboard.press('Enter');
await page.waitForTimeout(1500); s = await state();
ok(s.cart.length === 0 && s.q === '', `true no-match Enter adds nothing and clears the box (q="${s.q}")`);
ok(missedPosts.slice(mBefore).some((p) => p.reason === 'no_match' && p.term === fx.term), 'true no-match Enter was logged as a missed sale');

console.log('\n  8. Keyboard rules + errors');
await reset(); await inp.fill(''); await page.keyboard.type('t');
ok((await inp.inputValue()) === 't', 'plain letter t still types into the empty search box'); await inp.fill('');
ok(errors.length === 0, `no uncaught JS errors${errors.length ? ' — ' + errors.join(' | ') : ''}`);

await browser.close();
const td = fixture('teardown', { soft: true });
console.log(`\n  teardown: ${JSON.stringify(td)}`);
if (fail) { console.log(`\nPHARMACY COUNTER CHECK: FAIL — ${fail} failed, ${pass} passed`); process.exit(1); }
console.log(`\nPHARMACY COUNTER CHECK: PASS — ${pass} assertions; zero-stock strips open alternatives even on scanner/fast Enter, offline never blocks, carried products never become missed sales.`);
process.exit(0);
