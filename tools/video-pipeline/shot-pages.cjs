// Screenshot a list of panel pages as the demo user, through the same TLS
// proxy record.cjs uses. For scenario design: see what a page really looks
// like at 1920×1080 before writing selectors for it.
//
//   VIDEO_DEMO_PASS=... node shot-pages.cjs <loginPath> <email> <outDir> <path>[ <path>...]
//   e.g. node shot-pages.cjs /fbr-pos/login pharmacydemo@nestpos.pk /tmp/ph /fbr-pos/dashboard /fbr-pos/universal
const fs = require('fs');
const path = require('path');
const http = require('http');
const { execSync } = require('child_process');
const { chromium } = require('playwright-core');

const [loginPath, email, outDir, ...pages] = process.argv.slice(2);
const PASS = process.env.VIDEO_DEMO_PASS;
if (!loginPath || !email || !outDir || !pages.length || !PASS) {
  console.error('usage: VIDEO_DEMO_PASS=.. node shot-pages.cjs <loginPath> <email> <outDir> <path>...');
  process.exit(1);
}
fs.mkdirSync(outDir, { recursive: true });
const CHROMIUM = process.env.CHROMIUM_BIN || execSync('which chromium').toString().trim();
const PROXY_PORT = 5443, UPSTREAM_PORT = 5000;
async function startTlsProxy() {
  const https = require('https');
  const certDir = path.join(__dirname, 'out', '.cert');
  fs.mkdirSync(certDir, { recursive: true });
  const key = path.join(certDir, 'key.pem'), crt = path.join(certDir, 'cert.pem');
  if (!fs.existsSync(key)) {
    execSync(`openssl req -x509 -newkey rsa:2048 -keyout "${key}" -out "${crt}" -days 30 -nodes -subj "/CN=127.0.0.1" 2>/dev/null`);
  }
  const server = https.createServer({ key: fs.readFileSync(key), cert: fs.readFileSync(crt) }, (req, res) => {
    const headers = { ...req.headers, 'x-forwarded-proto': 'https', host: `127.0.0.1:${PROXY_PORT}` };
    const up = http.request({ host: '127.0.0.1', port: UPSTREAM_PORT, path: req.url, method: req.method, headers }, (ur) => {
      res.writeHead(ur.statusCode, ur.headers); ur.pipe(res);
    });
    up.on('error', () => { res.writeHead(502); res.end('upstream error'); });
    req.pipe(up);
  });
  await new Promise((r) => server.listen(PROXY_PORT, '127.0.0.1', r));
  return server;
}
const BASE = `https://127.0.0.1:${PROXY_PORT}`;

(async () => {
  const proxy = await startTlsProxy();
  const browser = await chromium.launch({ executablePath: CHROMIUM, headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage', '--hide-scrollbars'] });
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1920, height: 1080 } });
  const page = await ctx.newPage();
  await page.goto(BASE + loginPath, { waitUntil: 'networkidle' });
  const form = page.locator(`form[action*='${loginPath}']`).first();
  await form.locator('input[name=login], #login').first().fill(email);
  await form.locator('input[name=password], #password').first().fill(PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }).catch(() => {}),
    form.locator('button[type=submit]').first().click(),
  ]);
  console.log('after login:', page.url());
  try { const d = page.locator('#tn-domain-move-dismiss'); if (await d.count()) { await d.first().click({ timeout: 3000 }); } } catch (e) {}
  for (const p of pages) {
    const [urlPart, ...evals] = p.split('|');
    await page.goto(BASE + urlPart, { waitUntil: 'load', timeout: 45000 }).catch(e => console.log('goto err', urlPart, e.message));
    await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(900);
    for (const ev of evals) { try { await page.evaluate(ev); await page.waitForTimeout(700); } catch (e) { console.log('eval err', e.message); } }
    const name = urlPart.replace(/[^a-z0-9]+/gi, '_').replace(/^_+|_+$/g, '') || 'root';
    const file = path.join(outDir, name + '.png');
    await page.screenshot({ path: file, fullPage: false });
    console.log('shot', page.url(), '->', file);
  }
  await browser.close(); proxy.close();
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
