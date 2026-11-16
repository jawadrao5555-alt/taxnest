import {scrolledPixelDetail} from './scrolled-pixel-detail.mjs';
export async function pilotFiscalControlWorkflow(page,t,v,{baseUrl,login,loopback,saveEvidenceScreenshot,pass}){
 const f=t.pilotFiscalCases[v.width],check=(v,m)=>{if(!v)throw Error(m);};
 const snapshots=new Map();
 const companySnapshot=async c=>{
  await page.goto(baseUrl+'/admin/companies/'+c.id,{waitUntil:'domcontentloaded'});
  const displayedReporting=await page.getByText('FBR Reporting',{exact:true}).locator('..').innerText();
  check(displayedReporting.includes('Disabled')&&!displayedReporting.includes('Enabled'),'Displayed effective FBR reporting status is not OFF');
  const history={};for(const label of ['Transactions','Total Revenue']){const rows=page.getByText(label,{exact:true});check(await rows.count()>0,'Rendered company history metric missing: '+label);history[label]=await rows.evaluateAll(nodes=>nodes.map(n=>n.parentElement.innerText));}
  // This is rendered effective status; native probes separately assert raw DB/history preservation.
  return {displayedReporting,history};
 };
 for(const c of f.companies)snapshots.set(c.id,await companySnapshot(c));
 const url=id=>baseUrl+'/admin/companies/'+id+'/pilot-fiscal-control';
 for(const c of f.companies){
  await page.goto(url(c.id),{waitUntil:'domcontentloaded'});
  const panel=page.locator('#pilot-fiscal-control');await panel.waitFor({state:'visible'});
  check((await panel.locator('[data-pilot-fiscal-status]').innerText()).includes('OFF'),'Pilot effective OFF absent');
  check(await panel.locator('#pilot-fiscal-route').inputValue()==='off','Pilot route selector is not OFF');
  check(await panel.locator('#pilot-fiscal-route option[value=di]').isDisabled(),'DI route option enabled');
  const before=await page.request.get(url(c.id),{headers:{Accept:'application/json'}});check(before.status()===200,'Owner fiscal read failed');const initial=await before.json();
  check(initial.company_id===c.id&&initial.enabled===false&&initial.historical_submission===false,'Fiscal state company mismatch or armed');
  const token=await page.locator('meta[name=csrf-token]').getAttribute('content');
  const rejected=await page.request.post(url(c.id),{headers:{Accept:'application/json','X-CSRF-TOKEN':token},data:{route:'ims'}});
  check(rejected.status()===422,'Direct unverified ON was not rejected');
  check(JSON.stringify(await rejected.json())===JSON.stringify(initial),'Rejected ON mutated effective fiscal state');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),panel.locator('#pilot-fiscal-off').click()]);
  const after=await page.request.get(url(c.id),{headers:{Accept:'application/json'}});
  check(JSON.stringify(await after.json())===JSON.stringify(initial),'OFF changed effective state or historical eligibility');
  const other=f.companies.find(x=>x.id!==c.id);const sibling=await page.request.get(url(other.id),{headers:{Accept:'application/json'}});
  check((await sibling.json()).company_id===other.id,'Company-specific control lost target scope');
  await saveEvidenceScreenshot(page,'pilot-fiscal-control-'+c.category+'-'+v.width);
  await scrolledPixelDetail(page,['#pilot-fiscal-off','#pilot-fiscal-route','#pilot-fiscal-blocker'],'pilot-fiscal-control-'+c.category+'-controls-'+v.width,saveEvidenceScreenshot);
 }
 for(const denied of [{login:f.support,loginPath:'/admin/login',expected:403},{login:f.companies[0].owner,loginPath:'/fbr-pos/login',expected:302}]){
  const ctx=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  await ctx.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
  try{const p=await ctx.newPage();await login(p,{...t,...denied});
   const deniedToken=await p.locator('meta[name=csrf-token]').getAttribute('content');
   const assertDenied=async(r,label)=>{
    check(r.status()===denied.expected,label+' unexpected status '+r.status());
    if(denied.expected===302)check(new URL(r.headers().location||'',baseUrl).pathname==='/admin/login',label+' redirected outside admin sign-in boundary');
    const body=await r.text();check(!body.includes('required_evidence')&&!body.includes('pilot-fiscal-status'),label+' exposed fiscal control');
   };
   for(const c of f.companies){const r=await ctx.request.get(url(c.id),{headers:{Accept:'application/json'},maxRedirects:0});await assertDenied(r,'Unauthorized fiscal GET company '+c.id);
    const write=await ctx.request.post(url(c.id),{headers:{Accept:'application/json','X-CSRF-TOKEN':deniedToken},data:{enabled:true},maxRedirects:0});await assertDenied(write,'Unauthorized fiscal POST company '+c.id);}
  }finally{await ctx.close();}
 }
 for(const c of f.companies){
  const state=await page.request.get(url(c.id),{headers:{Accept:'application/json'}});const data=await state.json();
  check(state.status()===200&&data.company_id===c.id&&data.enabled===false&&data.historical_submission===false,'Forbidden probe changed effective fiscal state');
  check(JSON.stringify(await companySnapshot(c))===JSON.stringify(snapshots.get(c.id)),'Forbidden probe changed displayed reporting status or rendered history totals');
 }
 pass('PILOT FISCAL CONTROL PASS: '+v.width+': school/hospital effective OFF, incomplete IMS setup rejected with unchanged state, explicit targeted OFF, tenant/support denied; no bill or fiscal dispatch');
}
