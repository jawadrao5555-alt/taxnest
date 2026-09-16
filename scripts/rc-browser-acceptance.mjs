import { readFileSync } from 'node:fs';
import { assertLocalOnlyBaseUrl, attachDiagnostics, launchLocalBrowser, saveEvidenceScreenshot } from './lib/local-browser.mjs';

const baseUrl = assertLocalOnlyBaseUrl(process.env.BASE_URL);
const fixturePath = process.env.RC_BROWSER_FIXTURE;
if (!fixturePath?.startsWith('/tmp/taxnest-rc-browser-')) throw new Error('RC_BROWSER_FIXTURE must be generated under isolated /tmp state');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
if (!fixture.synthetic || !Array.isArray(fixture.readOnlyJourneys) || !Array.isArray(fixture.transactionalJourneys)) throw new Error('synthetic separated fixture required');
const requested = String(process.env.RC_BROWSER_ONLY || '').split(',').map(x => x.trim()).filter(Boolean);
const regular = [...fixture.readOnlyJourneys, ...fixture.transactionalJourneys];
const di = Object.entries(fixture.diUiRoleCases || {}).map(([name, item]) => ({ name, ...item }));
const cases = requested.length ? regular.filter(x => requested.includes(x.name)) : regular;
const requestedIsolation = requested.includes('health-isolation');
const unknown = requested.filter(name => name !== 'health-isolation' && ![...regular, ...di].some(x => x.name === name));
if (unknown.length) throw new Error(`unknown requested journey: ${unknown.join(', ')}`);
let failures = 0; const fail = m => { failures++; console.error(`FAIL: ${m}`); }; const pass = m => console.log(`PASS: ${m}`);
const views = [['desktop', { width: 1366, height: 900 }], ['mobile', { width: 390, height: 844 }]];
const loopback = host => ['127.0.0.1', 'localhost', '::1', '[::1]'].includes(host);
function valid(t) { if (!String(t.login).endsWith('.invalid') || !t.password || !t.loginPath?.startsWith('/')) throw new Error(`${t.name}: reserved synthetic credentials and relative login required`); }
async function dismiss(page) {
  // Announcement and survey components are mounted after the initial Alpine tick.
  await page.waitForTimeout(350);
  for (let i=0;i<8;i++) {
    const pra=page.locator('[data-pra-elaan-popup]:visible');
    if (await pra.count()) { await pra.locator('button').last().click(); await page.waitForTimeout(250); continue; }
    const b=page.locator('[x-ref="wnBtn"]:visible,button:has-text("Got it"):visible,button:has-text("Samajh gaya"):visible,[data-pos-survey] button:has-text("Baad Mein"):visible,[data-pos-survey] button:has-text("Later"):visible').first();
    if (!await b.count()) break; await b.click(); await page.waitForTimeout(250);
  }
}
async function waitForOperationalSurface(page) {
  // POS panels mount a short client-side loading shell after the server response.
  // Assertions must inspect the operational surface, never that transitional shell.
  await page.waitForFunction(
    () => !/NestPOS is loading/i.test(document.body?.innerText || ''),
    null,
    { timeout: 15000 },
  ).catch(() => {});
  await page.waitForTimeout(300);
}
async function openMobileCart(page, v, markers = []) {
  if (v.width >= 768 || !markers.includes('Current Order')) return;
  const cart = page.getByRole('button', { name: /cart/i }).first();
  if (await cart.count() && await cart.isVisible()) {
    await cart.click();
    await page.waitForTimeout(250);
  }
}
async function login(page,t) {
  await page.goto(baseUrl+t.loginPath,{waitUntil:'domcontentloaded',timeout:30000});
  await page.locator('input[name="login"],input[name="email"],input#login').first().fill(t.login);
  const p=page.locator('input[name="password"]').first(); await p.fill(t.password);
  await Promise.all([page.waitForURL(u=>!u.pathname.endsWith(t.loginPath),{timeout:30000}).catch(()=>null),p.press('Enter')]);
  if (page.url().endsWith(t.loginPath)) throw new Error(`${t.name}: authentication remained on login page`);
}
async function checkUsability(page,t,path,v) {
  const width=await page.evaluate(()=>Math.max(document.documentElement.scrollWidth,document.body.scrollWidth));
  if(width>v.width+2) fail(`${t.name}/${v.width}: horizontal overflow (${width}px)`);
  for(const text of t.absenceMarkers||[])if((await page.locator('body').innerText()).includes(text))fail(`${t.name}/${v.width}: forbidden control rendered: ${text}`);
  for(const mutation of t.blockedMutationPaths||[])if(await page.locator(`[href="${mutation}"],form[action="${mutation}"]`).count())fail(`${t.name}/${v.width}: mutation route rendered`);
  for(const selector of t.usableSelectors||[]){
    const el=page.locator(selector).first();
    if(!await el.count()) { fail(`${t.name}/${v.width}: required usable control missing: ${selector}`); continue; }
    await el.scrollIntoViewIfNeeded();
    const box=await el.boundingBox();
    if(!box||box.width<20||box.height<12||box.width>v.width+2)fail(`${t.name}/${v.width}: unusable control ${selector}`);
  }
}
async function surface(page,t,path,v) {
  if (path.endsWith('.csv')) {
    if (t.denied) {
      const response=await page.goto(baseUrl+path,{waitUntil:'domcontentloaded',timeout:30000});
      const status=response?.status()||0;
      if (![302,401,403].includes(status)&&new URL(page.url()).pathname===path) fail(`${t.name}/${v.width}: denied CSV unexpectedly rendered`); else pass(`AUTHZ DENIAL PASS: ${t.name}/${v.width}: denied CSV stayed denied`);
      return;
    }
    const [dl]=await Promise.all([page.waitForEvent('download',{timeout:30000}),page.evaluate(p=>location.assign(p),path)]);
    const p=await dl.path(); const csv=p&&readFileSync(p,'utf8');
    if (!csv || !/Number"?[,]+Customer,Status,Scheduled,Due,Amount/.test(csv)) fail(`${t.name}/${v.width}: CSV schema missing`); else pass(`${t.name}/${v.width}: authorized CSV downloaded`);
    return;
  }
  const response=await page.goto(baseUrl+path,{waitUntil:'domcontentloaded',timeout:45000});
  await waitForOperationalSurface(page); await dismiss(page); await openMobileCart(page,v,t.markers||[]);
  const status=response?.status()||0, body=await page.locator('body').innerText().catch(()=> '');
  if (t.denied) { if ([302,403].includes(status)||new URL(page.url()).pathname!==path) pass(`AUTHZ DENIAL PASS: ${t.name}/${v.width}: denied surface stayed denied`); else fail(`${t.name}/${v.width}: denied surface rendered (${status})`); return; }
  if (status>=400||page.url().includes('/login')) return fail(`${t.name}/${v.width}: ${path} unauthorized or errored (${status})`);
  const expected=(t.expectedPaths||[])[(t.paths||[t.path]).indexOf(path)]||t.allowRedirectTo||path;
  if(new URL(page.url()).pathname!==expected)return fail(`${t.name}/${v.width}: ${path} ended at ${new URL(page.url()).pathname}, expected ${expected}`);
  for(const marker of t.markers||[])if(!body.includes(marker))fail(`${t.name}/${v.width}: ${path} omitted required marker ${marker}`);
  const main=page.locator('main,[role="main"]').first();
  if(!await main.count())fail(`${t.name}/${v.width}: ${path} has no main-content landmark`);
  else for(const marker of t.mainMarkers||t.markers||[])if(!(await main.innerText()).includes(marker))fail(`${t.name}/${v.width}: ${path} main content omitted ${marker}`);
  pass(`${t.name}/${v.width}: ${path} rendered at its exact native destination`);
  await checkUsability(page,t,path,v);
}
async function workflow(page,t,v) {
  const f=t.serviceWorkflow; if(!f)return;
  await page.goto(baseUrl+f.createPath,{waitUntil:'domcontentloaded',timeout:30000});
  await waitForOperationalSurface(page); await dismiss(page);
  for(const [n,val] of Object.entries({customer_name:f.customerName,title:f.title,quantity:f.quantity,unit_price:f.unitPrice,scheduled_at:f.scheduledAt,...Object.fromEntries(Object.entries(f.details).map(([k,x])=>[`details[${k}]`,x]))})) { const el=page.locator(`[name="${n}"]`).first(); if(!await el.count())throw new Error(`${t.name}: form omitted ${n}`);await el.fill(String(val)); }
  const create=page.locator('form[action$="/pos/work-orders"] button[type="submit"],form[action$="/pos/work-orders"] button').first();
  await Promise.all([page.waitForURL(/\/pos\/work-orders\/\d+$/,{timeout:30000,waitUntil:'domcontentloaded'}),create.click()]); const order=new URL(page.url()).pathname;
  for(const s of f.transitions){const b=page.locator(`button[name="to_status"][value="${s}"]`).first();if(!await b.count())throw new Error(`${t.name}: transition ${s} unavailable`);await b.click();await page.waitForLoadState('domcontentloaded');}
  const invoice=page.locator('form[action$="/invoice"] button[type="submit"],form[action$="/invoice"] button').first(); await Promise.all([page.waitForURL(/\/pos\/transaction\/\d+$/,{timeout:30000}),invoice.click()]);
  await page.goto(baseUrl+order,{waitUntil:'domcontentloaded',timeout:30000}); if(!(await page.locator('body').innerText()).includes(f.invoiceMarker))throw new Error(`${t.name}: invoice linkage marker missing`); pass(`${t.name}/${v.width}: actual service create, transitions, and invoice linked`);
}
async function categoryMismatch(page,t,v) {
  const path=t.categoryCoverage?.mismatchPath; if(!path)return;
  const response=await page.goto(baseUrl+path,{waitUntil:'domcontentloaded',timeout:30000});
  const status=response?.status()||0, finalPath=new URL(page.url()).pathname;
  if(status<400&&finalPath===path)fail(`${t.name}/${v.width}: category mismatch direct URL rendered ${path}`);
  else pass(`CATEGORY URL GATE PASS: ${t.name}/${v.width}: ${t.categoryCoverage.category} rejected ${path}`);
}
async function sameProductCategorySurface(page,t,v) {
  const path=t.categoryCoverage?.sameProductPositivePath; if(!path)return;
  const response=await page.goto(baseUrl+path,{waitUntil:'domcontentloaded',timeout:30000});
  const status=response?.status()||0, finalPath=new URL(page.url()).pathname, main=page.locator('main,[role="main"]').first();
  if(status>=400||finalPath!==path||!await main.count())fail(`${t.name}/${v.width}: same-product category surface ${path} did not render`);
  else pass(`CATEGORY NATIVE PASS: ${t.name}/${v.width}: ${t.categoryCoverage.category} rendered ${path}`);
}
async function healthIsolation(browser,label,v,iso) {
  if(!iso?.branchUser||!iso.ownPatient||!iso.otherBranchPatient||!iso.foreignTenantPatient)throw new Error('health isolation fixture is incomplete');
  const c=await browser.newContext({viewport:v}); await c.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient')); const p=await c.newPage(),d=attachDiagnostics(p);
  try {
    await login(p,{name:'health-branch-isolation',...iso.branchUser}); await dismiss(p);
    const own=`/health/patients/${iso.ownPatient.id}`, ownResponse=await p.goto(baseUrl+own,{waitUntil:'domcontentloaded',timeout:30000});
    if((ownResponse?.status()||0)>=400||new URL(p.url()).pathname!==own||!(await p.locator('body').innerText()).includes(iso.ownPatient.identifier))fail(`HEALTH ISOLATION: ${label}: own branch patient is not accessible`);
    else pass(`HEALTH ISOLATION PASS: ${label}: own branch patient accessible`);
    for(const patient of [iso.otherBranchPatient,iso.foreignTenantPatient]) {
      const response=await p.goto(baseUrl+`/health/patients/${patient.id}`,{waitUntil:'domcontentloaded',timeout:30000});
      const body=await p.locator('body').innerText().catch(()=> '');
      const requestedPath = `/health/patients/${patient.id}`;
      if(((response?.status()||0)<400 && new URL(p.url()).pathname === requestedPath)||body.includes(patient.identifier))fail(`HEALTH ISOLATION: ${label}: denied patient ${patient.id} escaped scope`);
      else pass(`HEALTH ISOLATION PASS: ${label}: denied patient ${patient.id} did not escape scope`);
    }
    const consoleErrors=d.consoleErrors.filter(x=>!x.includes('ERR_BLOCKED_BY_CLIENT'));
    const failedRequests=d.failedRequests.filter(x=>!x.includes('ERR_BLOCKED_BY_CLIENT'));
    if(d.pageErrors.length||consoleErrors.length||failedRequests.length)fail(`HEALTH ISOLATION: ${label}: ${d.summary()}`);
  } finally {await saveEvidenceScreenshot(p,`rc-${label}-health-isolation`).catch(()=>{});await c.close();}
}
async function one(browser,label,v,t) {
  valid(t); const c=await browser.newContext({viewport:v}); await c.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient')); const p=await c.newPage(), d=attachDiagnostics(p);
  try { await login(p,t); await waitForOperationalSurface(p); await dismiss(p); if(t.submitSelector){await p.goto(baseUrl+t.submitPath,{waitUntil:'domcontentloaded'});await waitForOperationalSurface(p);p.once('dialog',x=>x.accept());await Promise.all([p.waitForURL(u=>!u.pathname.startsWith('/admin/companies/'),{timeout:30000}),p.locator(t.submitSelector).first().evaluate(n=>n.requestSubmit())]);} await workflow(p,t,v);for(const path of t.paths||[t.path])await surface(p,t,path,v);await sameProductCategorySurface(p,t,v);await categoryMismatch(p,t,v);if(d.pageErrors.length)fail(`${t.name}/${label}: page error ${d.pageErrors[0]}`);const expectedMismatch=t.categoryCoverage?.mismatchPath ? `${baseUrl}${t.categoryCoverage.mismatchPath}` : null;const hotelFallback=t.categoryCoverage?.category==='hotel';const intentionalMismatchFailure=x=>(expectedMismatch&&x.includes(expectedMismatch))||(hotelFallback&&(x.includes(`${baseUrl}/pos/hotel`)||x.includes(`${baseUrl}/pos/invoice/create`)));const consoleErrors=t.denied?[]:d.consoleErrors.filter(x=>!x.includes('ERR_BLOCKED_BY_CLIENT')&&!intentionalMismatchFailure(x));const failedRequests=t.denied?[]:d.failedRequests.filter(x=>!x.includes('ERR_BLOCKED_BY_CLIENT')&&!intentionalMismatchFailure(x));if(consoleErrors.length)fail(`${t.name}/${label}: console error ${consoleErrors[0]}`);if(failedRequests.length)fail(`${t.name}/${label}: failed request ${failedRequests[0]}`);const unexpectedHttp=t.denied?[]:d.httpErrors;if(unexpectedHttp.length)fail(`${t.name}/${label}: HTTP ${unexpectedHttp[0].status} ${unexpectedHttp[0].url}`);console.log(`DIAGNOSTICS: ${t.name}/${label}: ${d.summary()}`); }
  catch(e){fail(`${t.name}/${label}: ${e.message}`);} finally {await saveEvidenceScreenshot(p,`rc-${label}-${t.name}`).catch(()=>{});await c.close();}
}
const {browser}=await launchLocalBrowser();
try { for(const [label,v]of views)for(const t of cases)await one(browser,label,v,t); for(const [label,v]of views)if(!requested.length||requestedIsolation)await healthIsolation(browser,label,v,fixture.isolation); if(!requested.length&&!di.length)throw new Error('DI pending role fixture missing'); for(const [label,v]of views)for(const t of di)if(!requested.length||requested.includes(t.name))await one(browser,label,v,t); } finally {await browser.close();}
if(failures){console.error(`RC BROWSER ACCEPTANCE FAIL: ${failures} assertion(s) failed.`);process.exit(1);} console.log('RC BROWSER ACCEPTANCE PASS: all required desktop/mobile synthetic journeys passed.');