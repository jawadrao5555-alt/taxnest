// Task 1246: capture KDS, FBR stock, FBR reports screenshots (dev).
const { execSync } = require('child_process');
const { chromium } = require('playwright-core');
const DEMO_PASS = process.env.VIDEO_DEMO_PASS;
if (!DEMO_PASS) { console.error('VIDEO_DEMO_PASS env var missing — source .local/qa-creds.env first.'); process.exit(1); }
const CHROMIUM = execSync('which chromium').toString().trim();
const BASE = 'http://127.0.0.1:5000';

async function loginCookies(email, pass, loginPath) {
  const jar = {};
  const setJar = (res) => {
    const sc = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
    sc.forEach(c => { const [kv] = c.split(';'); const i = kv.indexOf('='); jar[kv.slice(0, i)] = kv.slice(i + 1); });
  };
  const hdr = () => ({ 'X-Forwarded-Proto': 'https', 'Cookie': Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ') });
  let res = await fetch(BASE + loginPath, { headers: hdr() });
  setJar(res);
  const html = await res.text();
  const token = html.match(/name="_token" value="([^"]+)"/)[1];
  res = await fetch(BASE + loginPath, {
    method: 'POST', redirect: 'manual',
    headers: { ...hdr(), 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _token: token, login: email, password: pass }).toString(),
  });
  setJar(res);
  console.log(loginPath, 'POST →', res.status, res.headers.get('location'));
  return Object.entries(jar).map(([name, value]) => ({ name, value: decodeURIComponent(value), domain: '127.0.0.1', path: '/' }));
}

async function shoot(ctx, url, out, opts = {}) {
  const page = await ctx.newPage();
  await page.addInitScript(() => { ['pos', 'fbrpos', 'di'].forEach(s => localStorage.setItem('tnPushAsked_' + s, '1')); });
  await page.goto(BASE + url, { waitUntil: 'networkidle', timeout: 45000 }).catch(e => console.log('goto warn', e.message));
  console.log(url, '→', page.url());
  // dismiss What's New / fixed overlays
  try { const b = page.locator('text=Samajh Gaya'); if (await b.count()) { await b.first().click(); await page.waitForTimeout(400); } } catch (e) {}
  await page.waitForTimeout(opts.wait || 1500);
  await page.evaluate(() => { document.querySelectorAll('button,a').forEach(el => { const t=(el.textContent||'').replace(/\s+/g,' ').trim(); if (t.length < 25 && /failed/i.test(t)) el.remove(); }); document.querySelectorAll('div,button,a').forEach(d => { const s = getComputedStyle(d); if (s.position === 'fixed' && parseInt(s.zIndex || 0) > 30) d.remove(); }); });
  await page.screenshot({ path: out, fullPage: false });
  console.log('saved', out);
  await page.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

  // FBR demo shop
  const fbrCookies = await loginCookies('fbrdemo@nestpos.pk', DEMO_PASS, '/fbr-pos/login');
  const fbrCtx = await browser.newContext({ viewport: { width: 1440, height: 860 }, deviceScaleFactor: 1.5, extraHTTPHeaders: { 'X-Forwarded-Proto': 'https' } });
  await fbrCtx.addCookies(fbrCookies);
  await shoot(fbrCtx, '/fbr-pos/stock', '/tmp/fbr-stock.png');
  await shoot(fbrCtx, '/fbr-pos/reports', '/tmp/fbr-reports.png', { wait: 3000 });
  await shoot(fbrCtx, '/fbr-pos/products', '/tmp/fbr-products.png');
  await fbrCtx.close();

  // Restaurant KDS (shared restaurant module)
  const posCookies = await loginCookies('videoresto@nestpos.pk', DEMO_PASS, '/pos/login');
  const posCtx = await browser.newContext({ viewport: { width: 1440, height: 860 }, deviceScaleFactor: 1.5, extraHTTPHeaders: { 'X-Forwarded-Proto': 'https' } });
  await posCtx.addCookies(posCookies);
  await shoot(posCtx, '/pos/restaurant/kds', '/tmp/fbr-kds.png', { wait: 2500 });
  await posCtx.close();

  await browser.close();
})().then(() => process.exit(0)).catch(e => { console.error(e); process.exit(1); });
