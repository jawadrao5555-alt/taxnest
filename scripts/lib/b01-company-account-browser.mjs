// Actual admin UI against independently seeded, disposable company accounts.
export async function b01CompanyAccountWorkflow(page,t,v,{baseUrl,waitForOperationalSurface,dismiss,saveEvidenceScreenshot,pass}) {
  const f=t.b01Account[v.width], path='/admin/companies/'+f.companyId;
  const visit=async()=>{const r=await page.goto(baseUrl+path,{waitUntil:'domcontentloaded'});if(!r.ok())throw Error('B01 Manage Company failed');await waitForOperationalSurface(page);await dismiss(page);};
  const account=()=>page.locator('[data-company-account]');
  const requireText=async(locator,text)=>{if(!(await locator.innerText()).includes(text))throw Error('B01 missing '+text);};
  const unchangedTerm=async()=>{
    await requireText(account().locator('[data-account-start]'),'01 Jan 2025');
    await requireText(account().locator('[data-account-end]'),'31 Dec 2025');
    await requireText(account().locator('[data-account-price]'),'12,499.00');
    await requireText(account().locator('[data-subscription-decision]'),'Locked by subscription rules');
    if(await account().getAttribute('data-subscription-allowed')!=='no')throw Error('B01 expired term became allowed');
  };
  const expand=async(selector)=>{const el=page.locator(selector);if(!await el.evaluate(e=>e.open))await el.locator('summary').first().click();};
  const openForm=async(kind)=>{const form=page.locator('[data-company-state-action="'+kind+'"]');const details=form.locator('xpath=..');if(!await details.evaluate(e=>e.open))await details.locator('summary').click();return form;};
  const submit=async(kind,reason)=>{
    const form=await openForm(kind);await form.getByRole('textbox',{name:kind==='suspend'?'Suspension reason':'Activation reason',exact:true}).fill(reason);
    if(kind==='suspend')await form.getByRole('textbox',{name:'Restoration condition (optional)',exact:true}).fill('Manual synthetic owner review');
    const [response]=await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button',{name:kind==='suspend'?'Suspend company':'Activate company',exact:true}).click()]);
    if(!response?.ok())throw Error('B01 status form did not return a usable page');await waitForOperationalSurface(page);await dismiss(page);
  };
  await visit();await unchangedTerm();
  if(await account().getAttribute('data-company-control')!=='active')throw Error('B01 initial company control differs');
  await expand('[data-account-history]');await requireText(page.locator('[data-account-history]'),'Synthetic B01 catalogue');
  await expand('[data-account-payments]');const payments=page.locator('[data-account-payments]');
  await requireText(payments,f.paymentRef);await requireText(payments,f.proofRef);
  if((await payments.innerText()).includes(f.foreignRef))throw Error('B01 foreign company proof escaped scope');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('[data-company-proofs-link]').click()]);
  if(new URL(page.url()).searchParams.get('company_id')!==String(f.companyId))throw Error('B01 proof link lost company');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('link',{name:'Pending',exact:true}).click()]);
  if(new URL(page.url()).searchParams.get('company_id')!==String(f.companyId))throw Error('B01 proof tab lost company');
  const proofTable=await page.locator('table').textContent();
  if(!proofTable.includes(f.proofRef)||proofTable.includes(f.foreignRef))throw Error('B01 scoped proof queue contains wrong records');
  await visit();await expand('[data-account-history]');await expand('[data-account-payments]');await openForm('suspend');
  const saved=await page.evaluate(()=>({theme:getAdminTheme(),dark:document.documentElement.classList.contains('dark')}));
  try {
    for(const theme of ['indigo','emerald','cyan','rose','amber','purple'])for(const dark of [false,true]) {
      await page.evaluate(({theme,dark})=>{applyAdminTheme(theme);document.documentElement.classList.toggle('dark',dark);},{theme,dark});
      const errors=await page.evaluate(()=>{
        const rgb=s=>{const srgb=s.match(/^color\(\s*srgb\s+(.+?)\s*\)$/),m=srgb||s.match(/^rgba?\(([^)]+)\)$/);if(!m)throw Error('Unsupported colour '+s);const c=m[1].replace(/\//g,' ').split(/[,\s]+/).filter(Boolean);return [...c.slice(0,3).map(x=>x.endsWith('%')?parseFloat(x)*2.55:Number(x)*(srgb?255:1)),c[3]==null?1:c[3].endsWith('%')?parseFloat(c[3])/100:Number(c[3])];};
        const over=(a,b)=>a.slice(0,3).map((x,i)=>x*a[3]+b[i]*(1-a[3]));
        const bg=el=>{const chain=[];for(let p=el;p;p=p.parentElement)chain.unshift(p);let c=[255,255,255];for(const p of chain)c=over(rgb(getComputedStyle(p).backgroundColor),c);return c;};
        const lum=c=>c.map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4;}).reduce((n,x,i)=>n+x*[.2126,.7152,.0722][i],0);
        const ratio=(a,b)=>(Math.max(lum(a),lum(b))+.05)/(Math.min(lum(a),lum(b))+.05);
        const errors=[],els=document.querySelectorAll('[data-company-account] :is(h2,h3,dt,dd,p,summary,th,td,a),[data-company-state-action] :is(label,textarea,button)');let sampled=0;
        for(const el of els){if(!el.getClientRects().length)continue;sampled++;const back=bg(el),r=ratio(over(rgb(getComputedStyle(el).color),back),back);if(r<4.5)errors.push(el.tagName+' contrast '+r.toFixed(2));if(el.placeholder){const ps=getComputedStyle(el,'::placeholder'),fore=rgb(ps.color);fore[3]*=Number(ps.opacity);if(ratio(over(fore,back),back)<4.5)errors.push('Faint reason placeholder');}}
        if(sampled<25)errors.push('Account contrast sample incomplete');
        const root=document.querySelector('[data-company-account]'),rect=root.getBoundingClientRect();if(rect.width>innerWidth||rect.right>innerWidth+1||rect.left<0)errors.push('Account card exceeds viewport');
        for(const el of root.querySelectorAll('[role="region"]'))if(el.getBoundingClientRect().right>rect.right+1)errors.push('History table escaped bounded region');
        return errors;
      });
      if(errors.length)throw Error('B01 '+theme+'/'+dark+': '+errors.join('; '));
      if(theme==='indigo')await saveEvidenceScreenshot(page,'rc-b01-account-'+v.width+'-'+(dark?'dark':'light'));
    }
  } finally {await page.evaluate(({theme,dark})=>{applyAdminTheme(theme);document.documentElement.classList.toggle('dark',dark);},saved);}
  await submit('suspend','Synthetic B01 review <plain text>');await unchangedTerm();
  if(await account().getAttribute('data-company-control')!=='suspended')throw Error('B01 suspension not visible');
  await requireText(page.locator('[data-current-suspension]'),'Synthetic B01 review <plain text>');
  await requireText(page.locator('[data-current-suspension]'),'Manual synthetic owner review');
  await requireText(page.locator('[data-current-suspension]'),'Synthetic RC Platform Administrator');
  if(await page.locator('[data-current-suspension] plain').count())throw Error('B01 reason was interpreted as markup');
  await expand('[data-account-audit]');await requireText(page.locator('[data-account-audit]'),'Company suspended');
  await saveEvidenceScreenshot(page,'rc-b01-suspended-'+v.width);
  await submit('activate','Synthetic B01 review completed');await unchangedTerm();
  if(await account().getAttribute('data-company-control')!=='active')throw Error('B01 activation not visible');
  await expand('[data-account-audit]');await requireText(page.locator('[data-account-audit]'),'Company activated');await requireText(page.locator('[data-account-audit]'),'Synthetic B01 review completed');
  if(await page.locator('[data-account-event]').count()!==2)throw Error('B01 expected exactly two lifecycle events');
  pass('B01 ACCOUNT PASS: '+v.width+': actual company period/price, isolated proof queue, reasoned suspend/activate, unchanged expired term, two audit events; six themes/light-dark >=4.5 and bounded tables');
}
