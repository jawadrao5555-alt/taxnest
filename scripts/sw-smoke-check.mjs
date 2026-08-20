#!/usr/bin/env node
// Service-worker regression check. Besides proving the handlers survive ordinary
// events, this exercises the sale-document trust boundary: only complete PRA/FBR
// universal documents may enter/leave SALE_CACHE, hard reload prefers the network,
// and an unavailable/invalid document produces a recoverable page rather than a
// blank cached shell.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const src = readFileSync(new URL('../public/sw.js', import.meta.url), 'utf8');
const ORIGIN = 'https://taxnest.com.pk';
const listeners = {};
const cacheStores = new Map();
let networkHandler;

const fail = (message) => {
  console.error('SW SMOKE FAIL: ' + message);
  process.exit(1);
};
const assert = (condition, message) => { if (!condition) fail(message); };

class MockHeaders {
  constructor(init = {}) {
    this.values = {};
    if (init instanceof MockHeaders) init = init.values;
    for (const [key, value] of Object.entries(init || {})) {
      this.values[String(key).toLowerCase()] = String(value);
    }
  }
  get(key) { return this.values[String(key).toLowerCase()] || null; }
}

class MockResponse {
  constructor(body = '', init = {}) {
    this.body = String(body ?? '');
    this.status = Number(init.status ?? 200);
    this.ok = this.status >= 200 && this.status < 300;
    this.redirected = !!init.redirected;
    this.url = init.url || '';
    this.headers = new MockHeaders(init.headers || {});
  }
  clone() {
    return new MockResponse(this.body, {
      status: this.status,
      redirected: this.redirected,
      url: this.url,
      headers: this.headers,
    });
  }
  async text() { return this.body; }
  static error() { return new MockResponse('', { status: 0 }); }
}

const cacheKey = (input) => new URL(typeof input === 'string' ? input : input.url, ORIGIN).href;
const getStore = (name) => {
  if (!cacheStores.has(name)) {
    const rows = new Map();
    cacheStores.set(name, {
      rows,
      match: async (input) => rows.get(cacheKey(input))?.clone(),
      put: async (input, response) => { rows.set(cacheKey(input), response.clone()); },
      addAll: async () => {},
      keys: async () => [...rows.keys()],
      delete: async (input) => rows.delete(cacheKey(input)),
    });
  }
  return cacheStores.get(name);
};

