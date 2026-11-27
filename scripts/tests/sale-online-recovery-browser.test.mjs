import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import http from 'node:http';
import {chromium, launchLocalBrowser, resolveChromiumPath} from '../lib/local-browser.mjs';

const worker = readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');
const saleHtml = (variant, label = 'current') => `<!doctype html><html><head>
<link rel="stylesheet" href="/build/assets/sale-${label}.css">
<script type="module" src="/build/assets/sale-${label}.js"></script>
</head><body><main data-tn-sale-document="${variant}" data-tn-sale-root>
<h1 data-ready>Opening sale</h1><button id="add">Add fictional item</button><output id="cart">0</output>
</main><script>window.tnBootFp={};function restaurantPos(){return {};}</script>
<!-- ${'fixture '.repeat(650)} --></body></html>`;

test('actual POS service worker opens online PRA/FBR despite failed offline prefetch, and retains safe recovery', {timeout: 90000}, async () => {
  let assetProbes = 0, oldAssetRequests = 0, partial = false;
  let firstProbeSeen = false, waitingBrowserAssets = [];
  let assetRequests = [];
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    res.setHeader('Cache-Control', 'no-store');
    if (url.pathname === '/sw.js') {
      res.setHeader('Content-Type', 'text/javascript');
      return res.end(worker);
    }
    if (url.pathname === '/install') {
      res.setHeader('Content-Type', 'text/html');
      return res.end('<!doctype html><script>navigator.serviceWorker.register("/sw.js")</script>');
    }
    if (url.pathname.startsWith('/build/')) {
      if (assetRequests.length < 40) assetRequests.push({path:url.pathname,destination:req.headers['sec-fetch-dest'],cache:req.headers['cache-control'],pragma:req.headers.pragma});
      if (url.pathname.includes('obsolete')) oldAssetRequests++;
      // Chromium also gives forwarded browser requests an empty destination.
      // The optional prefetch alone uses cache:no-store and sends no-cache;
      // ordinary forwarded script/style requests must remain usable online.
      if (req.headers['sec-fetch-dest'] === 'empty' && req.headers['cache-control'] === 'no-cache' && req.headers.pragma === 'no-cache') {
        assetProbes++;
        firstProbeSeen = true;
        res.writeHead(503, {'Content-Type':'text/plain'});
        res.end('Fictional optional prefetch unavailable');
        for (const send of waitingBrowserAssets.splice(0)) setImmediate(send);
        return;
      }
      const sendAsset = () => {
        if (url.pathname.endsWith('.css')) {
          res.setHeader('Content-Type', 'text/css');
          return res.end('body{font:18px sans-serif}main{padding:20px}button{padding:12px}');
        }
        res.setHeader('Content-Type', 'text/javascript');
        res.end('document.querySelector("[data-ready]").textContent="Sale ready";document.querySelector("#add").onclick=()=>document.querySelector("#cart").textContent=Number(document.querySelector("#cart").textContent)+1;');
      };
      // Keep the first browser cache write behind the failing prefetch so this
      // reproduction does not depend on which HTTP request wins a timing race.
      if (!firstProbeSeen) waitingBrowserAssets.push(sendAsset);
      else sendAsset();
      return;
    }
    if (url.pathname === '/pos/invoice/create' || url.pathname === '/fbr-pos/create') {
      const variant = url.pathname.startsWith('/fbr-pos') ? 'fbr' : 'pra';
      res.setHeader('Content-Type', 'text/html');
      if (partial) return res.end('<!doctype html><html><body>Incomplete fictional response</body></html>');
      res.setHeader('X-TaxNest-Sale-Document', variant);
      return res.end(saleHtml(variant));
    }
    res.setHeader('Content-Type', 'text/plain');
    res.end('Synthetic static installation resource');
  });
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  const base = `http://127.0.0.1:${server.address().port}`;
  let browser;
  try {
    ({browser} = await launchLocalBrowser());
    for (const variant of ['pra', 'fbr']) for (const width of [1366, 390]) {
      partial = false;
      firstProbeSeen = false;
      waitingBrowserAssets = [];
      assetRequests = [];
      const context = await browser.newContext({viewport:{width,height:844}});
      const page = await context.newPage();
      const errors = [], browserMessages = [];
      try {
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type() === 'error' && browserMessages.length < 20) browserMessages.push(message.text()); });
        await page.goto(base + '/install');
        await page.waitForFunction(() => !!navigator.serviceWorker.controller);
        const salePath = variant === 'pra' ? '/pos/invoice/create' : '/fbr-pos/create';
        const probesBefore = assetProbes;
        const response = await page.goto(base + salePath);
        assert.equal(response.status(), 200, 'healthy online sale must not become the recovery 503');
        await page.waitForFunction(() => document.querySelector('[data-ready]')?.textContent === 'Sale ready');
        await page.locator('#add').click();
        assert.equal(await page.locator('#cart').textContent(), '1');
        assert.ok(assetProbes > probesBefore, 'real worker asset-prefetch failure was exercised');
        assert.equal(await page.evaluate(async path => {
          for (const key of await caches.keys()) if (key.endsWith('-sale') && await (await caches.open(key)).match(path)) return true;
          return false;
        }, salePath), false, 'failed prefetch must not publish an offline copy');

        // An unprepared online document must not be offered during an outage.
        await context.setOffline(true);
        await page.goto(base + salePath);
        assert.match(await page.locator('body').innerText(), /You're Offline/);

        // A stale document with missing old hashes must go straight to fresh
        // HTML, without blocking navigation on an old asset network probe.
        await page.evaluate(async ({path, body, variant}) => {
          const key = (await caches.keys()).find(key => key.endsWith('-sale'));
          if (!key) throw new Error('sale cache missing');
          await (await caches.open(key)).put(path, new Response(body, {headers:{'Content-Type':'text/html','X-TaxNest-Sale-Document':variant}}));
        }, {path:salePath,body:saleHtml(variant,'obsolete'),variant});
        const obsoleteBefore = oldAssetRequests;
        // The real offline screen reloads on the online event. Observe that
        // navigation instead of racing it with another goto/evaluate call.
        await Promise.all([page.waitForNavigation({waitUntil:'load'}), context.setOffline(false)]);
        await page.waitForFunction(() => document.querySelector('[data-ready]')?.textContent === 'Sale ready');
        assert.equal(oldAssetRequests, obsoleteBefore, 'stale offline assets were fetched before fresh online HTML');

        // The browser has now cached the working assets. A subsequent valid
        // online visit may prepare a real offline document, which must open.
        await page.waitForFunction(async path => {
          const key = (await caches.keys()).find(key => key.endsWith('-sale'));
          return key && !!await (await caches.open(key)).match(path);
        }, salePath);
        await context.setOffline(true);
        await page.goto(base + salePath);
        await page.waitForFunction(() => document.querySelector('[data-ready]')?.textContent === 'Sale ready');
        await context.setOffline(false);

        // SetOffline(false) can dispatch the page's online recovery reload
        // after its promise resolves. Finish one explicit healthy navigation
        // before deleting the sale cache, otherwise that delayed reload can
        // race the malformed-response assertion below and replace its 503.
        await page.reload({waitUntil:'load'});
        await page.waitForFunction(() => document.querySelector('[data-ready]')?.textContent === 'Sale ready');

        // Malformed live HTML remains rejected. The actual recovery button
        // reaches a repaired server document without clearing session storage.
        await page.evaluate(async () => {
          localStorage.setItem('fictional-saved-setting', 'preserved');
          for (const key of await caches.keys()) if (key.endsWith('-sale')) await caches.delete(key);
        });
        partial = true;
        const rejected = await page.goto(base + salePath);
        assert.equal(rejected.status(), 503);
        assert.match(await page.locator('body').innerText(), /Sale screen needs a fresh copy/);
        partial = false;
        await page.getByRole('button', {name:'Try again', exact:true}).click();
        await page.waitForFunction(() => document.querySelector('[data-ready]')?.textContent === 'Sale ready');
        assert.equal(await page.evaluate(() => localStorage.getItem('fictional-saved-setting')), 'preserved');
        assert.deepEqual(errors, []);
      } catch (error) {
        const screen = await page.locator('body').innerText().catch(() => '<unavailable>');
        console.error('POS recovery fixture failure:', JSON.stringify({variant,width,assetRequests,errors,browserMessages,screen:screen.slice(0,500)}));
        throw error;
      } finally { await context.close(); }
    }
  } finally {
    if (browser) await browser.close();
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
  }
});

