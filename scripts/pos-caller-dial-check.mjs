#!/usr/bin/env node
/**
 * Task 1396 — browser-level check for the sale screen's "Call back" button.
 *
 * WHY A REAL BROWSER: the server half of call-back is already covered by
 * tests/Feature/PosCallerDialBackTest.php. The half the cashier actually
 * touches — the button, the success toast, the amber no-phone fallback card —
 * is Alpine code inside resources/views/pos/universal.blade.php, a file that
 * is edited constantly for unrelated POS work. A renamed method, a broken
 * x-data or a wrong route helper leaves every PHP test green while the button
 * silently does nothing during a rush. Only a real click can catch that.
 *
 * WHAT IT ASSERTS (Task 1396 "done looks like"):
 *   1. the PRA sale screen renders the recent-calls list for a Caller-ID shop
 *   2. clicking "Call back" shows the SUCCESS toast and the request really
 *      reaches the counter phone (a pending pos_caller_dial_requests row)
 *   3. the caller is attached to the open bill
 *   4. with no dial-capable phone paired, the AMBER fallback card opens with
 *      the enlarged number — never an error toast
 *   5. the button does not steal the keyboard: the plain-letter shortcuts and
 *      the guided Enter flow still work right after the click, in BOTH paths
 *
 * Runs against the dev server + staging MySQL. The shop state it needs
 * (plan gate, company toggle, a missed call, a paired phone) is set up and
 * torn down by scripts/pos-caller-dial-fixture.php — nothing is left flipped.
 *
 * Usage:
 *   node scripts/pos-caller-dial-check.mjs
 *   BASE_URL=... POS_CHECK_LOGIN=... POS_CHECK_PASSWORD=... node scripts/...
 *   CHROMIUM_BIN=/path/to/chromium  (override the browser binary)
 *
 * Exit codes: 0 = pass, 1 = FAIL (the button regressed), 2 = could not run
 * (dev server / MySQL / chromium missing) — deploy-live.sh treats 2 as a
 * blocker too, so a silently skipped check can never wave a broken button
 * through.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import pw from 'playwright-core';

const { chromium } = pw;
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BASE_URL = (process.env.BASE_URL || 'http://127.0.0.1:5000').replace(/\/+$/, '');
const HEADLESS = process.env.POS_CHECK_HEADED !== '1';

// ── result collection ────────────────────────────────────────────────────
let failed = 0;
const ok = (m) => console.log(`    OK: ${m}`);
const bad = (m) => { failed++; console.error(`    FAIL: ${m}`); };
const say = (m) => console.log(`\n  ${m}`);
const cannotRun = (m) => { console.error(`\nCALL-BACK CHECK: could not run — ${m}`); process.exit(2); };
const check = (cond, m, detail) => cond ? ok(m) : bad(`${m}${detail ? ` — ${detail}` : ''}`);

// ── credentials (env, else the untracked .local/qa-creds.env) ────────────
// Repo is PUBLIC — nothing is hardcoded here.
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
        login: process.env.POS_CHECK_LOGIN || fromFile.DEV_POS_LOGIN || '',
        password: process.env.POS_CHECK_PASSWORD || fromFile.DEV_POS_PASS || '',
    };
}

// ── chromium binary ──────────────────────────────────────────────────────
function chromiumPath() {
    const tries = [];
    if (process.env.CHROMIUM_BIN) tries.push(process.env.CHROMIUM_BIN);
    try { tries.push(chromium.executablePath()); } catch { /* browsers not downloaded */ }
    for (const p of tries) if (p && existsSync(p)) return p;
    // Nix container: no stable path, so scan the store (newest build wins).
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

