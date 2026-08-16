#!/usr/bin/env node
// Print-order regression check (Task 655 review, REVERSED by Task 994):
// extracts the REAL runAutoPrintChain / printReceipt / praPrintGrace sources
// from resources/views/pos/universal.blade.php and executes them in a stubbed
// component to assert that on the PRA silent-bill + silent-KOT fast path the
// KOT job is enqueued IMMEDIATELY (it needs no fiscal number) and never waits
// behind praPrintGrace() — owner voice note 16 Aug 2026: KOT reached the
// kitchen seconds late because it queued behind the receipt's bounded fiscal
// grace + enqueue roundtrip. The receipt must STILL be enqueued afterwards
// (grace respected so it carries the PRA number).
import { readFileSync } from 'node:fs';

const blade = readFileSync(new URL('../resources/views/pos/universal.blade.php', import.meta.url), 'utf8');

const fail = (msg) => { console.error('PRINT-ORDER FAIL: ' + msg); process.exit(1); };

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
const srcPrint = extractMethod(/async printReceipt\(onAfterPrint\)/);
const srcInner = extractMethod(/async _printReceiptInner\(onAfterPrint\)/);
const srcGrace = extractMethod(/async praPrintGrace\(\)/);

// Build a component with the REAL extracted methods + minimal stubs.
globalThis.window = { TXT: new Proxy({}, { get: () => 'x' }) };
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

let methods;
try {
  methods = (0, eval)(`({ ${srcChain}, ${srcPrint}, ${srcInner}, ${srcGrace} })`);
} catch (e) {
  fail('extracted sources do not evaluate: ' + e.message);
}

const events = [];
const comp = Object.assign({
  // state: silent fast path, bill starts PENDING (agent-mode)
  autoPrintEnabled: true, autoKotEnabled: true, silentBillPrint: true, silentKotPrint: true,
  printConfirmAsk: false, dineinAutoPrint: true, isRestaurantMode: true,
  lastTransactionId: 42, lastPraStatus: 'pending', lastIsOffline: false,
  // stubs
  kdsHandlesKot: () => false,
  printBeacon: () => {}, showToast: () => {}, openPrintConfirm: () => {},
  queuePrintTimer: (fn) => fn(),
  $nextTick: (fn) => fn(),
  // DEFERRED enqueue (review catch): the real trySilentPrint is a network
  // round-trip — the job is only "enqueued" when its promise RESOLVES. Record
  // the event after a real async delay so a fire-and-forget printReceipt()
  // (promise settling before the enqueue completes) lets KOT jump the queue
  // and the ordering assertion below catches it.
  trySilentPrint: async (job) => { await sleep(120); events.push(job.type); times[job.type] = Date.now() - t0; return { deduped: false }; },
  printKitchenTicket: (id, cb) => { events.push('kot'); times.kot = Date.now() - t0; if (cb) cb(); },
  printTxnKitchenTicket: (id, cb) => { events.push('kot'); times.kot = Date.now() - t0; if (cb) cb(); },
  _printViaIframe: () => { events.push('iframe'); },
  // status poll stubs: agent "submits" on the first grace probe
  _fetchPraStatus: async () => ({ success: true, pra_status: 'submitted', pra_invoice_number: 'QA' }),
  _applyPraStatus: () => { comp.lastPraStatus = 'submitted'; },
}, methods);

const t0 = Date.now();
const times = {};
comp.runAutoPrintChain(7, 'takeaway');
// grace waits 1.2s before its first probe — give the chain time to finish.
await sleep(1800);
await sleep(50);

if (events.length < 2) fail(`expected receipt + kot enqueues, got: ${JSON.stringify(events)}`);
if (!events.includes('kot')) fail(`KOT never enqueued: ${JSON.stringify(events)}`);
if (!events.includes('bill')) fail(`receipt never enqueued: ${JSON.stringify(events)}`);
// Task 994: KOT must NOT wait behind praPrintGrace (first probe is 1.2s out).
// A KOT enqueued after ~1s means it queued behind the fiscal grace again.
if (times.kot > 1000) fail(`KOT delayed behind fiscal grace (${times.kot}ms after chain start): ${JSON.stringify(events)}`);
// Receipt must still respect grace: bill enqueued AFTER the pending→submitted
// probe resolved (i.e. after the first 1.2s grace wait), never before.
if (times.bill < 1000) fail(`receipt skipped fiscal grace (enqueued at ${times.bill}ms while pra_status was pending)`);

console.log(`PRINT-ORDER OK: silent fast path enqueued ${JSON.stringify(events)} — KOT immediate (${times.kot}ms), receipt after grace (${times.bill}ms).`);
