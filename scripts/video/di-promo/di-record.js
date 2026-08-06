// DI Promo — B-roll recorder using Playwright + Chromium.
//
// Usage (from repo root):
//   node scripts/video/di-promo/di-record.js
//   DI_OUT_DIR=/custom/path node scripts/video/di-promo/di-record.js
//
// Outputs (gitignored — large binaries):
//   .local/video-studio/di-promo/register-take.webm   (unauthenticated /register form)
//   .local/video-studio/di-promo/register-timeline.json
//   .local/video-studio/di-promo/take.webm             (authenticated B-roll)
//   .local/video-studio/di-promo/timeline.json
//
// Environment:
//   DI_OUT_DIR   — output base dir (default: .local/video-studio relative to repo root)
//   CHROMIUM     — Chromium executable path (default: Nix-store path below)
//   DI_BASE_URL  — app base URL (default: http://127.0.0.1:5000)
//   DI_EMAIL     — demo account email (default: dev-only demo shop)
//   DI_PASS      — demo account password (default: dev-only demo shop)
//
// Demo credentials (didemo@nestpos.pk / NestPOS@Demo1) are a dev-only demo shop;
// they are acceptable in source as they grant access to no real customer data.
// For CI or other environments override with DI_EMAIL / DI_PASS env vars.
//
// CSRF fix: GET /login Set-Cookie forwarded into POST /login Cookie header.
// SW fix:   suppress service-worker registration in addInitScript.

const { chromium } = require('playwright-core');
const fs   = require('fs');
const http = require('http');
const path = require('path');

const CHROME   = process.env.CHROMIUM
  || '/nix/store/qa9cnw4v5xkxyip6mb9kxqfq1z4x2dx1-chromium-138.0.7204.100/bin/chromium';
const BASE     = process.env.DI_BASE_URL || 'http://127.0.0.1:5000';
const EMAIL    = process.env.DI_EMAIL    || 'didemo@nestpos.pk';
const PASS     = process.env.DI_PASS     || 'NestPOS@Demo1';

const SCRIPT_DIR = __dirname;
const OUT_BASE   = process.env.DI_OUT_DIR
  || path.join(SCRIPT_DIR, '../../../.local/video-studio');
const OUT        = path.join(OUT_BASE, 'di-promo');

fs.mkdirSync(OUT,            { recursive: true });
fs.mkdirSync(OUT + '/takes', { recursive: true });

const sleep = ms => new Promise(r => setTimeout(r, ms));

let curX = 640, curY = 360;
async function glide(page, x, y, ms) {
  const steps = Math.max(10, Math.round(ms / 30));
  for (let i = 1; i <= steps; i++) {
    const t = i / steps, e = t * t * (3 - 2 * t);
    await page.mouse.move(curX + (x - curX) * e, curY + (y - curY) * e);
    await sleep(ms / steps);
  }
  curX = x; curY = y;
}

const marks = [];
let t0 = 0;
const nowT = () => (Date.now() - t0) / 1000;
const mark  = name => {
  const t = nowT();
  marks.push({ name, t });
  console.log('MARK', name, t.toFixed(2));
};

async function nav(page, url, idleMs = 5000, settleSleep = 1200) {
  await page.evaluate(() => { try { window.stop(); } catch (_) {} }).catch(() => {});
  await sleep(400);
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForLoadState('networkidle', { timeout: idleMs }).catch(() => {});
  await sleep(settleSleep);
}

async function clickBtn(page, pat, ms = 700) {
  const pos = await page.evaluate(p => {
    const re = new RegExp(p, 'i');
    for (const el of document.querySelectorAll('button,a[role=button],input[type=submit]')) {
      const r = el.getBoundingClientRect();
      if (r.width < 8 || el.disabled || getComputedStyle(el).display === 'none') continue;
      if (re.test((el.innerText || el.value || '').replace(/\s+/g, ' ').trim()))
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    }
    return null;
  }, pat);
  if (!pos) { console.log('btn not found:', pat); return false; }
  await glide(page, pos.x, pos.y, ms);
  await page.mouse.click(pos.x, pos.y);
  return true;
}

