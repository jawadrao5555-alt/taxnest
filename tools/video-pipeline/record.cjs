// NestPOS tutorial recorder (Task 231).
// Usage: node tools/video-pipeline/record.cjs tools/video-pipeline/scenarios/<slug>.json
// Requires: out/<slug>/tts/durations.cjson (run durations.cjs after TTS).
const fs = require('fs');
const path = require('path');
const { execSync, spawn } = require('child_process');
const { chromium } = require('playwright-core');
const http = require('http');

const scenarioPath = process.argv[2];
if (!scenarioPath) { console.error('usage: record.cjs <scenario.json>'); process.exit(1); }
// Credentials are never stored in scenario JSONs (public repo): the literal
// placeholder {{VIDEO_DEMO_PASS}} is substituted from the environment here.
const rawScenario = fs.readFileSync(scenarioPath, 'utf8');
if (rawScenario.includes('{{VIDEO_DEMO_PASS}}') && !process.env.VIDEO_DEMO_PASS) {
  console.error('VIDEO_DEMO_PASS env var missing — source .local/qa-creds.env first.');
  process.exit(1);
}
const scenario = JSON.parse(rawScenario.split('{{VIDEO_DEMO_PASS}}').join(process.env.VIDEO_DEMO_PASS || ''));
const OUT = path.join(__dirname, 'out', scenario.slug);
fs.mkdirSync(OUT, { recursive: true });

const durations = JSON.parse(fs.readFileSync(path.join(OUT, 'tts', 'durations.cjson'), 'utf8'));
const PAD_MS = 1400;        // silence tail after each scene's narration
const AUDIO_LEAD_MS = 500;  // narration starts this long after the scene begins

const CHROMIUM = process.env.CHROMIUM_BIN
  || execSync('which chromium').toString().trim();

const CARD_CSS = `
  html,body{margin:0;height:100%;font-family:Inter,system-ui,sans-serif;}
  .card{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;
    background:radial-gradient(1200px 700px at 50% 35%, #0e6275 0%, #0A4D5C 55%, #073541 100%);color:#fff;text-align:center;}
  .logo{font-size:76px;font-weight:900;letter-spacing:-1px;}
  .logo span{color:#E7BF3B;}
  .rule{width:120px;height:4px;background:#E7BF3B;border-radius:2px;margin:28px 0;}
  .heading{font-size:44px;font-weight:800;}
  .sub{font-size:26px;color:#cfe7ec;margin-top:14px;font-weight:500;}
`;

function cardHtml(card, title) {
  const heading = card.heading || 'NestPOS';
  const sub = card.sub || title || '';
  return `<!doctype html><html><head><meta charset="utf-8"><style>${CARD_CSS}</style></head>
  <body><div class="card">
    <div class="logo">Nest<span>POS</span></div>
    <div class="rule"></div>
    <div class="heading">${heading}</div>
    <div class="sub">${sub}</div>
  </div></body></html>`;
}

// Synthetic cursor + ripple + highlight, injected on every page.
const CURSOR_JS = `(() => {
  if (window.__tnCur) return;
  const c = document.createElement('div');
  c.id = '__tn_cursor';
  c.style.cssText = 'position:fixed;left:960px;top:540px;width:26px;height:26px;z-index:2147483647;pointer-events:none;transform:translate(-4px,-2px);transition:none;';
  c.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24"><path d="M4 2 L4 19 L8.5 15.5 L11.5 22 L14.5 20.6 L11.5 14.2 L17.5 14 Z" fill="#f59e0b" stroke="#7c2d12" stroke-width="1.4"/></svg>';
  window.__tnCur = { x: 960, y: 540, el: c };
  window.__tnMove = (x, y) => { window.__tnCur.x = x; window.__tnCur.y = y; c.style.left = x + 'px'; c.style.top = y + 'px'; };
  const add = () => { if (document.body && !document.getElementById('__tn_cursor')) document.body.appendChild(c); };
  add();
  document.addEventListener('DOMContentLoaded', add);
  try { new MutationObserver(add).observe(document.documentElement, { childList: true }); } catch (e) {}
  window.__tnRipple = (x, y) => {
    const r = document.createElement('div');
    r.style.cssText = 'position:fixed;left:' + (x - 22) + 'px;top:' + (y - 22) + 'px;width:44px;height:44px;border-radius:50%;border:3px solid #7c3aed;background:rgba(124,58,237,.25);z-index:2147483646;pointer-events:none;transition:transform .45s ease-out,opacity .45s ease-out;';
    document.body.appendChild(r);
    requestAnimationFrame(() => { r.style.transform = 'scale(2.1)'; r.style.opacity = '0'; });
    setTimeout(() => r.remove(), 520);
  };
  window.__tnHalo = (rect) => {
    const h = document.createElement('div');
    h.style.cssText = 'position:fixed;left:' + (rect.x - 6) + 'px;top:' + (rect.y - 6) + 'px;width:' + (rect.w + 12) + 'px;height:' + (rect.h + 12) + 'px;border:3px solid #E7BF3B;border-radius:12px;box-shadow:0 0 0 6px rgba(231,191,59,.25);z-index:2147483645;pointer-events:none;opacity:0;transition:opacity .25s;';
    document.body.appendChild(h);
    requestAnimationFrame(() => { h.style.opacity = '1'; });
    setTimeout(() => { h.style.opacity = '0'; setTimeout(() => h.remove(), 300); }, 1600);
  };
})();`;

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function moveCursorTo(page, x, y, ms = 450) {
  const from = await page.evaluate(() => ({ x: window.__tnCur.x, y: window.__tnCur.y })).catch(() => ({ x: 960, y: 540 }));
  const steps = Math.max(8, Math.round(ms / 16));
  for (let i = 1; i <= steps; i++) {
    const t = i / steps, e = t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; // easeInOutQuad
    await page.evaluate(([px, py]) => window.__tnMove(px, py), [from.x + (x - from.x) * e, from.y + (y - from.y) * e]);
    await sleep(ms / steps);
  }
}