test('two cashier profiles cold-start and drain their own bills once on reconnect', {timeout: 120000}, async () => {
  const blade = readFileSync(new URL('../../resources/views/pos/universal.blade.php', import.meta.url), 'utf8');
  const methods = (start, end) => {
    const a = blade.indexOf(start), b = blade.indexOf(end, a);
    assert.ok(a >= 0 && b > a);
    return blade.slice(a, b);
  };
  // Exercise the actual IndexedDB and reconnect methods with a fictional receipt endpoint.
  const queue = methods('        idbOpen() {', '        // ─── OFFLINE CUSTOMER QUEUE') +
    methods('        async syncOfflineBills(', '        // Client-rendered interim receipt')
      .replace('{{ route("pos.invoice.store") }}', '/sale').replace('{{ csrf_token() }}', 'fictional');
  const billRequests = [];
  let offline = false;
  const server = http.createServer(async (req, res) => {
    if (offline) return req.socket.destroy();
    if (req.url === '/sw.js') {
      res.setHeader('Content-Type', 'application/javascript');
      return res.end(worker);
    }
    if (req.url === '/install') {
      res.setHeader('Content-Type', 'text/html');
      return res.end('<script>navigator.serviceWorker.register("/sw.js")</script>');
    }
    const cashier = /cashier=(one|two)/.exec(req.headers.cookie || '')?.[1];
    if (req.url === '/pos/invoice/create' && cashier) {
      res.setHeader('Content-Type', 'text/html');
      res.setHeader('X-TaxNest-Sale-Document', 'pra');
      return res.end(saleHtml('pra').replace('Opening sale', cashier));
    }
    if (req.url === '/sale' && req.method === 'POST') {
      let body = '';
      for await (const part of req) body += part;
      billRequests.push([cashier, JSON.parse(body).offline_uuid]);
      res.setHeader('Content-Type', 'application/json');
      return res.end('{"success":true}');
    }
    res.setHeader('Content-Type', req.url.endsWith('.js') ? 'application/javascript' : 'text/plain');
    res.end('');
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const url = 'http://127.0.0.1:' + server.address().port;
  const root = mkdtempSync(path.join(tmpdir(), 'nestpos-dual-'));
  const profiles = ['one', 'two'];
  const contexts = new Set();
  const open = async cashier => {
    const ctx = await chromium.launchPersistentContext(path.join(root, cashier), {
      executablePath: resolveChromiumPath(), headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    contexts.add(ctx);
    return ctx;
  };
  const close = async ctx => { await ctx.close(); contexts.delete(ctx); };
  try {
    for (const cashier of profiles) {
      const ctx = await open(cashier);
      await ctx.addCookies([{name:'cashier', value:cashier, domain:'127.0.0.1', path:'/', expires:Math.floor(Date.now()/1000)+3600}]);
      const page = await ctx.newPage();
      await page.goto(url + '/install');
      await page.waitForFunction(() => !!navigator.serviceWorker.controller);
      await page.goto(url + '/pos/invoice/create');
      await page.waitForFunction(async () => {
        const k = (await caches.keys()).find(name => name.endsWith('-sale'));
        return k && !!await (await caches.open(k)).match('/pos/invoice/create');
      });
      await close(ctx);
    }
    offline = true;
    const pages = [];
    for (const cashier of profiles) {
      const ctx = await open(cashier);
      const page = await ctx.newPage();
      await page.goto(url + '/pos/invoice/create');
      assert.equal(await page.locator('h1').innerText(), cashier);
      await page.addScriptTag({content: 'window.app = {' + queue + ' _offlineCompanyId:1, async syncOfflineCustomers(){}, _markConnectivity(){}, showToast(){}, loadLocalBills(){}, loadFailedBills(){} };'});
      await page.evaluate(id => app.idbPut({uuid:id, company_id:1, payload:{offline_uuid:id}}), 'sale-' + cashier);
      pages.push(page);
    }
    offline = false;
    await Promise.all(pages.map(page => page.evaluate(async () => {
      await Promise.all([app.syncOfflineBills(), app.syncOfflineBills()]);
      if ((await app.idbAllMine()).length) throw Error('Bill still queued');
    })));
    assert.deepEqual(billRequests.sort(), [['one','sale-one'], ['two','sale-two']]);
    // Login in one profile invalidates its cashier screen, the other stays usable.
    await pages[1].evaluate(() => fetch('/pos/login', {method:'POST'}));
    await pages[1].waitForFunction(async () => !(await caches.keys()).some(k=>k.endsWith('-sale')));
    offline = true;
    await pages[1].goto(url + '/pos/invoice/create');
    assert.match(await pages[1].locator('body').innerText(), /You're Offline|fresh copy/);
    await pages[0].goto(url + '/pos/invoice/create');
    assert.equal(await pages[0].locator('h1').innerText(), 'one');
  } finally {
    await Promise.all([...contexts].map(ctx=>ctx.close()));
    server.closeAllConnections();
    await new Promise(resolve=>server.close(resolve));
    rmSync(root, {recursive:true, force:true});
  }
});
