// Real Agent settings HTML on loopback; only the Electron IPC bridge is synthetic.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { assertLocalOnlyBaseUrl, launchLocalBrowser, saveEvidenceScreenshot } from './lib/local-browser.mjs';
const require = createRequire(import.meta.url);
const { createBuildInfo, parseBuildInfo } = require('../pra-agent/src/build-info');
const version = require('../pra-agent/package.json').version;
const identity = parseBuildInfo(createBuildInfo(version, new Date('2026-10-17T14:05:06.000Z')), version);
const html = readFileSync(new URL('../pra-agent/index.html', import.meta.url), 'utf8');
const displayScript = readFileSync(new URL('../pra-agent/src/build-info-view.js', import.meta.url), 'utf8');
const server = createServer((request, response) => {
  const mode = new URL(request.url, 'http://127.0.0.1').searchParams.get('mode') || 'valid';
  if (request.url.split('?')[0] === '/src/build-info-view.js') {
    response.writeHead(200, { 'Content-Type': 'text/javascript' }); response.end(displayScript); return;
  }
  if (request.url === '/favicon.ico') { response.writeHead(204); response.end(); return; }
  const info = mode === 'missing' ? { version, builtAt: null } : identity;
  const bridge = `<script>
    window.__unexpectedAgentCalls=[];
    window.agentAPI=new Proxy({
      getVersion:async()=>{if(${JSON.stringify(mode)}==='ipc-error')throw new Error('Synthetic identity IPC unavailable');return ${JSON.stringify(info)};},
      getConfig:async()=>({serverUrl:location.origin+'/api/agent',companyId:'Fictional company',apiKey:''}),
      getPrinters:async()=>({printers:[]}),getStatus:async()=>({running:false,connected:false}),
      imsMode:()=>null,checkUpdate:async()=>({available:false}),
      getPosSettings:async()=>({}),getLanSettings:async()=>({enabled:false}),getLanStatus:async()=>({enabled:false}),
    },{get:(target,key)=>key in target?target[key]:String(key).startsWith('on')?()=>{}:async()=>{window.__unexpectedAgentCalls.push(String(key));return {};}});
  </script>`;
  response.writeHead(200, { 'Content-Type': 'text/html' });
  response.end(html.replace('<script src="src/build-info-view.js">', bridge + '<script src="src/build-info-view.js">'));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = assertLocalOnlyBaseUrl('http://127.0.0.1:' + server.address().port);
let browser;
try {
  ({ browser } = await launchLocalBrowser());
  const context = await browser.newContext({ viewport: { width: 720, height: 720 }, timezoneId: 'Asia/Karachi' });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/*', route => route.request().url().startsWith(origin + '/') ? route.continue() : route.abort());
  for (const mode of ['valid', 'missing', 'ipc-error']) {
    await page.goto(origin + '/?mode=' + mode, { waitUntil: 'load' });
    await page.waitForFunction(() => !document.getElementById('agentVersion').textContent.includes('Loading'));
    assert.equal(await page.locator('#agentVersion').textContent(), mode === 'ipc-error' ? 'Unavailable' : 'v' + version);
    const date = page.locator('#buildVer');
    assert(await date.isVisible());
    if (mode === 'valid') {
      assert.equal(await date.getAttribute('datetime'), identity.builtAt);
      assert.match(await date.textContent(), /2026/);
      assert.match(await date.getAttribute('title'), /UTC/);
      assert(await date.evaluate(el => parseFloat(getComputedStyle(el).fontSize) >= 13));
      const box = await date.boundingBox(); assert(box.x >= 0 && box.x + box.width <= 720);
      await saveEvidenceScreenshot(page, 'agent-version-build-date');
    } else {
      assert.equal(await date.textContent(), 'Not available');
      assert.equal(await date.getAttribute('datetime'), null);
    }
    assert.equal(await page.locator('#serverUrl').inputValue(), origin + '/api/agent');
    assert.deepEqual(await page.evaluate(() => window.__unexpectedAgentCalls), []);
    assert.deepEqual(errors, []);
  }
  console.log('PASS: actual Agent settings HTML shows version/build date; missing/failed IPC honest; no settings writes');
} finally {
  if (browser) await browser.close();
  await new Promise(resolve => server.close(resolve));
}
