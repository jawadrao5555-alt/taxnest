// Debug probe: replicate the team add-member flow and dump what happens.
const { execSync } = require('child_process');
const { chromium } = require('playwright-core');
const path = require('path'); const fs = require('fs'); const http = require('http');
async function startTlsProxy() {
  const https = require('https');
  const certDir = path.join(__dirname, 'out', '.cert');
  fs.mkdirSync(certDir, { recursive: true });
  const key = path.join(certDir, 'key.pem'), crt = path.join(certDir, 'cert.pem');
  if (!fs.existsSync(key)) {
    execSync(`openssl req -x509 -newkey rsa:2048 -keyout "${key}" -out "${crt}" -days 30 -nodes -subj "/CN=127.0.0.1" 2>/dev/null`);
  }
  const server = https.createServer({ key: fs.readFileSync(key), cert: fs.readFileSync(crt) }, (req, res) => {
    const headers = { ...req.headers, 'x-forwarded-proto': 'https', host: '127.0.0.1:5443' };
    const up = http.request({ host: '127.0.0.1', port: 5000, path: req.url, method: req.method, headers }, (ur) => { res.writeHead(ur.statusCode, ur.headers); ur.pipe(res); });
    up.on('error', () => { res.writeHead(502); res.end('upstream error'); });
    req.pipe(up);
  });
  await new Promise((r) => server.listen(5443, '127.0.0.1', r));
  return server;
}
(async () => {
  await startTlsProxy();
  const b = await chromium.launch({ executablePath: execSync('which chromium').toString().trim(), headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage', '--ignore-certificate-errors'] });
  const ctx = await b.newContext({ viewport: { width: 1920, height: 1080 }, ignoreHTTPSErrors: true, baseURL: 'https://127.0.0.1:5443' });
  const page = await ctx.newPage();
  await page.goto('/pos/login');
  await page.fill('#login', 'videodemo@nestpos.pk');
  await page.fill('#password', 'NestPOS@Demo1');
  await page.click('button[type=submit]');
  await page.waitForSelector('.prod-card', { timeout: 20000 });
  await page.goto('/pos/team');
  await page.click("button:has-text('Member add karein')");
  await page.fill('form input[name=name]', 'Bilal Hussain');
  await page.fill('form input[name=email]', 'bilal@alnoor-demo.pk');
  await page.fill('form input[name=phone]', '03001234567');
  await page.fill('form input[name=password]', 'Demo@1234');
  await page.locator('form select[name=pos_role]').selectOption('pos_cashier');
  await Promise.all([
    page.waitForNavigation({ timeout: 15000 }).catch(e => console.log('nav err', e.message)),
    page.click("form button:has-text('Account banayein')"),
  ]);
  await page.waitForTimeout(1000);
  const flash = await page.locator('.bg-red-50, .bg-red-100, .text-red-600, .bg-green-50, .bg-emerald-50').allInnerTexts().catch(() => []);
  console.log('URL:', page.url());
  console.log('FLASH:', JSON.stringify(flash.slice(0, 6)));
  console.log('HAS BILAL ROW:', await page.locator("tr:has-text('Bilal Hussain')").count());
  await page.screenshot({ path: 'out/probe-team.png' });
  await b.close();
})();
