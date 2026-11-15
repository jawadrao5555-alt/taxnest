export async function praBoundedReadWorkflow(page,t,v,{baseUrl,login,loopback,saveEvidenceScreenshot,pass,dismiss,waitForOperationalSurface}){
 const f=t.praReadCases[v.width],check=(ok,m)=>{if(!ok)throw Error(m);};
 const root=p=>p.locator('[x-data^="tnMadadgar("]');
 const open=async p=>{await p.goto(baseUrl+'/pos/dashboard',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(p);await dismiss(p);await root(p).getByRole('button',{name:'Madadgar Support',exact:true}).click();await root(p).getByRole('button',{name:/Ask Madadgar/}).click();};
 const ask=async(p,q)=>{const input=root(p).locator('input[name="madadgar_q_nofill"]');await input.fill(q);const[r]=await Promise.all([p.waitForResponse(r=>new URL(r.url()).pathname==='/pos/madadgar/message'&&r.request().method()==='POST'),input.press('Enter')]);check(r.ok(),'PRA bounded read failed');const body=await r.json();await root(p).locator('[x-text="m.content"]').filter({hasText:body.reply}).last().waitFor();return body;};
 const scoped=(r,branch)=>{check(r.provenance?.answer_source==='company_read'&&r.provenance.branch_id===branch,'PRA read lost current campus/company scope');check(r.provenance.capabilities.billing_write===false&&r.provenance.capabilities.fiscal_submit===false,'Read advertises mutations');check(!r.reply.includes('SYNTHETIC-PRIVATE'),'Individual catalog data leaked');};
 await open(page);
 const closing=await ask(page,'today day close');scoped(closing,f.campus);
 check(closing.reply.includes('Saved cash PKR: 123.00')&&closing.reply.includes('Saved counted cash PKR: 120.00')&&closing.reply.includes('Saved variance PKR: -3.00'),'Frozen closing numbers missing or recalculated');
 check(closing.provenance.source_reference.includes('pos_day_close_reports'),'Closing source missing');
 await saveEvidenceScreenshot(page,'madadgar-pra-closing-'+v.width);
 const recipe=await ask(page,'recipe cost and servings');scoped(recipe,f.campus);
 check(recipe.reply.includes(`Product #${f.product} estimated ingredient cost PKR: 22.00`)&&recipe.reply.includes(`Product #${f.product} recorded-stock servings: 2`),'Recipe waste/cost or campus stock capacity incorrect');
 await saveEvidenceScreenshot(page,'madadgar-pra-recipe-'+v.width);
 const switchCampus=async id=>{const token=await page.locator('meta[name="csrf-token"]').getAttribute('content');return page.context().request.post(baseUrl+'/branch/switch',{form:{_token:token,branch_id:String(id),panel_guard:'pos'},maxRedirects:0});};
 check((await switchCampus(f.otherCampus)).status()===302,'Authorized campus switch failed');await open(page);
 const unknown=await ask(page,'recipe cost and servings');scoped(unknown,f.otherCampus);
 check(unknown.reply.includes(`Product #${f.product} recorded-stock servings: unknown`)&&!unknown.reply.includes('recorded-stock servings: 5'),'Missing campus stock fell back to company mirror or zero');
 const noClose=await ask(page,'today day close');scoped(noClose,f.otherCampus);check(noClose.reply.includes('No saved close')&&!noClose.reply.includes('Saved cash PKR: 123.00'),'Missing campus close leaked another campus snapshot');
 await saveEvidenceScreenshot(page,'madadgar-pra-campus-unknown-'+v.width);
 await switchCampus(f.foreignCampus);await open(page);const retained=await ask(page,'recipe cost and servings');scoped(retained,f.otherCampus);
 const foreign=await ask(page,'today day close for company '+f.foreignCompany);check(foreign.provenance?.answer_source!=='company_read'&&!foreign.reply.includes('123.00'),'Foreign company prompt broadened read');
 const context=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});await context.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
 try{const cashier=await context.newPage();await login(cashier,{...t,login:f.cashier});await open(cashier);const denied=await ask(cashier,'today day close');check(denied.provenance?.scope_reason==='company_read_unavailable'&&!denied.reply.includes('123.00'),'Cashier accessed owner closing read');}finally{await context.close();}
 pass('PRA BOUNDED READ PASS: '+v.width+': actual support UI frozen closing/counted variance and waste-aware recipe cost, selected-campus stock, missing campus stock/close unknown without global fallback, foreign branch/prompt and cashier denied; no provider/fiscal writes');
}