// CSRF-correct login: forward GET /login session cookie into POST.
async function loginViaCookies(ctx) {
  const { html, getCookies } = await new Promise((res, rej) =>
    http.get(BASE + '/login', r => {
      let d = '';
      r.on('data', c => d += c);
      r.on('end', () => res({ html: d, getCookies: r.headers['set-cookie'] || [] }));
    }).on('error', rej));

  const tok = (html.match(/name="_token"\s+value="([^"]+)"/) || [])[1];
  if (!tok) throw new Error('_token not found on GET /login');

  const cookieHdr = getCookies.map(c => c.split(';')[0]).filter(Boolean).join('; ');
  const body = `_token=${encodeURIComponent(tok)}&login=${encodeURIComponent(EMAIL)}&password=${encodeURIComponent(PASS)}`;

  const { postCookies, status } = await new Promise((res, rej) => {
    const req = http.request(BASE + '/login', {
      method: 'POST',
      headers: {
        'Content-Type':      'application/x-www-form-urlencoded',
        'Content-Length':    Buffer.byteLength(body),
        'Referer':           BASE + '/login',
        'X-Forwarded-Proto': 'https',
        'Cookie':            cookieHdr,
      },
    }, r => {
      let d = '';
      r.on('data', c => d += c);
      r.on('end', () => res({ postCookies: r.headers['set-cookie'] || [], status: r.statusCode }));
    });
    req.on('error', rej);
    req.write(body);
    req.end();
  });

  console.log('POST /login status:', status, '— cookies:', postCookies.length);
  if (!postCookies.length) throw new Error(`Login POST ${status} — no Set-Cookie`);

  const cookieMap = new Map();
  for (const raw of [...getCookies, ...postCookies]) {
    const seg = raw.split(';')[0]; const ei = seg.indexOf('=');
    const name = seg.slice(0, ei).trim(); const val = seg.slice(ei + 1).trim();
    if (name && val) cookieMap.set(name, val);
  }
  const parsed = [...cookieMap.entries()].map(([name, value]) => ({
    name, value, domain: '127.0.0.1', path: '/',
  }));
  await ctx.addCookies(parsed);
  console.log('LOGIN OK — injected', parsed.length, 'cookies');
}

function swSuppressor() {
  return () => {
    try { localStorage.setItem('tnPushAsked_di', '1'); } catch (_) {}
    if ('serviceWorker' in navigator)
      navigator.serviceWorker.register = () => Promise.resolve({});
  };
}

// ── Harvest the last-created webm from takes/ ────────────────────────────────
function harvestTake(excludePattern) {
  const all = fs.readdirSync(OUT + '/takes')
    .filter(f => f.endsWith('.webm') && (!excludePattern || !f.includes(excludePattern)))
    .map(f => ({ f, mt: fs.statSync(OUT + '/takes/' + f).mtimeMs }))
    .sort((a, b) => b.mt - a.mt);
  return all.length ? OUT + '/takes/' + all[0].f : null;
}