// ── fixture (dev shop state) ─────────────────────────────────────────────
// artisan/PHP in this container needs the Replit-Postgres env vars stripped
// or Laravel connects to the wrong database.
const PG_VARS = ['DATABASE_URL', 'DB_CONNECTION', 'PGHOST', 'PGPORT', 'PGUSER', 'PGPASSWORD', 'PGDATABASE'];
function fixture(cmd, { soft = false } = {}) {
    const env = { ...process.env };
    for (const v of PG_VARS) delete env[v];
    env.POS_CHECK_LOGIN = creds().login;
    try {
        const raw = execFileSync('php', ['scripts/pos-caller-dial-fixture.php', cmd], {
            cwd: ROOT, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
        });
        return JSON.parse(raw);
    } catch (e) {
        const msg = (e.stderr || e.message || '').toString().trim().split('\n')[0];
        if (soft) { console.error(`    (fixture ${cmd} failed: ${msg})`); return null; }
        cannotRun(`fixture ${cmd} failed: ${msg}`);
    }
}

// ── in-page helpers ──────────────────────────────────────────────────────
/** One snapshot of everything this check asserts on. */
function snap(page) {
    return page.evaluate(() => {
        const root = document.querySelector('[data-tn-sale-root]');
        if (!root || !window.Alpine) return null;
        const d = window.Alpine.$data(root);
        const ae = document.activeElement;
        const searchEl = document.querySelector('[x-ref="searchInput"]');
        return {
            callerIdOn: !!d.callerIdOn,
            guidedFlow: !!d.guidedFlow,
            flowStep: d.flowStep,
            orderType: d.orderType,
            showCallerLog: !!d.showCallerLog,
            callerLog: (d.callerLog || []).map((c) => ({ id: c.id, phone: c.phone, calledBack: !!c.called_back })),
            callerDialBusy: !!d.callerDialBusy,
            fallback: d.callerDialFallback ? { ...d.callerDialFallback } : null,
            toast: { ...d.toast },
            searchQuery: d.searchQuery,
            showSearchDropdown: !!d.showSearchDropdown,
            cart: (d.cart || []).map((i) => ({ name: i.item_name || i.name, taxExempt: !!i.is_tax_exempt })),
            customer: d.selectedCustomer ? { id: d.selectedCustomer.id, name: d.selectedCustomer.name, phone: d.selectedCustomer.phone } : null,
            addableProducts: (d.allProducts || []).filter((p) => parseFloat(p.price) > 0 && p.name).slice(0, 6).map((p) => p.name),
            focusInInput: !!(ae && ae.closest && ae.closest('input, textarea, select')),
            focusIsSearch: !!(ae && searchEl && ae === searchEl),
            TXT: {
                dialSent: window.TXT.caller_dial_sent,
                dialFailed: window.TXT.caller_dial_failed,
                noDevice: window.TXT.caller_dial_no_device,
            },
        };
    });
}

/**
 * The pos-app layout floats its own modals over whatever page loads (What's
 * New, the feedback survey, announcement popups). They are not what this check
 * is about but they sit ON TOP of the sale screen, so clear them the way a
 * cashier would: click each one's own dismiss button. They all follow the
 * layout's `xxDismiss()` convention, so a popup added later is handled too.
 */
/**
 * Click every layout popup's own dismiss button (the `xxDismiss()` convention)
 * until nothing is left for `settle` ms. Popups that ask the server first can
 * land a second or two after load, so silence has to be sustained, not instant.
 */
async function sweep(page, budgetMs = 6000) {
    const until = Date.now() + budgetMs;
    let quietSince = null;
    while (Date.now() < until) {
        const handle = await page.evaluateHandle(() => {
            // NOTE: offsetParent is always null for position:fixed — measure instead.
            const vis = (el) => {
                const cs = getComputedStyle(el);
                if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') return false;
                const r = el.getBoundingClientRect();
                return r.width > 0 && r.height > 0;
            };
            const root = document.querySelector('[data-tn-sale-root]');
            const overlay = [...document.querySelectorAll('.fixed.inset-0')]
                .find((n) => !root?.contains(n) && vis(n));
            if (!overlay) return null;
            return [...overlay.querySelectorAll('button')]
                .find((b) => /Dismiss\(\)/.test(b.getAttribute('@click') || b.getAttribute('x-on:click') || '') && vis(b)) || null;
        });
        const el = handle.asElement();
        if (!el) {
            if (quietSince === null) quietSince = Date.now();
            if (Date.now() - quietSince >= 2000) return;
            await page.waitForTimeout(200);
            continue;
        }
        quietSince = null;
        // A real click first (that is what a cashier does), but if Playwright's
        // actionability check refuses — another transparent overlay on top, a
        // transition still running — fall back to a plain DOM click so one
        // stubborn popup cannot fail the whole check for the wrong reason.
        try {
            await el.click({ timeout: 2000 });
        } catch {
            await el.evaluate((b) => b.click()).catch(() => {});
        }
        await page.waitForTimeout(200);
    }
}

