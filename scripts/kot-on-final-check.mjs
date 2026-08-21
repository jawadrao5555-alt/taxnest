#!/usr/bin/env node
// Task 1356 — "Bill final ho to KOT zaroor jaye" regression check.
//
// Owner video (dine-in, Table No 02): the cashier settled the cart with CASH
// without ever pressing "Send to Kitchen". The customer bill printed; the
// kitchen got NOTHING — no slip, no KDS card — and the food was never started.
// Cause: the sale screen's auto-print chain blanket-blocked KOT on dine-in
// finals ("the ticket already went at hold"), which is false for a cart that
// goes straight to pay.
//
// This check extracts the REAL runAutoPrintChain source from
// resources/views/pos/universal.blade.php and executes it in a stubbed
// component, asserting the safety net fires exactly where it should and
// NOWHERE else. Same technique as print-order-check.mjs / print-confirm-check.mjs.
//
// Locked invariants:
//   1. dine-in settled straight to CASH (server: kot_pending) → KOT fires, DELTA,
//      + cashier toast.
//   2. dine-in held & already KOT'd, then paid (kot_pending false) → nothing.
//   3. waiter order settled, its KOT already printed → nothing.
//   4. takeaway counter pay → unchanged (exactly ONE KOT, never doubled).
//   5. shop toggle kot_on_final_if_unsent OFF → net disabled, nothing.
//   6. live auto-printing KDS owns the ticket → cashier prints nothing
//      (the order is rescued onto the KDS board server-side instead).
//   7. plain retail / no restaurant order (orderId null) → no rescue.
//   8. Auto-Print master switch OFF → still nothing (net never overrides it).
//   9. the print-confirm (Yes/No) re-entry must FORWARD kot_pending, or the
//      dialog would silently swallow the rescue.
import { readFileSync } from 'node:fs';

const blade = readFileSync(new URL('../resources/views/pos/universal.blade.php', import.meta.url), 'utf8');
const fail = (msg) => { console.error('KOT-ON-FINAL FAIL: ' + msg); process.exit(1); };

// Extract a top-level object method (shorthand form) by brace counting.
function extractMethod(startPattern) {
  const start = blade.search(startPattern);
  if (start === -1) fail(`method not found: ${startPattern}`);
  let depth = 0, i = blade.indexOf('{', start);
  if (i === -1) fail(`no opening brace after: ${startPattern}`);
  for (; i < blade.length; i++) {
    if (blade[i] === '{') depth++;
    else if (blade[i] === '}') { depth--; if (depth === 0) return blade.slice(start, i + 1); }
  }
  fail(`unbalanced braces for: ${startPattern}`);
}

const srcChain = extractMethod(/runAutoPrintChain\(orderId, orderType = null/);
if (!/kotPending = false\)/.test(srcChain)) {
  fail('runAutoPrintChain lost its kotPending parameter — the safety net cannot be told a ticket is owed');
}

globalThis.window = { TXT: new Proxy({}, { get: (_t, k) => 'TXT.' + String(k) }) };

let methods;
try {
  methods = (0, eval)(`({ ${srcChain} })`);
} catch (e) {
  fail('extracted runAutoPrintChain does not evaluate: ' + e.message);
}

// Runs the real chain over a stub component and reports what it tried to print.
function run(state, args) {
  const events = [];
  const comp = Object.assign({
    // defaults: restaurant shop, auto-print + auto-KOT on, silent agent path,
    // no Yes/No dialog, dine-in receipts allowed, a real bill exists.
    autoPrintEnabled: true, autoKotEnabled: true,
    silentBillPrint: true, silentKotPrint: true,
    printConfirmAsk: false, dineinAutoPrint: true, isRestaurantMode: true,
    lastTransactionId: 42, lastPraStatus: '', lastIsOffline: false,
    kitchenSettings: { kot_on_final_if_unsent: true },
    kdsHandlesKot: () => false,
    // stubs
    printBeacon: () => {},
    showToast: (msg) => events.push({ t: 'toast', msg }),
    openPrintConfirm: (yes, no) => events.push({ t: 'confirm', yes, no }),
    queuePrintTimer: (fn) => { fn(); },
    $nextTick: (fn) => fn(),
    trySilentPrint: async () => ({ deduped: false }),
    printReceipt: async () => { events.push({ t: 'receipt' }); },
    printKitchenTicket: (id, cb, delta) => { events.push({ t: 'kot', id, delta }); if (cb) cb(); },
    printTxnKitchenTicket: (id, cb) => { events.push({ t: 'kot_txn', id }); if (cb) cb(); },
    _printViaIframe: () => { events.push({ t: 'iframe' }); },
  }, state, methods);

  comp.runAutoPrintChain(...args);
  return { events, comp, kots: events.filter(e => e.t === 'kot' || e.t === 'kot_txn') };
}

const ORDER = 9001;
let checks = 0;
const ok = (cond, msg) => { checks++; if (!cond) fail(msg); };

