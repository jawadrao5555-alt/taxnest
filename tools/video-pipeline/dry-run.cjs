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
        await page.goto(BASE + a.url, { waitUntil: 'domcontentloaded', timeout: 20000 });
        await page.waitForTimeout(600);
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
      case 'type':
        await page.locator(a.selector).first().fill(a.text, { timeout: TIMEOUT });
        break;
      case 'select':
        await page.locator(a.selector).first().selectOption(a.value, { timeout: TIMEOUT });
        await page.waitForTimeout(600);
        break;
      case 'scroll':
        await page.evaluate((by) => window.scrollBy(0, by), a.by || 0);
        await page.waitForTimeout(250);
        break;
      default: break;
    }
  } catch (e) {
    problems.push(`${where}  ->  ${String(e.message || e).split('\n')[0]}`);
  }
}

(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_BIN || undefined,
    args: ['--no-sandbox'],
  });
  const page = await browser.newPage({ viewport: { width: 1600, height: 900 } });
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
  // Non-zero on any unresolved action, so this can gate a recording run
  // instead of merely reporting after the fact.
  process.exit(problems.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
