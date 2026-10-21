import { createHash } from 'node:crypto';
import { lstatSync, readFileSync, realpathSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { recordingDirectory } from './lib/tutorial-recording.mjs';
import { assertLocalOnlyBaseUrl, attachDiagnostics, launchLocalBrowser, saveEvidenceScreenshot } from './lib/local-browser.mjs';

const baseUrl = assertLocalOnlyBaseUrl(process.env.BASE_URL);
const fixturePath = process.env.RC_BROWSER_FIXTURE;
if (!fixturePath || !/^\/tmp\/taxnest-rc-browser-[0-9]+(?:-[A-Za-z0-9_.-]+)?\/safe-runtime\/browser-state\/fixture\.json$/.test(fixturePath)) throw new Error('RC_BROWSER_FIXTURE must be generated under exact isolated /tmp state');
const fixtureStat = lstatSync(fixturePath);
if (!fixtureStat.isFile() || realpathSync(fixturePath) !== fixturePath) throw new Error('RC_BROWSER_FIXTURE must be a non-symlink regular file at its exact isolated target');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
if (!fixture.synthetic || !Array.isArray(fixture.readOnlyJourneys) || !Array.isArray(fixture.transactionalJourneys)) throw new Error('synthetic separated fixture required');
const requested = String(process.env.RC_BROWSER_ONLY || '').split(',').map(x => x.trim()).filter(Boolean);
const videoDirectory = recordingDirectory(process.env.RC_TUTORIAL_RECORDING, fixturePath, requested);
const recorded = [];
const regular = [...fixture.readOnlyJourneys, ...fixture.transactionalJourneys];
const di = Object.entries(fixture.diUiRoleCases || {}).map(([name, item]) => ({ name, ...item }));
// Exercise transactional table, settings and Hotel desk regressions first; retain every journey.
const ordered = [...regular.filter(x=>x.tableOrderWorkflow), ...regular.filter(x=>x.hotelSettingsWorkflow), ...regular.filter(x=>x.hotelWorkflow), ...regular.filter(x=>!x.hotelSettingsWorkflow&&!x.tableOrderWorkflow&&!x.hotelWorkflow)];
const cases = requested.length ? ordered.filter(x => requested.includes(x.name)) : ordered;
const requestedIsolation = requested.includes('health-isolation');
const unknown = requested.filter(name => name !== 'health-isolation' && ![...regular, ...di].some(x => x.name === name));
if (unknown.length) throw new Error(`unknown requested journey: ${unknown.join(', ')}`);
let failures = 0; const fail = m => { failures++; console.error(`FAIL: ${m}`); }; const pass = m => console.log(`PASS: ${m}`);
const views = [['desktop', { width: 1366, height: 900 }], ['mobile', { width: 390, height: 844 }]];
const loopback = host => ['127.0.0.1', 'localhost', '::1', '[::1]'].includes(host);
function valid(t) { if (!String(t.login).endsWith('.invalid') || !t.password || !t.loginPath?.startsWith('/')) throw new Error(`${t.name}: reserved synthetic credentials and relative login required`); }
async function dismissOverlay(overlay, action, label, hiddenTimeout=5000) {
  if (!await overlay.isVisible()) return false;
  try {
    await action.click({timeout:3000});
  } catch (error) {
    // Alpine may remove an observed control between locator resolution and the
    // pointer action. That is only acceptable when the corresponding real
    // overlay has actually become hidden; a visible/covered overlay remains an
    // assertion failure and retains the original click error.
    try {
      await overlay.waitFor({state:'hidden',timeout:1500});
      return true;
    } catch {
      throw new Error(`${label} remained visible after its dismissal action failed: ${error.message}`);
    }
  }
  await overlay.waitFor({state:'hidden',timeout:hiddenTimeout});
  return true;
}
async function dismiss(page) {
  // Announcement and survey components are mounted after the initial Alpine tick.
  await page.waitForTimeout(350);
  for (let i=0;i<8;i++) {
    const fbrCard=page.locator('[data-fbr-decision-card]:visible').first();
    const fbrChoice=fbrCard.locator(`xpath=.//button[contains(@*[name()="@click"], "fdChoose('without_fbr')")]`).first();
    if (await dismissOverlay(fbrCard,fbrChoice,'FBR integration decision',15000)) {
      // The decision is persisted asynchronously and then reloads. Waiting only
      // for the current load state races that reload on mobile; wait until this
      // actual blocking card is no longer visible before any sale control click.
      await page.waitForLoadState('domcontentloaded').catch(()=>{});
      await page.waitForTimeout(500);
      continue;
    }
    const whatsNewOverlay=page.locator('[x-show="wnOpen"]:visible').first();
    const whatsNew=whatsNewOverlay.locator(`xpath=.//button[contains(@*[name()="@click"], "wnDismiss")]`).first();
    if (await dismissOverlay(whatsNewOverlay,whatsNew,'What’s New')) {
      await page.waitForTimeout(300);
      continue;
    }
    const pra=page.locator('[data-pra-elaan-popup]:visible').first();
    if (await dismissOverlay(pra,pra.locator('button').last(),'PRA announcement')) { await page.waitForTimeout(250); continue; }
    const survey=page.locator('[data-pos-survey]:visible').first();
    if (await dismissOverlay(survey,survey.locator(`xpath=.//button[contains(@*[name()="@click"], "svDismiss")]`).first(),'POS survey')) { await page.waitForTimeout(250); continue; }
    break;
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
  // Mobile Cart is deliberately opened for order-content checks above. Return
  // through its real control before testing product search on the product pane.
  if(t.name==='fiscal' && v.width<768) {
    const back=page.locator('[data-fbr-cart-back]').first();
    if(await back.count() && await back.isVisible()) {
      await back.click({timeout:5000});
      await page.waitForFunction(()=>window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).mobileView==='menu');
    }
  }
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
    if(t.name==='fiscal'||t.readOnly) {
      try {
        await el.click({trial:true,timeout:5000});
        if(t.name==='fiscal') {
          await el.click({timeout:5000});
          if(!await el.evaluate(el=>document.activeElement===el))fail(`${t.name}/${v.width}: search did not receive focus after pointer click`);
        }
      }
      catch (error) {
        const geometry=await el.evaluate(el=>{
          const r=el.getBoundingClientRect(), x=r.left+r.width/2, y=r.top+r.height/2;
          const hit=document.elementFromPoint(x,y);
          return {rect:{x:r.x,y:r.y,width:r.width,height:r.height},viewport:{width:innerWidth,height:innerHeight,scrollY},
            targetStyle:{display:getComputedStyle(el).display,visibility:getComputedStyle(el).visibility},
            hit:hit?{tag:hit.tagName,id:hit.id,classes:hit.className}:null};
        });
        fail(`${t.name}/${v.width}: sale control is not pointer-actionable: ${selector}; ${JSON.stringify(geometry)}; ${error.message.slice(0,1600)}`);
      }
    }
  }
  if(t.readOnly) {
    const title=page.locator('main #invoice-heading');
    const titleBox=await title.boundingBox();
    if(!titleBox||titleBox.width<120||titleBox.height<18)fail(`${t.name}/${v.width}: invoice title is not usefully readable`);
    const invoiceContent=page.locator('main table').first();
    const contentBox=await invoiceContent.boundingBox();
    const actions=page.locator('main a,main button').filter({hasText:/Duplicate|Download|Email|WhatsApp/i});
    for(let i=0;i<await actions.count();i++) {
      const action=actions.nth(i);
      if(!await action.isVisible())continue;
      const box=await action.boundingBox();
      const position=await action.evaluate(el=>getComputedStyle(el).position);
      if(position==='fixed'||(box&&contentBox&&box.x<contentBox.x+contentBox.width&&box.x+box.width>contentBox.x&&box.y<contentBox.y+contentBox.height&&box.y+box.height>contentBox.y)) {
        fail(`${t.name}/${v.width}: invoice action toolbar overlaps document content`);
        break;
      }
      try { await action.click({trial:true,timeout:5000}); }
      catch { fail(`${t.name}/${v.width}: invoice action is not pointer-actionable`); break; }
    }
  }
}
async function checkTopNavigation(page,t,v) {
  if(!t.topNavPanel)return;
  const runtime=await page.evaluate(factory=>({
    alpine:!!window.Alpine,
    started:window.__alpineStarted===true,
    factory:typeof window[factory]==='function'
  }),t.topNavFactory);
  if(!runtime.alpine||!runtime.started||!runtime.factory)
    return fail(`${t.name}/${v.width}: Alpine runtime or ${t.topNavFactory} factory did not start`);
  const header=page.locator(`[data-tn-topnav="${t.topNavPanel}"]`).first();
  if(!await header.count())return fail(`${t.name}/${v.width}: shared ${t.topNavPanel} top navigation missing`);
  if(await page.locator('[role="dialog"][aria-modal="true"]:visible').count())return fail(`${t.name}/${v.width}: blocking overlay remained visible before top-navigation checks`);
  const scrollActions=header.locator('[data-tn-topnav-scroll-actions]').first();
  const menuCluster=header.locator('[data-tn-topnav-menu-cluster]').first();
  if(v.width>=1024&&v.width<=2200&&await header.getAttribute('data-tn-sale-header')!==null){
    const rows=await header.evaluate(el=>{
      const left=el.querySelector('.tn-impersonation-header-left')?.getBoundingClientRect();
      const tools=el.querySelector('#tn-nav-sale-tools')?.getBoundingClientRect();
      const menu=el.querySelector('[data-tn-topnav-menu-cluster]')?.getBoundingClientRect();
      return{leftBottom:left?.bottom,toolsTop:tools?.top,toolsRight:tools?.right,menuBottom:menu?.bottom,viewport:innerWidth};
    });
    if(rows.toolsTop===undefined||rows.toolsTop<Math.max(rows.leftBottom??0,rows.menuBottom??0)-1||rows.toolsRight>rows.viewport+1)
      fail(`${t.name}/${v.width}: sale actions overlapped the primary navigation (${JSON.stringify(rows)})`);
    else pass(`${t.name}/${v.width}: sale actions have a separate reachable row`);
  }
  if(!await scrollActions.count())fail(`${t.name}/${v.width}: ordinary header-action scroller missing`);
  if(!await menuCluster.count())return fail(`${t.name}/${v.width}: overflow-visible top-navigation menu cluster missing`);
  for(const name of ['notification','theme','profile']) {
    const button=header.locator(`[data-tn-topnav-control="${name}"]`).first();
    if(!await button.count()||!await button.isVisible()) { fail(`${t.name}/${v.width}: ${name} control missing`); continue; }
    if(!await menuCluster.locator(`[data-tn-topnav-control="${name}"]`).count())fail(`${t.name}/${v.width}: ${name} control escaped the overflow-visible menu cluster`);
    if(await scrollActions.locator(`[data-tn-topnav-control="${name}"]`).count())fail(`${t.name}/${v.width}: ${name} control remains inside the scrolling action strip`);
    const hit=await button.evaluate(el=>{const r=el.getBoundingClientRect(),n=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);return{inside:r.left>=0&&r.top>=0&&r.right<=innerWidth&&r.bottom<=innerHeight,reached:!!n&&(n===el||el.contains(n)),rect:{left:r.left,top:r.top,right:r.right,bottom:r.bottom},hit:n?.tagName||null};});
    if(!hit.inside){fail(`${t.name}/${v.width}: ${name} control left the viewport (${JSON.stringify(hit.rect)})`);continue;}
    if(!hit.reached){fail(`${t.name}/${v.width}: physical hit-test reached ${hit.hit||'nothing'} instead of ${name} button`);continue;}
    const stateKey={notification:'bellOpen',theme:'themeOpen',profile:'profileOpen'}[name];
    const stateBefore=await button.evaluate((el,key)=>Boolean(window.Alpine.$data(el)[key]),stateKey);
    if(stateBefore)fail(`${t.name}/${v.width}: ${name} Alpine state was open before its physical click`);
    await button.click({timeout:5000});
    const panel=button.locator('xpath=..').locator(':scope > div[x-show]').first();
    if(!await panel.count())fail(`${t.name}/${v.width}: ${name} panel missing after click`);
    else {
      try {
        await panel.waitFor({state:'visible',timeout:3000});
        let panelOk=true;
        const stateAfter=await button.evaluate((el,key)=>Boolean(window.Alpine.$data(el)[key]),stateKey);
        if(!stateAfter){fail(`${t.name}/${v.width}: ${name} Alpine state did not change false→true`);panelOk=false;}
        const geometry=await panel.evaluate(el=>{
          const r=el.getBoundingClientRect();
          const header=el.closest('[data-tn-topnav]');
          const hr=header?.getBoundingClientRect();
          const clipping=[];
          for(let n=el.parentElement;n&&n!==document.documentElement;n=n.parentElement){
            if(n===document.body)continue;
            const s=getComputedStyle(n);
            if(['auto','hidden','clip','scroll'].includes(s.overflowX)||['auto','hidden','clip','scroll'].includes(s.overflowY)){
              clipping.push({tag:n.tagName,cls:n.className,overflowX:s.overflowX,overflowY:s.overflowY});
            }
          }
          const x=Math.min(Math.max(r.left+8,1),innerWidth-2);
          const y=Math.min(Math.max(r.top+8,1),innerHeight-2);
          const hit=document.elementFromPoint(x,y);
          return {
            rect:{left:r.left,top:r.top,right:r.right,bottom:r.bottom,width:r.width,height:r.height},
            headerBottom:hr?.bottom??0,
            extendsBelowHeader:r.bottom>(hr?.bottom??0)+1,
            clipping,
            innerHit:!!hit&&(hit===el||el.contains(hit)),
            documentWidth:Math.max(document.documentElement.scrollWidth,document.body.scrollWidth),
          };
        });
        if(geometry.rect.width<=0||geometry.rect.height<=0||!geometry.extendsBelowHeader){fail(`${t.name}/${v.width}: ${name} panel has no visible area below the header (${JSON.stringify(geometry.rect)})`);panelOk=false;}
        if(geometry.clipping.length){fail(`${t.name}/${v.width}: ${name} panel has clipping overflow ancestor ${JSON.stringify(geometry.clipping[0])}`);panelOk=false;}
        if(!geometry.innerHit){fail(`${t.name}/${v.width}: ${name} panel failed its inner physical hit-test`);panelOk=false;}
        if(geometry.documentWidth>v.width+2){fail(`${t.name}/${v.width}: ${name} panel introduced horizontal document overflow (${geometry.documentWidth}px)`);panelOk=false;}
        if(panelOk)pass(`${t.name}/${v.width}: ${name} physical click opened an unclipped panel`);
      }
      catch { fail(`${t.name}/${v.width}: ${name} click did not open its panel`); }
    }
    await button.click({timeout:5000});
    if(await panel.count()) {
      try { await panel.waitFor({state:'hidden',timeout:3000}); }
      catch { fail(`${t.name}/${v.width}: ${name} panel did not close normally`); }
    }
  }
}
async function checkAdminContrast(page,t,v) {
  const result=await page.evaluate(()=>{
    const rgb=s=>(s.match(/\d+(?:\.\d+)?/g)||[]).slice(0,3).map(Number);
    const luminance=c=>{const x=rgb(c).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;});return x[0]*.2126+x[1]*.7152+x[2]*.0722;};
    const ratio=(a,b)=>{const x=luminance(a),y=luminance(b);return(Math.max(x,y)+.05)/(Math.min(x,y)+.05);};
    const title=document.querySelector('main h1.text-white');
    const cardLabel=document.querySelector('main .bg-gray-900 .text-gray-400');
    const card=cardLabel?.closest('.bg-gray-900');
    const viewButton=[...document.querySelectorAll('main button')].find(el=>el.textContent.includes('View as Company'));
    return{title:title?ratio(getComputedStyle(title).color,getComputedStyle(document.body).backgroundColor):null,
      label:cardLabel&&card?ratio(getComputedStyle(cardLabel).color,getComputedStyle(card).backgroundColor):null,
      viewButton:viewButton?ratio(getComputedStyle(viewButton).color,getComputedStyle(viewButton).backgroundColor):null};
  });
  if(result.title!==null&&result.title<3)fail(`${t.name}/${v.width}: admin page title contrast ${result.title.toFixed(2)}:1`);
  if(result.label!==null&&result.label<4.5)fail(`${t.name}/${v.width}: admin dark-card label contrast ${result.label.toFixed(2)}:1`);
  if(result.viewButton!==null&&result.viewButton<4.5)fail(`${t.name}/${v.width}: admin action contrast ${result.viewButton.toFixed(2)}:1`);
  if(result.title!==null&&result.label!==null&&result.title>=3&&result.label>=4.5&&result.viewButton>=4.5)pass(`${t.name}/${v.width}: admin title, dark-card label and action remain readable`);
}
async function checkTopNavigationWithReloadRetry(page,t,path,v) {
  try {
    await checkTopNavigation(page,t,v);
  } catch (error) {
    if (!/Execution context was destroyed|most likely because of a navigation/i.test(String(error?.message || error))) throw error;
    await page.goto(baseUrl+path,{waitUntil:'domcontentloaded',timeout:45000});
    await waitForOperationalSurface(page);
    await dismiss(page);
    await checkTopNavigation(page,t,v);
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
  await waitForOperationalSurface(page); await dismiss(page); await checkTopNavigationWithReloadRetry(page,t,path,v); await openMobileCart(page,v,t.markers||[]);
  if(path==='/pos/inventory'){
    const tabs=page.locator('.tn-inventory-nav').first();
    if(!await tabs.count())fail(`${t.name}/${v.width}: inventory navigation missing`);
    else {
      const geometry=await tabs.evaluate(el=>{
        const links=[...el.querySelectorAll('a')].map(a=>a.getBoundingClientRect());
        return{rows:[...new Set(links.map(r=>Math.round(r.top)))],scrollable:el.scrollWidth>el.clientWidth,overflow:getComputedStyle(el).overflowX,
          clipped:links.some(r=>r.height<=0),count:links.length};
      });
      if(geometry.count<4||geometry.rows.length!==1||geometry.clipped||geometry.overflow!=='auto')
        fail(`${t.name}/${v.width}: inventory tabs wrapped or overlapped (${JSON.stringify(geometry)})`);
      else pass(`${t.name}/${v.width}: inventory tabs form one reachable scrolling row`);
    }
  }
  if(t.fiscalReceipt) {
    for(const selector of ['[data-fbr-fiscal-receipt] img[alt="FBR digital invoicing system"]','img[alt="FBR QR Code"]']) {
      const image=page.locator(selector);if(!await image.count() || !await image.evaluate(el=>el.complete&&el.naturalWidth>0)) fail(`${t.name}/${v.width}: fiscal image did not load: ${selector}`);
    }
    await page.emulateMedia({media:'print'});
    if(!await page.locator('[data-fbr-required-profile]').isVisible()) fail(`${t.name}/${v.width}: required fiscal profile hidden on print`);
    await page.emulateMedia({media:'screen'});
  }
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
async function hotelWorkflow(page, t, v, diagnostics) {
  const room = v.width < 768 ? 'RC-202' : 'RC-201';
  await page.goto(baseUrl + '/pos/hotel', {waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  const reporting = page.locator('[data-hotel-pra-reporting="1"]');
  const reportingToggle = reporting.locator('[data-hotel-pra-toggle="1"]');
  await reportingToggle.waitFor({state:'visible'});
  const originalMode = await reportingToggle.getAttribute('aria-checked');
  for (const enabled of [originalMode !== 'true', originalMode === 'true']) {
    const [response] = await Promise.all([
      page.waitForResponse(r => r.url() === baseUrl + '/pos/api/toggle-pra' && r.request().method() === 'POST'),
      reportingToggle.click(),
    ]);
    if (!response.ok()) throw new Error('Hotel reporting toggle rejected');
    await page.waitForFunction(enabled => document.querySelector('[data-hotel-pra-toggle="1"]')?.getAttribute('aria-checked') === String(enabled), enabled);
  }
  pass(`${t.name}/${v.width}: real PRA toggle switched and restored this account mode`);
  await page.goto(baseUrl + '/pos/customize', {waitUntil:'domcontentloaded'});
  await dismiss(page);
  const taxCards = page.locator('[data-hotel-tax-cards="1"]');
  const advanced = taxCards.locator('xpath=ancestor::details[1]');
  if (!await advanced.evaluate(el => el.open)) await advanced.locator('summary').first().click();
  const originalTaxMode = await taxCards.locator('[aria-checked="true"]').getAttribute('data-hotel-tax-mode');
  for (const mode of [...['exclusive','inclusive','inclusive_card_save'].filter(m => m !== originalTaxMode), originalTaxMode]) {
    const [response] = await Promise.all([
      page.waitForResponse(r => r.url().includes('/settings/tax-pricing-mode') && r.request().method() === 'POST'),
      taxCards.locator(`[data-hotel-tax-mode="${mode}"]`).click(),
    ]);
    if (!response.ok()) throw new Error('Hotel tax card setting rejected');
    await page.waitForFunction(mode => document.querySelector(`[data-hotel-tax-mode="${mode}"]`)?.getAttribute('aria-checked') === 'true', mode);
  }
  pass(`${t.name}/${v.width}: all three real tax cards saved and original mode restored`);
  // Save paper once in Settings, preserve it across navigation, then restore.
  const printingCard = page.locator('[data-hotel-settings-card="printing"]');
  await printingCard.locator('summary').click();
  const paperForm = printingCard.locator('[data-hotel-receipt-settings]');
  const originalPaper = await paperForm.locator('[name="paper"]').inputValue();
  for (const paper of ['58mm','a4','80mm',originalPaper]) {
    const cardNow = page.locator('[data-hotel-settings-card="printing"]');
    if (!await cardNow.evaluate(el => el.open)) await cardNow.locator('summary').click();
    const paperFormNow = cardNow.locator('[data-hotel-receipt-settings]');
    await paperFormNow.locator('[name="paper"]').selectOption(paper);
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}), paperFormNow.locator('button').click()]);
    await page.locator('[data-hotel-settings]').waitFor({state:'visible'});
    await page.locator('[data-hotel-settings-card="printing"] summary').click();
    if (await page.locator('[data-hotel-receipt-settings] [name="paper"]').inputValue() !== paper) throw new Error('Saved paper choice was not retained');
    await page.goto(baseUrl + '/pos/customize', {waitUntil:'domcontentloaded'});
    await dismiss(page);
  }
  pass(`${t.name}/${v.width}: real saved 58mm/A4/80mm preferences restored`);

  await page.goto(baseUrl + '/pos/hotel', {waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  const card = page.locator('[data-hotel-room-state="vacant"]').filter({hasText: room});
  await card.locator('[data-hotel-room-check-in]').click();
  const bookingPopup = page.locator('[data-hotel-checkin-popup]');
  await bookingPopup.waitFor({state:'visible'});
  if (new URL(page.url()).pathname !== '/pos/hotel') throw new Error('Check-in left the front desk instead of opening a popup');
  const bookingBounds = await bookingPopup.boundingBox();
  if (!bookingBounds || bookingBounds.width < Math.min(700, v.width - 50)) throw new Error('Check-in popup remained cramped');
  await saveEvidenceScreenshot(page, `hotel-checkin-form-${v.width}.png`);
  await dismiss(page);
  const form = page.locator('form[action$="/pos/hotel/stays"]');
  await form.locator('[name="guest_name"]').fill('Synthetic Simple Desk ' + room);
  await form.locator('[name="guest_phone"]').fill('03000000000');
  await form.locator('[name="rate_amount"]').fill('4000');
  await form.locator('[name="discount_type"]').selectOption('amount');
  await form.locator('[name="discount_value"]').fill('500');
  await form.locator('details').filter({has: page.locator('[name="advance_amount"]')}).locator('summary').click();
  await form.locator('[name="advance_amount"]').fill('1000');
  const bookedRoomId = await form.locator('[name="room_id"]').inputValue();
  await page.addInitScript(() => { window.__hotelPrintCalls = 0; window.print = () => { ++window.__hotelPrintCalls; }; });
  await Promise.all([page.waitForURL(/\/pos\/hotel\/stays\/\d+$/, {timeout:30000,waitUntil:'domcontentloaded'}), form.locator('button').click()]);
  const checkinPreview = page.locator('[data-hotel-bill-preview="1"]');
  await checkinPreview.waitFor({state:'visible'});
  await page.waitForFunction(()=>!document.querySelector('[data-preview-confirm]').disabled && document.querySelector('[data-preview-lines]').childElementCount > 0);
  if (await page.evaluate(() => window.__hotelPrintCalls) !== 0) throw new Error('Check-in automatically opened native print');
  if (!(await checkinPreview.locator('[data-preview-lines]').innerText()).trim()) throw new Error('Check-in popup omitted room charges');
  await saveEvidenceScreenshot(page, `hotel-checkin-preview-${v.width}.png`);
  await checkinPreview.locator('[data-preview-back]').click();
  await checkinPreview.waitFor({state:'hidden'});
  const stayPath = new URL(page.url()).pathname;
  if (!/\/pos\/hotel\/stays\/\d+$/.test(stayPath)) throw new Error('Draft receipt Close left the stay page');
  pass(`${t.name}/${v.width}: unconfirmed check-in receipt Close preserved stay page`);
  await page.goto(baseUrl + '/pos/hotel/stays/create?walk_in=1', {waitUntil:'domcontentloaded'});
  await page.waitForFunction(()=>{const form=document.querySelector('form[x-data^="hotelBookingForm"]');return form && window.Alpine.$data(form).busy === false;});
  const checkinOptions = await page.locator('[name="room_id"] option').allTextContents();
  if (checkinOptions.some(text => text.startsWith(room + ' · '))) throw new Error('Occupied room still appeared in the direct check-in picker');
  for (const blocked of ['RC-INACTIVE', 'RC-OUT', 'RC-DIRTY']) {
    if (checkinOptions.some(text => text.startsWith(blocked + ' · '))) throw new Error(`${blocked} appeared in the direct check-in picker`);
  }
  await saveEvidenceScreenshot(page, `hotel-available-rooms-${v.width}.png`);
  pass(`${t.name}/${v.width}: occupied room removed from real check-in dropdown`);
  pass(`${t.name}/${v.width}: inactive, out-of-service and dirty rooms absent from real check-in dropdown`);
  // Future reservation stays available outside occupied dates; changing dates clears conflicts.
  await page.goto(baseUrl + '/pos/hotel/stays/create', {waitUntil:'domcontentloaded'});
  const reserveForm = page.locator('form[x-data^="hotelBookingForm"]');
  const today = await reserveForm.locator('[name="check_in_date"]').inputValue();
  const shifted = days => { const date=new Date(today+'T12:00:00Z');date.setUTCDate(date.getUTCDate()+days);return date.toISOString().slice(0,10); };
  await reserveForm.locator('[name="check_out_date"]').fill(shifted(8));
  await reserveForm.locator('[name="check_in_date"]').fill(shifted(7));
  await page.waitForFunction(id=>{const d=window.Alpine.$data(document.querySelector('form[x-data^="hotelBookingForm"]'));return !d.busy && d.options.some(room=>room.id===id);},bookedRoomId);
  await reserveForm.locator('[name="room_id"]').selectOption(bookedRoomId);
  await page.waitForFunction(()=>{const d=window.Alpine.$data(document.querySelector('form[x-data^="hotelBookingForm"]'));return !d.busy && d.quote?.available;});
  await reserveForm.locator('[name="check_in_date"]').fill(today);
  await reserveForm.locator('[name="check_out_date"]').fill(shifted(1));
  await page.waitForFunction(id=>{const d=window.Alpine.$data(document.querySelector('form[x-data^="hotelBookingForm"]'));return !d.busy && !d.room && !d.options.some(room=>room.id===id);},bookedRoomId);
  if (await reserveForm.locator('[name="room_id"]').inputValue()) throw new Error('Changed dates kept an unavailable room selected');
  pass(`${t.name}/${v.width}: real reservation dates restore future availability and clear overlapping selection`);
  await page.goto(baseUrl + stayPath, {waitUntil:'domcontentloaded'});
  await dismiss(page);
  if (await page.locator('#hotel-charge').isVisible() || await page.locator('#hotel-payment').isVisible()) throw new Error('Hotel action forms must start collapsed');
  await saveEvidenceScreenshot(page, `hotel-simple-desk-${v.width}-stay.png`);
  await page.locator('[data-hotel-open-desk="1"]').click();
  const deskModal = page.locator('[data-hotel-bill-preview="1"]');
  await deskModal.waitFor({state:'visible'});
  await deskModal.locator('[data-desk-method]').selectOption('card');
  await page.waitForFunction(() => { const d = document.querySelector('[data-hotel-bill-preview]'); return d && !d.querySelector('[data-preview-confirm]').disabled && Number(d.querySelector('[data-desk-amount]').value) === 2500; });
  await deskModal.locator('[data-desk-flow]').selectOption('checkout');
  await page.waitForFunction(() => !document.querySelector('[data-preview-confirm]').disabled);
  await saveEvidenceScreenshot(page, `hotel-simple-desk-${v.width}-checkout.png`);
  let billConfirmPosts = 0;
  const countConfirm = request => { if (request.method() === 'POST' && request.url().endsWith(stayPath + '/bill-confirm')) ++billConfirmPosts; };
  page.on('request', countConfirm);
  const previewModal = page.locator('[data-hotel-bill-preview="1"]');
  await previewModal.waitFor({state:'visible'});
  if (!(await previewModal.locator('[data-preview-draft-receipt]').innerText()).includes('Draft receipt')) throw new Error('Check-in did not render a draft receipt');
  await page.keyboard.press('Escape');
  if (!await previewModal.isVisible()) throw new Error('Receipt review closed without explicit Close');
  await previewModal.locator('[data-preview-edit]').click();
  const editFrame = page.frameLocator('[data-preview-editor-frame]');
  await editFrame.locator('[data-hotel-draft-editor]').waitFor({state:'visible'});
  if (await editFrame.locator('[name="rate_amount"]').count() !== 2) throw new Error('Existing scoped rate/date/room editing missing from popup');
  for (const rate of ['3900','4000']) {
    const rateForm = editFrame.locator('form[action$="/move"]');
    await rateForm.locator('[name="rate_amount"]').fill(rate);
    await rateForm.locator('button[type="button"]').click();
    const rateSave = rateForm.locator('button:not([type="button"])');
    await rateSave.waitFor({state:'visible'});
    await page.waitForFunction(() => {
      const frame=document.querySelector('[data-preview-editor-frame]');
      return frame?.contentDocument?.querySelector('form[action$="/move"] button:not([type="button"])')?.disabled === false;
    });
    const [savedRate] = await Promise.all([page.waitForResponse(r=>r.url().endsWith(stayPath+'/move')&&r.request().method()==='POST'),rateSave.click()]);
    if (!savedRate.ok() && savedRate.status() !== 302) throw new Error('Same-room draft rate save failed');
    await editFrame.locator('[data-hotel-draft-editor]').waitFor({state:'visible'});
    await page.waitForFunction(rate => document.querySelector('[data-preview-editor-frame]')?.contentDocument?.querySelector('form[action$="/move"] [name="rate_amount"]')?.value === rate, rate);
  }

  const discountForm = editFrame.locator('form[action$="/discount"]');
  const originalDiscount = await discountForm.locator('[name="discount_value"]').inputValue();
  const [edited] = await Promise.all([
    page.waitForResponse(r => r.url().endsWith(stayPath + '/discount') && r.request().method() === 'POST'),
    discountForm.locator('button').click(),
  ]);
  if (edited.status() >= 400) throw new Error('Scoped draft edit failed');
  await editFrame.locator('[data-hotel-draft-editor]').waitFor({state:'visible'});
  if (await editFrame.locator('[name="discount_value"]').inputValue() !== originalDiscount) throw new Error('Draft edit changed saved discount unexpectedly');
  await previewModal.locator('[data-preview-editor-done]').click();
  await page.waitForFunction(() => !document.querySelector('[data-preview-confirm]').disabled);
  if (!(await previewModal.locator('[data-preview-lines]').innerText()).trim()) throw new Error('Bill preview omitted actual folio lines');
  await saveEvidenceScreenshot(page, `hotel-bill-preview-${v.width}-draft.png`);
  await previewModal.locator('[data-preview-back]').click();
  await previewModal.waitFor({state:'hidden'});
  if (billConfirmPosts !== 0) throw new Error('Closing preview created/reported a bill');
  if (await deskModal.locator('[data-desk-method]').inputValue() !== 'card' || Number(await deskModal.locator('[data-desk-amount]').inputValue()) !== 2500) throw new Error('Preview close lost payment edits');
  await page.locator('[data-hotel-open-desk="1"]').click();
  await previewModal.waitFor({state:'visible'});
  if (await deskModal.locator('[data-desk-flow]').inputValue() !== 'checkout' || await deskModal.locator('[data-desk-method]').inputValue() !== 'card' || Number(await deskModal.locator('[data-desk-amount]').inputValue()) !== 2500) throw new Error('Reopening receipt reset the cashier payment/action');
  await page.waitForFunction(() => !document.querySelector('[data-preview-confirm]').disabled);
  await deskModal.locator('[data-desk-flow]').selectOption('checkout');
  await page.waitForFunction(() => !document.querySelector('[data-preview-confirm]').disabled);
  // Lose a real NONZERO payment confirmation after disposable Laravel commits,
  // then reload the page. Recovery must use GET only, not a second payment POST.
  await deskModal.locator('[data-desk-flow]').selectOption('collect');
  await page.waitForFunction(() => !document.querySelector('[data-preview-confirm]').disabled);
  await deskModal.locator('[data-desk-amount]').fill('100');
  await deskModal.locator('[data-desk-update]').click();
  await page.waitForFunction(() => !document.querySelector('[data-preview-confirm]').disabled);
  await page.evaluate(() => {
    const original = window.fetch;
    window.fetch = async (...args) => {
      const url = typeof args[0] === 'string' ? args[0] : args[0].url;
      const response = await original(...args);
      if (url.endsWith('/bill-confirm') && args[1]?.method === 'POST' && response.ok) {
        window.__hotelCommittedResult = await response.clone().json();
        throw new Error('Synthetic committed response lost');
      }
      return response;
    };
  });
  await previewModal.locator('[data-preview-confirm]').click();
  await previewModal.locator('[data-preview-error]').filter({hasText:'Synthetic committed response lost'}).waitFor({state:'visible'});
  const firstBill = await page.evaluate(() => window.__hotelCommittedResult);
  const pendingAttempt = await page.evaluate(() => {
    const key = document.querySelector('[data-hotel-recovery-form]').dataset.recoveryKey;
    return JSON.parse(localStorage.getItem(key)).payload;
  });
  if (Number(pendingAttempt.amount) !== 100) throw new Error('Nonzero operation was not persisted before send');
  const recoveredResponse = page.waitForResponse(r => r.url().includes(stayPath + '/bill-recovery?') && r.request().method() === 'GET');
  await page.reload({waitUntil:'domcontentloaded'});
  const recovered = await recoveredResponse;
  if (!recovered.ok() || (await recovered.json()).result?.bill_id !== firstBill.bill_id) throw new Error('Reload did not reconcile the original bill');
  await previewModal.locator('[data-preview-result]').waitFor({state:'visible'});
  if (billConfirmPosts !== 1) throw new Error('Reload automatically repeated a monetary POST');
  const afterReload = await page.request.get(baseUrl + stayPath + '/checkout-quote?payment_method=card');
  if (!afterReload.ok() || Number((await afterReload.json()).balance) !== 2400) throw new Error('Nonzero payment was not recorded exactly once');
  if (await page.evaluate(() => localStorage.getItem(document.querySelector('[data-hotel-recovery-form]').dataset.recoveryKey)) !== null) throw new Error('Confirmed operation remained pending');
  pass(`${t.name}/${v.width}: real nonzero commit, lost response and reload recovered original invoice with GET only`);
  if (!firstBill.bill_id) throw new Error('Partial advance did not issue full bill');
  if (!await previewModal.isVisible()) throw new Error('Confirm closed the original receipt dialog');
  if(await previewModal.locator('[data-preview-edit]').isVisible() || await previewModal.locator('[data-preview-delete]').isVisible()) throw new Error('Issued receipt exposed draft edit/delete');
  if (await previewModal.locator('[data-preview-desk-controls]').isVisible()) throw new Error('Issued receipt still showed payment/action controls through display utilities');
  const receiptPopup = previewModal.locator('[data-preview-issued]');
  await receiptPopup.waitFor({state:'visible'});
  const receiptFrame = page.frameLocator('[data-receipt-frame]');
  await receiptFrame.locator('#receiptActions').waitFor({state:'attached'});
  await page.waitForFunction(() => !document.querySelector('[data-receipt-print]').disabled);
  await page.keyboard.press('Escape');
  if (!await previewModal.isVisible()) throw new Error('Issued receipt closed on Escape');
  if (!(await receiptFrame.locator('body').innerText()).includes(firstBill.invoice_number)) throw new Error('Receipt popup did not show the issued invoice');
  const receiptFrameBounds = await receiptPopup.locator('[data-receipt-frame]').boundingBox();
  const viewport = page.viewportSize();
  if (!receiptFrameBounds || receiptFrameBounds.width < Math.min(500, viewport.width - 90) || receiptFrameBounds.height < Math.min(288, viewport.height * .45)) throw new Error('Receipt popup is too small to read');
  if (await receiptPopup.locator('[name="paper"],[name="receipt_printer"]').count()) throw new Error('Receipt popup exposed printer settings');
  const frame = page.frames().find(frame => frame.url().includes('/bills/' + firstBill.bill_id + '/receipt'));
  if (!frame) throw new Error('Scoped receipt frame missing');
  await receiptPopup.locator('[data-receipt-print]').click();
  if (await frame.evaluate(() => window.__hotelPrintCalls) !== 1) throw new Error('Print did not invoke the receipt frame print');
  await saveEvidenceScreenshot(page, `hotel-receipt-popup-${v.width}.png`);
  await receiptPopup.locator('[data-receipt-close]').click();
  await previewModal.waitFor({state:'hidden'});
  await page.locator('[data-hotel-open-desk]').first().click();
  await previewModal.waitFor({state:'visible'});
  await receiptPopup.waitFor({state:'visible'});
  await page.waitForFunction(() => !document.querySelector('[data-receipt-print]').disabled);
  if (!await previewModal.locator('[data-preview-credit-note]').isVisible()) throw new Error('Credit note missing from shared popup');
  await saveEvidenceScreenshot(page, `hotel-bill-preview-${v.width}-advance-invoice.png`);
  pass(`${t.name}/${v.width}: check-in stayed in popup; actual invoice preview and Print invoked without a new tab or settings`);

  // Controlled transport faults test the client retry path; real enqueue/dedupe is covered by HTTP tests.
  const previewRoute = baseUrl + stayPath + '/receipt-preview';
  const printRoute = baseUrl + stayPath + '/bills/' + firstBill.bill_id + '/print';
  // The operational service worker stays enabled. Inject only these two fetches in the page,
  // since Playwright network routes cannot intercept requests fulfilled by that worker.
  await page.evaluate(({previewRoute,printRoute}) => {
    window.__hotelFetchOriginal = window.fetch;
    window.__hotelPrintAttempts = [];
    window.fetch = async (...args) => {
      const url = new URL(String(args[0]), location.href).href;
      if (url === printRoute) {
        window.__hotelPrintAttempts.push(JSON.parse(args[1].body).print_attempt_uuid);
        const first = window.__hotelPrintAttempts.length === 1;
        return new Response(JSON.stringify(first ? {success:false,message:'Synthetic lost response'} : {success:false,reason:'agent_offline'}),
          {status:first ? 503 : 409,headers:{'Content-Type':'application/json'}});
      }
      const response = await window.__hotelFetchOriginal(...args);
      if (url !== previewRoute) return response;
      const data = await response.json();
      return new Response(JSON.stringify({...data,silent:true}),{status:response.status,headers:{'Content-Type':'application/json'}});
    };
  }, {previewRoute,printRoute});
  await previewModal.locator('[data-preview-receipt]').click();
  await receiptPopup.waitFor({state:'visible'});
  await page.waitForFunction(() => !document.querySelector('[data-receipt-print]').disabled);
  await receiptPopup.locator('[data-receipt-print]').click();
  await page.waitForFunction(() => document.querySelector('[data-receipt-status]').textContent.includes('Synthetic lost response'), null, {timeout:10000}).catch(async error => {
    const state = await receiptPopup.evaluate(el => ({status:el.querySelector('[data-receipt-status]').textContent,disabled:el.querySelector('[data-receipt-print]').disabled,frame:el.querySelector('iframe').src}));
    throw new Error('Lost-response probe: ' + JSON.stringify({state,attempts:await page.evaluate(()=>window.__hotelPrintAttempts),http:diagnostics.httpErrors}) + ' ' + error.message);
  });
  if (await receiptPopup.locator('[data-receipt-browser]').isVisible()) throw new Error('Uncertain print silently enabled duplicate browser fallback');
  await receiptPopup.locator('[data-receipt-close]').click();
  await previewModal.waitFor({state:'hidden'});
  await page.locator('[data-hotel-open-desk]').first().click();
  await previewModal.waitFor({state:'visible'});
  await receiptPopup.waitFor({state:'visible'});
  await page.waitForFunction(() => !document.querySelector('[data-receipt-print]').disabled);
  await previewModal.locator('[data-preview-receipt]').click();
  await receiptPopup.waitFor({state:'visible'});
  await page.waitForFunction(() => !document.querySelector('[data-receipt-print]').disabled);
  await receiptPopup.locator('[data-receipt-print]').click();
  await receiptPopup.locator('[data-receipt-browser]').waitFor({state:'visible'});
  const attempts = await page.evaluate(() => window.__hotelPrintAttempts);
  if (attempts.length !== 2 || attempts[0] !== attempts[1]) throw new Error('Lost enqueue retry changed its UUID after close/reopen');
  await receiptPopup.locator('[data-receipt-browser]').click();
  await saveEvidenceScreenshot(page, `hotel-print-safe-fallback-${v.width}.png`);
  // Controlled terminal Agent acknowledgement proves that polling follows the inline dialog.
  await page.evaluate(({printRoute,stayPath}) => {
    const previousFetch = window.fetch;
    window.__hotelInlineStatusReads = 0;
    window.fetch = async (...args) => {
      const url = new URL(String(args[0]), location.href).href;
      if (url === printRoute) return new Response(JSON.stringify({success:true,job_id:987654}), {status:200,headers:{'Content-Type':'application/json'}});
      if (url.endsWith(stayPath + '/print-jobs/987654')) {
        ++window.__hotelInlineStatusReads;
        return new Response(JSON.stringify({status:'done'}), {status:200,headers:{'Content-Type':'application/json'}});
      }
      return previousFetch(...args);
    };
  }, {printRoute,stayPath});
  await receiptPopup.locator('[data-receipt-print]').click();
  await page.waitForFunction(() => window.__hotelInlineStatusReads > 0 && document.querySelector('[data-receipt-status]').textContent.includes(window.hotelDeskPopupConfig.labels.agent_done), null, {timeout:10000});
  if (await receiptPopup.locator('[data-receipt-browser]').isVisible()) throw new Error('Acknowledged inline job offered duplicate fallback');
  await receiptPopup.locator('[data-receipt-close]').click();
  await previewModal.waitFor({state:'hidden'});
  await page.locator('[data-hotel-open-desk]').first().click();
  await previewModal.waitFor({state:'visible'});
  await receiptPopup.waitFor({state:'visible'});
  await page.waitForFunction(() => !document.querySelector('[data-receipt-print]').disabled);
  await page.evaluate(() => { window.fetch = window.__hotelFetchOriginal; delete window.__hotelFetchOriginal; delete window.__hotelPrintAttempts; });
  pass(`${t.name}/${v.width}: uncertain response retained UUID through close/reopen; explicit rejected-enqueue browser fallback`);

  // Reproduce the owner transactions-list Return click; it must open Hotel review.
  const returnPage = await page.context().newPage();
  await returnPage.goto(baseUrl + '/pos/transactions?tab=local', {waitUntil:'domcontentloaded'});
  await dismiss(returnPage);
  const hotelReturn = returnPage.locator(`[data-hotel-return-entry="${firstBill.bill_id}"]`);
  await hotelReturn.click();
  await returnPage.waitForURL(baseUrl + stayPath + '/credit-notes#bill-' + firstBill.bill_id);
  await returnPage.locator(`[data-hotel-credit-notes] #bill-${firstBill.bill_id}`).waitFor({state:'visible'});
  if ((await returnPage.locator('body').innerText()).includes('Use the Hotel Return / Credit Note screen for this bill.')) throw new Error('Hotel Return still redirects to the generic guard error');
  await saveEvidenceScreenshot(returnPage, `hotel-return-entry-${v.width}.png`);
  await returnPage.close();
  pass(`${t.name}/${v.width}: transactions Return opens the original Hotel bill credit-review screen`);
  const billsPage = await page.context().newPage();
  await billsPage.goto(baseUrl + '/pos/hotel/folios', {waitUntil:'domcontentloaded'});
  await dismiss(billsPage);
  await billsPage.locator('[data-hotel-billing]').waitFor({state:'visible'});
  const backLink = billsPage.locator('[data-hotel-report-back]');
  if(new URL(await backLink.getAttribute('href'), baseUrl).pathname !== '/pos/hotel') throw new Error('Bills Back missed Hotel Dashboard');
  await billsPage.locator('[data-hotel-tax-toggle]').click();
  if(await billsPage.locator('[data-hotel-tax-detail]').first().isHidden()) throw new Error('Tax details did not expand');
  await billsPage.locator('[data-hotel-tax-toggle]').click();
  await backLink.click(); await billsPage.waitForURL(baseUrl + '/pos/hotel');
  await billsPage.goto(baseUrl + '/pos/reports', {waitUntil:'domcontentloaded'});
  await billsPage.locator('[data-hotel-report-back]').click(); await billsPage.waitForURL(baseUrl + '/pos/hotel');
  await billsPage.goto(baseUrl + '/pos/hotel/folios', {waitUntil:'domcontentloaded'});
  const invoiceEntry = billsPage.locator(`[data-hotel-invoice="${firstBill.bill_id}"]`);
  await invoiceEntry.waitFor({state:'visible'});
  if (!(await invoiceEntry.innerText()).includes(firstBill.invoice_number)) throw new Error('Hotel Bills lost the original invoice number');
  await invoiceEntry.locator('a').first().click();
  await billsPage.waitForURL(baseUrl + '/pos/transaction/' + firstBill.bill_id);
  await saveEvidenceScreenshot(billsPage, `hotel-bills-original-${v.width}.png`);
  await billsPage.goto(baseUrl + '/pos/hotel/folios', {waitUntil:'domcontentloaded'});
  const filterForm = billsPage.locator('[data-hotel-billing-filters]');
  await filterForm.locator('[name="period"]').selectOption('all');
  await filterForm.locator('[name="invoice"]').fill(firstBill.invoice_number);
  await Promise.all([billsPage.waitForNavigation({waitUntil:'domcontentloaded'}),filterForm.locator('button').click()]);
  if (await billsPage.locator('[data-hotel-invoice]').count() !== 1) throw new Error('Invoice filter did not match one real bill');
  const [csvDownload] = await Promise.all([billsPage.waitForEvent('download'),billsPage.locator('a[href*="/folios/csv"]').click()]);
  const csvPath = await csvDownload.path();
  const csvText = readFileSync(csvPath,'utf8');
  if (!csvText.includes(firstBill.invoice_number)) throw new Error('Filtered Hotel CSV omitted the invoice');
  const [pdfDownload] = await Promise.all([billsPage.waitForEvent('download'),billsPage.locator('a[href*="/folios/pdf"]').click()]);
  if (!readFileSync(await pdfDownload.path()).subarray(0,4).equals(Buffer.from('%PDF'))) throw new Error('Filtered Hotel PDF failed');
  await saveEvidenceScreenshot(billsPage, `hotel-filtered-exports-${v.width}.png`);

  await billsPage.close();
  pass(`${t.name}/${v.width}: Hotel Bills links the same original invoice as Transactions`);

  await previewModal.locator('[data-preview-checkout]').click();
  await page.waitForFunction(() => { const d = document.querySelector('[data-hotel-bill-preview]'); return d && !d.querySelector('[data-preview-confirm]').disabled && Number(d.querySelector('[data-desk-amount]').value) === 2400; });
  const [confirmed] = await Promise.all([
    page.waitForResponse(r => r.url().endsWith(stayPath + '/bill-confirm') && r.request().method() === 'POST'),
    previewModal.locator('[data-preview-confirm]').click(),
  ]);
  if (!confirmed.ok()) throw new Error('Remaining payment checkout failed');
  const checkoutResult = await confirmed.json();
  await receiptPopup.waitFor({state:'visible'}).catch(async error => {
    const state = await previewModal.evaluate(el => ({open:el.open,error:el.querySelector('[data-preview-error]').textContent,issuedHidden:el.querySelector('[data-preview-issued]').hidden,issuedChildren:el.querySelector('[data-preview-issued]').childElementCount,receiptUrl:el.querySelector('[data-preview-receipt]').href}));
    throw new Error('Checkout receipt: ' + JSON.stringify({checkoutResult,state}) + ' ' + error.message);
  });
  await page.waitForFunction(() => !document.querySelector('[data-receipt-print]').disabled);
  if (checkoutResult.bill_id !== firstBill.bill_id) throw new Error('Checkout duplicated the existing full bill');
  if (billConfirmPosts !== 2) throw new Error('Expected one initial payment and one checkout; reload must not pay again');
  if (await previewModal.locator('[data-preview-qr]').isVisible()) throw new Error('Unreported local bill advertised a PRA QR');
  if (new URL(page.url()).pathname !== stayPath) throw new Error('Checkout automatically left the receipt before Close');
  await saveEvidenceScreenshot(page, `hotel-bill-preview-${v.width}-confirmed.png`);
  page.off('request', countConfirm);
  await Promise.all([page.waitForURL(baseUrl + '/pos/hotel', {timeout:30000}), receiptPopup.locator('[data-receipt-close]').click()]);
  pass(`${t.name}/${v.width}: completed checkout Close returned to Hotel Dashboard`);
  await page.goto(baseUrl + stayPath, {waitUntil:'domcontentloaded'});
  await dismiss(page);
  if (!await page.locator('a[href*="/bills/"][href$="/receipt"]').count()) throw new Error('Checkout must expose the issued receipt');
  let historicalMoneyPosts = 0;
  const countHistoricalMoney = request => {
    if (request.method() === 'POST' && /\/(?:bill-confirm|payments|checkout|print)$/.test(new URL(request.url()).pathname)) ++historicalMoneyPosts;
  };
  page.on('request', countHistoricalMoney);
  await page.locator(`a[href$="${stayPath}/bills/${firstBill.bill_id}/receipt"]`).first().click();
  const historicalPopup = page.locator('[data-hotel-receipt-popup]');
  await historicalPopup.waitFor({state:'visible'});
  await page.waitForFunction(() => !document.querySelector('[data-hotel-receipt-popup] [data-receipt-print]').disabled);
  if (new URL(page.url()).pathname !== stayPath) throw new Error('Historical receipt changed the stay page');
  await saveEvidenceScreenshot(page, `hotel-historical-receipt-${v.width}.png`);
  await historicalPopup.locator('[data-receipt-close]').click();
  await historicalPopup.waitFor({state:'hidden'});
  if (new URL(page.url()).pathname !== stayPath) throw new Error('Historical receipt Close redirected to Dashboard');
  if (historicalMoneyPosts !== 0) throw new Error('Historical receipt reprint created a money or print request');
  page.off('request', countHistoricalMoney);
  pass(`${t.name}/${v.width}: standalone historical receipt Close preserved stay page without payment or print POST`);
  if (await page.locator(`a[href="${baseUrl}${stayPath}/checkout"]`).count()) throw new Error('Checked-out stay must not expose another checkout');
  pass(`${t.name}/${v.width}: room check-in, edited rate, discount, advance, card checkout and receipt passed`);
  // Correct the actual fictional issued stay through the owner UI, not mocks.
  await page.goto(baseUrl + stayPath + '/correction', {waitUntil:'domcontentloaded'});
  await dismiss(page);
  const correction = page.locator('[data-hotel-correction="1"] form');
  await correction.locator('[name="reason"]').fill('Synthetic duplicate stay correction');
  await correction.locator('[name="payment_method"]').selectOption('card');
  await correction.locator('[name="confirmed"]').check();
  await saveEvidenceScreenshot(page, `hotel-correction-${v.width}-preview.png`);
  await Promise.all([page.waitForURL(baseUrl + stayPath, {timeout:30000,waitUntil:'domcontentloaded'}), correction.locator('button').click()]);
  await dismiss(page);
  if (!await page.locator('a[href*="/bills/"][href$="/receipt"]').count()) throw new Error('Correction erased the source receipt');
  await page.goto(baseUrl + stayPath + '/correction', {waitUntil:'domcontentloaded'});
  await dismiss(page);
  await page.locator('[data-hotel-correction="1"] [role="alert"]').waitFor({state:'visible'});
  if (await page.locator('[data-hotel-correction="1"] form').count()) throw new Error('Corrected stay can be reversed twice');
  if (!(await page.locator('[data-hotel-correction="1"]').innerText()).includes('Rs 0.00')) throw new Error('Corrected charges/money did not become zero');
  pass(`${t.name}/${v.width}: actual owner correction preserved source bill and prevented repeated reversal`);

  const stayId = stayPath.split('/').pop();
  const originalGuest = 'Synthetic Simple Desk ' + room;
  const editedGuest = 'Synthetic Edited Guest ' + room;
  await page.goto(baseUrl + '/pos/hotel/guests', {waitUntil:'domcontentloaded'});
  await dismiss(page);
  const guestRow = page.locator('tbody tr').filter({hasText:originalGuest});
  await Promise.all([page.waitForURL(/guests\/\d+\/edit$/), guestRow.locator(`a[href$="/guests/${stayId}/edit"]`).click()]);
  const guestForm = page.locator(`form[action$="/guests/${stayId}"]`);
  await guestForm.locator('[name="guest_name"]').fill(editedGuest);
  await guestForm.locator('[name="guest_phone"]').fill('03110000000');
  await Promise.all([page.waitForURL(baseUrl + '/pos/hotel/guests'), guestForm.locator('button').click()]);
  await dismiss(page);
  if (!await page.locator('tbody tr').filter({hasText:editedGuest}).count()) throw new Error('Guest edit was not reflected in directory');
  await saveEvidenceScreenshot(page, `hotel-guests-${v.width}-edited.png`);
  await page.goto(baseUrl + '/pos/hotel/stays/create', {waitUntil:'domcontentloaded'});
  if (!await page.locator('option').filter({hasText:editedGuest}).count()) throw new Error('Returning guest selector lost edited profile');
  const accountSection = page.locator('[data-hotel-account-billing]');
  if (await accountSection.getAttribute('open') !== null) throw new Error('Corporate payer section must be optional and collapsed');
  await page.goto(baseUrl + stayPath, {waitUntil:'domcontentloaded'});
  if (!(await page.locator('body').innerText()).includes(originalGuest)) throw new Error('Guest edit rewrote historical stay');
  await page.goto(baseUrl + '/pos/hotel/guests', {waitUntil:'domcontentloaded'});
  await dismiss(page);
  const removal = page.locator(`form[action$="/guests/${stayId}"]`);
  page.once('dialog', dialog => dialog.accept());
  await Promise.all([page.waitForResponse(r => r.url().endsWith(`/guests/${stayId}`) && r.request().method() === 'POST'), removal.locator('button').click()]);
  await page.locator('tbody tr').filter({hasText:editedGuest}).waitFor({state:'detached'});
  if (await page.locator('tbody tr').filter({hasText:editedGuest}).count()) throw new Error('Deleted directory profile reappeared');
  await saveEvidenceScreenshot(page, `hotel-guests-${v.width}-removed.png`);
  await page.goto(baseUrl + stayPath, {waitUntil:'domcontentloaded'});
  if (!(await page.locator('body').innerText()).includes(originalGuest)) throw new Error('Guest deletion erased historical stay');
  pass(`${t.name}/${v.width}: guest edit/removal and returning selector preserve historical stays; account billing stays optional`);

  // Second real UI booking: full advance, original receipt, zero-money checkout.
  await page.goto(baseUrl + '/pos/hotel', {waitUntil:'domcontentloaded'}); await dismiss(page);
  const dirtyPrepaidRoom = page.locator('[data-hotel-room-state="dirty"]').filter({hasText:room});
  if (await dirtyPrepaidRoom.count()) {
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),dirtyPrepaidRoom.locator('form[action$="/housekeeping"] button').click()]);
    await dismiss(page);
  }
  const prepaidRoom = page.locator('[data-hotel-room-state="vacant"]').filter({hasText:room});
  await prepaidRoom.locator('[data-hotel-room-check-in]').click();
  await bookingPopup.waitFor({state:'visible'});
  const prepaidForm = bookingPopup.locator('form');
  await prepaidForm.locator('[name="guest_name"]').fill('Synthetic prepaid ' + room);
  await prepaidForm.locator('[name="rate_amount"]').fill('4000');
  await prepaidForm.locator('[name="discount_type"]').selectOption('amount');
  await prepaidForm.locator('[name="discount_value"]').fill('500');
  await prepaidForm.locator('details').filter({has:page.locator('[name="advance_amount"]')}).locator('summary').click();
  await prepaidForm.locator('[name="advance_amount"]').fill('3500');
  await Promise.all([page.waitForURL(/\/pos\/hotel\/stays\/\d+$/,{waitUntil:'domcontentloaded'}),prepaidForm.locator('button').click()]);
  const prepaidReview = page.locator('[data-hotel-bill-preview]');
  await prepaidReview.waitFor({state:'visible'});
  await page.waitForFunction(()=>!document.querySelector('[data-preview-confirm]').disabled);
  if (await prepaidReview.locator('[data-desk-amount]').isVisible()) throw new Error('Fully prepaid receipt requested another amount');
  const [prepaidIssued] = await Promise.all([page.waitForResponse(r=>r.url().endsWith('/bill-confirm')&&r.request().method()==='POST'),prepaidReview.locator('[data-preview-confirm]').click()]);
  if (!prepaidIssued.ok()) throw new Error('Fully prepaid bill confirmation failed');
  const prepaidBill = await prepaidIssued.json();
  await page.waitForFunction(()=>!document.querySelector('[data-receipt-print]').disabled);
  await prepaidReview.locator('[data-preview-checkout]').click();
  await page.waitForFunction(()=>!document.querySelector('[data-preview-confirm]').disabled);
  if (await prepaidReview.locator('[data-desk-amount]').isVisible() || Number(await prepaidReview.locator('[data-desk-amount]').inputValue()) !== 0) throw new Error('Paid checkout did not suppress payment');
  const [prepaidCheckedOut] = await Promise.all([page.waitForResponse(r=>r.url().endsWith('/bill-confirm')&&r.request().method()==='POST'),prepaidReview.locator('[data-preview-confirm]').click()]);
  if (!prepaidCheckedOut.ok()) throw new Error('Zero-money checkout failed');
  const prepaidResult = await prepaidCheckedOut.json();
  if (prepaidResult.stay_status !== 'checked_out' || prepaidResult.bill_id !== prepaidBill.bill_id) throw new Error('Paid checkout changed the original invoice');
  await saveEvidenceScreenshot(page, `hotel-paid-checkout-${v.width}.png`);
  pass(`${t.name}/${v.width}: full advance hides amount; checkout reuses original invoice without another payment`);


}
async function hotelCreditReview(page, t, v) {
  const path = t.hotelCreditReview.path;
  await page.goto(baseUrl + '/pos/tax-reports?period=all&tab=pra', {waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  const shortcut = page.locator(`a[data-tax-credit-note="${t.hotelCreditReview.billId}"]`);
  await shortcut.waitFor({state:'visible'});
  const scroll = page.locator('[data-tax-report-scroll="1"]');
  for (const right of [false, true]) {
    await scroll.evaluate(async (el, right) => {
      el.scrollLeft = right ? el.scrollWidth : 0;
      await new Promise(resolve => requestAnimationFrame(resolve));
    }, right);
    const bounds = await scroll.boundingBox(), button = await shortcut.boundingBox();
    if (!bounds || !button || button.x < bounds.x - 1 || button.x + button.width > bounds.x + bounds.width + 1)
      throw new Error('Credit Note action escaped the visible scrolled table: ' + JSON.stringify({right,bounds,button,layout:await scroll.evaluate(el=>({scrollLeft:el.scrollLeft,scrollWidth:el.scrollWidth,clientWidth:el.clientWidth,tableDisplay:getComputedStyle(el.querySelector('table')).display,tableOverflow:getComputedStyle(el.querySelector('table')).overflowX}))}));
    await saveEvidenceScreenshot(page, `tax-credit-sticky-${right ? 'right' : 'left'}-${v.width}.png`);
  }
  await Promise.all([page.waitForURL(/\/credit-notes#bill-\d+$/), shortcut.click()]);
  if (new URL(page.url()).pathname !== path) throw new Error('Tax Reports credit shortcut opened a different stay');
  await page.locator('[data-hotel-credit-notes="1"]').waitFor({state:'visible'});
  await saveEvidenceScreenshot(page, `tax-report-credit-shortcut-${v.width}.png`);
  pass(`${t.name}/${v.width}: Tax Reports opens original Hotel credit review directly`);

  const dialog = page.locator('dialog[data-credit-bill="'+t.hotelCreditReview.billId+'"]');
  await dialog.waitFor({state:'visible'});
  if (await dialog.locator('form').count()) throw new Error('Default-OFF credit issuance became actionable');
  if (!(await dialog.innerText()).includes('2,000.00')) throw new Error('Whole invoice total missing');
  await dialog.locator('[data-credit-close]').click();
  await dialog.waitFor({state:'hidden'});
  if (await page.locator('[data-credit-activation]').count()) throw new Error('Everyday flow exposed activation settings');
  const settings = async (issuance, refunds, settingsPath=path) => {
    await page.goto(baseUrl+settingsPath+'?settings=1',{waitUntil:'domcontentloaded'});
    await waitForOperationalSurface(page); await dismiss(page);
    const activation=page.locator('[data-credit-activation="1"]');
    await activation.locator('[name="issuance"]').selectOption(issuance);
    await activation.locator('[name="refunds"]').selectOption(refunds);
    await activation.locator('[name="confirmed"]').check();
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),activation.locator('button').click()]);
    await activation.locator('[name="issuance"]').waitFor({state:'visible'});
    if (await activation.locator('[name="issuance"]').inputValue()!==issuance) throw new Error('Owner setting did not persist');
  };
  await settings('1','0');
  await page.goto(baseUrl+path+'#bill-'+t.hotelCreditReview.billId,{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  await dialog.waitFor({state:'visible'});
  const issue=dialog.locator('form[data-credit-simple="1"]');
  await issue.waitFor({state:'visible'});
  if (await issue.locator('[name="mode"]').inputValue()!=='full') throw new Error('Simple flow did not use whole invoice');
  if (await issue.locator('input[type="number"],select').count()) throw new Error('Simple flow retained manual entry controls');
  const reason=issue.locator('textarea[name="reason"]');
  if (await reason.getAttribute('required') === null) throw new Error('Actual credit reason is not required');
  if (await issue.evaluate(form=>form.checkValidity())) throw new Error('Empty credit reason accepted by browser');
  await reason.fill('Synthetic unused service correction');
  if (!await issue.evaluate(form=>form.checkValidity())) throw new Error('Valid actual reason refused');
  if (await issue.locator('button[type="submit"]').count()!==1) throw new Error('Single confirmation action missing');
  await saveEvidenceScreenshot(page,'hotel-credit-simple-full-'+v.width+'.png');
  // No fiscal POST in browser: real controller POST/idempotency is exercised with synthetic backend fixtures.
  await dialog.locator('[data-credit-close]').click();
  await page.locator('#bill-'+t.hotelCreditReview.billId+' [data-credit-open]').click();
  await dialog.waitFor({state:'visible'});
  await page.keyboard.press('Escape');
  await dialog.waitFor({state:'hidden'});
  await page.goto(baseUrl+t.hotelCreditReview.refundPath,{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  if (await page.locator('form[action$="/refund"]').count()) throw new Error('Refund appeared before separate opt-in');
  await page.locator('[data-credit-open^="credit-result-"]').first().click();
  const result=page.locator('dialog[open][id^="credit-result-"]');
  await result.waitFor({state:'visible'});
  if (!await result.locator('[data-credit-status="accepted"]').count()) throw new Error('Accepted credit status missing');
  if (!(await result.innerText()).includes('Credit not yet refunded')) throw new Error('Refund balance missing');
  const printLink=result.locator('a[href*="/receipt"]');
  await printLink.waitFor({state:'visible'});
  await printLink.click();
  const creditReceiptPopup=page.locator('[data-hotel-receipt-popup]');
  await creditReceiptPopup.waitFor({state:'visible'});
  const creditReceiptFrame=page.frameLocator('[data-receipt-frame]');
  await creditReceiptFrame.locator('#receiptActions').waitFor({state:'attached'});
  if (!(await creditReceiptFrame.locator('body').innerText()).includes('SYNTHETIC-ACCEPTED-CREDIT')) throw new Error('Print opened a different or missing credit receipt');
  const receiptText=await creditReceiptFrame.locator('body').innerText();
  if (!receiptText.includes('Credit amount') || !receiptText.includes('Credit not yet refunded') || receiptText.includes('Thank you for your purchase')) throw new Error('Credit receipt still reads as a sale');
  await saveEvidenceScreenshot(page,'hotel-credit-print-'+v.width+'.png');
  await creditReceiptPopup.locator('[data-receipt-close]').click();
  await result.waitFor({state:'visible'});
  await saveEvidenceScreenshot(page,'hotel-credit-view-existing-'+v.width+'.png');
  await page.keyboard.press('Escape');
  await settings('0','1',t.hotelCreditReview.refundPath);
  await page.goto(baseUrl+t.hotelCreditReview.refundPath,{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  const refund=page.locator('form[action$="/refund"]').first();
  await refund.waitFor({state:'visible'});
  await refund.locator('[name="amount"]').fill('100');
  await refund.locator('[name="method"]').selectOption('card');
  await refund.locator('[name="confirmed"]').check();
  await saveEvidenceScreenshot(page,'hotel-credit-separate-refund-'+v.width+'.png');
  await settings('0','0');
  await page.goto(baseUrl+path,{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  await page.locator('[data-hotel-credit-notes] > [role="alert"]').waitFor({state:'visible'});
  pass(t.name+'/'+v.width+': single full-invoice popup, separate settings/refund, existing-note view and default-OFF restoration passed');

}

async function restaurantTopSellingWorkflow(page,t,v) {
  await page.goto(baseUrl+'/pos/restaurant/dashboard',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);await dismiss(page);
  const viewAll=page.locator('a[href$="/pos/restaurant/top-selling"]').first();
  await Promise.all([page.waitForURL(u=>u.pathname==='/pos/restaurant/top-selling'),viewAll.click()]);
  const summary=page.locator('[data-top-selling-summary]');
  await summary.waitFor({state:'visible'});
  if(await summary.locator('tbody tr').count()!==16)throw new Error('View All did not show all sixteen sold items');
  await summary.getByRole('link',{name:'Synthetic complete item 16',exact:true}).click();
  const details=page.locator('[data-top-selling-details]');
  await details.waitFor({state:'visible'});
  const text=await details.innerText();
  if(!text.includes('RC-TOP-ORDER')||!text.includes('1,600.00'))throw new Error('selected item order/amount details missing');
  const from=page.locator('input[name="from"]');const to=page.locator('input[name="to"]');
  const current=await from.inputValue();await from.fill(current);await to.fill(current);
  await Promise.all([page.waitForURL(u=>u.searchParams.get('from')===current&&!u.searchParams.has('item')),page.locator('form button').filter({hasText:'Apply'}).click()]);
  if(await summary.locator('tbody tr').count()!==16)throw new Error('filter change lost full item list');
  pass(t.name+'/'+v.width+': real View All opens sixteen items and selected-order detail, filters retain complete view');
}

async function notificationWorkflow(page, t, v, diagnostics) {
  const panel=t.notificationWorkflow, prefix=panel==='di'?'':panel==='fbr'?'/fbr-pos':'/pos';
  await page.goto(baseUrl+(panel==='di'?'/profile':prefix+'/my-profile'),{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);
  const expectedFailureUrl=baseUrl+prefix+'/whats-new/seen';
  if(panel!=='di'){
  const modal=page.locator('[data-wn-featured="1"]:visible');
  await modal.waitFor({state:'visible',timeout:15000});
  if(await modal.count()!==1)throw new Error('duplicate featured receipts rendered multiple popups');
  const dismissButton=modal.locator('button').last();
  const endpoint='**'+prefix+'/whats-new/seen';
  let injectedFailures=0;
  await page.route(endpoint, route=>{
    if(route.request().method()!=='POST')return route.continue();
    injectedFailures++;
    return route.fulfill({status:500,contentType:'application/json',body:'{"ok":false}'});
  });
  const [failedAck]=await Promise.all([
    page.waitForResponse(r=>r.url()===expectedFailureUrl&&r.request().method()==='POST'),
    dismissButton.click(),
  ]);
  if(failedAck.status()!==500||injectedFailures!==1)throw new Error('failed-save probe did not inject exactly one expected response');
  await modal.locator('[role="alert"]:visible').waitFor({state:'visible',timeout:5000});
  if(!await modal.isVisible())throw new Error('failed acknowledgement silently closed the notice');
  await page.unroute(endpoint);
  // Remove only the one asserted fault-injection response; later real failures still fail acceptance.
  const injectedHttp=diagnostics.httpErrors.findIndex(x=>x.url===expectedFailureUrl&&x.method==='POST'&&x.status===500);
  if(injectedHttp<0)throw new Error('expected injected failure was not recorded by diagnostics');
  diagnostics.httpErrors.splice(injectedHttp,1);
  const injectedConsole=diagnostics.consoleErrors.findIndex(x=>x.includes(expectedFailureUrl)&&/500/.test(x)&&/Failed to load resource/.test(x));
  if(injectedConsole>=0)diagnostics.consoleErrors.splice(injectedConsole,1);
  const [ack]=await Promise.all([
    page.waitForResponse(r=>r.url().endsWith(prefix+'/whats-new/seen')&&r.request().method()==='POST'),
    dismissButton.click(),
  ]);
  if(!ack.ok()||!(await ack.json()).ok)throw new Error('notification retry was not acknowledged by the real server');
  await modal.waitFor({state:'hidden',timeout:5000});
  }
  for(let refresh=0;refresh<2;refresh++){
    await page.reload({waitUntil:'domcontentloaded'});
    await waitForOperationalSurface(page);
    if(await page.locator('[x-show="wnOpen"]:visible').count())throw new Error('refresh reopened an acknowledged featured notice or routine queue');
    await page.locator('[data-tn-topnav-control="notification"]').first().click();
    const bell=page.locator('[x-show="bellOpen"]:visible').first();
    await bell.waitFor({state:'visible',timeout:5000});
    const text=await bell.innerText();
    for(let i=1;i<=7;i++)if(!text.includes('Synthetic routine '+panel+' '+i))throw new Error('routine bell history disappeared after refresh');
    if(panel!=='di'&&(text.match(new RegExp('Synthetic featured '+panel,'g'))||[]).length!==1)throw new Error('duplicate deployment receipts were not consolidated in bell history');
    if(/Synthetic routine (pra|fbr|di) /.test(text.replaceAll('Synthetic routine '+panel+' ', 'Own notice ')))throw new Error('another product notification leaked into the bell');
    await page.locator('[data-tn-topnav-control="notification"]').first().click();
  }
  const notificationButton=page.locator('[data-tn-topnav-control="notification"]').first();
  const count=notificationButton.locator('[x-text="unseen"]');
  const before=Number((await count.innerText()).trim());
  if(!Number.isInteger(before)||before<7)throw new Error('routine notices missing from unread count');
  await notificationButton.click();
  const row=page.locator('[x-show="bellOpen"]:visible button').filter({hasText:'Synthetic routine '+panel+' 1'}).first();
  await page.waitForFunction(()=>[...document.querySelectorAll('[data-whats-new-detail-id]')].every(el=>el._x_dataStack?.length));
  const [detailAck]=await Promise.all([
    page.waitForResponse(r=>r.url()===expectedFailureUrl&&r.request().method()==='POST'), row.click(),
  ]).catch(async error=>{
    const states=await page.locator('[data-whats-new-detail-id]').evaluateAll(nodes=>nodes.map(n=>({id:n.dataset.whatsNewDetailId,open:window.Alpine.$data(n).open,seen:window.Alpine.$data(n).wasSeen,error:window.Alpine.$data(n).seenError})));
    throw new Error(error.message+' detail state '+JSON.stringify(states));
  });
  if(!detailAck.ok()||!(await detailAck.json()).ok)throw new Error('opening detail did not persist read status');
  const detail=page.locator('[data-whats-new-detail-id]:visible');
  await detail.waitFor({state:'visible'});
  await page.waitForFunction(expected=>[...document.querySelectorAll('[data-tn-topnav-control="notification"] [x-text="unseen"]')].some(x=>Number(x.textContent.trim())===expected),before-1);
  await detail.getByRole('button',{name:'Close',exact:true}).click();
  await notificationButton.click();
  if(await row.locator('[x-show="rowUnseen"]').isVisible())throw new Error('opened notification still displays NEW');
  await row.click();
  await page.locator('[data-whats-new-detail-id]:visible').getByRole('button',{name:'Close',exact:true}).click();
  if(Number((await count.innerText()).trim())!==before-1)throw new Error('reopening read notice decremented count again');
  await page.reload({waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);
  if(Number((await count.innerText()).trim())!==before-1)throw new Error('read acknowledgement did not survive reload');
  pass(t.name+'/'+v.width+': read on open clears NEW, persists count, repeat is idempotent; failed-save retry and seven routine notices');
}

async function hotelSettingsWorkflow(page,t,v,diagnostics) {
  await page.goto(baseUrl+'/pos/customize',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);
  await dismiss(page);
  const root=page.locator('[data-hotel-settings="1"]');
  await root.waitFor({state:'visible'});
  if(await root.locator('[data-hotel-settings-card]').count()!==6)throw new Error('Hotel settings must have exactly six primary groups');
  if(await root.locator('[data-hotel-settings-outlet]').count())throw new Error('Rooms-only settings exposed restaurant controls');
  if(await root.locator('[data-hotel-settings-advanced]').getAttribute('open')!==null)throw new Error('Advanced settings were expanded by default');
  const appearance=root.locator('[data-hotel-settings-card="appearance"]');
  await appearance.locator('summary').click();
  pass(t.name+'/'+v.width+': Appearance opened through an actual pointer click');
  const rose=appearance.locator('[data-hotel-theme="rose"]');
  const themeUrl=baseUrl+'/pos/settings/theme';
  await page.route('**/pos/settings/theme',route=>route.fulfill({status:500,contentType:'application/json',body:'{"success":false,"message":"Synthetic failed save"}'}));
  const [failure]=await Promise.all([page.waitForResponse(r=>r.url()===themeUrl&&r.request().method()==='POST'),rose.click()]).catch(async error=>{
    const state=await root.evaluate(el=>{const d=window.Alpine.$data(el);return {busy:d.busy,status:d.status,error:d.error,component:el.getAttribute('x-data')};});
    throw new Error('Theme request failed: '+error.message+'; state='+JSON.stringify(state)+'; pageErrors='+JSON.stringify(diagnostics.pageErrors));
  });
  if(failure.status()!==500)throw new Error('Failed-save probe did not inject expected response');
  await root.locator('[role="alert"]:visible').waitFor({state:'visible'});
  await page.waitForFunction(()=>document.querySelector('[data-hotel-theme="rose"]')?.disabled===false,null,{timeout:5000}).catch(async error=>{
    const state=await root.evaluate(el=>{const d=window.Alpine.$data(el);return {busy:d.busy,status:d.status,error:d.error};});
    throw new Error('Failed save did not release theme control: '+JSON.stringify(state)+'; '+error.message);
  });
  pass(t.name+'/'+v.width+': injected failure kept the control usable for retry');
  if(await page.locator('body').getAttribute('data-theme')!=='blue')throw new Error('Unconfirmed theme save changed appearance');
  if(await appearance.locator('[data-hotel-theme="blue"]').getAttribute('aria-pressed')!=='true')throw new Error('Failed save changed selected colour');
  await page.unroute('**/pos/settings/theme');
  const injectedHttp=diagnostics.httpErrors.findIndex(x=>x.url===themeUrl&&x.method==='POST'&&x.status===500);
  if(injectedHttp<0)throw new Error('Expected failed-save response not recorded');
  diagnostics.httpErrors.splice(injectedHttp,1);
  const injectedConsole=diagnostics.consoleErrors.findIndex(x=>x.includes(themeUrl)&&/500/.test(x)&&/Failed to load resource/.test(x));
  if(injectedConsole>=0)diagnostics.consoleErrors.splice(injectedConsole,1);
  const [saved]=await Promise.all([page.waitForResponse(r=>r.url()===themeUrl&&r.request().method()==='POST'),rose.click()]);
  if(!saved.ok()||(await saved.json()).success!==true)throw new Error('Theme retry did not save to actual server');
  await page.waitForFunction(()=>document.body.getAttribute('data-theme')==='rose');
  await page.reload({waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);
  await dismiss(page);
  if(await page.locator('body').getAttribute('data-theme')!=='rose')throw new Error('Confirmed theme was lost after refresh');
  // Restore the fictional fixture so both viewport runs exercise the same initial value.
  await root.locator('[data-hotel-settings-card="appearance"] summary').click();
  const [restore]=await Promise.all([page.waitForResponse(r=>r.url()===themeUrl&&r.request().method()==='POST'),root.locator('[data-hotel-theme="blue"]').click()]);
  if(!restore.ok()||(await restore.json()).success!==true)throw new Error('Synthetic fixture theme restore failed');
  await page.waitForFunction(()=>document.body.getAttribute('data-theme')==='blue');
  pass(t.name+'/'+v.width+': six category-specific groups, no outlet leakage, real failed-save retry and persisted colour');
}

async function formatHistoryWorkflow(page,t,v) {
  const name='Synthetic approved format '+v.width+' '+Date.now();
  const csv='Outlet,Qty,Item,HS,Rate,GST\nFictional buyer,2,Beef,02023000,100,36\n';
  async function upload() {
    await page.goto(baseUrl+'/invoices?tab=completed',{waitUntil:'domcontentloaded'});
    await waitForOperationalSurface(page); await dismiss(page);
    const importer=page.locator('[x-data="bulkImport()"]');
    await importer.locator('button').first().click();
    await importer.locator('input[type=file]').setInputFiles({name:'fictional-format.csv',mimeType:'text/csv',buffer:Buffer.from(csv)});
    await importer.getByRole('button',{name:'Continue to Preview'}).waitFor();
    return importer;
  }
  let importer=await upload();
  for(const [field,column] of Object.entries({buyer_name:'Outlet',quantity:'Qty',description:'Item',hs_code:'HS',price:'Rate',tax:'GST'})) {
    const row=importer.locator('div.grid.grid-cols-12').filter({has:page.locator('span[x-text="f.key"]').filter({hasText:new RegExp('^'+field+'$')})});
    await row.locator('select[x-model="mappingSel[f.key]"]').selectOption(column);
  }
  for(const [field,value] of Object.entries({buyer_address:'Fictional address',destination_province:'Punjab',document_type:'Sale Invoice'})) {
    const row=importer.locator('div.grid.grid-cols-12').filter({has:page.locator('span[x-text="f.key"]').filter({hasText:new RegExp('^'+field+'$')})});
    await row.locator('select[x-model="mappingSel[f.key]"]').selectOption('');
    const control=row.locator('[x-model="defaultsSel[f.key]"]');
    if(await control.evaluate(el=>el.tagName==='SELECT'))await control.selectOption(value);else await control.fill(value);
  }
  await importer.locator('input[x-model=savePreset]').check();
  await importer.locator('input[x-model=presetName]').fill(name);
  const [saved]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/import-apply-mapping')),importer.getByRole('button',{name:'Continue to Preview'}).click()]);
  const result=await saved.json();
  if(!saved.ok()||result.preset_saved!==name)throw new Error('Explicit format approval/save failed');
  importer=await upload();
  const presetRow=importer.locator('div.flex.flex-wrap.items-center.justify-between').filter({has:page.locator('span[x-text="p.name"]').filter({hasText:name})});
  const [history]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/history')),presetRow.getByRole('button',{name:'History',exact:true}).click()]);
  const versions=(await history.json()).versions;
  if(!history.ok()||versions.length!==1||!versions[0].approved_at||versions[0].snapshot.mapping.quantity!=='Qty')throw new Error('Saved approved history missing');
  await importer.getByText('Format history: '+name,{exact:true}).waitFor();
  await importer.getByText('Version 1',{exact:false}).waitFor();
  pass(t.name+'/'+v.width+': authenticated explicit format approval and persisted history UI; no invoice creation/submission');
}

async function smartImportWorkflow(page,t,v) {
  await page.goto(baseUrl+'/invoices?tab=completed',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  const importer=page.locator('[x-data="bulkImport()"]');
  await importer.locator('button').first().click();
  await importer.locator('input[x-model="smartSourceSystem"]').fill('rc-smart-'+v.width+'-'+Date.now());
  const values={buyer_name:'Unverified source label',buyer_ntn:'',buyer_cnic:'',buyer_address:'Source address',destination_province:'Punjab',document_type:'Sale Invoice',hs_code:'',description:'Synthetic beverage',quantity:'2',price:'',tax:'',schedule_type:'',tax_rate:'',source_invoice_number:'TEST-UI-'+v.width,invoice_date:'2026-01-01',source_sku:'TEST-SKU',source_outlet:'TEST-OUTLET',source_gross:'1300',source_discount:'100',source_vat:'216',source_net:'1416',invoice_gross:'1300',invoice_discount:'100',invoice_vat:'216',invoice_net:'1416'};
  const deposit={...values,source_sku:'TEST-EMPTY',description:'COKE 250 ML IMPLIED EMP 24B RGB',quantity:'2',source_gross:'100',source_discount:'0',source_vat:'0',source_net:'100'};
  const returned={...deposit,quantity:'-2',source_gross:'-100',source_net:'-100'};
  const csv=Object.keys(values).join(',')+'\n'+[values,deposit,returned].map(row=>Object.values(row).join(',')).join('\n')+'\n';
  await importer.locator('input[type=file]').setInputFiles({name:'synthetic-smart-'+v.width+'.csv',mimeType:'text/csv',buffer:Buffer.from(csv)});
  await page.waitForURL(u=>u.pathname.endsWith('/source-review'),{timeout:30000});
  const reviewPath=new URL(page.url()).pathname;
  if(!(await page.locator('body').innerText()).includes('Unknown SKU'))throw new Error('Unknown SKU did not require review');
  if(await page.getByRole('button',{name:'Create / resume drafts'}).count())throw new Error('Unapproved source offered draft creation');
  async function mapping(kind,fields) {
    await page.locator('summary').filter({hasText:'Verified company mappings'}).click();
    const form=page.locator('form[action$="/verified-mapping"]');
    await form.locator('select[name=kind]').selectOption(kind);
    await form.locator('[name=source_code]').fill(kind==='sku'?'TEST-SKU':'TEST-OUTLET');
    for(const [key,value]of Object.entries(fields)) {
      const field=form.locator('[name="details['+key+']"]');
      if(await field.evaluate(el=>el.tagName==='SELECT'))await field.selectOption(value); else await field.fill(value);
    }
    await form.locator('[name=evidence]').fill('Fictional verification evidence for isolated browser test');
    await form.locator('[name=verified]').check();
    await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Save verified mapping'}).click()]);
    await dismiss(page);
    if(!(await page.locator('body').innerText()).includes('Verified mapping saved'))throw new Error(kind+' mapping was not persisted');
  }
  await mapping('sku',{description:'Verified synthetic beverage',hs_code:'22021010',litres_per_case:'6',uom:'Liter',schedule_type:'standard',tax_rate:'18'});
  await mapping('buyer',{buyer_name:'Verified synthetic buyer',buyer_address:'Fictional verified address',destination_province:'Punjab',registration_type:'Unregistered'});
  await page.locator('input[name="exclusions[3]"]').fill('Verified synthetic empty-bottle deposit charge');
  await page.locator('input[name="exclusions[4]"]').fill('Verified synthetic empty-bottle deposit return');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Refresh review with current mappings and exclusions'}).click()]);
  await dismiss(page);
  if((await page.locator('body').innerText()).includes('Unknown SKU'))throw new Error('Verified SKU did not resolve source review: '+(await page.locator('body').innerText()).slice(-6000));
  const form=page.locator('form[action$="/source-review"]');
  await form.locator('[name=approve]').check();
  await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Review and approve drafts'}).click()]);
  await dismiss(page);
  if(!(await page.locator('body').innerText()).includes('Approved for draft creation'))throw new Error('Explicit review approval failed');
  const [process]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/process')&&r.request().method()==='POST'),page.getByRole('button',{name:'Create / resume drafts'}).click()]);
  if(!process.ok())throw new Error('Reviewed draft creation failed: '+process.status());
  const statusPath=reviewPath.replace('/source-review','/status');
  const status=await page.evaluate(async path=>await(await fetch(path,{headers:{Accept:'application/json'}})).json(),statusPath);
  if(status.created_invoices!==1||status.source_receipts.length!==1||status.source_receipts[0].fbr_invoice_number)throw new Error('Expected one local draft/receipt and no FBR submission');
  // Resume uses the same source receipt, including immutable existing invoices.
  const [resume]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/process')&&r.request().method()==='POST'),page.getByRole('button',{name:'Create / resume drafts'}).click()]);
  if(!resume.ok())throw new Error('Reviewed resume failed');
  const resumed=await page.evaluate(async path=>await(await fetch(path,{headers:{Accept:'application/json'}})).json(),statusPath);
  if(resumed.created_invoices!==1||resumed.source_receipts.length!==1||resumed.source_receipts[0].invoice_id!==status.source_receipts[0].invoice_id)throw new Error('Draft resume duplicated original invoice');
  await page.goto(baseUrl+reviewPath,{waitUntil:'domcontentloaded'});
  if(!(await page.locator('body').innerText()).includes('TEST-UI-'+v.width))throw new Error('Original source identity disappeared');
  pass(t.name+'/'+v.width+': authenticated upload, verified mappings, source review, explicit approval and draft receipt; no fiscal posting');
  await formatHistoryWorkflow(page,t,v);
}

async function adminReviewContrast(page) {
  const saved=await page.evaluate(()=>({theme:getAdminTheme(),dark:document.documentElement.classList.contains('dark')}));
  try {
    for(const theme of ['indigo','emerald','cyan','rose','amber','purple']) for(const dark of [false,true]) {
      await page.evaluate(({theme,dark})=>{applyAdminTheme(theme);document.documentElement.classList.toggle('dark',dark);},{theme,dark});
      const failures=await page.evaluate(()=>{
        const rgb=s=>{const m=s.match(/rgba?\(([^)]+)\)/);return m?m[1].split(/[, ]+/).slice(0,3).map(Number):null;};
        const luminance=c=>c.map(x=>{x/=255;return x<=.04045?x/12.92:Math.pow((x+.055)/1.055,2.4);}).reduce((n,x,i)=>n+x*[.2126,.7152,.0722][i],0);
        const bad=[];
        for(const el of document.querySelectorAll('.tn-dashboard-detail-filters input,.tn-dashboard-detail-filters select,.tn-dashboard-detail-filters option,.tn-detail-primary,.tn-detail-secondary,.tn-detail-status,.tn-admin-sidebar .admin-active-link,.tn-preview-toolbar button,.tn-preview-toolbar a')) {
          const style=getComputedStyle(el),fg=rgb(style.color),bg=rgb(style.backgroundColor);
          if(!fg||!bg) {bad.push(el.tagName+': missing native colour');continue;}
          const a=luminance(fg),b=luminance(bg),ratio=(Math.max(a,b)+.05)/(Math.min(a,b)+.05);
          if(ratio<4.5)bad.push(el.tagName+' '+el.getAttribute('name')+': contrast '+ratio.toFixed(2));
        }
        return bad;
      });
      if(failures.length)throw new Error('Admin review contrast '+theme+'/'+(dark?'dark':'light')+': '+failures.join('; '));
    }
  } finally {
    await page.evaluate(({theme,dark})=>{applyAdminTheme(theme);document.documentElement.classList.toggle('dark',dark);},saved);
  }
}

// Full OAuth/MCP journey against the disposable native database, including actual
// consent/revocation button clicks. The callback is loopback-only fixture config.
async function reportingConnectorWorkflow(page,t,v) {
  const f=t.reportingConnector, request=page.context().request;
  const metadata=await (await request.get(baseUrl+'/.well-known/oauth-protected-resource/api/reporting/mcp')).json();
  const callback=baseUrl+'/admin/reporting/connections';
  const registration=await request.post(baseUrl+'/api/reporting/oauth/register',{data:{client_name:'Synthetic read-only browser '+v.width+' '+Date.now(),redirect_uris:[callback],token_endpoint_auth_method:'none'}});
  if(registration.status()!==201)throw new Error('OAuth registration did not accept the loopback fixture callback');
  const client=await registration.json(), verifier='a'.repeat(64);
  const params={client_id:client.client_id,redirect_uri:callback,response_type:'code',code_challenge:createHash('sha256').update(verifier).digest('base64url'),code_challenge_method:'S256',resource:metadata.resource,scope:'taxnest.reports.read',state:'browser-'+v.width};
  await page.goto(baseUrl+'/admin/reporting/oauth/authorize?'+new URLSearchParams(params),{waitUntil:'domcontentloaded'});
  await checkUsability(page,{name:t.name},'/admin/reporting/oauth/authorize',v);
  await page.locator('[name="company_ids[]"][value="'+f.companyId+'"]').check();
  await saveEvidenceScreenshot(page,'rc-'+v.width+'-reporting-consent');
  await Promise.all([page.waitForURL(u=>u.pathname==='/admin/reporting/connections'),page.getByRole('button',{name:'Allow read-only access',exact:true}).click()]);
  const code=new URL(page.url()).searchParams.get('code');
  if(!code||new URL(page.url()).searchParams.get('state')!==params.state)throw new Error('Owner consent callback did not carry authorization code/state');
  const exchanged=await request.post(baseUrl+'/api/reporting/oauth/token',{form:{grant_type:'authorization_code',client_id:client.client_id,redirect_uri:callback,resource:metadata.resource,code,code_verifier:verifier}});
  if(exchanged.status()!==200)throw new Error('PKCE token exchange failed');
  const tokens=await exchanged.json();
  const report=async(companyName)=>request.post(baseUrl+'/api/reporting/mcp',{headers:{Authorization:'Bearer '+tokens.access_token},data:{jsonrpc:'2.0',id:1,method:'tools/call',params:{name:'get_company_billing_summary',arguments:{company_name:companyName,date_from:f.date}}}});
  const ownResponse=await report(f.companyName), own=await ownResponse.json();
  if(own.result?.isError||Number(own.result?.structuredContent?.totals?.net_billing)!==f.billing)throw new Error('Native billing mismatch: '+JSON.stringify({status:ownResponse.status(),rpcError:own.error,toolError:own.result?.isError,content:own.result?.isError?own.result?.content:undefined,totals:own.result?.structuredContent?.totals,period:own.result?.structuredContent?.period}));
  const foreign=await (await report(f.foreignCompanyName)).json();
  if(!foreign.result?.isError)throw new Error('Connection company scope leaked a foreign company report');
  await page.goto(baseUrl+'/admin/reporting/connections',{waitUntil:'domcontentloaded'});
  await page.locator('[data-reporting-onboarding]').waitFor();
  const card=page.locator('article').filter({has:page.getByRole('heading',{name:client.client_name,exact:true})});
  if(!(await card.locator('[data-report-read-evidence]').innerText()).includes('Successful report reads: 1.'))throw new Error('Successful read evidence missing or failed foreign read counted');
  await saveEvidenceScreenshot(page,'rc-'+v.width+'-reporting-read-evidence');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),card.getByRole('button',{name:'Revoke connection',exact:true}).click()]);
  if(!(await card.innerText()).includes('Revoked'))throw new Error('Owner revocation did not persist');
  if((await report(f.companyName)).status()!==401)throw new Error('Revoked token retained billing access');
  pass(t.name+'/'+v.width+': real consent, PKCE, native billing, foreign-company denial and immediate revoke');
}

async function companyDirectoryContrast(page,t,v) {
  await page.goto(baseUrl+'/admin/companies?search=Synthetic%20Contrast',{waitUntil:'domcontentloaded'});
  await page.locator('.tn-directory-alert').waitFor({state:'visible'});
  const saved=await page.evaluate(()=>({theme:getAdminTheme(),dark:document.documentElement.classList.contains('dark')}));
  try {
    for(const theme of ['indigo','emerald','cyan','rose','amber','purple'])for(const dark of [false,true]) {
      await page.evaluate(({theme,dark})=>{applyAdminTheme(theme);document.documentElement.classList.toggle('dark',dark);},{theme,dark});
      const errors=await page.evaluate(()=>{
        const rgb=s=>{const m=s.match(/rgba?\(([^)]+)\)/);if(!m)throw new Error('Unsupported computed colour '+s);const c=m[1].split(/[, /]+/).map(Number);return [...c.slice(0,3),c[3]??1];};
        const over=(a,b)=>a.slice(0,3).map((x,i)=>x*a[3]+b[i]*(1-a[3]));
        const bg=el=>{const chain=[];for(let p=el;p;p=p.parentElement)chain.unshift(p);let c=[255,255,255];for(const p of chain)c=over(rgb(getComputedStyle(p).backgroundColor),c);return c;};
        const lum=c=>c.map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4;}).reduce((n,x,i)=>n+x*[.2126,.7152,.0722][i],0);
        const contrast=(a,b)=>(Math.max(lum(a),lum(b))+.05)/(Math.min(lum(a),lum(b))+.05);
        const errors=[];
        for(const el of document.querySelectorAll('[data-company-directory] :is(h1,h2,a,p,span,th,td,input,select,option,button)')) {
          const option=el.tagName==='OPTION';if(!option&&!el.getClientRects().length)continue;
          if(!['INPUT','SELECT','OPTION'].includes(el.tagName)&&![...el.childNodes].some(n=>n.nodeType===Node.TEXT_NODE&&n.textContent.trim()))continue;
          const cs=getComputedStyle(el),back=bg(el),front=over(rgb(cs.color),back),ratio=contrast(front,back);
          if(ratio<4.5)errors.push(el.tagName+' '+el.textContent.trim().slice(0,35)+' contrast '+ratio.toFixed(2));
          if(parseFloat(cs.fontSize)<12)errors.push(el.tagName+' text below 12px');
          if(el.tagName==='INPUT'&&el.placeholder){const ps=getComputedStyle(el,'::placeholder');if(contrast(over(rgb(ps.color),back),back)<4.5)errors.push('Faint search placeholder');}
        }
        return errors;
      });
      if(errors.length)throw new Error('Directory '+theme+'/'+dark+': '+errors.join('; '));
    }
    await saveEvidenceScreenshot(page,'rc-'+v.width+'-company-directory-contrast');
    await page.locator('select[name=status]').selectOption('approved');
    await Promise.all([page.waitForURL(u=>u.searchParams.get('status')==='approved'),page.getByRole('button',{name:'Filter',exact:true}).click()]);
    if(await page.locator('[data-directory-status]').count()!==1||await page.locator('[data-directory-status]').first().getAttribute('data-directory-status')!=='approved')throw new Error('Directory filter changed behavior');
    await page.locator('.tn-directory-actions a').first().click({trial:true});
    pass(t.name+'/'+v.width+': actual alert/owner/status/actions/options >=4.5 contrast in six themes/light-dark; GET filter preserved');
  } finally {await page.evaluate(({theme,dark})=>{applyAdminTheme(theme);document.documentElement.classList.toggle('dark',dark);},saved);}
}

async function workflow(page,t,v,diagnostics) {
  if(t.whatsappAutoSend)return whatsappAutoSendWorkflow(page,t,v);
  if(t.riderRealtime)return riderRealtimeWorkflow(page,t,v);
  if(t.companyDirectoryContrast)return companyDirectoryContrast(page,t,v);
  if(t.fbrReconciliation) {
    for(const [decision,id] of Object.entries(t.fbrReconciliation[v.width])) {
      await page.goto(baseUrl+'/fbr-pos/fail-queue',{waitUntil:'domcontentloaded'});
      await dismiss(page);
      await Promise.all([page.waitForURL(`**/fbr-pos/fail-queue/${id}/reconcile`,{waitUntil:'domcontentloaded'}),
        page.locator(`a[href$="/fbr-pos/fail-queue/${id}/reconcile"]`).click()]);
      await dismiss(page);
      const form=page.locator('[data-fbr-owner-reconcile] form');
      await form.locator('[name=decision]').selectOption(decision);
      await form.locator('[name=evidence_source]').selectOption('ims_log');
      // Retain the requested environment shown by the actual company fixture; never guess production.
      await form.locator('[name=evidence_reference]').fill('Fictional checked IMS log '+v.width);
      const number='90000000'+v.width;
      if(decision==='accepted') await form.locator('[name=fiscal_number]').fill(number);
      await form.locator('[name=confirmed]').check();
      await Promise.all([page.waitForURL(/\/fbr-pos\/fail-queue$/,{waitUntil:'domcontentloaded'}),form.locator('button').click()]);
      const result=await page.request.get(baseUrl+`/fbr-pos/transaction/${id}/fbr-status`);
      if(!result.ok())throw new Error('Reconciled invoice status unavailable');
      const state=await result.json();
      if(state.fbr_status!==(decision==='accepted'?'submitted':'failed'))throw new Error('Owner reconciliation did not persist the checked result');
      if(decision==='accepted'&&state.fbr_invoice_number!==number)throw new Error('Owner fiscal identity changed');
    }
    pass(t.name+'/'+v.width+': owner acceptance/rejection reconciliation, no fiscal submission');
    return;
  }
  if(t.reportingConnector)return reportingConnectorWorkflow(page,t,v);
  if(t.dashboardDetails) {
    await page.goto(baseUrl+'/admin/dashboard',{waitUntil:'domcontentloaded'});
    for(const key of ['di','pos','fbrpos']) {
      const tab=page.locator(`.tn-company-tabs button`).filter({hasText:key==='di'?'Digital Invoice':key==='pos'?'PRA POS':'FBR POS'});
      await tab.click();
      if(await tab.getAttribute('aria-pressed')!=='true' || await page.locator('.tn-company-tabs button[aria-pressed="true"]').count()!==1) throw new Error('Selected admin company tab is not explicit: '+key);
      if(!await page.locator(`[data-company-panel="${key}"]`).isVisible() || await page.locator('[data-company-panel]:not([hidden])').count()!==1) throw new Error('Selected company tab does not show its own panel: '+key);
    }
    const keys=await page.locator('[data-dashboard-metric]').evaluateAll(els=>els.map(el=>el.dataset.dashboardMetric));
    if(keys.length!==13 || new Set(keys).size!==13) throw new Error('All 13 distinct dashboard links are required');
    for(const key of keys) {
      await page.goto(baseUrl+'/admin/dashboard',{waitUntil:'domcontentloaded'});
      const link=page.locator(`[data-dashboard-metric="${key}"]`);
      await link.click({trial:true,timeout:5000});
      const geometry=await link.boundingBox();
      if(!geometry || geometry.width<40 || geometry.height<40) throw new Error('Metric has no useful click area: '+key);
      await Promise.all([page.waitForURL('**/admin/dashboard/details/'+key,{waitUntil:'domcontentloaded'}),link.click()]);
      await page.locator(`[data-dashboard-detail="${key}"]`).waitFor({state:'visible'});
      await checkUsability(page,t,key,v);
      await page.getByRole('link',{name:'Admin Dashboard',exact:false}).last().click();
      await page.waitForURL('**/admin/dashboard');
    }
    await page.goto(baseUrl+'/admin/dashboard/details/total_companies',{waitUntil:'domcontentloaded'});
    if(await page.locator('.tn-admin-sidebar a[aria-current="page"]').count()!==1)throw new Error('Dashboard navigation loses selection on drilldown');
    await adminReviewContrast(page);
    const status=page.locator('.tn-detail-status').first();
    if(!await status.count())throw new Error('Synthetic admin company status fixture is missing');
    await Promise.all([page.waitForURL(u=>u.searchParams.has('status')),status.click()]);
    await page.locator('.tn-detail-status[data-selected="true"][aria-current="true"]').waitFor({state:'visible'});
    await adminReviewContrast(page);
    await page.getByRole('link',{name:'Clear status',exact:true}).click();
    await page.waitForURL(u=>!u.searchParams.has('status'));
    await page.goto(baseUrl+'/admin/dashboard/details/di_invoices',{waitUntil:'domcontentloaded'});
    await page.locator('input[name="q"]').fill('RC');
    await Promise.all([page.waitForURL(u=>u.searchParams.get('q')==='RC'),page.getByRole('button',{name:'Apply filters'}).click()]);
    const [download]=await Promise.all([page.waitForEvent('download'),page.getByRole('button',{name:'Export filtered CSV'}).click()]);
    const csv=readFileSync(await download.path(),'utf8');
    if(!csv.startsWith('id,company,company_id,product,reference,status,amount,created_at,')) throw new Error('Dashboard CSV schema missing');
    if(!t.invoicePreviewCsp)throw new Error('Invoice preview must enforce the actual production CSP');
    await page.addInitScript(() => {
      window.invoicePreviewPolicyViolations=[];
      document.addEventListener('securitypolicyviolation',event=>window.invoicePreviewPolicyViolations.push({directive:event.effectiveDirective,uri:event.blockedURI}));
    });
    await page.route('**/admin/dashboard/**',async route=>{
      const response=await route.fetch();
      await route.fulfill({response,headers:{...response.headers(),'content-security-policy':t.invoicePreviewCsp}});
    });
    for(const doc of t.previewDocuments || []) {
      await page.goto(baseUrl+'/admin/dashboard/details/'+doc.metric+'?company_id='+doc.company,{waitUntil:'domcontentloaded'});
      const link=page.locator(`[data-invoice-preview$="/${doc.product}/${doc.company}/${doc.record}/preview"]`);
      if(!await link.count())throw new Error('Typed invoice preview link missing: '+doc.product);
      const [response]=await Promise.all([page.waitForResponse(r=>r.url().endsWith(`/${doc.product}/${doc.company}/${doc.record}/preview`)),link.click()]);
      if(response.status()!==200 || !response.headers()['content-type']?.includes('application/pdf')) throw new Error('Actual '+doc.product+' document did not render as PDF');
      // Chrome's PDF response capture can expose an empty CDP body. Verify
      // binary bytes via the authenticated HTTP client; the UI separately
      // validates its fetched blob before showing any preview/download controls.
      const documentResponse=await page.request.get(response.url());
      const previewBytes=await documentResponse.body();
      if(documentResponse.status()!==200 || !documentResponse.headers()['content-type']?.includes('application/pdf') || !previewBytes.subarray(0,5).equals(Buffer.from('%PDF-')))throw new Error('Invalid actual invoice PDF: '+doc.product+' bytes='+previewBytes.length+' prefix='+previewBytes.subarray(0,24).toString('hex'));
      await page.locator('#tn-invoice-preview').waitFor({state:'visible'});
      await page.locator('[data-preview-download]').waitFor({state:'visible'});
      await page.waitForFunction(()=>!document.querySelector('[data-preview-print]').disabled);
      await page.waitForTimeout(350);
      const violations=await page.evaluate(()=>window.invoicePreviewPolicyViolations || []);
      if(violations.some(x=>/frame|child|default/.test(x.directive)))throw new Error('Production CSP blocked invoice preview: '+JSON.stringify(violations));
      await saveEvidenceScreenshot(page,`rc-admin-invoice-preview-${doc.product}-${v.width}`);
      await adminReviewContrast(page);
      const [pdf]=await Promise.all([page.waitForEvent('download'),page.locator('[data-preview-download]').click()]);
      const bytes=readFileSync(await pdf.path());
      if(!bytes.subarray(0,5).equals(Buffer.from('%PDF-')))throw new Error('Downloaded invoice is not a PDF');
      if(pdf.suggestedFilename()!==`taxnest-${doc.product}-${doc.company}-${doc.record}.pdf`)throw new Error('PDF filename loses company/product identity');
      await page.getByRole('button',{name:'Close invoice preview'}).click();
      if(await page.locator('#tn-invoice-preview').isVisible())throw new Error('Invoice preview did not close');
      const other=t.previewDocuments.find(x=>x.product!==doc.product);
      const denied=await page.request.get(baseUrl+`/admin/dashboard/invoices/${doc.product}/${other.company}/${doc.record}/download`);
      if(denied.status()!==404)throw new Error('Cross-product/company preview was not rejected');
    }
    await page.goto(baseUrl+'/admin/dashboard/details/di_invoices?q=RC-CROSS-PRODUCT-SECRET',{waitUntil:'domcontentloaded'});
    if(await page.locator('[data-invoice-preview]').count())throw new Error('Wrong-product invoice leaked into DI list');
    pass(`${t.name}/${v.width}: all 13 cards opened, returned, filtered, exported and isolated invoice PDFs previewed/downloaded`);
    return;
  }
  if(t.smartImport)return smartImportWorkflow(page,t,v);
  if(t.ingredientUnitAudit)return ingredientUnitAudit(page,t,v);
  if (t.tableOrderWorkflow) return tableOrderWorkflow(page,t,v);
  if (t.hotelSettingsWorkflow) return hotelSettingsWorkflow(page,t,v,diagnostics);
  if (t.riderBulkSelection) return riderBulkSelectionWorkflow(page,t,v);
  if (t.restaurantTopSelling) return restaurantTopSellingWorkflow(page,t,v);
  if (t.notificationWorkflow) return notificationWorkflow(page,t,v,diagnostics);
  if (t.hotelWorkflow) return hotelWorkflow(page,t,v,diagnostics);
  if (t.hotelCreditReview) return hotelCreditReview(page,t,v);
  const f=t.serviceWorkflow; if(!f)return;
  await page.goto(baseUrl+f.createPath,{waitUntil:'domcontentloaded',timeout:30000});
  await waitForOperationalSurface(page); await dismiss(page);
  for(const [n,val] of Object.entries({customer_name:f.customerName,title:f.title,quantity:f.quantity,unit_price:f.unitPrice,scheduled_at:f.scheduledAt,...Object.fromEntries(Object.entries(f.details).map(([k,x])=>[`details[${k}]`,x]))})) { const el=page.locator(`[name="${n}"]`).first(); if(!await el.count())throw new Error(`${t.name}: form omitted ${n}`);await el.fill(String(val)); }
  const create=page.locator('form[action$="/pos/work-orders"] button[type="submit"],form[action$="/pos/work-orders"] button').first();
  await Promise.all([page.waitForURL(/\/pos\/work-orders\/\d+$/,{timeout:30000,waitUntil:'domcontentloaded'}),create.click()]); const order=new URL(page.url()).pathname;
  await dismiss(page);
  for(const s of f.transitions){const b=page.locator(`button[name="to_status"][value="${s}"]`).first();if(!await b.count())throw new Error(`${t.name}: transition ${s} unavailable`);await b.click();await page.waitForLoadState('domcontentloaded');await dismiss(page);}
  const invoice=page.locator('form[action$="/invoice"] button[type="submit"],form[action$="/invoice"] button').first(); await Promise.all([page.waitForURL(/\/pos\/transaction\/\d+$/,{timeout:30000}),invoice.click()]); await dismiss(page);
  await page.goto(baseUrl+order,{waitUntil:'domcontentloaded',timeout:30000}); if(!(await page.locator('body').innerText()).includes(f.invoiceMarker))throw new Error(`${t.name}: invoice linkage marker missing`); pass(`${t.name}/${v.width}: actual service create, transitions, and invoice linked`);
}


async function ingredientUnitAudit(page,t,v) {
  const f=t.ingredientUnitAudit, unused=f.unused[v.width], path='/pos/restaurant/ingredients';
  const card=name=>page.locator('[data-search]').filter({has:page.getByRole('heading',{name,exact:true})});
  const edit=async item=>{
    await card(item.name).getByRole('button',{name:'Edit',exact:true}).click();
    const modal=page.locator('[x-show="showEditModal"]');
    await modal.waitFor({state:'visible'});
    const form=modal.locator('form');
    await form.locator('[name="unit"]').selectOption('kg');
    await form.locator('[name="base_unit"]').selectOption('kg');
    return form;
  };
  const save=async form=>{
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.locator('button[type="submit"]').click()]);
    await waitForOperationalSurface(page); await dismiss(page);
  };
  await page.goto(baseUrl+path,{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  if(!(await card(unused.name).innerText()).includes('0.0000 pcs'))throw new Error('unused fixture did not start in pcs');
  await save(await edit(unused));
  if(!(await card(unused.name).innerText()).includes('0.0000 kg'))throw new Error('real unit edit was not persisted on the reloaded card');
  await save(await edit(f.stocked));
  const text=await page.locator('main').innerText();
  if(!text.includes('stock ya recipes')||!(await card(f.stocked.name).innerText()).includes('50.0000 pcs'))throw new Error('stocked unit change failed silently or relabelled the stock');
  const invalid=await edit(unused);
  await invalid.locator('[name="unit"]').evaluate(select=>{
    const option=document.createElement('option');option.value='stones';option.textContent='Invalid test value';select.append(option);
  });
  await invalid.locator('[name="unit"]').selectOption('stones');
  await save(invalid);
  const alert=page.locator('[role="alert"]').filter({hasText:'Ingredient save nahi hua:'});
  if(!await alert.isVisible()||!(await card(unused.name).innerText()).includes('0.0000 kg'))throw new Error('invalid unit lacks visible validation feedback or changed persisted unit');
  pass(t.name+'/'+v.width+': real unused pcs-to-kg edit, visible stocked-unit refusal and invalid-unit rejection');
}

async function tableOrderWorkflow(page,t,v) {
  await catalogSearchCompleteWorkflow(page,t,v);
  await deliveryAddressSnapshotWorkflow(page,t,v);
  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  const root=page.locator('[data-tn-sale-root]');
  for(const expected of t.tableOrderWorkflow) {
    await page.locator('[data-video="counter-dine-in"]').click();
    const tile=page.locator('[data-video="counter-table"]:visible').filter({hasText:'T-'+expected.number});
    await tile.waitFor({state:'visible'});
    // Table-status must identify this exact waiter order before the click.
    await page.waitForFunction(({id,orderId})=>{
      const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));
      return d.tablePickerFlat().some(x=>Number(x.id)===id&&Number(x.order?.id)===orderId);
    },{id:expected.tableId,orderId:expected.orderId});
    const [response]=await Promise.all([
      page.waitForResponse(r=>r.url()===baseUrl+'/pos/api/incoming-orders/'+expected.orderId+'/claim'&&r.request().method()==='POST'),
      tile.click(),
    ]);
    if(!response.ok()||(await response.json()).success!==true)throw new Error(expected.status+' table claim failed');
    await page.waitForFunction(({id,orderId,name})=>{
      const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));
      return Number(d.incomingOrderId)===orderId&&Number(d.selectedTable?.id)===id
        &&d.cart.length===1&&d.cart[0].item_name===name&&d.orderType==='dine_in'&&!d.showTablePicker;
    },{id:expected.tableId,orderId:expected.orderId,name:expected.itemName});
    await openMobileCart(page,v,['Current Order']);
    await root.getByText(expected.itemName,{exact:true}).first().waitFor({state:'visible'});
    if(!await page.locator('[data-video="open-payment"]').isEnabled())throw new Error(expected.status+' table lost its payment action');
    pass(t.name+'/'+v.width+': '+expected.status+' exact occupied table opened with items and enabled payment');
    if(v.width<768) {
      // Return through the real mobile cart back button before opening another table.
      // The Dine-in selector belongs to the menu pane and is hidden while the cart is open.
      await root.locator('.tn-cart-header > button').first().click();
      await page.locator('[data-video="counter-dine-in"]').waitFor({state:'visible'});
    }
  }
  if(t.kotDeleteCases) await repeatedDishDeleteWorkflow(page,t,v);
  if(t.cashierHandoff) await cashierHandoffWorkflow(page,t,v);
}
async function repeatedDishDeleteWorkflow(originalPage,t,v) {
  const expected=t.kotDeleteCases[v.width<768?'mobile':'desktop'];
  const context=await originalPage.context().browser().newContext({viewport:v});
  const page=await context.newPage();
  try {
    await login(page,t);
    await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
    await waitForOperationalSurface(page); await dismiss(page);
    await page.locator('[data-video="counter-dine-in"]').click();
    const [claim]=await Promise.all([
      page.waitForResponse(r=>r.url()===baseUrl+'/pos/api/incoming-orders/'+expected.orderId+'/claim'&&r.request().method()==='POST'),
      page.locator('[data-video="counter-table"]:visible').filter({hasText:'T-'+expected.number}).click(),
    ]);
    if(!claim.ok())throw new Error('Repeated dish table claim failed');
    await page.waitForFunction(id=>{
      const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));
      return Number(d.incomingOrderId)===id&&d.cart.length===3;
    },expected.orderId);
    await openMobileCart(page,v,['Current Order']);
    await page.locator('[data-video="cart-remove"][data-line-id="'+expected.lineIds[2]+'"]').click();
    const [saved]=await Promise.all([
      page.waitForResponse(r=>r.url()===baseUrl+'/pos/restaurant/orders/'+expected.orderId+'/edit'&&r.request().method()==='POST'),
      page.locator('[data-video="order-edit-save"]').click(),
    ]);
    const body=await saved.json();
    if(!saved.ok()||!body.success)throw new Error('Delete edit failed');
    const ids=body.order.items.map(i=>Number(i.line_id));
    if(JSON.stringify(ids.slice().sort((a,b)=>a-b))!==JSON.stringify(expected.lineIds.slice(0,2).sort((a,b)=>a-b)))throw new Error('Deletion created duplicate lines or removed retained IDs: '+JSON.stringify(ids));
    if(body.order.items.reduce((n,i)=>n+Number(i.quantity),0)!==3||Number(body.total)!==300)throw new Error('Deletion doubled quantities or billing total');
    if(Object.keys(body.kot.add||{}).length)throw new Error('Pure deletion incorrectly generated an ADD kitchen action');
    await page.waitForFunction(()=>{
      const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));
      return !d.incomingEditBusy&&d.cart.length===2&&d.cart.reduce((n,i)=>n+Number(i.quantity),0)===3;
    });
    // Reload and reopen through the actual table to prove persisted state, not a client-only splice.
    await page.reload({waitUntil:'domcontentloaded'}); await waitForOperationalSurface(page); await dismiss(page);
    await page.locator('[data-video="counter-dine-in"]').click();
    await page.locator('[data-video="counter-table"]:visible').filter({hasText:'T-'+expected.number}).click();
    await page.waitForFunction(id=>{
      const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));
      return Number(d.incomingOrderId)===id&&d.cart.length===2&&d.cart.reduce((n,i)=>n+Number(i.quantity),0)===3;
    },expected.orderId);
    pass(t.name+'/'+v.width+': repeated dish deletion retained exact line IDs, quantity 3/total 300, no ADD and persisted after reopen');
  } finally {await context.close();}
}
async function cashierHandoffWorkflow(page,t,v) {
  const h=t.cashierHandoff, expected=h.cases[v.width<768?'mobile':'desktop'];
  const initialContext=await page.context().browser().newContext({viewport:v});
  page=await initialContext.newPage(); await login(page,t);
  const openConflict=async(page)=>{
    await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
    await waitForOperationalSurface(page); await dismiss(page);
    await page.locator('[data-video="counter-dine-in"]').click();
    const tile=page.locator('[data-video="counter-table"]:visible').filter({hasText:'T-'+expected.number});
    const [claim]=await Promise.all([
      page.waitForResponse(r=>r.url()===baseUrl+'/pos/api/incoming-orders/'+expected.orderId+'/claim'&&r.request().method()==='POST'), tile.click(),
    ]);
    if(claim.status()!==409)throw new Error('Assigned table conflict expected HTTP409, got '+claim.status());
    await page.locator('[data-video="cashier-conflict"]').waitFor({state:'visible'});
    // The application consumes the real JSON response. Inspect that parsed
    // contract rather than asking CDP to retrieve an already-consumed body
    // across mobile navigation; do not mock or replay the claim.
    await page.waitForFunction(({orderId})=>{
      const c=window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).cashierConflict;
      return c?.code==='cashier_assignment_conflict'&&Number(c.order_id)===orderId
        &&Number(c.assigned_cashier_id)>0&&typeof c.assigned_cashier==='string';
    },{orderId:expected.orderId});
    await page.waitForFunction(()=>window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).cart.length===0);
  };
  await openConflict(page);
  if(await page.locator('[data-video="handoff-confirm"]').count())throw new Error('Cashier received manager handoff control');
  await initialContext.close();
  const managerContext=await initialContext.browser().newContext({viewport:v});
  const managerPage=await managerContext.newPage();
  await login(managerPage,h.manager); await openConflict(managerPage);
  await managerPage.locator('[data-video="handoff-cashier"]').selectOption(String(h.cashierId));
  const [transfer]=await Promise.all([
    managerPage.waitForResponse(r=>r.url()===baseUrl+'/pos/api/incoming-orders/'+expected.orderId+'/transfer'&&r.request().method()==='POST'),
    managerPage.locator('[data-video="handoff-confirm"]').click(),
  ]);
  if(!transfer.ok())throw new Error('Manager transfer failed');
  await managerPage.locator('[data-video="cashier-conflict"]').waitFor({state:'hidden'});
  await managerContext.close();
  const cashierContext=await managerContext.browser().newContext({viewport:v});
  const cashierPage=await cashierContext.newPage();
  await login(cashierPage,t);
  await cashierPage.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(cashierPage); await dismiss(cashierPage);
  await cashierPage.locator('[data-video="counter-dine-in"]').click();
  const [claim]=await Promise.all([
    cashierPage.waitForResponse(r=>r.url()===baseUrl+'/pos/api/incoming-orders/'+expected.orderId+'/claim'&&r.request().method()==='POST'),
    cashierPage.locator('[data-video="counter-table"]:visible').filter({hasText:'T-'+expected.number}).click(),
  ]);
  if(!claim.ok())throw new Error('Transferred table still cannot open');
  await cashierPage.waitForFunction(({orderId,itemName})=>{
    const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));
    return Number(d.incomingOrderId)===orderId&&d.cart.length===1
      &&d.cart[0].item_name===itemName&&d.orderType==='dine_in';
  },{orderId:expected.orderId,itemName:expected.itemName});
  await openMobileCart(cashierPage,v,['Current Order']);
  await cashierPage.locator('[data-tn-sale-root]').getByText(expected.itemName,{exact:true}).first().waitFor({state:'visible'});
  if(!await cashierPage.locator('[data-video="open-payment"]').isEnabled())throw new Error('Transferred table payment disabled');
  pass(t.name+'/'+v.width+': cashier conflict, explicit manager transfer and exact-order reopen passed');
  await cashierContext.close();
}
async function categoryMismatch(page,t,v) {
  const path=t.categoryCoverage?.mismatchPath; if(!path)return;
  const requestedUrl=baseUrl+path;
  // Prove server-side denial with the same authenticated browser session.
  // Client redirects or a failed navigation alone do not prove a category gate.
  const gate=await page.request.get(requestedUrl,{maxRedirects:0,headers:{Accept:'text/html'}});
  const denied=[401,403,404].includes(gate.status());
  const redirected=[302,303,307,308].includes(gate.status());
  if(!denied&&!redirected)throw new Error('category URL returned '+gate.status()+' instead of a server-side denial');
  const destination=redirected?new URL(gate.headers().location||'',requestedUrl):null;
  const prefix=t.categoryCoverage.panel==='fbr'?'/fbr-pos/':'/pos/';
  if(destination&&(destination.origin!==new URL(baseUrl).origin||!destination.pathname.startsWith(prefix)||destination.pathname.endsWith('/login')||destination.pathname===path))
    throw new Error('category rejection redirected outside the authenticated native panel');
  let navigationGate=null;
  const observe=response=>{
    if(response.url()===requestedUrl&&response.request().isNavigationRequest())navigationGate=response;
  };
  page.on('response',observe);
  let response;
  try {
    response=await page.goto(requestedUrl,{waitUntil:'commit',timeout:30000});
    await page.waitForLoadState('domcontentloaded');
  } catch(error) {
    // A canceled navigation does not itself prove authorization. The real
    // HTTP request above uses this browser's authenticated cookie jar and must
    // already have returned a panel-local denial. Independently require the
    // actual browser to remain on a usable native screen. Do not retry.
    if(!/net::ERR_ABORTED/.test(error.message)||!redirected)throw error;
    if(navigationGate){
      if(![302,303,307,308].includes(navigationGate.status()))throw new Error('browser category request was not denied');
      const actual=new URL(navigationGate.headers().location||'',requestedUrl);
      if(actual.href!==destination.href)throw new Error('browser denial differs from authenticated HTTP gate');
    }
    const expected=t.expectedPaths||t.paths||[t.path];
    await page.waitForURL(url=>url.origin===new URL(baseUrl).origin&&expected.includes(url.pathname),{timeout:15000});
    await waitForOperationalSurface(page);
    const main=page.locator('main,[role="main"]').first();
    if(!await main.isVisible())throw new Error('aborted redirect did not settle on usable native content');
    const text=await main.innerText();
    for(const marker of t.mainMarkers||t.markers||[])if(!text.includes(marker))throw new Error('aborted redirect omitted native marker '+marker);
    pass('CATEGORY DENIAL PROOF: '+t.name+'/'+v.width+': authenticated HTTP '+gate.status()+' -> '+destination.pathname+'; canceled navigation left the native landing usable'+(navigationGate?'; browser denial also observed':'; denial proven through the browser-session HTTP request'));
  } finally {
    page.off('response',observe);
  }
  const status=response?.status()||navigationGate?.status()||gate.status(), finalPath=new URL(page.url()).pathname;
  if(status>=500||(!denied&&finalPath===path))throw new Error('category mismatch URL rendered or server failed: '+path+' ('+status+')');
  pass('CATEGORY URL GATE PASS: '+t.name+'/'+v.width+': '+t.categoryCoverage.category+' rejected '+path+' with HTTP '+gate.status());
}
async function sameProductCategorySurface(page,t,v) {
  const path=t.categoryCoverage?.sameProductPositivePath; if(!path)return;
  const response=await page.goto(baseUrl+path,{waitUntil:'domcontentloaded',timeout:30000});
  const status=response?.status()||0, finalPath=new URL(page.url()).pathname, main=page.locator('main,[role="main"]').first();
  if(status>=400||finalPath!==path||!await main.count())fail(`${t.name}/${v.width}: same-product category surface ${path} did not render`);
  else pass(`CATEGORY NATIVE PASS: ${t.name}/${v.width}: ${t.categoryCoverage.category} rendered ${path}`);
}
async function customerAmountAudit(page,t,v) {
  const f=t.customerAmountAudit, history='/pos/customers/'+f.customerId+'/history';
  for(const bill of f.cases) {
    await page.goto(baseUrl+history,{waitUntil:'domcontentloaded'});
    await waitForOperationalSurface(page); await dismiss(page);
    const root=page.locator('[x-data="historyBills()"]');
    if(!(await root.innerText()).includes('PKR '+f.spent))throw new Error('customer lifetime spend differs from persisted payable sum');
    const row=root.locator('tr[role="button"]').filter({hasText:bill.invoice});
    if(!await row.count()||!(await row.innerText()).includes('PKR '+bill.payable))throw new Error(bill.invoice+' history row has the wrong payable');
    await row.click();
    await page.waitForFunction(expected=>{
      const d=window.Alpine.$data(document.querySelector('[x-data="historyBills()"]'));
      return d.open&&!d.loading&&!d.error&&Number(d.bill.total)===expected;
    },bill.payable);
    const modal=root.locator('[x-show="open"]');
    if(!(await modal.innerText()).includes('PKR '+bill.payable))throw new Error('quick-view total does not match history');
    await modal.locator('button').first().click();
    await page.goto(baseUrl+'/pos/transaction/'+bill.id+'/receipt',{waitUntil:'domcontentloaded'});
    if(!(await page.locator('body').innerText()).includes('PKR '+bill.payable+'.00'))throw new Error('actual receipt payable differs from history');
    await page.goto(baseUrl+'/pos/transactions?tab=local',{waitUntil:'domcontentloaded'});
    await waitForOperationalSurface(page); await dismiss(page);
    const transactionRow=page.locator('tr').filter({has:page.locator('a[href$="/pos/transaction/'+bill.id+'"]')});
    if(!await transactionRow.count()||!(await transactionRow.innerText()).includes('PKR '+bill.payable))throw new Error('Transactions row differs from the receipt');
  }
  await page.goto(baseUrl+history,{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  const [csvDownload]=await Promise.all([page.waitForEvent('download'),page.locator('a[href$="'+history+'/export"]').click()]);
  const stream=await csvDownload.createReadStream();if(!stream)throw new Error('CSV download stream is unavailable');
  let csv='';for await(const chunk of stream)csv+=chunk.toString();
  for(const bill of f.cases) {
    const line=csv.split(/\r?\n/).find(line=>line.includes(bill.invoice));
    if(!line||!line.endsWith(','+bill.payable+'.00'))throw new Error('downloaded CSV does not use persisted payable for '+bill.invoice);
  }
  const [pdfDownload]=await Promise.all([page.waitForEvent('download'),page.locator('a[href$="'+history+'/pdf"]').click()]);
  const pdfStream=await pdfDownload.createReadStream();if(!pdfStream)throw new Error('PDF download stream is unavailable');
  let magic='';for await(const chunk of pdfStream){if(magic.length<5)magic+=chunk.toString('ascii');}
  if(!magic.startsWith('%PDF'))throw new Error('customer history PDF did not render');
  pass(t.name+'/'+v.width+': discounted QR and unchanged cash receipt/history/Transactions/CSV agree; PDF renders');
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
  valid(t); const failuresBefore = failures;
  const c=await browser.newContext({viewport:v,serviceWorkers:'allow',...(videoDirectory?{recordVideo:{dir:join(videoDirectory,'raw'),size:v}}:{})}); await c.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient')); const p=await c.newPage(), d=attachDiagnostics(p);
  try { await login(p,t.notificationWorkflow?{...t,login:requested.length?(v.width<768?t.preflightMobileLogin:t.preflightLogin):(v.width<768?t.mobileLogin:t.login)}:t); await waitForOperationalSurface(p); if(!t.notificationWorkflow)await dismiss(p); if(t.submitSelector){await p.goto(baseUrl+t.submitPath,{waitUntil:'domcontentloaded'});await waitForOperationalSurface(p);await checkAdminContrast(p,t,v);p.once('dialog',x=>x.accept());await Promise.all([p.waitForURL(u=>!u.pathname.startsWith('/admin/companies/'),{timeout:30000}),p.locator(t.submitSelector).first().evaluate(n=>n.requestSubmit())]);} await workflow(p,t,v,d);if(t.customerAmountAudit)await customerAmountAudit(p,t,v);for(const path of t.paths||[t.path])await surface(p,t,path,v);await sameProductCategorySurface(p,t,v);await categoryMismatch(p,t,v);if(d.pageErrors.length)fail(`${t.name}/${label}: page error ${d.pageErrors[0]}`);const expectedMismatch=t.categoryCoverage?.mismatchPath ? `${baseUrl}${t.categoryCoverage.mismatchPath}` : null;const hotelFallback=t.categoryCoverage?.category==='hotel';const intentionalMismatchFailure=x=>(expectedMismatch&&x.includes(expectedMismatch))||(hotelFallback&&(x.includes(`${baseUrl}/pos/hotel`)||x.includes(`${baseUrl}/pos/invoice/create`)));const consoleErrors=t.denied?[]:d.consoleErrors.filter(x=>!x.includes('ERR_BLOCKED_BY_CLIENT')&&!intentionalMismatchFailure(x));const failedRequests=t.denied?[]:d.failedRequests.filter(x=>!x.includes('ERR_BLOCKED_BY_CLIENT')&&!intentionalMismatchFailure(x));if(consoleErrors.length)fail(`${t.name}/${label}: console error ${consoleErrors[0]}`);if(failedRequests.length)fail(`${t.name}/${label}: failed request ${failedRequests[0]}`);const unexpectedHttp=t.denied?[]:d.httpErrors;if(unexpectedHttp.length)fail(`${t.name}/${label}: HTTP ${unexpectedHttp[0].status} ${unexpectedHttp[0].url}`);console.log(`DIAGNOSTICS: ${t.name}/${label}: ${d.summary()}`); }
  catch(e){fail(`${t.name}/${label}: ${e.message}`);} finally {
    await saveEvidenceScreenshot(p,`rc-${label}-${t.name}`).catch(()=>{});
    const video=p.video(); await c.close();
    if(videoDirectory && video) {
      if(failures === failuresBefore) {
        const name=`${t.name}-${label}.webm`, target=join(videoDirectory,name);
        await video.saveAs(target);
        const bytes=readFileSync(target);
        if(!bytes.length)throw new Error('Empty tutorial recording');
        recorded.push({name,sha256:createHash('sha256').update(bytes).digest('hex'),size:bytes.length,journey:t.name,viewport:v});
      }
      await video.delete();
    }
  }
}
const {browser}=await launchLocalBrowser();
try { if(!requested.length) { const fiscal=cases.find(t=>t.name==='fiscal'), mobile=views.find(([,v])=>v.width===390); if(fiscal&&mobile) { await one(browser,'mobile-fiscal-preflight',mobile[1],fiscal); if(failures)throw new Error('FBR mobile fiscal preflight failed; pointer-access acceptance remains required'); } } for(const [label,v]of views)for(const t of cases){await one(browser,label,v,t);if((t.hotelSettingsWorkflow||t.tableOrderWorkflow||t.hotelWorkflow)&&failures)throw new Error("Settings/table-order/Hotel preflight failed; required browser acceptance remains failed");} for(const [label,v]of views)if(!requested.length||requestedIsolation)await healthIsolation(browser,label,v,fixture.isolation); if(!requested.length&&!di.length)throw new Error('DI pending role fixture missing'); for(const [label,v]of views)for(const t of di)if(!requested.length||requested.includes(t.name))await one(browser,label,v,t); } finally {await browser.close();}
if(failures){console.error(`RC BROWSER ACCEPTANCE FAIL: ${failures} assertion(s) failed.`);process.exit(1);}
if(videoDirectory) {
  if(recorded.length !== cases.length * views.length)throw new Error('Incomplete tutorial recordings');
  writeFileSync(join(videoDirectory,'recording-manifest.json'),JSON.stringify({schema_version:1,source_sha:execFileSync('git',['rev-parse','HEAD'],{encoding:'utf8'}).trim(),synthetic:true,review_status:'OWNER_REVIEW_REQUIRED',recorded_at:new Date().toISOString(),artifacts:recorded},null,2)+'\n');
}
console.log('RC BROWSER ACCEPTANCE PASS: all required desktop/mobile synthetic journeys passed.');


async function whatsappAutoSendWorkflow(page,t,v) {
  await page.goto(baseUrl+'/pos/settings/whatsapp-delivery',{waitUntil:'domcontentloaded'});
  await page.locator('[data-whatsapp-readiness]').waitFor();
  if(!await page.locator('[data-confirmed-delivery]').innerText())throw new Error('Missing company delivery evidence state');
  await waitForOperationalSurface(page); await dismiss(page);
  const toggle=page.locator('input[type=checkbox][name=auto_send]');
  if(await toggle.isChecked())throw new Error('New company auto-send must default OFF');
  for(const key of ['wa_api_token','wa_app_secret'])if(await page.locator('input[name='+key+']').inputValue())throw new Error('Saved secret leaked into form');
  const html=await page.content();if(html.includes('synthetic-api-token')||html.includes('synthetic-app-secret'))throw new Error('Saved secret leaked into HTML');
  await toggle.check();await Promise.all([page.waitForURL('**/pos/settings/whatsapp-delivery'),page.getByRole('button',{name:'Save settings',exact:true}).click()]);
  await page.getByRole('status').waitFor(); await dismiss(page);
  if(!await toggle.isChecked())throw new Error('ON did not persist');
  await toggle.uncheck();await Promise.all([page.waitForURL('**/pos/settings/whatsapp-delivery'),page.getByRole('button',{name:'Save settings',exact:true}).click()]);
  await page.getByRole('status').waitFor(); await dismiss(page);await page.reload({waitUntil:'domcontentloaded'});
  if(await toggle.isChecked())throw new Error('OFF did not persist');
  await saveEvidenceScreenshot(page,'rc-'+v.width+'-whatsapp-optional-autosend');
  pass(t.name+'/'+v.width+': actual owner form defaults OFF, persists ON/OFF, keeps blank encrypted secrets private');
}

async function riderRealtimeWorkflow(page,t,v) {
  await page.goto(baseUrl+'/pos/riders/tracking',{waitUntil:'domcontentloaded'});
  const read=()=>page.evaluate(id=>{
    const root=document.querySelector('[x-data^="riderTracking("]');
    const d=root&&window.Alpine?.$data(root),marker=d?.markers?.[id];
    return marker?{lat:marker.getLatLng().lat,lng:marker.getLatLng().lng}:null;
  },t.riderRealtime.id);
  await page.waitForFunction(id=>{
    const root=document.querySelector('[x-data^="riderTracking("]');
    return root&&window.Alpine?.$data(root)?.markers?.[id];
  },t.riderRealtime.id);
  const at=Date.now(),lat=v.width===390?31.522:31.521,lng=74.322;
  const response=await page.request.post(baseUrl+'/api/rider-app/v1/locations',{
    headers:{Authorization:'Bearer '+t.riderRealtime.token},data:{points:[{lat,lng,acc:8,at}]}});
  if(!response.ok()||!(await response.json()).acknowledged_at?.includes(at))throw new Error('Real rider upload not acknowledged');
  await page.waitForFunction(({id,lat,lng})=>{
    const d=window.Alpine.$data(document.querySelector('[x-data^="riderTracking("]'));
    const m=d.markers[id]?.getLatLng();return m&&Math.abs(m.lat-lat)<.000001&&Math.abs(m.lng-lng)<.000001;
  },{id:t.riderRealtime.id,lat,lng},{timeout:10000});
  const pin=await read();if(!pin)throw new Error('Actual Leaflet rider marker missing');
  pass(t.name+'/'+v.width+': real authenticated API upload reaches Leaflet pin through automatic five-second poll');
}

async function catalogSearchCompleteWorkflow(page,t,v) {
  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  // Synthetic in-memory catalog: actual sale UI/methods, no company/product writes.
  await page.evaluate(()=>{
    const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));
    d.allDeals=Array.from({length:16},(_,i)=>({id:900000+i,type:'deal',name:'QA Deal No '+(i+1),price:100,category_id:991}));
    d.allProducts=[];d.allServices=[];d.searchQuery='';d.selectedCategory=777;d.searchAnyWord=false;
  });
  const input=page.locator('[x-ref="searchInput"]');await input.fill('QA Deal');
  await page.waitForFunction(()=>window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).searchSuggestions.length===16);
  const dropdown=page.locator('[x-ref="searchDropdown"]');await dropdown.waitFor({state:'visible'});
  for(const n of [13,14,15,16]) {
    const item=dropdown.locator('button').filter({hasText:'QA Deal No '+n});
    await item.scrollIntoViewIfNeeded();if(!await item.isVisible())throw new Error('matching deal '+n+' is hidden from search');
  }
  await page.evaluate(()=>{const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));d.highlightIndex=11;d.moveHighlight(1);});
  await page.waitForFunction(()=>window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).highlightIndex===12);

  await page.evaluate(()=>{
    const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));
    d.allDeals=Array.from({length:85},(_,i)=>({id:900000+i,type:'deal',name:'QA Deal No '+(i+1),price:100,category_id:991}));
    d.onSearchInput();
  });
  await page.waitForFunction(()=>window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).searchSuggestions.length===85);
  const more=dropdown.locator('button[x-show="searchSuggestions.length > searchDisplayCount"]');
  await more.scrollIntoViewIfNeeded();await more.click();
  if(!await dropdown.isVisible())throw new Error('Load more closed the search dropdown');
  const last=dropdown.locator('button').filter({hasText:'QA Deal No 85'});
  await last.scrollIntoViewIfNeeded();if(!await last.isVisible())throw new Error('Load more did not reveal matching deal 85');
  await saveEvidenceScreenshot(page,'catalog-search-complete-'+v.width);
  pass(t.name+'/'+v.width+': all sixteen deals searchable across category filters; 13-16 visible and keyboard reachable');
}

