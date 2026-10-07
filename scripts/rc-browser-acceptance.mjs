import { lstatSync, readFileSync, realpathSync } from 'node:fs';
import { assertLocalOnlyBaseUrl, attachDiagnostics, launchLocalBrowser, saveEvidenceScreenshot } from './lib/local-browser.mjs';

const baseUrl = assertLocalOnlyBaseUrl(process.env.BASE_URL);
const fixturePath = process.env.RC_BROWSER_FIXTURE;
if (!fixturePath || !/^\/tmp\/taxnest-rc-browser-[0-9]+(?:-[A-Za-z0-9_.-]+)?\/safe-runtime\/browser-state\/fixture\.json$/.test(fixturePath)) throw new Error('RC_BROWSER_FIXTURE must be generated under exact isolated /tmp state');
const fixtureStat = lstatSync(fixturePath);
if (!fixtureStat.isFile() || realpathSync(fixturePath) !== fixturePath) throw new Error('RC_BROWSER_FIXTURE must be a non-symlink regular file at its exact isolated target');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
if (!fixture.synthetic || !Array.isArray(fixture.readOnlyJourneys) || !Array.isArray(fixture.transactionalJourneys)) throw new Error('synthetic separated fixture required');
const requested = String(process.env.RC_BROWSER_ONLY || '').split(',').map(x => x.trim()).filter(Boolean);
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
      try { await el.click({trial:true,timeout:5000}); }
      catch { fail(`${t.name}/${v.width}: sale control is not pointer-actionable: ${selector}`); }
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
async function hotelWorkflow(page, t, v) {
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
  await page.goto(baseUrl + '/pos/hotel', {waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  const card = page.locator('[data-hotel-room-state="vacant"]').filter({hasText: room});
  await Promise.all([page.waitForURL(/stays\/create/), card.locator('[data-hotel-room-check-in]').click()]);
  await dismiss(page);
  const form = page.locator('form[action$="/pos/hotel/stays"]');
  await form.locator('[name="guest_name"]').fill('Synthetic Simple Desk ' + room);
  await form.locator('[name="guest_phone"]').fill('03000000000');
  await form.locator('[name="rate_amount"]').fill('4000');
  await form.locator('[name="discount_type"]').selectOption('amount');
  await form.locator('[name="discount_value"]').fill('500');
  await form.locator('details').filter({has: page.locator('[name="advance_amount"]')}).locator('summary').click();
  await form.locator('[name="advance_amount"]').fill('1000');
  await page.addInitScript(() => { window.__hotelPrintCalls = 0; window.print = () => { ++window.__hotelPrintCalls; }; });
  await Promise.all([page.waitForURL(/\/pos\/hotel\/stays\/\d+$/, {timeout:30000,waitUntil:'domcontentloaded'}), form.locator('button').click()]);
  const checkinPreview = page.locator('[data-hotel-bill-preview="1"]');
  await checkinPreview.waitFor({state:'visible'});
  if (await page.evaluate(() => window.__hotelPrintCalls) !== 0) throw new Error('Check-in automatically opened native print');
  if (!(await checkinPreview.locator('[data-preview-lines]').innerText()).trim()) throw new Error('Check-in popup omitted room charges');
  await saveEvidenceScreenshot(page, `hotel-checkin-preview-${v.width}.png`);
  await checkinPreview.locator('[data-preview-back]').click();
  await checkinPreview.waitFor({state:'hidden'});
  const stayPath = new URL(page.url()).pathname;
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
  if (!(await previewModal.locator('[data-preview-lines]').innerText()).trim()) throw new Error('Bill preview omitted actual folio lines');
  await saveEvidenceScreenshot(page, `hotel-bill-preview-${v.width}-draft.png`);
  await previewModal.locator('[data-preview-back]').click();
  await previewModal.waitFor({state:'hidden'});
  if (billConfirmPosts !== 0) throw new Error('Closing preview created/reported a bill');
  if (await deskModal.locator('[data-desk-method]').inputValue() !== 'card' || Number(await deskModal.locator('[data-desk-amount]').inputValue()) !== 2500) throw new Error('Preview close lost payment edits');
  await page.locator('[data-hotel-open-desk="1"]').click();
  await previewModal.waitFor({state:'visible'});
  await page.waitForFunction(() => !document.querySelector('[data-preview-confirm]').disabled);
  await deskModal.locator('[data-desk-flow]').selectOption('checkout');
  await page.waitForFunction(() => !document.querySelector('[data-preview-confirm]').disabled);
  // Report the full card-priced bill with only the recorded advance received.
  await deskModal.locator('[data-desk-flow]').selectOption('collect');
  await page.waitForFunction(() => !document.querySelector('[data-preview-confirm]').disabled);
  await deskModal.locator('[data-desk-amount]').fill('0');
  await deskModal.locator('[data-desk-update]').click();
  await page.waitForFunction(() => !document.querySelector('[data-preview-confirm]').disabled);
  const [issued] = await Promise.all([
    page.waitForResponse(r => r.url().endsWith(stayPath + '/bill-confirm') && r.request().method() === 'POST'),
    previewModal.locator('[data-preview-confirm]').click(),
  ]);
  if (!issued.ok()) throw new Error('Full bill with partial advance failed');
  const firstBill = await issued.json();
  await previewModal.locator('[data-preview-receipt]').waitFor({state:'visible'});
  if (!firstBill.bill_id) throw new Error('Partial advance did not issue full bill');
  if (!await previewModal.locator('[data-preview-credit-note]').isVisible()) throw new Error('Credit note missing from shared popup');
  await saveEvidenceScreenshot(page, `hotel-bill-preview-${v.width}-advance-invoice.png`);
  const receiptPagePromise = page.context().waitForEvent('page');
  await previewModal.locator('[data-preview-receipt]').click();
  const receiptPage = await receiptPagePromise;
  await receiptPage.waitForLoadState('domcontentloaded');
  if (!(await receiptPage.locator('body').innerText()).includes(firstBill.invoice_number)) throw new Error('Scoped Hotel receipt did not render the original bill');
  await saveEvidenceScreenshot(receiptPage, `hotel-receipt-${v.width}-advance.png`);
  await receiptPage.close();

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
  const invoiceEntry = billsPage.locator(`[data-hotel-invoice="${firstBill.bill_id}"]`);
  await invoiceEntry.waitFor({state:'visible'});
  if (!(await invoiceEntry.innerText()).includes(firstBill.invoice_number)) throw new Error('Hotel Bills lost the original invoice number');
  await invoiceEntry.locator('a').first().click();
  await billsPage.waitForURL(baseUrl + '/pos/transaction/' + firstBill.bill_id);
  await saveEvidenceScreenshot(billsPage, `hotel-bills-original-${v.width}.png`);
  await billsPage.close();
  pass(`${t.name}/${v.width}: Hotel Bills links the same original invoice as Transactions`);

  await previewModal.locator('[data-preview-checkout]').click();
  await page.waitForFunction(() => { const d = document.querySelector('[data-hotel-bill-preview]'); return d && !d.querySelector('[data-preview-confirm]').disabled && Number(d.querySelector('[data-desk-amount]').value) === 2500; });
  const [confirmed] = await Promise.all([
    page.waitForResponse(r => r.url().endsWith(stayPath + '/bill-confirm') && r.request().method() === 'POST'),
    previewModal.locator('[data-preview-confirm]').click(),
  ]);
  if (!confirmed.ok()) throw new Error('Remaining payment checkout failed');
  const checkoutResult = await confirmed.json();
  if (checkoutResult.bill_id !== firstBill.bill_id) throw new Error('Checkout duplicated the existing full bill');
  await previewModal.locator('[data-preview-receipt]').waitFor({state:'visible'});
  if (billConfirmPosts !== 2) throw new Error('Shared dialog posted an unexpected number of actions');
  if (await previewModal.locator('[data-preview-qr]').isVisible()) throw new Error('Unreported local bill advertised a PRA QR');
  await saveEvidenceScreenshot(page, `hotel-bill-preview-${v.width}-confirmed.png`);
  page.off('request', countConfirm);
  await Promise.all([page.waitForURL(baseUrl + stayPath, {timeout:30000}), previewModal.locator('[data-preview-stay]').click()]);
  await dismiss(page);
  if (!await page.locator('a[href*="/bills/"][href$="/receipt"]').count()) throw new Error('Checkout must expose the issued receipt');
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
      throw new Error('Credit Note action escaped the visible scrolled table');
    await saveEvidenceScreenshot(page, `tax-credit-sticky-${right ? 'right' : 'left'}-${v.width}.png`);
  }
  await Promise.all([page.waitForURL(/\/credit-notes#bill-\d+$/), shortcut.click()]);
  if (new URL(page.url()).pathname !== path) throw new Error('Tax Reports credit shortcut opened a different stay');
  await page.locator('[data-hotel-credit-notes="1"]').waitFor({state:'visible'});
  await saveEvidenceScreenshot(page, `tax-report-credit-shortcut-${v.width}.png`);
  pass(`${t.name}/${v.width}: Tax Reports opens original Hotel credit review directly`);
  for (const mode of ['full', 'partial']) {
    await page.goto(baseUrl + path, {waitUntil:'domcontentloaded'});
    await waitForOperationalSurface(page); await dismiss(page);
    const screen = page.locator('[data-hotel-credit-notes="1"]');
    await screen.waitFor({state:'visible'});
    await screen.locator('[role="alert"]').waitFor({state:'visible'});
    const form = screen.locator('form[action$="/review"]').first();
    await form.locator('[name="mode"]').selectOption(mode);
    if (mode === 'partial') await form.locator('input[type="number"]').first().fill('1');
    await Promise.all([page.waitForURL(/\/credit-notes\/\d+\/review$/), form.locator('button').click()]);
    const review = page.locator('[data-hotel-credit-review="1"]');
    await review.waitFor({state:'visible'});
    if (await review.locator('form').count()) throw new Error('Unverified credit issuance became actionable');
    if (!await review.locator('[role="alert"]').count()) throw new Error('Verification gate missing from credit review');
    await review.locator('[data-credit-header-tax]').waitFor({state:'visible'});
    if (!(await review.innerText()).includes(mode === 'full' ? '2,000.00' : '1,000.00')) throw new Error('Original quantity review total is wrong');
    await saveEvidenceScreenshot(page, `hotel-credit-${mode}-${v.width}.png`);
  }
  pass(`${t.name}/${v.width}: original full/partial review and default fiscal gate passed`);
  await page.goto(baseUrl + path, {waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  const activation = page.locator('[data-credit-activation="1"]');
  await activation.locator('[name="issuance"]').selectOption('1');
  await activation.locator('[name="refunds"]').selectOption('0');
  await activation.locator('[name="confirmed"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}), activation.locator('button').click()]);
  for (const mode of ['full', 'partial']) {
    await page.goto(baseUrl + path, {waitUntil:'domcontentloaded'});
    await waitForOperationalSurface(page); await dismiss(page);
    const form = page.locator('form[action$="/review"]').first();
    await form.locator('[name="mode"]').selectOption(mode);
    if (mode === 'partial') await form.locator('input[type="number"]').first().fill('1');
    await Promise.all([page.waitForURL(/\/credit-notes\/\d+\/review$/), form.locator('button').click()]);
    const issue = page.locator('[data-hotel-credit-review="1"] form[action$="/issue"]');
    await issue.waitFor({state:'visible'});
    await issue.locator('[name="reason"]').fill('Synthetic original bill adjustment');
    await issue.locator('[name="confirmed"]').check();
    await saveEvidenceScreenshot(page, `hotel-credit-enabled-${mode}-${v.width}.png`);
  }
  await page.goto(baseUrl + t.hotelCreditReview.refundPath, {waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  if (await page.locator('form[action$="/refund"]').count()) throw new Error('Refund appeared before its separate opt-in');
  await activation.locator('[name="issuance"]').selectOption('0');
  await activation.locator('[name="refunds"]').selectOption('1');
  await activation.locator('[name="confirmed"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}), activation.locator('button').click()]);
  const refund = page.locator('form[action$="/refund"]').first();
  await refund.waitFor({state:'visible'});
  await refund.locator('[name="amount"]').fill('100');
  await refund.locator('[name="method"]').selectOption('card');
  await refund.locator('[name="confirmed"]').check();
  await saveEvidenceScreenshot(page, `hotel-credit-enabled-refund-${v.width}.png`);
  // No fiscal API calls: backend regressions exercise posting with synthetic acceptance.
  // Restore this fixture so the next viewport must also prove default-OFF behavior.
  await page.goto(baseUrl + path, {waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  await activation.locator('[name="issuance"]').selectOption('0');
  await activation.locator('[name="refunds"]').selectOption('0');
  await activation.locator('[name="confirmed"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}), activation.locator('button').click()]);
  await activation.locator('[name="issuance"] option:checked').waitFor();
  if (await activation.locator('[name="issuance"]').inputValue() !== '0') throw new Error('Owner deactivation did not persist');
  pass(`${t.name}/${v.width}: scoped owner activation opens full/partial issuance and deactivation persists`);

}

async function notificationWorkflow(page, t, v, diagnostics) {
  const panel=t.notificationWorkflow, prefix=panel==='fbr'?'/fbr-pos':'/pos';
  await page.goto(baseUrl+prefix+'/my-profile',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);
  const modal=page.locator('[data-wn-featured="1"]:visible');
  await modal.waitFor({state:'visible',timeout:15000});
  if(await modal.count()!==1)throw new Error('duplicate featured receipts rendered multiple popups');
  const dismissButton=modal.locator('button').last();
  const endpoint='**'+prefix+'/whats-new/seen';
  let injectedFailures=0;
  const expectedFailureUrl=baseUrl+prefix+'/whats-new/seen';
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
  for(let refresh=0;refresh<2;refresh++){
    await page.reload({waitUntil:'domcontentloaded'});
    await waitForOperationalSurface(page);
    if(await page.locator('[x-show="wnOpen"]:visible').count())throw new Error('refresh reopened an acknowledged featured notice or routine queue');
    await page.locator('[data-tn-topnav-control="notification"]').first().click();
    const bell=page.locator('[x-show="bellOpen"]:visible').first();
    await bell.waitFor({state:'visible',timeout:5000});
    const text=await bell.innerText();
    for(let i=1;i<=7;i++)if(!text.includes('Synthetic routine '+panel+' '+i))throw new Error('routine bell history disappeared after refresh');
    if((text.match(new RegExp('Synthetic featured '+panel,'g'))||[]).length!==1)throw new Error('duplicate deployment receipts were not consolidated in bell history');
    await page.locator('[data-tn-topnav-control="notification"]').first().click();
  }
  pass(t.name+'/'+v.width+': real failed-save retry, duplicate consolidation and seven routine notices across refresh');
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

async function workflow(page,t,v,diagnostics) {
  if (t.tableOrderWorkflow) return tableOrderWorkflow(page,t,v);
  if (t.hotelSettingsWorkflow) return hotelSettingsWorkflow(page,t,v,diagnostics);
  if (t.notificationWorkflow) return notificationWorkflow(page,t,v,diagnostics);
  if (t.hotelWorkflow) return hotelWorkflow(page,t,v);
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

async function tableOrderWorkflow(page,t,v) {
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
  if(t.cashierHandoff) await cashierHandoffWorkflow(page,t,v);
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
  valid(t); const c=await browser.newContext({viewport:v,serviceWorkers:'allow'}); await c.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient')); const p=await c.newPage(), d=attachDiagnostics(p);
  try { await login(p,t.notificationWorkflow&&v.width<768?{...t,login:t.mobileLogin}:t); await waitForOperationalSurface(p); if(!t.notificationWorkflow)await dismiss(p); if(t.submitSelector){await p.goto(baseUrl+t.submitPath,{waitUntil:'domcontentloaded'});await waitForOperationalSurface(p);await checkAdminContrast(p,t,v);p.once('dialog',x=>x.accept());await Promise.all([p.waitForURL(u=>!u.pathname.startsWith('/admin/companies/'),{timeout:30000}),p.locator(t.submitSelector).first().evaluate(n=>n.requestSubmit())]);} await workflow(p,t,v,d);for(const path of t.paths||[t.path])await surface(p,t,path,v);await sameProductCategorySurface(p,t,v);await categoryMismatch(p,t,v);if(d.pageErrors.length)fail(`${t.name}/${label}: page error ${d.pageErrors[0]}`);const expectedMismatch=t.categoryCoverage?.mismatchPath ? `${baseUrl}${t.categoryCoverage.mismatchPath}` : null;const hotelFallback=t.categoryCoverage?.category==='hotel';const intentionalMismatchFailure=x=>(expectedMismatch&&x.includes(expectedMismatch))||(hotelFallback&&(x.includes(`${baseUrl}/pos/hotel`)||x.includes(`${baseUrl}/pos/invoice/create`)));const consoleErrors=t.denied?[]:d.consoleErrors.filter(x=>!x.includes('ERR_BLOCKED_BY_CLIENT')&&!intentionalMismatchFailure(x));const failedRequests=t.denied?[]:d.failedRequests.filter(x=>!x.includes('ERR_BLOCKED_BY_CLIENT')&&!intentionalMismatchFailure(x));if(consoleErrors.length)fail(`${t.name}/${label}: console error ${consoleErrors[0]}`);if(failedRequests.length)fail(`${t.name}/${label}: failed request ${failedRequests[0]}`);const unexpectedHttp=t.denied?[]:d.httpErrors;if(unexpectedHttp.length)fail(`${t.name}/${label}: HTTP ${unexpectedHttp[0].status} ${unexpectedHttp[0].url}`);console.log(`DIAGNOSTICS: ${t.name}/${label}: ${d.summary()}`); }
  catch(e){fail(`${t.name}/${label}: ${e.message}`);} finally {await saveEvidenceScreenshot(p,`rc-${label}-${t.name}`).catch(()=>{});await c.close();}
}
const {browser}=await launchLocalBrowser();
try { for(const [label,v]of views)for(const t of cases){await one(browser,label,v,t);if((t.hotelSettingsWorkflow||t.tableOrderWorkflow||t.hotelWorkflow)&&failures)throw new Error("Settings/table-order/Hotel preflight failed; required browser acceptance remains failed");} for(const [label,v]of views)if(!requested.length||requestedIsolation)await healthIsolation(browser,label,v,fixture.isolation); if(!requested.length&&!di.length)throw new Error('DI pending role fixture missing'); for(const [label,v]of views)for(const t of di)if(!requested.length||requested.includes(t.name))await one(browser,label,v,t); } finally {await browser.close();}
if(failures){console.error(`RC BROWSER ACCEPTANCE FAIL: ${failures} assertion(s) failed.`);process.exit(1);} console.log('RC BROWSER ACCEPTANCE PASS: all required desktop/mobile synthetic journeys passed.');
