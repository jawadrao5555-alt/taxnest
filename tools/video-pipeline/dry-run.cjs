// Walk a scenario's actions WITHOUT recording, reporting every selector that
// does not resolve instead of aborting on the first one.
//
// A failed take costs a full ~5 minute record cycle to discover one bad
// selector. This replays the same clicks against the same app in ~40 seconds
// and lists all of them at once.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const scenarioPath = process.argv[2];
if (!scenarioPath) { console.error('usage: dry-run.cjs <scenario.json>'); process.exit(1); }

let raw = fs.readFileSync(scenarioPath, 'utf8');
raw = raw.split('{{VIDEO_DEMO_PASS}}').join(process.env.VIDEO_DEMO_PASS || '');
const scenario = JSON.parse(raw);
const BASE = scenario.baseUrl || 'http://127.0.0.1:5000';
const TIMEOUT = 6000;

const problems = [];

async function run(page, scene, a) {
  const where = `${scene.id} · ${a.do} ${a.selector || a.url || ''}`;
  try {
    switch (a.do) {
      case 'goto':
        // Absolute URLs escape baseUrl (the LAN tutorial visits the agent
        // window and the pairing page, which are not served by the POS app).
        await page.goto(/^https?:\/\//i.test(a.url) ? a.url : BASE + a.url, { waitUntil: 'domcontentloaded', timeout: 20000 });
        await page.waitForTimeout(600);
        break;
      case 'viewport':
        await page.setViewportSize({ width: a.width || 1600, height: a.height || 900 });
        break;
      case 'wait': await page.waitForTimeout(Math.min(a.ms || 500, 800)); break;
      case 'waitFor':
      case 'highlight':
        await page.locator(a.selector).first().waitFor({ state: 'visible', timeout: TIMEOUT });
        break;
      case 'click':
        await page.locator(a.selector).first().click({ timeout: TIMEOUT });
        await page.waitForTimeout(700);
        break;
      case 'type': {
        // textFrom is resolved here too, not stubbed: a wrong value would sail
        // through the fill and then fail three scenes later as a mystery.
        let text = a.text;
        if (a.textFrom) {
          const body = await (await fetch(a.textFrom.url)).json();
          text = String(body[a.textFrom.key] || '');
        }
        await page.locator(a.selector).first().fill(text != null ? text : '', { timeout: TIMEOUT });
        break;
      }
      case 'offline':
        // Real offline, same as the recorder — otherwise every selector that
        // only exists while the line is down looks like a scenario bug.
        await page.context().setOffline(!!a.on);
        await page.evaluate((on) => window.dispatchEvent(new Event(on ? 'offline' : 'online')), !!a.on);
        await page.waitForTimeout(1500);
        break;
      case 'eval':
        await page.evaluate(a.js);
        await page.waitForTimeout(400);
        break;
      case 'select':
        await page.locator(a.selector).first().selectOption(a.value, { timeout: TIMEOUT });
        await page.waitForTimeout(600);
        break;
      case 'scroll':
        await page.evaluate((by) => window.scrollBy(0, by), a.by || 0);
        await page.waitForTimeout(250);
        break;
      case 'clearCookies':
        // Match record.cjs exactly for multi-role scenarios (waiter → cashier).
        // Without this, /pos/login redirects back to the first logged-in role
        // and every selector after the switch reports a misleading failure.
        await page.context().clearCookies();
        await page.waitForTimeout(200);
        break;
      default: break;
    }
  } catch (e) {
    // Optional actions exist precisely because they may not be there.
    if (a.optional) { console.log(`  (optional skipped: ${a.do} ${a.selector || ''})`); return; }
    problems.push(`${where}  [at ${page.url()}]  ->  ${String(e.message || e).split('\n')[0]}`);
    // A shot of the moment it broke turns "selector timed out" into an
    // obvious answer (nag popup, wrong page, still loading).
    try {
      const dir = path.join(__dirname, 'out', '.dryrun');
      fs.mkdirSync(dir, { recursive: true });
      await page.screenshot({ path: path.join(dir, `${scene.id}-${a.do}.png`) });
    } catch (e2) {}
  }
}

// Most scenarios are recorded over the https proxy record.cjs puts in front of
// the dev server; the preflight has to speak the same URL or every goto dies on
// a bare connection error instead of reporting real selector problems.
async function startTlsProxy() {
  const https = require('https');
  const http = require('http');
  const { execSync } = require('child_process');
  const certDir = path.join(__dirname, 'out', '.cert');
  fs.mkdirSync(certDir, { recursive: true });
  const key = path.join(certDir, 'key.pem'), crt = path.join(certDir, 'cert.pem');
  if (!fs.existsSync(key)) {
    execSync(`openssl req -x509 -newkey rsa:2048 -keyout "${key}" -out "${crt}" -days 30 -nodes -subj "/CN=127.0.0.1" 2>/dev/null`);
  }
  const server = https.createServer({ key: fs.readFileSync(key), cert: fs.readFileSync(crt) }, (req, res) => {
    const headers = { ...req.headers, 'x-forwarded-proto': 'https', host: '127.0.0.1:5443' };
    const up = http.request({ host: '127.0.0.1', port: 5000, path: req.url, method: req.method, headers }, (ur) => {
      res.writeHead(ur.statusCode, ur.headers);
      ur.pipe(res);
    });
    up.on('error', () => { res.writeHead(502); res.end('upstream error'); });
    req.pipe(up);
  });
  await new Promise((r) => server.listen(5443, '127.0.0.1', r));
  return server;
}

(async () => {
  const proxy = BASE.startsWith('https://127.0.0.1:5443') ? await startTlsProxy() : null;
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_BIN || undefined,
    args: ['--no-sandbox', '--disable-features=HttpsUpgrades,HttpsFirstModeV2,HttpsFirstBalancedModeAutoEnable'],
  });
  const page = await browser.newPage({ viewport: { width: 1600, height: 900 }, ignoreHTTPSErrors: true });
  page.on('dialog', (d) => d.accept().catch(() => {}));

  for (const scene of scenario.scenes) {
    if (!scene.actions) continue;
    process.stdout.write(`· ${scene.id}\n`);
    for (const a of scene.actions) await run(page, scene, a);
  }

  console.log('\n===== SELECTOR REPORT =====');
  if (!problems.length) console.log('all actions resolved');
  else problems.forEach(p => console.log('FAIL  ' + p));
  console.log(`final url: ${page.url()}`);

  await browser.close();
  if (proxy) { try { proxy.close(); } catch (e) {} }
  // Non-zero on any unresolved action, so this can gate a recording run
  // instead of merely reporting after the fact.
  process.exit(problems.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