async function deliveryAddressSnapshotWorkflow(page,t,v) {
  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);await dismiss(page);
  const token=await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const headers={'Accept':'application/json','X-CSRF-TOKEN':token,'X-Requested-With':'XMLHttpRequest'};
  const address='Synthetic delivery lane '+v.width;
  const response=await page.request.post(baseUrl+'/pos/restaurant/api/customer-store',{
    headers,data:{name:'Synthetic address customer '+v.width,phone:'0300'+String(1000000+v.width),address},
  });
  const data=await response.json();
  if(!response.ok()||!data.success||!data.customer?.id)throw new Error('Synthetic delivery customer setup failed');
  let release;const gate=new Promise(resolve=>release=resolve);
  const bookUrl=baseUrl+'/pos/api/customer-addresses?customer_id='+data.customer.id;
  await page.route(bookUrl,async route=>{await gate;await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({addresses:[{id:0,label:'Default',address}]})});});
  let moneyPosts=0;
  page.on('request',request=>{if(request.method()==='POST'&&request.url()===baseUrl+'/pos/invoice/store')moneyPosts++;});
  // Actual UI DTO without address, as happens in the customer picker. Server book is delayed.
  await page.evaluate(customer=>{
    const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));
    d.orderType='delivery';d.selectCustomerFromPhone({id:customer.id,name:customer.name,phone:customer.phone});
    d.cart=[{cart_uid:'qa-address',item_id:null,item_type:'manual',item_name:'Synthetic address proof',quantity:1,unit_price:100,is_tax_exempt:true,special_notes:''}];
    d.lastPayTime=0;
  },data.customer);
  const posted=page.waitForResponse(r=>r.url()===baseUrl+'/pos/invoice/store'&&r.request().method()==='POST');
  const payment=page.evaluate(async()=>{
    const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));
    await d.processPaymentManual('cash',true,true);
  });
  await page.waitForTimeout(150);
  if(moneyPosts!==0)throw new Error('Delivery payment serialized before its current address lookup completed');
  release();
  const savedResponse=await posted;const saved=await savedResponse.json();await payment;
  if(!savedResponse.ok()||!saved.success||!saved.transaction_id)throw new Error('Synthetic provisional delivery save failed: '+JSON.stringify(saved));
  const payload=savedResponse.request().postDataJSON();
  if(payload.delivery_address!==address)throw new Error('Selected delivery address missing from real bill payload');
  await page.unroute(bookUrl);
  await page.goto(baseUrl+'/pos/transaction/'+saved.transaction_id+'/receipt',{waitUntil:'domcontentloaded'});
  const receipt=page.locator('body');
  if(!(await receipt.innerText()).includes(address))throw new Error('Persisted address absent from receipt preview');
  await page.emulateMedia({media:'print'});
  const line=page.getByText(address,{exact:true});
  if(!await line.isVisible())throw new Error('Persisted address hidden in print layout');
  await saveEvidenceScreenshot(page,'delivery-address-preview-print-'+v.width);
  await page.emulateMedia({media:'screen'});
  pass(t.name+'/'+v.width+': delayed address lookup awaited; real provisional payload, receipt preview and print CSS retain address');
}

