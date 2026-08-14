// Task 669: screenshot the Day Close auto-close checkbox + cutoff selector card (dev).
// Login done via curl (playwright can't follow the forced-https redirect); session
// cookie injected into the browser context (cookie-inject pattern).
const fs = require('fs');
const { execSync } = require('child_process');
const { chromium } = require('playwright-core');

const CHROMIUM = execSync('which chromium').toString().trim();
const BASE = 'http://127.0.0.1:5000';

// Parse curl cookie jar
const jar = fs.readFileSync('/tmp/jar', 'utf8');
const cookies = jar.split('\n').filter(l => l && !l.startsWith('#') || l.startsWith('#HttpOnly_'))
  .map(l => l.replace(/^#HttpOnly_/, '').split('\t'))
  .filter(p => p.length === 7)
  .map(p => ({ name: p[5], value: p[6], domain: '127.0.0.1', path: p[2] }));
console.log('cookies:', cookies.map(c => c.name).join(', '));

(async () => {
  const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, extraHTTPHeaders: { 'X-Forwarded-Proto': 'https' } });
  await ctx.addCookies(cookies);
  const page = await ctx.newPage();
  await page.goto(BASE + '/pos/day-close', { waitUntil: 'load' });
  console.log('day-close url:', page.url());
  await page.waitForSelector('#dc-auto-close-chk', { timeout: 15000 });
  try { const btn = page.locator('text=Samajh Gaya'); if (await btn.count()) { await btn.first().click(); await page.waitForTimeout(500); } } catch (e) {}
  await page.evaluate(() => { document.querySelectorAll('div').forEach(d => { const s = getComputedStyle(d); if (s.position === 'fixed' && parseInt(s.zIndex || 0) > 50) d.remove(); }); });
  const card = page.locator('#dc-auto-close-chk').locator('xpath=ancestor::div[contains(@class,"rounded-xl")][1]');
  await page.evaluate(() => { const c = document.getElementById('dc-auto-close-chk'); c.checked = true; });
  await card.scrollIntoViewIfNeeded();
  await page.waitForTimeout(800);
  await card.screenshot({ path: '/tmp/dayclose-auto-card.png' });
  console.log('saved /tmp/dayclose-auto-card.png');
  await browser.close();
})().then(() => process.exit(0)).catch(e => { console.error(e); process.exit(1); });
