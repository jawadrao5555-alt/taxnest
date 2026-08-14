const fs = require('fs');
const { execSync } = require('child_process');
const { chromium } = require('playwright-core');
const CHROMIUM = execSync('which chromium').toString().trim();
const jar = fs.readFileSync('/tmp/jar', 'utf8');
const cookies = jar.split('\n').map(l => l.replace(/^#HttpOnly_/, '').split('\t')).filter(p => p.length === 7).map(p => ({ name: p[5], value: p[6], domain: '127.0.0.1', path: p[2] }));
(async () => {
  const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, extraHTTPHeaders: { 'X-Forwarded-Proto': 'https' } });
  await ctx.addCookies(cookies);
  const page = await ctx.newPage();
  await page.goto('http://127.0.0.1:5000/pos/dashboard', { waitUntil: 'load' });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: '/tmp/popup-check.png' });
  await browser.close();
})().then(() => process.exit(0)).catch(e => { console.error(e); process.exit(1); });