const sandbox = {
  console,
  URL,
  Response: MockResponse,
  Headers: MockHeaders,
  fetch: async (request, init) => networkHandler(request, init),
  caches: {
    open: async (name) => getStore(name),
    keys: async () => [...cacheStores.keys()],
    delete: async (name) => cacheStores.delete(name),
    match: async (input) => {
      for (const cache of cacheStores.values()) {
        const hit = await cache.match(input);
        if (hit) return hit;
      }
      return undefined;
    },
  },
  location: { origin: ORIGIN },
  setTimeout,
  clearTimeout,
};
sandbox.self = {
  addEventListener: (name, fn) => { listeners[name] = fn; },
  skipWaiting: () => {},
  clients: { claim: () => {}, matchAll: async () => [] },
  registration: { scope: ORIGIN + '/', showNotification: async () => {} },
  location: sandbox.location,
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(src, sandbox, { filename: 'sw.js' });

assert(typeof listeners.fetch === 'function', 'no fetch listener registered');
assert(typeof listeners.message === 'function', 'no message listener registered');

const validHtml = (variant, label = 'valid') =>
  '<!doctype html><html><body>' +
  `<div data-tn-sale-document="${variant}" data-tn-sale-root>${label}</div>` +
  '<script>window.tnBootFp={}; function restaurantPos(){ return {}; }</script>' +
  'x'.repeat(5000) + '</body></html>';

const htmlResponse = (body, {
  variant = null,
  status = 200,
  redirected = false,
  url = '',
} = {}) => new MockResponse(body, {
  status,
  redirected,
  url,
  headers: {
    'content-type': 'text/html; charset=UTF-8',
    ...(variant ? { 'x-taxnest-sale-document': variant } : {}),
  },
});

const request = (path, {
  mode = 'navigate',
  method = 'GET',
  cache = 'default',
  headers = {},
} = {}) => ({
  url: new URL(path, ORIGIN).href,
  mode,
  method,
  cache,
  headers: new MockHeaders(headers),
});

async function dispatchFetch(req) {
  let responsePromise;
  const waits = [];
  listeners.fetch({
    request: req,
    // A real FetchEvent has no data property. Leaving it absent guards the old
    // regression where fetch handling accidentally read message-event state.
    respondWith: (value) => { responsePromise = Promise.resolve(value); },
    waitUntil: (value) => { waits.push(Promise.resolve(value)); },
    resultingClientId: 'smoke-client',
  });
  await Promise.allSettled(waits);
  return responsePromise ? responsePromise : undefined;
}

async function dispatchMessage(data) {
  const waits = [];
  listeners.message({
    data,
    waitUntil: (value) => { waits.push(Promise.resolve(value)); },
    source: null,
  });
  await Promise.all(waits);
}

// 1. Ordinary same-origin routes and cross-origin assets must not throw.
networkHandler = async (input) => htmlResponse('<html>ordinary</html>', { url: typeof input === 'string' ? input : input.url });
for (const req of [
  request('/pos/dashboard'),
  request('/pos/logout'),
  request('/build/assets/app.css', { mode: 'no-cors' }),
  request('https://cdn.example.com/x.js', { mode: 'no-cors' }),
]) {
  await dispatchFetch(req);
}

// Locate the versioned sale cache after the first intercepted sale navigation.
networkHandler = async (input) => {
  const path = new URL(typeof input === 'string' ? input : input.url, ORIGIN).pathname;
  const variant = path.startsWith('/fbr-pos') ? 'fbr' : 'pra';
  return htmlResponse(validHtml(variant, 'network-first'), { variant, url: new URL(path, ORIGIN).href });
};
let served = await dispatchFetch(request('/pos/invoice/create'));
assert((await served.text()).includes('network-first'), 'fresh valid PRA document was not served');
const saleName = [...cacheStores.keys()].find((name) => name.endsWith('-sale'));
assert(saleName, 'SALE_CACHE was not opened');
const saleCache = getStore(saleName);
assert(await saleCache.match('/pos/invoice/create'), 'fresh valid PRA document was not cached');

// 2. A complete cached document remains usable offline.
networkHandler = async () => { throw new Error('offline'); };
served = await dispatchFetch(request('/pos/invoice/create'));
assert((await served.text()).includes('network-first'), 'validated cached PRA document was not served offline');

// 3. An old login/empty/partial cache entry is evicted. With a healthy network,
// it is replaced by a validated sale document; without one, a clear recovery
// response is returned instead of replaying the malformed shell.
await saleCache.put('/pos/invoice/create', htmlResponse('<html>login shell</html>'));
networkHandler = async () => htmlResponse(validHtml('pra', 'replaced-invalid'), {
  variant: 'pra', url: ORIGIN + '/pos/invoice/create',
});
served = await dispatchFetch(request('/pos/invoice/create'));
assert((await served.text()).includes('replaced-invalid'), 'invalid cached document was not replaced from network');

await saleCache.put('/pos/invoice/create', htmlResponse('<html>partial</html>'));
networkHandler = async () => { throw new Error('flaky'); };
served = await dispatchFetch(request('/pos/invoice/create'));
assert(served.status === 503, 'invalid cache + failed network did not return recovery response');
assert((await served.text()).includes('Sale screen needs a fresh copy'), 'recovery response has no actionable message');

// 4. Browser hard refresh must prefer a fresh valid network document, while a
// transient network failure may safely fall back to the previously validated copy.
await saleCache.put('/pos/invoice/create', htmlResponse(validHtml('pra', 'old-cache'), { variant: 'pra' }));
networkHandler = async () => htmlResponse(validHtml('pra', 'hard-refresh-fresh'), {
  variant: 'pra', url: ORIGIN + '/pos/invoice/create',
});
served = await dispatchFetch(request('/pos/invoice/create', { cache: 'reload' }));
assert((await served.text()).includes('hard-refresh-fresh'), 'hard refresh did not prefer fresh network document');
assert((await (await saleCache.match('/pos/invoice/create')).text()).includes('hard-refresh-fresh'), 'hard refresh did not replace sale cache');

networkHandler = async () => { throw new Error('intermittent outage'); };
served = await dispatchFetch(request('/pos/invoice/create', { cache: 'reload' }));
assert((await served.text()).includes('hard-refresh-fresh'), 'hard refresh did not fall back to validated cache on outage');

// 5. A hard-refresh auth redirect must win over and delete the personal sale
// cache, preventing a logged-out/shared terminal from replaying the old user.
networkHandler = async () => htmlResponse('<html>login</html>', {
  redirected: true, url: ORIGIN + '/pos/login',
});
served = await dispatchFetch(request('/pos/invoice/create', { cache: 'reload' }));
assert(served.redirected, 'login redirect was hidden behind sale cache');
assert(!(await saleCache.match('/pos/invoice/create')), 'personal sale cache survived auth redirect');

// 6. PRA and FBR markers are not interchangeable; both supported variants prime.
await saleCache.put('/fbr-pos/create', htmlResponse(validHtml('pra', 'wrong-variant'), { variant: 'pra' }));
networkHandler = async (input) => {
  const path = new URL(typeof input === 'string' ? input : input.url, ORIGIN).pathname;
  const variant = path.startsWith('/fbr-pos') ? 'fbr' : 'pra';
  return htmlResponse(validHtml(variant, 'prime-' + variant), { variant, url: ORIGIN + path });
};
served = await dispatchFetch(request('/fbr-pos/create'));
assert((await served.text()).includes('prime-fbr'), 'FBR route accepted PRA cache marker');

await sandbox.caches.delete(saleName);
await dispatchMessage({ type: 'TN_PRIME_SALE_CACHE', url: '/pos/invoice/create' });
const primed = getStore(saleName);
assert(await primed.match('/pos/invoice/create'), 'prime message did not cache PRA document');
assert(await primed.match('/fbr-pos/create'), 'prime message did not cache FBR document');

// Defensive message paths must remain harmless.
await dispatchMessage({ type: 'SKIP_WAITING' });
await dispatchMessage(undefined);

console.log('SW SMOKE OK: validated PRA/FBR sale documents, hard-refresh network preference, offline fallback, invalid-cache eviction, auth cleanup, recovery UI, prime-cache and ordinary events.');