async function clearLayoutPopups(page) {
    // Mark What's New seen so it does not re-open on the next page load.
    await page.evaluate(async () => {
        const t = document.querySelector('meta[name="csrf-token"]')?.content;
        if (t) {
            await fetch('/pos/whats-new/seen', {
                method: 'POST', headers: { 'X-CSRF-TOKEN': t, Accept: 'application/json' },
            }).catch(() => {});
        }
    });
    // Some popups arrive LATE (the day-close reminder asks the server first,
    // and that answer can take a second on a shop with a long backlog), so an
    // empty pass proves nothing — sweep for a few seconds before believing it.
    await sweep(page, 6000);
    const blocker = await page.evaluate(() => {
        const vis = (el) => {
            const cs = getComputedStyle(el);
            if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') return false;
            const r = el.getBoundingClientRect();
            return r.width > 0 && r.height > 0;
        };
        const root = document.querySelector('[data-tn-sale-root]');
        const el = [...document.querySelectorAll('.fixed.inset-0')].find((n) => !root?.contains(n) && vis(n));
        if (!el) return null;
        // Name it properly — "show" alone tells the next person nothing.
        const label = (el.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 70);
        const clicks = [...el.querySelectorAll('button')]
            .map((b) => b.getAttribute('@click') || b.getAttribute('x-on:click') || '')
            .filter(Boolean).join(' | ') || 'no @click buttons';
        return `${el.getAttribute('x-show') || el.className.slice(0, 40)} :: "${label}" :: ${clicks}`;
    });
    if (blocker) {
        await sweep(page, 4000);
        const stillThere = await page.evaluate(() => {
            const vis = (el) => {
                const cs = getComputedStyle(el);
                if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') return false;
                const r = el.getBoundingClientRect();
                return r.width > 0 && r.height > 0;
            };
            const root = document.querySelector('[data-tn-sale-root]');
            return [...document.querySelectorAll('.fixed.inset-0')].some((n) => !root?.contains(n) && vis(n));
        });
        if (stillThere) cannotRun(`a layout popup is covering the sale screen (${blocker}) — give it a dismiss button or extend clearLayoutPopups()`);
    }
}

async function waitFor(page, predicate, label, timeout = 10000) {
    const started = Date.now();
    let last = null;
    while (Date.now() - started < timeout) {
        last = await snap(page);
        if (last && predicate(last)) return last;
        await page.waitForTimeout(60);
    }
    throw new Error(`timed out waiting for ${label} (last state: ${JSON.stringify(last)?.slice(0, 400)})`);
}

/**
 * Click the button whose Alpine @click handler contains `needle`. Buttons in
 * the recent-calls list live in an x-for that the 7-second caller poll can
 * re-render underneath us, so a stale handle is retried once — a genuinely
 * missing handler still throws on the re-query.
 */
async function clickHandler(page, needle, label) {
    let lastErr = null;
    for (let attempt = 0; attempt < 2; attempt++) {
        const handle = await page.evaluateHandle(
            (n) => [...document.querySelectorAll('button')].find((b) => (b.getAttribute('@click') || '').includes(n)) || null,
            needle,
        );
        const el = handle.asElement();
        if (!el) throw new Error(`${label}: no button with @click containing "${needle}" — the handler was renamed or the markup moved`);
        try { await el.click({ timeout: 8000 }); return el; }
        catch (e) { lastErr = e; await page.waitForTimeout(300); }
    }
    throw new Error(`${label}: could not click the button — ${lastErr?.message?.split('\n')[0]}`);
}