async function center(page, selector) {
  // Always target the VISIBLE match — POS screens keep hidden duplicates
  // (mobile/desktop variants) of many controls in the DOM.
  const loc = page.locator(selector).locator('visible=true').first();
  await loc.waitFor({ state: 'visible', timeout: 15000 });
  await loc.scrollIntoViewIfNeeded();
  const b = await loc.boundingBox();
  if (!b) throw new Error('no box for ' + selector);
  return { x: b.x + b.width / 2, y: b.y + b.height / 2, box: b, loc };
}

async function runAction(page, a) {
  switch (a.do) {
    case 'goto':
      await page.goto(scenario.baseUrl + a.url, { waitUntil: 'networkidle' });
      await page.evaluate(CURSOR_JS);
      break;
    case 'wait': await sleep(a.ms || 500); break;
    case 'waitFor': await page.locator(a.selector).first().waitFor({ state: 'visible', timeout: 20000 }); break;
    case 'click': {
      const p = await center(page, a.selector);
      await moveCursorTo(page, p.x, p.y, a.moveMs || 500);
      await page.evaluate(([x, y]) => window.__tnRipple(x, y), [p.x, p.y]);
      await sleep(120);
      await p.loc.click({ force: !!a.force });
      await sleep(a.after || 400);
      break;
    }
    case 'type': {
      const p = await center(page, a.selector);
      await moveCursorTo(page, p.x, p.y, 400);
      await page.evaluate(([x, y]) => window.__tnRipple(x, y), [p.x, p.y]);
      await p.loc.click();
      await p.loc.pressSequentially(a.text, { delay: a.delay || 70 });
      await sleep(a.after || 300);
      break;
    }
    case 'press': {
      if (a.selector) await page.locator(a.selector).first().press(a.key);
      else await page.keyboard.press(a.key);
      await sleep(a.after || 400);
      break;
    }
    case 'highlight': {
      const p = await center(page, a.selector);
      await moveCursorTo(page, p.x, p.y, 450);
      await page.evaluate((r) => window.__tnHalo(r), { x: p.box.x, y: p.box.y, w: p.box.width, h: p.box.height });
      await sleep(a.after || 900);
      break;
    }
    case 'eval': await page.evaluate(a.js); break;
    case 'select': {
      // Choose a native <select> option (value or label) with a visible cursor
      // move (e.g. sale-screen category picker).
      const p = await center(page, a.selector);
      await moveCursorTo(page, p.x, p.y, 450);
      await page.evaluate(([x, y]) => window.__tnRipple(x, y), [p.x, p.y]);
      const sel = page.locator(a.selector).locator('visible=true').first();
      await sel.selectOption(a.value !== undefined ? { value: a.value } : { label: a.label });
      await sleep(a.after || 600);
      break;
    }
    case 'viewport': // phone-frame scenes (rider portal): page is letterboxed in the 16:9 video
      await page.setViewportSize({ width: a.width || 430, height: a.height || 1080 });
      await sleep(a.after || 400);
      break;
    case 'clearCookies': // switch role/session mid-video (waiter → kitchen → cashier)
      await page.context().clearCookies();
      await sleep(200);
      break;
    case 'upload': { // file input (may be hidden) — real upload, no fake
      await page.locator(a.selector).first().setInputFiles(path.resolve(__dirname, a.file));
      await sleep(a.after || 600);
      break;
    }
    case 'offline': { // REAL network offline/online toggle (Task 234)
      await page.context().setOffline(!!a.on);
      // fire the browser events the app listens to
      await page.evaluate((on) => window.dispatchEvent(new Event(on ? 'offline' : 'online')), !!a.on);
      await sleep(a.after || 600);
      break;
    }
    case 'setFile': {
      // Attach a file to an <input type=file> (Excel-import scenes) with a
      // visible cursor move to the input first.
      const p = await center(page, a.selector);
      await moveCursorTo(page, p.x, p.y, 450);
      await page.evaluate(([x, y]) => window.__tnRipple(x, y), [p.x, p.y]);
      await page.locator(a.selector).locator('visible=true').first().setInputFiles(a.path);
      await sleep(a.after || 600);
      break;
    }
    case 'scroll': {
      // Smooth-scroll the page (or a container) so long report pages can be toured.
      // Supports both arg styles: `dy` (mouse-wheel, Task 234 scenes) and
      // `by` (smooth scrollBy, Task 232 scenes).
      if (a.selector) {
        const p = await center(page, a.selector); // scrollIntoViewIfNeeded already ran
        await sleep(a.after || 700);
      } else if (a.dy !== undefined) {
        await page.mouse.wheel(0, a.dy || 400);
        await sleep(a.after || 500);
      } else {
        await page.evaluate((y) => window.scrollBy({ top: y, behavior: 'smooth' }), a.by || 600);
        await sleep(a.after || 900);
      }
      break;
    }
    default: throw new Error('unknown action ' + a.do);
  }
}

