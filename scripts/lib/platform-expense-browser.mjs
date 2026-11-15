import {scrolledPixelDetail} from './scrolled-pixel-detail.mjs';
export async function platformExpenseWorkflow(page,t,v,{baseUrl,login,loopback,saveEvidenceScreenshot,pass}){
 const f=t.expenseCases[v.width],path='/admin/platform-expenses',category='synthetic_'+v.width,reference='SYN-EXPENSE-'+v.width;
 const check=(ok,m)=>{if(!ok)throw Error(m);};
 await page.goto(baseUrl+path,{waitUntil:'domcontentloaded'});
 const panel=page.locator('#platform-expenses'),form=page.locator('#expense-record');await panel.waitFor();
 check((await panel.innerText()).includes('does not send money'),'Record-only boundary absent');
 await form.locator('[name=category]').fill(category);await form.locator('[name=amount]').fill('123.45');await form.locator('[name=reference]').fill(reference);await form.locator('[name=notes]').fill('Synthetic QA only');
 let posts=0,saved,payload;let committedResolve;const committed=new Promise(r=>committedResolve=r);
 const handler=async route=>{if(route.request().method()!=='POST')return route.continue();posts++;if(posts===1){payload=route.request().postDataJSON();const response=await route.fetch();check(response.status()===200,'Synthetic expense save failed');saved=await response.json();committedResolve();await route.abort('blockedbyclient');}else await route.continue();};
 await page.route('**'+path,handler);
 await form.getByRole('button',{name:'Record expense only',exact:true}).click();await Promise.race([committed,new Promise((_,reject)=>setTimeout(()=>reject(Error('Expense server commit was not observed within30seconds')),30000))]);
 await page.locator('#expense-result').filter({hasText:'Response unavailable'}).waitFor();
 check(saved.record_only===true&&saved.kind==='expense','Expense response not record-only');
 await page.reload({waitUntil:'domcontentloaded'});await page.locator('#expense-retry').waitFor({state:'visible'});
 check(posts===1,'Refresh automatically repeated expense write');
 const [retry]=await Promise.all([page.waitForResponse(r=>new URL(r.url()).pathname===path&&r.request().method()==='POST'),page.locator('#expense-retry').click()]);
 const recovered=await retry.json();check(retry.ok()&&recovered.id===saved.id&&posts===2,'Lost response retry duplicated expense');
 check(retry.request().postDataJSON().request_id===payload.request_id,'Retry changed stable request ID');
 await page.unroute('**'+path,handler);
 const token=await page.locator('#expense-record [name=_token]').inputValue();const post=(url,data)=>page.context().request.post(baseUrl+url,{data,headers:{Accept:'application/json','X-CSRF-TOKEN':token},maxRedirects:0});
 for(const change of [{amount:'124.45'},{category:'different'},{reference:'different'}])check((await post(path,{...payload,...change})).status()===409,'Changed-data replay was accepted');
 await page.goto(baseUrl+path+'?category='+category,{waitUntil:'domcontentloaded'});
 check(await page.locator('[data-expense-id]').count()===1,'Expense ledger contains duplicate row');check((await page.locator('#expense-totals').innerText()).includes('Net recorded expenses: PKR 123.45'),'Saved total incorrect after retry');
 const row=page.locator(`[data-expense-id="${saved.id}"]`);await row.locator('summary').click();const reverse=row.locator('form');
 await reverse.locator('[name=reference]').fill('SYN-REV-'+v.width);await reverse.locator('[name=notes]').fill('Synthetic correction only');
 const [reversal]=await Promise.all([page.waitForResponse(r=>new URL(r.url()).pathname===path+'/'+saved.id+'/reverse'&&r.request().method()==='POST'),reverse.getByRole('button',{name:'Record reversal only',exact:true}).click()]);
 const reversed=await reversal.json(),reversePayload=reversal.request().postDataJSON();check(reversal.ok()&&reversed.kind==='reversal'&&reversed.record_only===true,'Full record-only reversal failed');
 const replay=await post(path+'/'+saved.id+'/reverse',reversePayload);check(replay.ok()&&(await replay.json()).id===reversed.id,'Exact reversal retry duplicated record');
 check((await post(path+'/'+saved.id+'/reverse',{...reversePayload,request_id:await page.evaluate(()=>crypto.randomUUID())})).status()===409,'Second reversal accepted');
 await page.goto(baseUrl+path+'?category='+category,{waitUntil:'domcontentloaded'});
 check(await page.locator('[data-expense-id]').count()===2,'Append-only original/reversal rows not retained');
 check((await page.locator(`[data-expense-id="${saved.id}"]`).innerText()).includes('Reversed (original retained)'),'Original expense erased or still reversible');
 check((await page.locator('#expense-totals').innerText()).includes('Net recorded expenses: PKR 0.00'),'Reversal failed to net recorded totals once');
 await scrolledPixelDetail(page,['#expense-totals'],'platform-expense-reconciled-'+v.width,saveEvidenceScreenshot);
 const context=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});await context.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
 try{const support=await context.newPage();await login(support,{...t,login:f.support});const denied=await context.request.get(baseUrl+path,{headers:{Accept:'application/json'},maxRedirects:0});check(denied.status()===403&&!(await denied.text()).includes(reference),'Support role read platform expense');}finally{await context.close();}
 pass('PLATFORM EXPENSE PASS: '+v.width+': actual record-only form, committed response loss and explicit same-ID refresh retry, changed-data409, one original, full reversal and duplicate protection, frozen original retained, filtered net0 and non-superadmin403; no money execution');
}