// ── 1. THE BUG: dine-in cart settled straight to CASH ────────────────────────
{
  const { kots, events } = run({}, [ORDER, 'dine_in', null, false, false, /* kotPending */ true]);
  ok(kots.length === 1, `dine-in direct cash: expected exactly 1 kitchen ticket, got ${kots.length} — the owner's Table 02 bug is back`);
  ok(kots[0].id === ORDER, 'dine-in direct cash: ticket must be printed for the settled order');
  ok(kots[0].delta === true, 'dine-in direct cash: ticket MUST be a delta (unseen lines only) — a full reprint would duplicate anything already sent');
  ok(events.some(e => e.t === 'toast' && /kot_auto_sent_on_final/.test(e.msg)),
    'dine-in direct cash: cashier gets no confirmation toast — she has no way to know the kitchen was reached');
  ok(events.some(e => e.t === 'receipt'), 'dine-in direct cash: the customer receipt must still print');
}

// ── 2. dine-in held + already KOT'd, then paid ───────────────────────────────
{
  const { kots } = run({}, [ORDER, 'dine_in', null, false, false, /* kotPending */ false]);
  ok(kots.length === 0, 'dine-in hold+KOT then pay: a SECOND kitchen slip printed — the kitchen already has this order');
}

// ── 3. waiter order settled by the cashier (its KOT already printed) ─────────
// The sale screen only passes an order id when the server says a ticket is owed,
// so a normally-printed waiter order arrives here with neither.
{
  const { kots } = run({}, [null, 'dine_in', null, false, false, /* kotPending */ false]);
  ok(kots.length === 0, 'waiter settle: printed a duplicate kitchen ticket');
}
// ...and the agent-offline waiter order (never printed) IS rescued.
{
  const { kots } = run({}, [ORDER, 'dine_in', null, false, false, true]);
  ok(kots.length === 1 && kots[0].delta === true, 'waiter settle with a never-printed ticket: the rescue must still fire as a delta');
}

// ── 4. takeaway counter pay — unchanged behaviour, never doubled ─────────────
{
  const before = run({}, [ORDER, 'takeaway', null, false, false, /* kotPending */ false]);
  ok(before.kots.length === 1, `takeaway counter pay: expected the usual single KOT, got ${before.kots.length}`);
  const after = run({}, [ORDER, 'takeaway', null, false, false, /* kotPending */ true]);
  ok(after.kots.length === 1, `takeaway + kot_pending: the safety net must MERGE with the existing rule, not add a second ticket (got ${after.kots.length})`);
  ok(after.kots[0].delta === true, 'takeaway: KOT stays a delta');
}

// ── 5. shop switched the safety net OFF ──────────────────────────────────────
{
  const { kots, events } = run({ kitchenSettings: { kot_on_final_if_unsent: false } },
    [ORDER, 'dine_in', null, false, false, true]);
  ok(kots.length === 0, 'kot_on_final_if_unsent OFF: the net still fired — the toggle does nothing');
  ok(!events.some(e => e.t === 'toast'), 'kot_on_final_if_unsent OFF: no toast should be shown either');
}

// ── 6. live auto-printing KDS owns the ticket ────────────────────────────────
{
  const { kots, events } = run({ kdsHandlesKot: () => true }, [ORDER, 'dine_in', null, false, false, true]);
  ok(kots.length === 0, 'KDS auto-print shop: the cashier must not print — the board owns the ticket');
  ok(!events.some(e => e.t === 'toast'), 'KDS auto-print shop: no "ticket sent" toast (nothing was sent from here)');
}

// ── 7. no restaurant order (plain retail / manual cart) ──────────────────────
{
  const { kots } = run({}, [null, 'dine_in', null, false, false, /* kotPending */ true]);
  ok(kots.length === 0, 'no order id: the rescue must not invent a ticket');
}

// ── 8. Auto-Print master switch OFF still wins ───────────────────────────────
{
  const { events } = run({ autoPrintEnabled: false }, [ORDER, 'dine_in', null, false, false, true]);
  ok(events.length === 0, 'Auto-Print OFF: the safety net overrode the master switch');
}

// ── 9. Yes/No print dialog must forward kot_pending ──────────────────────────
{
  const { events } = run({ printConfirmAsk: true }, [ORDER, 'dine_in', null, false, false, true]);
  const confirm = events.find(e => e.t === 'confirm');
  ok(!!confirm, 'print-confirm shop: expected the Yes/No dialog to open');
  for (const [label, cb] of [['Yes', confirm.yes], ['No', confirm.no]]) {
    const inner = [];
    const probe = run({
      printConfirmAsk: true,
      printKitchenTicket: (id, cb2, delta) => { inner.push({ id, delta }); if (cb2) cb2(); },
    }, [ORDER, 'dine_in', null, false, false, true]);
    // Re-enter through the captured callback on the SAME component instance.
    probe.comp.printKitchenTicket = (id, _cb, delta) => inner.push({ id, delta });
    probe.events.find(e => e.t === 'confirm')[label === 'Yes' ? 'yes' : 'no']();
    ok(inner.length === 1 && inner[0].delta === true,
      `print-confirm "${label}": the rescue KOT was dropped on re-entry (kot_pending not forwarded)`);
  }
}

console.log(`KOT-ON-FINAL OK: ${checks} invariants — unseen lines on a final bill reach the kitchen (delta + toast); held/waiter/KDS/toggle-off/retail paths print nothing extra.`);
