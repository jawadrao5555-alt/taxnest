#!/usr/bin/env node
// Print-confirm regression check (Task 1025 — owner video, live shop):
// extracts the REAL runAutoPrintChain / openPrintConfirm / resolvePrintConfirm /
// returnToTablesAfterReceipt sources from resources/views/pos/universal.blade.php
// and executes them in a stubbed component to assert:
//   1. "Print this bill?" popup answered NO → the KOT still fires through its
//      existing gates; only the CUSTOMER RECEIPT is skipped (before Task 1025
//      "No" killed the whole chain and the kitchen never got the ticket).
//   2. Answered YES → both receipt and KOT fire exactly as before.
//   3. Per-bill skip-receipt override → NO popup asked (question is about the
//      receipt; there is none), KOT fires directly.
//   4. Dine-in → still never auto-KOTs; "No" on a dine-in receipt prints nothing.
//   5. kdsHandlesKot() → KOT suppressed even on the "No" re-entry.
//   6. returnToTablesAfterReceipt navigates ONLY when the paid bill's snapshot
//      (lastOrderType) is dine_in — takeaway/delivery stay on the sale screen.
import { readFileSync } from 'node:fs';

const blade = readFileSync(new URL('../resources/views/pos/universal.blade.php', import.meta.url), 'utf8');

let failures = 0;
const fail = (msg) => { console.error('PRINT-CONFIRM FAIL: ' + msg); failures++; };
const hard = (msg) => { console.error('PRINT-CONFIRM FAIL: ' + msg); process.exit(1); };

// Extract a top-level object method (shorthand form) by brace counting.
function extractMethod(startPattern) {
  const start = blade.search(startPattern);
  if (start === -1) hard(`method not found: ${startPattern}`);
  let depth = 0, i = blade.indexOf('{', start);
  if (i === -1) hard(`no opening brace after: ${startPattern}`);
  for (; i < blade.length; i++) {
    if (blade[i] === '{') depth++;
    else if (blade[i] === '}') { depth--; if (depth === 0) return blade.slice(start, i + 1); }
  }
  hard(`unbalanced braces for: ${startPattern}`);
}

