// Real ServiceWorker/CacheStorage regression, isolated loopback fixture only.
export async function privateCollectionCacheCheck(originalPage, { baseUrl, path, screenshotName, saveEvidenceScreenshot }) {
  if (!['127.0.0.1','localhost','::1','[::1]'].includes(new URL(baseUrl).hostname)) throw new Error('Private cache test requires loopback');
  const context=await originalPage.context().browser().newContext({storageState:await originalPage.context().storageState(),viewport:originalPage.viewportSize(),serviceWorkers:'allow'});
  await context.route('**/*',route => ['127.0.0.1','localhost','::1','[::1]'].includes(new URL(route.request().url()).hostname) ? route.continue() : route.abort('blockedbyclient'));
  const page=await context.newPage();
  try {
  await page.goto(baseUrl + path, {waitUntil:'domcontentloaded'});
  await page.evaluate(async () => { await navigator.serviceWorker.register('/sw.js', {scope:'/'}); await navigator.serviceWorker.ready; });
  await page.reload({waitUntil:'domcontentloaded'});
  await page.waitForFunction(() => !!navigator.serviceWorker.controller);
  const marker='SYNTHETIC-STALE-PRIVATE-COLLECTION';
  await page.evaluate(async ({path,marker}) => {
    const keys=await caches.keys();
    for(const name of [...keys,'taxnest-old-private-proof-runtime']) {
      const cache=await caches.open(name);
      await cache.put(new Request(new URL(path,location.origin)), new Response('<html><body>'+marker+'</body></html>',{headers:{'Content-Type':'text/html'}}));
    }
  },{path,marker});
  try {
    await page.context().setOffline(true);
    const response=await page.goto(baseUrl+path,{waitUntil:'domcontentloaded'});
    if(response?.status()!==503 || !(response.headers()['cache-control']||'').includes('no-store')) throw new Error('Private offline navigation did not return safe503 no-store');
    const body=await page.locator('body').innerText().catch(()=> '');
    if(body.includes(marker)) throw new Error('Service worker replayed stale private collection HTML while offline');
    await saveEvidenceScreenshot(page,screenshotName);
  } finally {
    await page.context().setOffline(false);
    await page.goto(baseUrl+path,{waitUntil:'domcontentloaded'});
    await page.evaluate(async path => { for(const key of await caches.keys()) { const cache=await caches.open(key); await cache.delete(new Request(new URL(path,location.origin))); } },path);
  }
  } finally { await context.close(); }
}
