import {scrolledPixelDetail} from './scrolled-pixel-detail.mjs';
export async function prospectiveReminderWorkflow(page,t,v,{baseUrl,login,loopback,dismiss,waitForOperationalSurface,saveEvidenceScreenshot,pass}) {
 const cases=t.policyCases[v.width].reminders;
 if(!cases || Object.keys(cases).length!==3)throw Error('Required frozen reminder phase fixtures missing');
 for(const phase of ['trial','confirmed','grace']) {
  const ctx=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  await ctx.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
  try {
   const p=await ctx.newPage(),f=cases[phase];
   await login(p,{...t,login:f.login,loginPath:'/fbr-pos/login'});
   await p.goto(baseUrl+'/fbr-pos/dashboard',{waitUntil:'domcontentloaded'});
   await waitForOperationalSurface(p);await dismiss(p);
   for(const locale of ['en','ur','rur']) {
    await p.locator('#school-workspace-menu summary').click();
    const select=p.locator('#school-language');await select.selectOption(locale);
    await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),select.locator('..').locator('button').click()]);
    const notice=p.locator(`[data-prospective-reminder="${phase}"]`);await notice.waitFor({state:'visible'});
    if((await notice.innerText()).trim()!==f.texts[locale])throw Error('Reminder text is not exact frozen '+phase+'/'+locale+' translation');
    if(await notice.getAttribute('data-reminder-until')!==f.until)throw Error('Reminder deadline differs from frozen snapshot');
    if(locale==='ur' && await notice.getAttribute('dir')!=='rtl')throw Error('Urdu reminder is not RTL');
    await notice.scrollIntoViewIfNeeded();
    const errors=await notice.evaluate(el=>{
     const r=el.getBoundingClientRect(),cs=getComputedStyle(el),out=[];
     if(r.width<200||r.left<0||r.right>innerWidth+1||el.scrollWidth>el.clientWidth+1)out.push('horizontal overflow');
     if(parseFloat(cs.fontSize)<13||parseFloat(cs.lineHeight)<18)out.push('unreadable text size/leading');
     if(el.scrollHeight>el.clientHeight+1)out.push('text clipped vertically');
     const hit=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);if(!hit||!el.contains(hit))out.push('notice covered by another surface');
     if(cs.color!=='rgb(87, 59, 9)'||cs.backgroundColor!=='rgb(255, 248, 225)')out.push('expected high-contrast notice palette missing');
     return out;
    });
    if(errors.length)throw Error('Reminder '+phase+'/'+locale+': '+errors.join(', '));
    await scrolledPixelDetail(p,[`[data-prospective-reminder="${phase}"]`],'prospective-reminder-'+phase+'-'+locale+'-'+v.width,saveEvidenceScreenshot);
   }
  } finally {await ctx.close();}
 }
 pass('PROSPECTIVE REMINDERS PASS: '+v.width+': actual School dashboard frozen trial/confirmed/grace, EN/UR/RUR exact text/deadlines, readable bounded geometry and RTL; synthetic only, no bills or email sends');
}
