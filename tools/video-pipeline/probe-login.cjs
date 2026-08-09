// Debug probe: login through the same TLS proxy record.cjs uses, log everything.
const fs = require('fs');
const path = require('path');
const http = require('http');
const { execSync } = require('child_process');
const { chromium } = require('playwright-core');

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
      res.writeHead(ur.statusCode, ur.headers);
      ur.pipe(res);
    });
    up.on('error', () => { res.writeHead(502); res.end('upstream error'); });
    req.pipe(up);
  });
  await new Promise((r) => server.listen(PROXY_PORT, '127.0.0.1', r));
  return server;
}

(async () => {
  const proxy = await startTlsProxy();
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_BIN,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 720 } });
  const page = await ctx.newPage();
  page.on('response', (r) => {
    const u = r.url();
    if (u.includes('/pos/') || r.status() >= 300) {
      console.log('RESP', r.status(), r.request().method(), u.slice(0, 90), '| set-cookie:', (r.headers()['set-cookie'] || '').slice(0, 100));
    }
  });
  await page.goto('https://127.0.0.1:5443/pos/login', { waitUntil: 'networkidle' });
  console.log('cookies after GET:', (await ctx.cookies()).map(c => `${c.name} secure=${c.secure} samesite=${c.sameSite}`).join(' ; '));
  await page.fill('#login', 'videodemo@nestpos.pk');
  await page.fill('#password', 'NestPOS@Demo1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle', timeout: 20000 }).catch(e => console.log('nav err', e.message)),
    page.click('button[type=submit]'),
  ]);
  console.log('after submit URL:', page.url());
  console.log('cookies after POST:', (await ctx.cookies()).map(c => `${c.name} secure=${c.secure}`).join(' ; '));
  const body = (await page.content()).slice(0, 400).replace(/\s+/g, ' ');
  console.log('BODY:', body);
  await page.goto('https://127.0.0.1:5443/pos/suggestions', { waitUntil: 'networkidle' }).catch(e => console.log('goto err', e.message));
  console.log('suggestions URL:', page.url());
  await browser.close();
  proxy.close();
  process.exit(0);
})();