// ── PART 1: /register recording (unauthenticated) ────────────────────────────
async function recordRegister(browser) {
  console.log('\n=== RECORDING /register (unauthenticated) ===');
  const ctx = await browser.newContext({
    viewport:    { width: 1280, height: 720 },
    recordVideo: { dir: OUT + '/takes', size: { width: 1280, height: 720 } },
  });
  await ctx.addInitScript(() => {
    if ('serviceWorker' in navigator)
      navigator.serviceWorker.register = () => Promise.resolve({});
  });

  const page  = await ctx.newPage();
  const regT0 = Date.now();
  const regMarks = [];
  const regMark  = name => {
    const t = (Date.now() - regT0) / 1000;
    regMarks.push({ name, t });
    console.log('MARK', name, t.toFixed(2));
  };

  await page.goto(BASE + '/register', { waitUntil: 'domcontentloaded', timeout: 25000 });
  await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
  await sleep(1000);
  regMark('R1');

  const nameField = await page.$('input[name=name], input[id=name]');
  if (nameField) {
    await nameField.click(); await sleep(300);
    await page.type('input[name=name]', 'Al-Farooq Traders', { delay: 75 });
    await sleep(400);
  }
  const emailField = await page.$('input[name=email], input[type=email]');
  if (emailField) {
    await emailField.click(); await sleep(300);
    await page.type('input[name=email], input[type=email]', 'alfarooq@business.pk', { delay: 65 });
    await sleep(400);
  }
  const passField = await page.$('input[name=password], input[type=password]');
  if (passField) {
    await passField.click(); await sleep(300);
    await page.type('input[name=password]', '••••••••', { delay: 60 });
    await sleep(400);
  }
  await glide(page, 640, 350, 600);
  await sleep(500);
  await page.evaluate(() => window.scrollBy({ top: 150, behavior: 'smooth' }));
  await sleep(800);
  regMark('R1END');
  await sleep(500);

  const videoPath = await page.video()?.path();
  await ctx.close();
  await sleep(1200);

  const src = (videoPath && fs.existsSync(videoPath)) ? videoPath : harvestTake('register');
  let regWebm = null;
  if (src) {
    regWebm = OUT + '/register-take.webm';
    fs.renameSync(src, regWebm);
    const kb = Math.round(fs.statSync(regWebm).size / 1024);
    console.log('REGISTER TAKE saved:', regWebm, kb + 'KB');
    fs.writeFileSync(OUT + '/register-timeline.json', JSON.stringify(regMarks, null, 2));
  } else {
    console.error('WARNING: register-take.webm not saved');
  }
}

