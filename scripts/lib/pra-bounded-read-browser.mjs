export async function praBoundedReadWorkflow(page,t,v,{baseUrl,login,loopback,saveEvidenceScreenshot,pass,dismiss,waitForOperationalSurface}){
 const f=t.praReadCases[v.width],check=(ok,m)=>{if(!ok)throw Error(m);};
 const root=p=>p.locator('[x-data^="tnMadadgar("]');
 const open=async p=>{await p.goto(baseUrl+'/pos/dashboard',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(p);await dismiss(p);await root(p).getByRole('button',{name:'Madadgar Support',exact:true}).click();await root(p).getByRole('button',{name:/Ask Madadgar/}).click();};
 const composerBounds=async p=>{
  // Alpine switches x-show after the menu click; do not measure its hidden pre-render frame.
  await root(p).locator('input[name="madadgar_q_nofill"]').waitFor({state:'visible'});
  await p.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  const rects=await root(p).locator('input[name="madadgar_q_nofill"]').evaluate(input=>{const form=input.form,button=form.querySelector('button[type="submit"]');const rect=e=>{const r=e.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height};};return {input:rect(input),button:rect(button),form:rect(form),width:innerWidth,height:innerHeight};});
  for(const r of [rects.input,rects.button])check(r.width>20&&r.height>20&&r.left>=rects.form.left-1&&r.right<=rects.form.right+1&&r.left>=0&&r.right<=rects.width&&r.top>=0&&r.bottom<=rects.height,'PRA composer control clipped or outside its form/viewport: '+JSON.stringify(rects));
  check(rects.input.right<=rects.button.left,'PRA composer input overlaps send button');
  check(Math.abs(rects.button.width-40)<=1,'PRA inline send button stretched by global mobile form styling');
 };
 const answerCapture=async(body,needle,name)=>{
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  const message=root(page).locator('[x-text="m.content"]').filter({hasText:body.reply}).last();
  const visible=await message.evaluate((el,needle)=>{
   const text=el.firstChild,index=el.textContent.indexOf(needle);if(!text||index<0)throw Error('Expected answer text missing');
   const range=document.createRange();range.setStart(text,index);range.setEnd(text,index+needle.length);
   const scroller=el.closest('[x-ref="mbody"]');let r=range.getBoundingClientRect(),b=scroller.getBoundingClientRect();scroller.scrollTop+=r.top-b.top-12;
   r=range.getBoundingClientRect();b=scroller.getBoundingClientRect();return r.top>=b.top&&r.bottom<=b.bottom&&r.left>=b.left&&r.right<=b.right;
  },needle);
  check(visible,'Expected numeric/unknown answer is clipped in evidence');await composerBounds(page);await saveEvidenceScreenshot(page,name+'-answer-'+v.width);
 };
 const ask=async(p,q)=>{await composerBounds(p);const input=root(p).locator('input[name="madadgar_q_nofill"]');await input.fill(q);const[r]=await Promise.all([p.waitForResponse(r=>new URL(r.url()).pathname==='/pos/madadgar/message'&&r.request().method()==='POST'),input.press('Enter')]);check(r.ok(),'PRA bounded read failed');const body=await r.json();await root(p).locator('[x-text="m.content"]').filter({hasText:body.reply}).last().waitFor();return body;};
 const scoped=(r,branch)=>{check(r.provenance?.answer_source==='company_read'&&r.provenance.branch_id===branch,'PRA read lost current campus/company scope');check(r.provenance.capabilities.billing_write===false&&r.provenance.capabilities.fiscal_submit===false,'Read advertises mutations');check(!r.reply.includes('SYNTHETIC-PRIVATE'),'Individual catalog data leaked');};
 await open(page);
 const closing=await ask(page,'today day close');scoped(closing,f.campus);
 check(closing.reply.includes('Saved cash PKR: 123.00')&&closing.reply.includes('Saved counted cash PKR: 120.00')&&closing.reply.includes('Saved variance PKR: -3.00'),'Frozen closing numbers missing or recalculated');
 check(closing.provenance.source_reference.includes('pos_day_close_reports'),'Closing source missing');
 await saveEvidenceScreenshot(page,'madadgar-pra-closing-'+v.width);
 await answerCapture(closing,'Saved cash PKR: 123.00','madadgar-pra-closing');
 const recipe=await ask(page,'recipe cost and servings');scoped(recipe,f.campus);
 check(recipe.reply.includes(`Product #${f.product} estimated ingredient cost PKR: 22.00`)&&recipe.reply.includes(`Product #${f.product} recorded-stock servings: 2`),'Recipe waste/cost or campus stock capacity incorrect');
 await saveEvidenceScreenshot(page,'madadgar-pra-recipe-'+v.width);
 await answerCapture(recipe,`Product #${f.product} estimated ingredient cost PKR: 22.00`,'madadgar-pra-recipe');
 const switchCampus=async id=>{const token=await page.locator('meta[name="csrf-token"]').getAttribute('content');return page.context().request.post(baseUrl+'/branch/switch',{form:{_token:token,branch_id:String(id),panel_guard:'pos'},maxRedirects:0});};
 check((await switchCampus(f.otherCampus)).status()===302,'Authorized campus switch failed');await open(page);
 const unknown=await ask(page,'recipe cost and servings');scoped(unknown,f.otherCampus);
 check(unknown.reply.includes(`Product #${f.product} recorded-stock servings: unknown`)&&!unknown.reply.includes('recorded-stock servings: 5'),'Missing campus stock fell back to company mirror or zero');
 const noClose=await ask(page,'today day close');scoped(noClose,f.otherCampus);check(noClose.reply.includes('No saved close')&&!noClose.reply.includes('Saved cash PKR: 123.00'),'Missing campus close leaked another campus snapshot');
 await saveEvidenceScreenshot(page,'madadgar-pra-campus-unknown-'+v.width);
 await answerCapture(noClose,'No saved close','madadgar-pra-campus-unknown');
 await switchCampus(f.foreignCampus);await open(page);const retained=await ask(page,'recipe cost and servings');scoped(retained,f.otherCampus);
 const foreign=await ask(page,'today day close for company '+f.foreignCompany);check(foreign.provenance?.answer_source!=='company_read'&&!foreign.reply.includes('123.00'),'Foreign company prompt broadened read');
 const context=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});await context.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
 try{const cashier=await context.newPage();await login(cashier,{...t,login:f.cashier});await open(cashier);const denied=await ask(cashier,'today day close');check(denied.provenance?.scope_reason==='company_read_unavailable'&&!denied.reply.includes('123.00'),'Cashier accessed owner closing read');}finally{await context.close();}
 pass('PRA BOUNDED READ PASS: '+v.width+': actual support UI frozen closing/counted variance and waste-aware recipe cost, selected-campus stock, missing campus stock/close unknown without global fallback, foreign branch/prompt and cashier denied; no provider/fiscal writes');
}
