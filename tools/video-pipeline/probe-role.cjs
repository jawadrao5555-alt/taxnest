// Quick probe: can a confined role reach /pos/tutorials, and is it still
// confined elsewhere? Usage: node probe-role.cjs <email> <password>
// Reuses the TLS proxy trick from record.cjs (app forces https).
const { chromium } = require('playwright-core');
const https = require('https');
const http = require('http');
const { execSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const EMAIL = process.argv[2] || 'ahmed@alnoor-demo.pk';
const PASS = process.argv[3] || 'Demo@1234';
const APP = 'http://127.0.0.1:5000';
const PROXY_PORT = 5444;

async function main() {
  // self-signed cert
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'probe-tls-'));
  execSync(
    `openssl req -x509 -newkey rsa:2048 -keyout ${dir}/key.pem -out ${dir}/cert.pem -days 2 -nodes -subj "/CN=localhost" 2>/dev/null`
  );
  const proxy = https.createServer(
    { key: fs.readFileSync(`${dir}/key.pem`), cert: fs.readFileSync(`${dir}/cert.pem`) },
    (req, res) => {
      const opt = {
        hostname: '127.0.0.1',
        port: 5000,
        path: req.url,
        method: req.method,
        headers: { ...req.headers, 'x-forwarded-proto': 'https', host: `localhost:${PROXY_PORT}` },
      };
      const up = http.request(opt, (ur) => {
        res.writeHead(ur.statusCode, ur.headers);
        ur.pipe(res);
      });
      req.pipe(up);
      up.on('error', () => res.end());
    }
  );
  await new Promise((r) => proxy.listen(PROXY_PORT, r));

  const browser = await chromium.launch({
    executablePath: execSync('which chromium').toString().trim(),
    args: ['--ignore-certificate-errors', '--no-sandbox'],
  });
  const page = await (await browser.newContext({ ignoreHTTPSErrors: true })).newPage();
  const B = `https://localhost:${PROXY_PORT}`;

  await page.goto(`${B}/pos/login`, { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(1500); console.log('login page url:', page.url().replace(B, ''));
  await page.fill('#login', EMAIL);
  await page.fill('#password', PASS);
  try {
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }), page.click('button[type=submit]')]);
  } catch (e) {
    console.log('login nav note:', String(e).split('\n')[0]);
  }
  console.log('after-login url:', page.url().replace(B, ''));

  await page.goto(`${B}/pos/tutorials`, { waitUntil: 'networkidle' });
  console.log('tutorials url:', page.url().replace(B, ''), '| h1:', (await page.locator('h1').first().textContent().catch(() => '?'))?.trim());

  await page.goto(`${B}/pos/team`, { waitUntil: 'networkidle' });
  console.log('team url (should be confined away):', page.url().replace(B, ''));

  await browser.close();
  proxy.close();
}
main().catch((e) => {
  console.error(e);
  process.exit(1);
});
