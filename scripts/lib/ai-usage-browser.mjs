import {verifyAiUsageAdminThemes} from './ai-usage-theme-browser.mjs';
import {scrolledPixelDetail} from './scrolled-pixel-detail.mjs';
export async function aiUsageWorkflow(page,t,v,{baseUrl,login,loopback,dismiss,waitForOperationalSurface,saveEvidenceScreenshot,pass}) {
  const f=t.aiUsageCases[v.width];
  const check=(ok,message)=>{if(!ok)throw Error(message);};
  const visibleSurface=async p=>{
    await waitForOperationalSurface(p);
    await p.waitForFunction(()=>window.__alpineStarted===true || Boolean(window.Alpine));
    await dismiss(p);
    const heading=p.locator('#ai-usage-heading');
    await heading.scrollIntoViewIfNeeded();
    check(await heading.isVisible(),'AI usage heading must be visible');
    await p.locator('#usage-month').click({trial:true});
    const shape=await heading.evaluate(el=>{
      const r=el.getBoundingClientRect(); const hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);
      return {width:r.width,height:r.height,visible:r.top>=0&&r.bottom<=innerHeight,uncovered:hit===el||el.contains(hit)};
    });
    check(shape.width>=120&&shape.height>=20&&shape.visible&&shape.uncovered,'Usage heading is clipped, covered, or outside viewport');
    check(await p.locator('#ai-usage-summary').evaluate(el=>getComputedStyle(el).display)==='grid','Usage CSS did not load');
    const geometry=await p.evaluate(()=>{
      const content=document.querySelector('#ai-usage-content').getBoundingClientRect();
      const summary=document.querySelector('#ai-usage-summary').getBoundingClientRect();
      const dock=document.querySelector('[data-madadgar-placement]');
      const dr=dock?.getBoundingClientRect();
      let visible={left:Math.max(0,summary.left),right:Math.min(innerWidth,summary.right),top:Math.max(0,summary.top),bottom:Math.min(innerHeight,summary.bottom)};
      for(let p=document.querySelector('#ai-usage-summary').parentElement;p;p=p.parentElement){if(/(auto|scroll|hidden|clip)/.test(getComputedStyle(p).overflowY)){const r=p.getBoundingClientRect();visible.top=Math.max(visible.top,r.top);visible.bottom=Math.min(visible.bottom,r.bottom);}}
      const overlaps=dr&&visible.bottom>visible.top&&dr.bottom>visible.top&&dr.top<visible.bottom&&dr.right>visible.left&&dr.left<visible.right;
      const table=document.querySelector('#ai-usage-events');
      return {within:content.left>=0&&content.right<=innerWidth+1,
        dockSafe:!dock||(dock.dataset.madadgarPlacement==='inline'&&!overlaps),
        scroll:getComputedStyle(table).overflowX==='auto'&&table.clientWidth<=innerWidth,
        cellPadding:parseFloat(getComputedStyle(table.querySelector('td')).paddingLeft)>=10};
    });
    check(geometry.within&&geometry.dockSafe,'AI usage content or support dock overlaps/clips the visible summary: '+JSON.stringify(geometry));
    check(geometry.scroll&&geometry.cellPadding,'Usage events need readable padded cells inside a bounded horizontal scroller');
    if(await p.locator('.tn-admin-sidebar').count()){
      check(await p.locator('.tn-admin-sidebar svg').first().evaluate(el=>el.getBoundingClientRect().width)<=40,'Admin navigation icons are unstyled');
    }
  };
  const verify=async(p,company)=>{
    await visibleSurface(p);
    await p.getByRole('heading',{name:'AI usage and budget',exact:true}).waitFor();
    const dl=p.locator('dl').filter({has:p.locator('dt',{hasText:'Known estimated cost (USD)'})});
    const value=label=>dl.locator('dt').filter({hasText:label}).locator('xpath=following-sibling::dd[1]').innerText();
    check((await value('Recorded events')).trim()==='4','Usage total must equal four synthetic events');
    check((await value('Provider reservations (dispatch not inferred)')).trim()==='2','Provider reservation count wrong');
    check((await value('Blocked by budget')).trim()==='1','Synthetic budget block missing');
    check((await value('Provider errors')).trim()==='1','Uncertain provider error missing');
    check((await value('Known estimated cost')).includes('0.000020'),'Explicit synthetic token cost wrong');
    check((await value('Known estimated cost')).includes('incomplete: 1 unknown'),'Unknown cost was silently zeroed');
    check((await value('Committed allowance')).includes('0.000720'),'Uncertain allowance not retained');
    check((await p.locator('body').innerText()).includes(company.companyName),'Wrong company usage rendered');
    const table=p.locator('table').filter({has:p.getByRole('columnheader',{name:'Estimated USD',exact:true})});
    check(await table.getByText('budget_exhausted',{exact:true}).count()===1,'Wrong budget error category');
    check(await table.getByText('provider_transport_error',{exact:true}).count()===1,'Missing sanitized error category');
    check(await table.getByText('Unknown',{exact:true}).count()>0,'Unknown values absent');
  };
  const context=async()=>{
    const ctx=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
    await ctx.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
    return ctx;
  };
  for(const c of f.panels){
    await page.goto(baseUrl+'/admin/companies/'+c.companyId+'/ai-usage',{waitUntil:'domcontentloaded'});
    await verify(page,c);
    await verifyAiUsageAdminThemes(page,pass,v.width);
    const form=page.locator('form[action$="/ai-usage"]');
    await form.locator('#monthly_budget').fill('0');
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button',{name:'Save explicit rates / budget'}).click()]);
    check(await form.locator('#monthly_budget').inputValue()==='0.000000','Explicit zero budget not preserved');
    await form.locator('#monthly_budget').fill('0.001000');
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button',{name:'Save explicit rates / budget'}).click()]);
    const token=await form.locator('input[name="_token"]').inputValue();
    const bad=await page.context().request.post(baseUrl+'/admin/companies/'+c.companyId+'/ai-usage',{form:{_token:token,input_per_million:'-1'},headers:{Accept:'application/json'},maxRedirects:0});
    check(bad.status()===422,'Negative rate accepted');
    await visibleSurface(page);
    await saveEvidenceScreenshot(page,'ai-usage-admin-'+(c.prefix==='/pos'?'pos':'fbr')+'-'+v.width);
    await page.locator('#ai-usage-budget-form').scrollIntoViewIfNeeded();
    await form.locator('#monthly_budget').click({trial:true});
    const budgetBox=await form.locator('#monthly_budget').boundingBox();
    check(budgetBox&&budgetBox.width>=100&&budgetBox.height>=30,'Budget input is not usable');
    await scrolledPixelDetail(page,['#ai-usage-budget-form h2','#input_per_million','#output_per_million','#monthly_budget','#ai-usage-budget-form button[type=submit]'],'ai-usage-controls-'+(c.prefix==='/pos'?'pos':'fbr')+'-'+v.width,saveEvidenceScreenshot);
    const ctx=await context();
    try {
      const owner=await ctx.newPage();
      await login(owner,{...t,name:t.name+' '+c.prefix+' owner',login:c.login,loginPath:c.prefix+'/login'});
      await owner.goto(baseUrl+c.prefix+'/madadgar/usage',{waitUntil:'domcontentloaded'});
      await verify(owner,c);
      if(c.prefix==='/fbr-pos') {
        const fiscal=owner.locator('#school-fiscal-status');
        await fiscal.waitFor();
        check(/off|disabled|blocked/i.test(await fiscal.innerText()),'School portal access must not enable fiscal submission');
      }
      check(await owner.locator('#monthly_budget').count()===0,'Company owner received platform rate editor');
      const foreign=f.panels.find(x=>x.companyId!==c.companyId);
      await owner.goto(baseUrl+c.prefix+'/madadgar/usage?company_id='+foreign.companyId,{waitUntil:'domcontentloaded'});
      await verify(owner,c);
      check(!(await owner.locator('body').innerText()).includes(foreign.companyName),'Query broadened company scope');
      await saveEvidenceScreenshot(owner,'ai-usage-owner-'+(c.prefix==='/pos'?'pos':'fbr')+'-'+v.width);
    } finally { await ctx.close(); }
    const cashierCtx=await context();
    try {
      const cashier=await cashierCtx.newPage();
      await login(cashier,{...t,name:t.name+' '+c.prefix+' cashier',login:c.cashier,loginPath:c.prefix+'/login'});
      const denied=await cashierCtx.request.get(baseUrl+c.prefix+'/madadgar/usage',{headers:{Accept:'application/json'},maxRedirects:0});
      check(denied.status()===403,'Cashier read owner usage');
    } finally { await cashierCtx.close(); }
  }
  const ctx=await context();
  try {
    const support=await ctx.newPage();await login(support,{...t,name:t.name+' platform support',login:f.support});
    const denied=await ctx.request.get(baseUrl+'/admin/companies/'+f.panels[0].companyId+'/ai-usage',{headers:{Accept:'application/json'},maxRedirects:0});
    check(denied.status()===403,'Support role read sensitive configuration usage');
  } finally { await ctx.close(); }
  pass('AI USAGE PASS: '+v.width+': real POS/FBR owner/admin pages, scoped counts and explicit rates, uncertain cost not zero, cap/error metadata, zero budget save, negative rejection and cashier/support denial; synthetic metadata only, no provider request');
}
