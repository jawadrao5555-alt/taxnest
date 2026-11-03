import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import {launchLocalBrowser} from '../lib/local-browser.mjs';

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