/**
 * The keyboard contract, probed exactly as a cashier would: plain-letter
 * shortcuts (T = tax toggle on the last cart row), letters routing back into
 * the product search, and the guided Enter chain (empty search + cart -> the
 * Order-Type step). Never blurs anything — whatever focus the preceding click
 * left behind is precisely what is under test.
 */
async function probeKeyboard(page, phase) {
    const before = await snap(page);
    if (!before.cart.length) throw new Error(`${phase}: keyboard probe needs an item in the cart`);
    const taxWas = before.cart[before.cart.length - 1].taxExempt;
    const queryWas = before.searchQuery;

    await page.keyboard.press('t');
    let s = await waitFor(page, (x) => x.cart.length && x.cart[x.cart.length - 1].taxExempt !== taxWas,
        `${phase}: plain T to toggle tax`, 8000);
    check(s.searchQuery === queryWas, `${phase}: plain-letter shortcut T still fires (did not type into a field)`,
        `searchQuery became "${s.searchQuery}"`);

    await page.keyboard.press('t');              // restore the row
    await waitFor(page, (x) => x.cart[x.cart.length - 1].taxExempt === taxWas, `${phase}: T toggles back`, 8000);

    // A non-shortcut letter must land in the search box (keyboard routing home).
    await page.keyboard.press('z');
    s = await waitFor(page, (x) => x.searchQuery.endsWith('z') && x.focusIsSearch,
        `${phase}: letter to reach the product search`, 8000);
    ok(`${phase}: typing still reaches the product search box`);

    // Clear it again (the caret sits at 0 after a programmatic focus, so a
    // plain Backspace would be a no-op — not what is under test here).
    await page.locator('[x-ref="searchInput"]').fill(queryWas);
    await waitFor(page, (x) => x.searchQuery === queryWas && !x.showSearchDropdown, `${phase}: search box to clear`, 8000);

    // Guided flow: Enter on an empty search with a live cart opens the
    // Order-Type step. That is the chain's first hop — if the button had
    // grabbed the keyboard, this is where the cashier would be stuck.
    await page.keyboard.press('Enter');
    s = await waitFor(page, (x) => x.flowStep === 'type', `${phase}: guided Enter to open the Order-Type step`, 8000);
    ok(`${phase}: guided Enter flow still advances (flowStep=type)`);

    await page.keyboard.press('Escape');
    await waitFor(page, (x) => x.flowStep !== 'type', `${phase}: Escape to leave the Order-Type step`, 8000);
}

// ── main ─────────────────────────────────────────────────────────────────
const { login, password } = creds();
if (!login || !password) {
    cannotRun('POS check credentials missing — set POS_CHECK_LOGIN/POS_CHECK_PASSWORD or create .local/qa-creds.env');
}
const CHROMIUM = chromiumPath();
if (!CHROMIUM) cannotRun('no chromium binary found — set CHROMIUM_BIN=/path/to/chromium');

const reachable = await fetch(`${BASE_URL}/pos/login`, { redirect: 'manual' }).then(() => true).catch(() => false);
if (!reachable) cannotRun(`cannot reach ${BASE_URL} — start the Laravel Server workflow (or set BASE_URL)`);

console.log(`CALL-BACK CHECK — ${BASE_URL} (chromium: ${CHROMIUM})`);

say('Fixture: Caller ID shop with one missed call and a dial-ready counter phone');
const setup = fixture('setup');
console.log(`    shop ${setup.company_id}, call from ${setup.customer_name} ${setup.display_phone}`);