// The app force-generates https URLs (APP_URL is https + forceScheme), so plain
// http recording breaks on the first redirect. Run a tiny self-signed TLS proxy
// in front of the dev server (port 5000) and record over real https — the
// browser accepts the cert via ignoreHTTPSErrors, the app sees
// X-Forwarded-Proto: https and keeps generating same-host https URLs.
const PROXY_PORT = 5443;
const UPSTREAM_PORT = 5000;
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
  process.on('exit', () => { try { proxy.close(); } catch (e) {} });

  const browser = await chromium.launch({
    executablePath: CHROMIUM,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--force-device-scale-factor=1', '--hide-scrollbars', '--mute-audio',
      '--disable-features=HttpsUpgrades,HttpsFirstModeV2,HttpsFirstBalancedModeAutoEnable'],
  });
  const ctx = await browser.newContext({
    viewport: { width: 1920, height: 1080 },
    recordVideo: { dir: OUT, size: { width: 1920, height: 1080 } },
    ignoreHTTPSErrors: true,
  });
  ctx.setDefaultTimeout(20000);
  await ctx.addInitScript(CURSOR_JS);
  const page = await ctx.newPage();
  // Auto-accept confirm()/alert() dialogs (e.g. day-close "are you sure").
  page.on('dialog', (d) => d.accept().catch(() => {}));
  await page.goto('about:blank');
  const t0 = Date.now(); // ≈ recording start (context creation happened ~instantly before)

  const timeline = [];
  for (const scene of scenario.scenes) {
    const audioMs = Math.round((durations[scene.id] || 0) * 1000);
    const start = Date.now() - t0;
    console.log(`▶ ${scene.id} @${(start / 1000).toFixed(1)}s (audio ${(audioMs / 1000).toFixed(1)}s)`);

    if (scene.card) {
      await page.setContent(cardHtml(scene.card, scenario.title));
    } else {
      for (const a of (scene.actions || [])) await runAction(page, a);
    }
    const actionsDone = Date.now() - t0;
    // Hold until narration (starting at start+AUDIO_LEAD_MS) + pad has finished.
    const sceneEnd = Math.max(start + AUDIO_LEAD_MS + audioMs + PAD_MS, actionsDone + 600, start + (scene.minMs || 0));
    await sleep(Math.max(0, sceneEnd - (Date.now() - t0)));
    timeline.push({ id: scene.id, startMs: start, audioAtMs: start + AUDIO_LEAD_MS, endMs: Date.now() - t0 });
  }

  await ctx.close();
  const video = await page.video().path();
  const dest = path.join(OUT, 'capture.webm');
  fs.copyFileSync(video, dest); fs.unlinkSync(video);
  fs.writeFileSync(path.join(OUT, 'timeline.json'), JSON.stringify(timeline, null, 2));
  await browser.close();
  console.log('✓ wrote', dest, 'and timeline.json');
  process.exit(0); // playwright leaves the event loop alive in this env — force a clean exit
})().catch(e => { console.error(e); process.exit(1); });