async function riderBulkSelectionWorkflow(page,t,v){
  const fixture=t.riderBulkSelection[v.width];
  await page.goto(baseUrl+'/pos/deliveries',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);await dismiss(page);
  await page.locator('[data-rider-settlement-open="'+fixture.riderId+'"]').click();
  const modal=page.locator('[data-rider-settlement-dialog="'+fixture.riderId+'"]');await modal.waitFor({state:'visible'});
  const all=modal.locator('[data-settle-select-all]');await all.uncheck();await all.check();
  const skipped=modal.locator('[data-settle-bill="'+fixture.ids[2]+'"]');await skipped.uncheck();
  if(!await all.evaluate(el=>el.indeterminate))throw new Error('Rider selection does not show omitted bills');
  if(await modal.locator('[name="received_amount"]').inputValue()!=='200')throw new Error('Rider selected cash total includes omitted bills');
  await saveEvidenceScreenshot(page,'rider-select-all-omit-'+v.width+'.png');
  let posts=0;const count=r=>{if(r.method()==='POST'&&r.url()===baseUrl+'/pos/riders/'+fixture.riderId+'/settle')posts++;};page.on('request',count);
  page.once('dialog',dialog=>dialog.dismiss());await modal.locator('button[type="submit"]').click();
  if(posts!==0)throw new Error('Cancelled rider confirmation submitted money');
  page.once('dialog',dialog=>dialog.accept());
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),modal.locator('button[type="submit"]').click()]);
  if(posts!==1)throw new Error('Rider confirmation did not submit exactly once');
  page.off('request',count);await waitForOperationalSurface(page);await dismiss(page);
  await page.locator('[data-rider-settlement-open="'+fixture.riderId+'"]').click();
  const remaining=page.locator('[data-rider-settlement-dialog="'+fixture.riderId+'"]');await remaining.waitFor({state:'visible'});
  const remainingIds=await remaining.locator('[data-settle-bill]').evaluateAll(nodes=>nodes.map(el=>Number(el.value)));
  if(remainingIds.length!==1||remainingIds[0]!==fixture.ids[2])throw new Error('Omitted cash bill did not remain pending after selected settlement');
  await remaining.locator('button').filter({hasText:'Cancel'}).click();
}
