import {scrolledPixelDetail} from './scrolled-pixel-detail.mjs';
export async function prospectivePolicyWorkflow(page,t,v,{baseUrl,saveEvidenceScreenshot,pass}){
 const f=t.policyCases[v.width],check=(ok,m)=>{if(!ok)throw Error(m);};
 await page.goto(baseUrl+'/billing/plans',{waitUntil:'domcontentloaded'});
 const summary=page.locator('[data-billing-policy-summary][data-policy-version=selected_trial_v1]');await summary.waitFor({state:'visible'});
 check((await summary.innerText()).includes(f.plan),'Selected trial package missing from recorded policy');
 for(const field of ['trial_starts_at','trial_ends_at'])check(await summary.locator(`[data-policy-date="${field}"] time`).getAttribute('datetime')===f.terms[field],'Recorded '+field+' differs from stored frozen ISO timestamp');
 check(Date.parse(f.terms.trial_ends_at)-Date.parse(f.terms.trial_starts_at)===72*60*60*1000,'Prospective trial is not exactly72hours');
 for(const field of ['paid_starts_at','paid_ends_at','grace_ends_at'])check(await summary.locator(`[data-policy-date="${field}"] time`).count()===0,'Unpaid trial displays invented '+field);
 await scrolledPixelDetail(page,['[data-billing-policy-summary]'],'billing-policy-trial-'+v.width,saveEvidenceScreenshot);
 pass('PROSPECTIVE POLICY PASS: '+v.width+': actual selected-package billing page, exact stored ISO72hour trial dates, no invented paid/grace dates; legacy unchanged asserted separately, no payment or admin approval executed');
}
