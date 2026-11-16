import { madadgarEscalationRecovery } from './madadgar-escalation-browser.mjs';
export async function madadgarReadWorkflow(page,t,v,{baseUrl,login,loopback,saveEvidenceScreenshot,pass}) {
  const f=t.madadgarReadCases[v.width];
  const check=(ok,message)=>{if(!ok)throw Error(message);};
  const root=p=>p.locator('[x-data^="tnMadadgar("]');
  const open=async p=>{
    await p.goto(baseUrl+'/fbr-pos/school-fees',{waitUntil:'domcontentloaded'});
    await p.locator('#school-fiscal-status').waitFor();
    check(/disabled|off|blocked/i.test(await p.locator('#school-fiscal-status').innerText()),'School workspace fiscal status is not OFF');
    await root(p).getByRole('button',{name:'Madadgar Support',exact:true}).click();
    await root(p).getByRole('button',{name:/Ask Madadgar/}).click();
    await root(p).locator('input[name="madadgar_q_nofill"]').waitFor({state:'visible'});
  };
  const ask=async(p,question)=>{
    const input=root(p).locator('input[name="madadgar_q_nofill"]');
    await input.fill(question);
    const [response]=await Promise.all([p.waitForResponse(r=>new URL(r.url()).pathname==='/fbr-pos/madadgar/message'&&r.request().method()==='POST'),input.press('Enter')]);
    check(response.ok(),'Scoped assistant request failed');
    return response.json();
  };
  await open(page);
  const response=await ask(page,'today collections');
  check(response.provenance?.answer_source==='company_read','Bounded question did not use deterministic company read');
  check(response.provenance.branch_id===f.campus,'Read broadened beyond selected campus');
  check(response.provenance.report_date===f.date,'Read report date differs from fixture date');
  check(response.provenance.source_reference.includes('school_fee_receipts'),'Read source missing');
  check(response.provenance.capabilities.fiscal_submit===false&&response.provenance.capabilities.billing_write===false,'Read answer advertises write/fiscal capability');
  check(response.reply.includes('Recorded collections PKR: 123.45')&&response.reply.includes('Receipt count: 1'),'Known synthetic collections total/count incorrect');
  check(response.reply.includes('Fiscal submission OFF/blocked'),'School fiscal OFF missing');
  check(!response.reply.includes('999.99')&&!response.reply.includes('SYNTHETIC-PRIVATE-READ'),'Other campus value or individual student reference leaked');
  await root(page).locator('[data-madadgar-provenance]').waitFor();
  await root(page).locator('[x-ref="mbody"]').evaluate(el=>{el.scrollTop=0;});
  await saveEvidenceScreenshot(page,'madadgar-school-read-'+v.width);
  await root(page).locator('[x-ref="mbody"]').evaluate(el=>{el.scrollTop=el.scrollHeight;});
  await saveEvidenceScreenshot(page,'madadgar-school-source-'+v.width);
  const foreign=await ask(page,'today collections for company '+f.foreignCompany);
  check(foreign.provenance?.answer_source!=='company_read'&&!foreign.reply.includes('123.45')&&!foreign.reply.includes('999.99'),'Foreign-company instruction accessed numeric read');
  const escalation=await madadgarEscalationRecovery(page,f,v,{baseUrl,root,open,ask,saveEvidenceScreenshot,check});
  const context=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  await context.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
  try {
    const cashier=await context.newPage();
    await login(cashier,{...t,login:f.cashier});
    await open(cashier);
    const history=await context.request.get(baseUrl+'/fbr-pos/madadgar/history?session_id='+escalation.sessionId);
    check(!(await history.json()).messages.some(m=>m.content===escalation.reply),'Cashier accessed another user confirmation history');
    const denied=await ask(cashier,'today collections');
    check(denied.provenance?.scope_reason==='company_read_unavailable'&&denied.provenance.capabilities.live_data_read===false,'Cashier received owner-only read');
    check(!denied.reply.includes('123.45')&&!denied.reply.includes('999.99'),'Cashier denial leaked collections');
    await root(cashier).locator('[data-madadgar-provenance]').waitFor();
    await saveEvidenceScreenshot(cashier,'madadgar-cashier-denied-'+v.width);
  } finally {await context.close();}
  pass('MADADGAR READ PASS: '+v.width+': actual UI known campus collection/source/date, fiscal OFF, no individual references, foreign intent denied and cashier denied; provider local-only; explicit lost-response confirmation refresh/same UUID/ref, no autosend, changed details409, one saved message/suggestion');
}