let browser = null;
try {
    browser = await chromium.launch({
        executablePath: CHROMIUM,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
        headless: HEADLESS,
    });
    // serviceWorkers:'block' — the sale screen is served cache-first by sw.js;
    // this check must always see the CURRENT blade, never a cached boot.
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, serviceWorkers: 'block' });
    const page = await ctx.newPage();

    const pageErrors = [];
    page.on('pageerror', (e) => pageErrors.push(e.message));

    // The app forces https in generated URLs; rewrite redirects back to the
    // plain-http dev origin (same trick as scripts/mobile-check.cjs).
    await page.route('**/*', async (route) => {
        let response;
        try { response = await route.fetch({ maxRedirects: 0 }); } catch { await route.continue(); return; }
        const s = response.status();
        if ([301, 302, 303, 307, 308].includes(s)) {
            const loc = response.headers()['location'] || '';
            if (loc && !loc.startsWith(BASE_URL)) {
                const rw = loc.startsWith('/') ? BASE_URL + loc : loc.replace(/^https?:\/\/[^/]+/, BASE_URL);
                await route.fulfill({ status: s, headers: { ...response.headers(), location: rw }, body: '' });
                return;
            }
        }
        await route.fulfill({ response });
    });

    say('Sign in to the PRA POS panel');
    await page.goto(`${BASE_URL}/pos/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.fill('input[name="login"]', login);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.endsWith('/pos/login'), { timeout: 30000 }),
        page.keyboard.press('Enter'),
    ]);
    if (page.url().includes('/pos/login')) cannotRun(`login failed for ${login} — reset the dev password or fix .local/qa-creds.env`);
    ok(`signed in as ${login}`);

    say('Open the sale screen');
    await clearLayoutPopups(page);   // mark What's New seen BEFORE the sale screen renders
    await page.goto(`${BASE_URL}/pos/invoice/create`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    let s = await waitFor(page, (x) => x.callerIdOn !== undefined, 'the sale screen Alpine component to boot', 30000);
    await clearLayoutPopups(page);
    check(s.callerIdOn, 'sale screen renders with Caller ID switched on', 'callerIdOn is false — the gate or the baked flag regressed');
    check(s.guidedFlow, 'guided keyboard flow is on for this shop (needed by the keyboard assertions)');
    if (!s.callerIdOn) throw new Error('Caller ID is off on the sale screen — nothing to click');

    // A live bill: call-back happens mid-rush, and both keyboard probes need
    // a cart row to act on.
    say('Start a bill (product typed into the search box, added with Enter)');
    let added = false;
    for (const name of s.addableProducts) {
        const box = page.locator('[x-ref="searchInput"]');
        await box.click();
        await box.fill('');
        await box.pressSequentially(name, { delay: 25 });
        await page.waitForTimeout(300);
        await box.press('Enter');
        try { await waitFor(page, (x) => x.cart.length > 0, 'the product to reach the cart', 3000); added = true; break; }
        catch { /* out of stock / blocked — try the next product */ }
    }
    if (!added) throw new Error('could not add any product to the cart — the search box or the catalog is broken');
    s = await snap(page);
    ok(`cart has ${s.cart.length} item (${s.cart[0].name})`);

    say('Baseline: keyboard shortcuts work BEFORE the button is touched');
    // Blur only here: the cashier is not typing when a call comes in. Every
    // later probe starts from whatever focus the click itself left.
    await page.evaluate(() => document.activeElement?.blur());
    await probeKeyboard(page, 'baseline');

    say('Recent calls: open the list and click "Call back" (phone ready)');
    await clickHandler(page, 'openCallerLog()', 'caller log button');
    s = await waitFor(page, (x) => x.showCallerLog && x.callerLog.length > 0, 'the recent-calls list to load');
    check(s.callerLog[0].phone === setup.display_phone, 'the missed call is listed on the sale screen',
        `first row shows "${s.callerLog[0].phone}", expected "${setup.display_phone}"`);

    await clickHandler(page, 'callerDialBack(ev, { attach: true })', 'Call back button');

    // 1) the toast the cashier looks for
    s = await waitFor(page, (x) => x.toast.show && x.toast.message === x.TXT.dialSent, 'the success toast');
    check(s.toast.type === 'success', 'clicking "Call back" shows the SUCCESS toast', `toast type was "${s.toast.type}"`);
    check(await page.locator('[x-show="toast.show"]').isVisible(), 'the toast is actually visible on screen');
    check(!s.fallback, 'no fallback card while a phone is paired');

    // 2) it really left the building — a queued request for the counter phone
    const afterDial = fixture('status');
    const pending = (afterDial.dial_requests || []).filter((r) => r.status === 'pending');
    check(pending.length === 1, 'the request actually reached the counter phone queue',
        `${(afterDial.dial_requests || []).length} dial request row(s), ${pending.length} pending`);
    check(!!afterDial.called_back_at, 'the call is stamped as called back');
    s = await waitFor(page, (x) => x.callerLog[0] && x.callerLog[0].calledBack, 'the row to show the called-back tick', 8000);
    ok('the recent-calls row shows the called-back tick');

    // 3) the customer is on the bill
    check(s.customer && String(s.customer.id) === String(setup.customer_id),
        'the caller is attached to the open bill',
        `selectedCustomer=${JSON.stringify(s.customer)} expected id ${setup.customer_id}`);

    // 4) the keyboard survived the click
    say('Keyboard AFTER the click (phone ready) — nothing was stolen');
    await probeKeyboard(page, 'after dial');

    say('No dial-capable phone paired: the amber fallback, not an error');
    const dead = fixture('dial-dead');
    check(dead.dial_ready === false && dead.expected_reason === 'no_device',
        'fixture aged the paired phone out of the dial window', JSON.stringify(dead.expected_reason));
    // The success path leaves the list open, so the same row is still there.
    s = await snap(page);
    if (!s.showCallerLog) {
        await clickHandler(page, 'openCallerLog()', 'caller log button');
        await waitFor(page, (x) => x.showCallerLog && x.callerLog.length > 0, 'the recent-calls list to reload');
    }
    await clickHandler(page, 'callerDialBack(ev, { attach: true })', 'Call back button (no phone)');

    s = await waitFor(page, (x) => !!x.fallback, 'the fallback card');
    check(s.fallback.reason === 'no_device', 'the fallback names the real reason',
        `reason was "${s.fallback.reason}"`);
    check(s.fallback.phone === setup.display_phone, 'the fallback carries the caller number',
        `card holds "${s.fallback.phone}"`);
    check(!(s.toast.show && s.toast.message === s.TXT.dialFailed), 'no error toast — the cashier gets the number, not a dead end');

    const card = page.locator('[x-show="callerDialFallback"]');
    check(await card.isVisible(), 'the fallback card is on screen');
    const shown = await card.locator('p.text-2xl').first();
    check((await shown.innerText()).trim() === setup.display_phone, 'the card shows the ENLARGED number',
        `rendered "${(await shown.innerText()).trim()}"`);
    const fontPx = await shown.evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    check(fontPx >= 20, 'the number is actually enlarged', `font-size is ${fontPx}px`);
    const amber = await card.locator('div.bg-amber-500').first().evaluate((el) => getComputedStyle(el).backgroundColor);
    check(amber === 'rgb(245, 158, 11)', 'the card keeps its amber header', `header background is ${amber}`);
    const reasonText = (await card.locator('p.text-\\[11px\\]').first().innerText()).trim();
    check(reasonText.length > 0, 'the card explains why no phone answered', 'the reason line rendered empty');

    say('Keyboard AFTER the fallback opens — still nothing stolen');
    check(!(await snap(page)).focusInInput, 'the fallback card holds no input to trap the keyboard');
    await probeKeyboard(page, 'after fallback');

    if (pageErrors.length) bad(`uncaught JS error(s) on the sale screen: ${pageErrors.slice(0, 3).join(' | ')}`);
    else ok('no uncaught JS errors on the sale screen');
} catch (e) {
    bad(e.message);
} finally {
    if (browser) await browser.close().catch(() => {});
    fixture('teardown', { soft: true });
}

console.log('');
if (failed) {
    console.error(`CALL-BACK CHECK: FAILED (${failed}) — the sale screen's "Call back" button regressed.`);
    process.exit(1);
}
console.log('CALL-BACK CHECK: PASS — Call back dials, attaches the customer, falls back to the number, and never steals the keyboard.');
process.exit(0);
