#!/usr/bin/env node
// Caller ID v2 repeat-order cart-merge regression test (Task 1101).
//
// Extracts the ACTUAL addToCart() and callerRepeatOrder() method bodies from
// resources/views/pos/universal.blade.php (brace-counting, same approach as
// pos-search-rank-test.mjs) and drives them with a stubbed fetch so the LIVE
// blade code — not a hand copy — is under test.
//
// Invariants locked here:
//   1. Repeating a last order MERGES quantities with the active cart
//      (existing qty 5 + historical qty 2 = 7 — never overwritten to 2).
//   2. Duplicate historical lines each add their own quantity.
//   3. Deleted/unknown products and out-of-stock (blockOutOfStock ON) items
//      are skipped and reported via toast, without touching the cart.
//
// Run standalone: node scripts/pos-caller-repeat-check.mjs
// Exit 0 = all pass, exit 1 = failure.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ROOT = path.resolve(fileURLToPath(import.meta.url), '../..');
const UNIVERSAL = path.join(ROOT, 'resources/views/pos/universal.blade.php');

const fail = (msg) => { console.error('CALLER-REPEAT FAIL:', msg); process.exit(1); };

function extractMethod(src, startPattern) {
    const start = src.search(startPattern);
    if (start === -1) fail(`method not found: ${startPattern}`);
    let depth = 0, i = src.indexOf('{', start);
    if (i === -1) fail(`no opening brace after: ${startPattern}`);
    for (; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}') { depth--; if (depth === 0) return src.slice(start, i + 1); }
    }
    fail(`unbalanced braces in: ${startPattern}`);
}

// Blade echoes ({{ route('...') }}) inside JS strings contain nested quotes —
// valid once compiled, not as raw JS. Neutralize them before eval (URL content
// is irrelevant here: fetch is stubbed).
const src = readFileSync(UNIVERSAL, 'utf8').replace(/\{\{.*?\}\}/gs, '/stub');
const addSrc = extractMethod(src, /addToCart\(item\)\s*\{/);
const repSrc = extractMethod(src, /callerRepeatOrder\(ev\)\s*\{/);

// Stubbed component: only what the two methods touch.
function makeComponent(lastOrderResponse) {
    globalThis.window = globalThis.window || {};
    globalThis.window.TXT = new Proxy({}, { get: (_, k) => `[${String(k)}] ` });
    globalThis.fetch = () => Promise.resolve({ json: () => Promise.resolve(lastOrderResponse) });
    // eslint-disable-next-line no-new-func
    const obj = new Function(`return ({ ${addSrc}, ${repSrc} });`)();
    return Object.assign(obj, {
        cart: [],
        activeCartIndex: -1,
        cartAnimating: false,
        allProducts: [
            { id: 7, type: 'product', name: 'Zinger', price: '500', stockStatus: 'in' },
            { id: 8, type: 'product', name: 'Fries', price: '200', stockStatus: 'out' },
        ],
        allServices: [{ id: 3, type: 'service', name: 'Delivery Svc', price: '100' }],
        blockOutOfStock: true,
        toasts: [],
        isInventoryEnabled: () => true,
        animateQty: () => {},
        scrollToCartItem: () => {},
        showToast(msg, type) { obj.toasts.push({ msg, type }); },
        callerBillFrom: () => {},
        dismissCallerPopup: () => {},
        showCallerLog: false,
    });
}

const tick = () => new Promise(r => setImmediate(r));
const run = async (c, ev) => { c.callerRepeatOrder(ev); await tick(); await tick(); await tick(); };
const ev = { match: { customer_id: 42 } };

// ── 1. Merge with active cart: 5 existing + 2 repeated = 7 ──────────────────
{
    const c = makeComponent({ ok: true, items: [{ item_type: 'product', item_id: 7, name: 'Zinger', quantity: 2 }], skipped: [] });
    c.cart.push({ cart_uid: 'x', item_id: 7, item_type: 'product', item_name: 'Zinger', quantity: 5, unit_price: 500 });
    await run(c, ev);
    if (c.cart.length !== 1) fail(`case 1: expected 1 cart row, got ${c.cart.length}`);
    if (c.cart[0].quantity !== 7) fail(`case 1: active qty 5 + repeat qty 2 must be 7, got ${c.cart[0].quantity}`);
}

// ── 2. Fresh cart + duplicate historical lines: 2 + 1 = 3, service added ────
{
    const c = makeComponent({ ok: true, items: [
        { item_type: 'product', item_id: 7, name: 'Zinger', quantity: 2 },
        { item_type: 'product', item_id: 7, name: 'Zinger', quantity: 1 },
        { item_type: 'service', item_id: 3, name: 'Delivery Svc', quantity: 1 },
    ], skipped: [] });
    await run(c, ev);
    const zinger = c.cart.find(r => r.item_id === 7 && r.item_type === 'product');
    const svc = c.cart.find(r => r.item_id === 3 && r.item_type === 'service');
    if (!zinger || zinger.quantity !== 3) fail(`case 2: duplicate lines must sum to 3, got ${zinger && zinger.quantity}`);
    if (!svc || svc.quantity !== 1) fail('case 2: service line missing');
    if (zinger.unit_price !== 500) fail(`case 2: must re-price from CURRENT catalog (500), got ${zinger.unit_price}`);
}

// ── 3. Deleted + out-of-stock items skipped with toast, cart untouched ──────
{
    const c = makeComponent({ ok: true, items: [
        { item_type: 'product', item_id: 999, name: 'Gone Burger', quantity: 1 },
        { item_type: 'product', item_id: 8, name: 'Fries', quantity: 2 },
        { item_type: 'product', item_id: 7, name: 'Zinger', quantity: 1 },
    ], skipped: ['Mega Deal'] });
    await run(c, ev);
    if (c.cart.length !== 1 || c.cart[0].item_id !== 7) fail('case 3: only the available product may enter the cart');
    const toast = c.toasts.find(t => t.msg.includes('Mega Deal') && t.msg.includes('Gone Burger') && t.msg.includes('Fries'));
    if (!toast) fail(`case 3: skipped toast must name all skipped items, got ${JSON.stringify(c.toasts)}`);
}

console.log('CALLER-REPEAT CHECK: PASS — repeat-order merges quantities and skips unavailable items correctly.');
