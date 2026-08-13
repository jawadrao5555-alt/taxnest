#!/usr/bin/env node
// Service-worker smoke check (Task 655 review): loads public/sw.js in a stubbed
// SW environment and asserts the fetch handler survives an ordinary same-origin
// navigation (regression guard: a stray edit once replaced `new URL(req.url)`
// with `e.data.url`, which throws on EVERY intercepted fetch and silently kills
// offline-first + logout cache hygiene), and that the TN_PRIME_SALE_CACHE
// message handler runs without touching undefined fields.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const src = readFileSync(new URL('../public/sw.js', import.meta.url), 'utf8');

const listeners = {};
const noopCache = {
  match: async () => undefined,
  put: async () => {},
  addAll: async () => {},
  keys: async () => [],
  delete: async () => true,
};
const sandbox = {
  console,
  URL,
  Response: class { constructor(body, init) { this.body = body; this.init = init; } },
  Headers: class { get() { return ''; } },
  fetch: async () => ({ ok: true, redirected: false, status: 200, headers: { get: () => 'text/html' }, clone() { return this; } }),
  caches: { open: async () => noopCache, keys: async () => [], delete: async () => true, match: async () => undefined },
  location: { origin: 'https://taxnest.com.pk' },
  setTimeout, clearTimeout,
};
sandbox.self = {
  addEventListener: (name, fn) => { listeners[name] = fn; },
  skipWaiting: () => {},
  clients: { claim: () => {}, matchAll: async () => [] },
  registration: { scope: 'https://taxnest.com.pk/' },
  location: sandbox.location,
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(src, sandbox, { filename: 'sw.js' });

const fail = (msg) => { console.error('SW SMOKE FAIL: ' + msg); process.exit(1); };

if (typeof listeners.fetch !== 'function') fail('no fetch listener registered');
if (typeof listeners.message !== 'function') fail('no message listener registered');

// 1. Ordinary same-origin navigation must not throw and must not read e.data.
const mkFetchEvent = (url, mode) => ({
  request: { url, mode, method: 'GET', headers: { get: () => '' } },
  // NOTE: real FetchEvent has NO `data` property — leave it undefined on purpose.
  respondWith: () => {},
  waitUntil: () => {},
});
for (const [url, mode] of [
  ['https://taxnest.com.pk/pos/dashboard', 'navigate'],
  ['https://taxnest.com.pk/pos/invoice/create', 'navigate'],
  ['https://taxnest.com.pk/fbr-pos/create', 'navigate'],
  ['https://taxnest.com.pk/pos/logout', 'navigate'],
  ['https://taxnest.com.pk/build/assets/app.css', 'no-cors'],
  ['https://cdn.example.com/x.js', 'no-cors'], // cross-origin: must return early
]) {
  try {
    listeners.fetch(mkFetchEvent(url, mode));
  } catch (err) {
    fail(`fetch handler threw for ${url}: ${err.message}`);
  }
}

// 2. TN_PRIME_SALE_CACHE message (both PRA + FBR url variants) must not throw.
for (const data of [
  { type: 'TN_PRIME_SALE_CACHE', url: '/pos/invoice/create' },
  { type: 'TN_PRIME_SALE_CACHE', url: '/fbr-pos/create' },
  { type: 'SKIP_WAITING' },
  undefined, // defensive: message with no data must not kill the SW
]) {
  try {
    listeners.message({ data, waitUntil: () => {}, source: null });
  } catch (err) {
    fail(`message handler threw for data=${JSON.stringify(data)}: ${err.message}`);
  }
}

console.log('SW SMOKE OK: fetch + message handlers survive navigation, logout, prime-cache and cross-origin events.');