const srcChain   = extractMethod(/runAutoPrintChain\(orderId, orderType = null/);
const srcOpen    = extractMethod(/openPrintConfirm\(onYes, onNo = null\) \{/);
const srcResolve = extractMethod(/resolvePrintConfirm\(yes\) \{/);
const srcReturn  = extractMethod(/returnToTablesAfterReceipt\(\) \{/);

globalThis.window = {
  TXT: new Proxy({}, { get: () => 'x' }),
  focus: () => {},
  location: { assign: (url) => { globalThis.__navigatedTo = url; } },
};
globalThis.document = { activeElement: null, getElementById: () => null };

let methods;
try {
  methods = (0, eval)(`({ ${srcChain}, ${srcOpen}, ${srcResolve}, ${srcReturn} })`);
} catch (e) {
  hard('extracted sources do not evaluate: ' + e.message);
}

// Fresh component per scenario — REAL chain/confirm/return methods + minimal stubs.
function makeComp(overrides = {}) {
  const events = [];
  const comp = Object.assign({
    events,
    // defaults: silent both, confirm ON, takeaway with a live restaurant order
    autoPrintEnabled: true, autoKotEnabled: true, silentBillPrint: true, silentKotPrint: true,
    printConfirmAsk: true, dineinAutoPrint: true, isRestaurantMode: true,
    showPrintConfirm: false, printConfirmChoice: 'yes', printConfirmAction: null, printConfirmNoAction: null,
    lastTransactionId: 42, lastPraStatus: 'submitted', lastIsOffline: false,
    // tables-first state
    tablesFirstFlow: true, tableBoardEnabled: true, tablesReturnPending: false,
    showReceipt: true, lastOrderType: null,
    printChainBusy: () => false,
    // stubs
    kdsHandlesKot: () => false,
    printBeacon: (tag) => { events.push('beacon:' + tag); },
    showToast: () => {},
    queuePrintTimer: (fn) => fn(),
    $nextTick: (fn) => fn(),
    $refs: {},
    trySilentPrint: async (job) => { events.push(job.type); return { deduped: false }; },
    printReceipt: (cb) => { events.push('bill'); if (cb) cb(); },
    printKitchenTicket: (id, cb) => { events.push('kot'); if (cb) cb(); },
    printTxnKitchenTicket: (id, cb) => { events.push('kot'); if (cb) cb(); },
    _printViaIframe: () => { events.push('iframe'); },
    praPrintGrace: async () => {},
  }, methods, overrides);
  return comp;
}

const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const printed = (ev) => ev.filter(e => e === 'bill' || e === 'kot');

// ── 1. Takeaway + confirm ON + answer NO → KOT only ─────────────────────────
{
  const c = makeComp();
  c.runAutoPrintChain(7, 'takeaway');
  if (!c.showPrintConfirm) fail('scenario 1: confirm popup never opened for a takeaway receipt');
  c.resolvePrintConfirm(false);
  await sleep(80);
  const p = printed(c.events);
  if (!p.includes('kot')) fail(`scenario 1: "No" killed the KOT — kitchen gets nothing: ${JSON.stringify(c.events)}`);
  if (p.includes('bill')) fail(`scenario 1: "No" still printed the customer bill: ${JSON.stringify(c.events)}`);
  if (!c.events.includes('beacon:print-confirm-no')) fail('scenario 1: deliberate "No" beacon missing (diagnostics)');
}

// ── 2. Takeaway + confirm ON + answer YES → both ────────────────────────────
{
  const c = makeComp();
  c.runAutoPrintChain(7, 'takeaway');
  c.resolvePrintConfirm(true);
  await sleep(80);
  const p = printed(c.events);
  if (!p.includes('kot') || !p.includes('bill')) fail(`scenario 2: "Yes" must print BOTH: ${JSON.stringify(c.events)}`);
}

// ── 3. Per-bill skip-receipt override → no popup, KOT direct ────────────────
{
  const c = makeComp();
  c.runAutoPrintChain(7, 'takeaway', null, /* skipReceiptOverride */ true);
  await sleep(80);
  if (c.showPrintConfirm) fail('scenario 3: popup asked although the receipt was already skipped per-bill');
  const p = printed(c.events);
  if (!p.includes('kot')) fail(`scenario 3: skip-receipt override lost the KOT: ${JSON.stringify(c.events)}`);
  if (p.includes('bill')) fail(`scenario 3: skip-receipt override printed the bill anyway: ${JSON.stringify(c.events)}`);
}

// ── 4. Dine-in + answer NO → nothing prints (dine-in never auto-KOTs) ───────
{
  const c = makeComp();
  c.runAutoPrintChain(7, 'dine_in');
  if (!c.showPrintConfirm) fail('scenario 4: dine-in receipt (dineinAutoPrint ON) should still ask');
  c.resolvePrintConfirm(false);
  await sleep(80);
  if (printed(c.events).length) fail(`scenario 4: dine-in "No" printed something: ${JSON.stringify(c.events)}`);
}

// ── 4b. Dine-in + dineinAutoPrint OFF → no popup, nothing prints ────────────
{
  const c = makeComp({ dineinAutoPrint: false });
  c.runAutoPrintChain(7, 'dine_in');
  await sleep(80);
  if (c.showPrintConfirm) fail('scenario 4b: print_on_pay_dinein OFF must not ask');
  if (printed(c.events).length) fail(`scenario 4b: print_on_pay_dinein OFF printed something: ${JSON.stringify(c.events)}`);
}

// ── 5. KDS handles KOT + answer NO → nothing prints ─────────────────────────
{
  const c = makeComp({ kdsHandlesKot: () => true });
  c.runAutoPrintChain(7, 'takeaway');
  c.resolvePrintConfirm(false);
  await sleep(80);
  if (printed(c.events).includes('kot')) fail(`scenario 5: KDS suppression lost on the "No" re-entry: ${JSON.stringify(c.events)}`);
}

// ── 6. Tables return gated on the PAID bill's snapshot ──────────────────────
{
  const c = makeComp({ lastOrderType: 'takeaway' });
  globalThis.__navigatedTo = null;
  if (c.returnToTablesAfterReceipt() !== false) fail('scenario 6: takeaway bill must NOT arm the tables return');
  if (globalThis.__navigatedTo) fail('scenario 6: takeaway bill navigated to ' + globalThis.__navigatedTo);

  const d = makeComp({ lastOrderType: 'delivery' });
  if (d.returnToTablesAfterReceipt() !== false) fail('scenario 6: delivery bill must NOT arm the tables return');

  const e = makeComp({ lastOrderType: 'dine_in' });
  globalThis.__navigatedTo = null;
  if (e.returnToTablesAfterReceipt() !== true) fail('scenario 6: dine-in bill must still return to Tables');
  if (globalThis.__navigatedTo !== '/pos/restaurant/tables') fail('scenario 6: dine-in navigation target wrong: ' + globalThis.__navigatedTo);
}

// ── 7. FBR universal port shares the same confirm path — same contract ──────
{
  const fbr = readFileSync(new URL('../resources/views/fbr-pos/universal.blade.php', import.meta.url), 'utf8');
  function extractFbr(startPattern) {
    const start = fbr.search(startPattern);
    if (start === -1) hard(`FBR method not found: ${startPattern}`);
    let depth = 0, i = fbr.indexOf('{', start);
    for (; i < fbr.length; i++) {
      if (fbr[i] === '{') depth++;
      else if (fbr[i] === '}') { depth--; if (depth === 0) return fbr.slice(start, i + 1); }
    }
    hard(`FBR unbalanced braces for: ${startPattern}`);
  }
  let fbrMethods;
  try {
    fbrMethods = (0, eval)(`({ ${extractFbr(/runAutoPrintChain\(orderId, isFbrHeld/)}, ${extractFbr(/openPrintConfirm\(onYes, onNo = null\) \{/)}, ${extractFbr(/resolvePrintConfirm\(yes\) \{/)} })`);
  } catch (e) {
    hard('FBR extracted sources do not evaluate: ' + e.message);
  }
  const mk = () => makeComp(fbrMethods);

  const cNo = mk();
  cNo.runAutoPrintChain(7, false);
  if (!cNo.showPrintConfirm) fail('scenario 7: FBR confirm popup never opened');
  cNo.resolvePrintConfirm(false);
  await sleep(80);
  let p = printed(cNo.events);
  if (!p.includes('kot')) fail(`scenario 7: FBR "No" killed the KOT: ${JSON.stringify(cNo.events)}`);
  if (p.includes('bill')) fail(`scenario 7: FBR "No" still printed the bill: ${JSON.stringify(cNo.events)}`);

  const cYes = mk();
  cYes.runAutoPrintChain(7, false);
  cYes.resolvePrintConfirm(true);
  await sleep(80);
  p = printed(cYes.events);
  if (!p.includes('kot') || !p.includes('bill')) fail(`scenario 7: FBR "Yes" must print BOTH: ${JSON.stringify(cYes.events)}`);
}

if (failures) { console.error(`PRINT-CONFIRM: ${failures} failure(s).`); process.exit(1); }
console.log('PRINT-CONFIRM OK: "No" = receipt-only skip (KOT alive through all gates, PRA + FBR); tables return fires only after a dine-in bill.');