// ── PART 2: authenticated B-roll ─────────────────────────────────────────────
async function recordMain(browser) {
  console.log('\n=== RECORDING authenticated B-roll ===');
  const ctx = await browser.newContext({
    viewport:    { width: 1280, height: 720 },
    recordVideo: { dir: OUT + '/takes', size: { width: 1280, height: 720 } },
  });
  await ctx.addInitScript(swSuppressor());
  await loginViaCookies(ctx);

  const page = await ctx.newPage();
  t0 = Date.now();

  // seg04 — /dashboard
  await nav(page, BASE + '/dashboard', 6000, 1000);
  mark('C1');
  await page.mouse.move(640, 300);
  await sleep(900);
  await page.evaluate(() => window.scrollBy({ top: 220, behavior: 'smooth' }));
  await sleep(1300);
  await page.evaluate(() => window.scrollBy({ top: -220, behavior: 'smooth' }));
  await sleep(700);
  mark('C1END');

  // seg05 — /invoice/create
  await nav(page, BASE + '/invoice/create', 5000, 1000);
  mark('C2');
  const buyerName = await page.$('input[name=buyer_name]');
  if (buyerName) {
    await buyerName.click(); await sleep(300);
    await page.type('input[name=buyer_name]', 'Tariq Electronics Pvt Ltd', { delay: 55 });
    await sleep(500);
  }
  const buyerNtn = await page.$('input[name=buyer_ntn]');
  if (buyerNtn) {
    await buyerNtn.click(); await sleep(300);
    await page.type('input[name=buyer_ntn]', '4100001001', { delay: 60 });
    await sleep(500);
  }
  await page.evaluate(() => window.scrollBy({ top: 220, behavior: 'smooth' }));
  await sleep(900);
  const descField = await page.$('input[name="items[0][description]"]');
  if (descField) {
    await descField.click(); await sleep(300);
    await page.type('input[name="items[0][description]"]', 'UPS 1000VA Inverter Unit', { delay: 50 });
    await sleep(400);
  }
  await page.evaluate(() => window.scrollBy({ top: 120, behavior: 'smooth' }));
  await sleep(600);
  mark('C3');

  // seg06 — draft invoice → Submit to FBR modal (record spinner state; do NOT confirm)
  await nav(page, BASE + '/invoices?tab=draft', 4000, 800);
  const draftHref = await page.evaluate(() => {
    for (const a of document.querySelectorAll('a[href*="/invoice/"]')) {
      if (/\/invoice\/\d+$/.test(a.getAttribute('href') || ''))
        return a.getAttribute('href');
    }
    return null;
  });
  console.log('Draft href:', draftHref);

  if (draftHref) {
    await nav(page, BASE + draftHref, 4000, 800);
    mark('DRAFT_OPEN');
    await page.keyboard.press('Escape');
    await sleep(300);
    const opened = await clickBtn(page, 'Submit to FBR', 1000);
    if (opened) {
      await sleep(900);
      mark('MODAL_OPEN');
      await sleep(2600);   // film spinner state
      mark('MODAL_END');
      await page.keyboard.press('Escape');
      await sleep(700);
    }
  }

  // seg07 — completed (locked) invoice: FBR number + QR code
  // Navigate to ?tab=completed — default tab is 'draft' (InvoiceController line 38).
  await nav(page, BASE + '/invoices?tab=completed', 4000, 800);
  const subHref = await page.evaluate(() => {
    for (const a of document.querySelectorAll('a[href*="/invoice/"]')) {
      if (/\/invoice\/\d+$/.test(a.getAttribute('href') || ''))
        return a.getAttribute('href');
    }
    return null;
  });
  console.log('Completed invoice href:', subHref);

  if (subHref) {
    await nav(page, BASE + subHref, 4000, 800);
    mark('C4');
    await page.mouse.move(640, 300);
    await sleep(700);
    await page.evaluate(() => window.scrollBy({ top: 250, behavior: 'smooth' }));
    await sleep(1000);
    await page.evaluate(() => window.scrollBy({ top: 250, behavior: 'smooth' }));
    await sleep(900);
    await page.mouse.move(640, 420);
    await sleep(500);
    await page.evaluate(() => window.scrollBy({ top: -500, behavior: 'smooth' }));
    await sleep(600);
    mark('C4END');
  }

  // seg09a — invoice list (completed tab — green FBR Production badges)
  await nav(page, BASE + '/invoices?tab=completed', 4000, 800);
  mark('C5');
  await page.mouse.move(640, 300);
  await sleep(700);
  await page.evaluate(() => window.scrollBy({ top: 220, behavior: 'smooth' }));
  await sleep(1000);
  await page.evaluate(() => window.scrollBy({ top: 160, behavior: 'smooth' }));
  await sleep(800);

  // seg09b — /dashboard final pan
  await nav(page, BASE + '/dashboard', 5000, 800);
  mark('C6');
  await page.mouse.move(640, 280);
  await sleep(900);
  await page.evaluate(() => window.scrollBy({ top: 180, behavior: 'smooth' }));
  await sleep(1000);
  mark('END');
  await sleep(500);

  fs.writeFileSync(OUT + '/timeline.json', JSON.stringify(marks, null, 2));
  console.log('TIMELINE written —', marks.length, 'marks');
  marks.forEach(m => console.log('  ', m.name, m.t.toFixed(2)));

  const videoPath = await page.video()?.path();
  await ctx.close();
  await sleep(1500);

  const src = (videoPath && fs.existsSync(videoPath)) ? videoPath : harvestTake('register');
  if (src) {
    fs.renameSync(src, OUT + '/take.webm');
    const kb = Math.round(fs.statSync(OUT + '/take.webm').size / 1024);
    console.log('TAKE saved:', OUT + '/take.webm', kb + 'KB');
  } else {
    console.error('WARNING: take.webm not saved');
    console.log('takes/ contents:', fs.readdirSync(OUT + '/takes'));
  }
}

// ── main ─────────────────────────────────────────────────────────────────────
(async () => {
  const browser = await chromium.launch({
    executablePath: CHROME,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  await recordRegister(browser);
  await recordMain(browser);
  await browser.close();
  console.log('\nRECORD DONE');
  process.exit(0);
})().catch(e => {
  console.error('RECORD FAIL:', e.message);
  console.error(e.stack);
  process.exit(1);
});
