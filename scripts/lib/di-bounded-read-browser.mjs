export async function diBoundedReadWorkflow(page,t,v,{baseUrl,login,loopback,saveEvidenceScreenshot,pass}) {
 const f=t.diReadCases[v.width],path='/madadgar/di-read';
 const check=(ok,message)=>{if(!ok)throw Error(message);};
 await page.goto(baseUrl+'/dashboard',{waitUntil:'domcontentloaded'});
 if(v.width<1024) await page.locator('button[onclick="toggleSidebar()"]').click();
 const link=page.getByRole('link',{name:'Madadgar: recorded totals',exact:true});
 await link.click();
 check(new URL(page.url()).pathname===path,'DI owner link did not open the bounded read page');
 const panel=page.locator('[data-di-madadgar-read]');
 await panel.waitFor({state:'visible'});
 const read=async question=>{
  await panel.locator('#di-read-question').selectOption(question);
  const [response]=await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),panel.getByRole('button',{name:'Read records',exact:true}).click()]);
  check(response?.ok(),'Bounded read page request failed');
  check((response.headers()['cache-control']??'').includes('no-store'),'Private read response is cacheable');
  return panel.locator('[data-di-read-result]').innerText();
 };
 const result=await read('today billing');
 check(result.includes('Billed sales PKR: '+f.total)&&result.includes('Completed documents: 1'),'DI read did not isolate locked invoice');
 check(!result.includes('999.00')&&!result.includes('Billed sales PKR: '+f.foreignTotal)&&!result.includes('SYNTHETIC-PRIVATE-BUYER'),'Draft/foreign invoice or buyer detail leaked');
 check(result.includes('invoices'),'Read provenance source omitted');
 await panel.locator('[data-di-read-result]').scrollIntoViewIfNeeded();
 await saveEvidenceScreenshot(page,'di-read-billing-'+v.width);
 const payment=await read('today payments');
 check(payment.includes('Actual collected total unknown')&&!payment.includes('Recorded tendered cash PKR: 0'),'Missing DI payments presented as zero');
 await saveEvidenceScreenshot(page,'di-read-payments-unknown-'+v.width);
 const token=await panel.locator('input[name="_token"]').inputValue();
 const invalid=await page.context().request.post(baseUrl+path,{form:{_token:token,question:'SELECT secret FROM users'},headers:{Accept:'application/json'},maxRedirects:0});
 check(invalid.status()===422,'Arbitrary DI query accepted');
 const forged=await page.context().request.post(baseUrl+path,{form:{_token:token,question:'today billing',company_id:f.foreignCompany},headers:{Accept:'text/html'},maxRedirects:0});
 const forgedHtml=await forged.text();
 check(forged.ok()&&forgedHtml.includes('Billed sales PKR: '+f.total)&&!forgedHtml.includes('Billed sales PKR: '+f.foreignTotal),'Client company selector broadened read');
 const context=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
 await context.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
 try {
  const cashier=await context.newPage();await login(cashier,{...t,login:f.cashier});
  const denied=await context.request.get(baseUrl+path,{headers:{Accept:'application/json'},maxRedirects:0});
  check(denied.status()===403,'Non-owner accessed DI totals');
  check(!(await denied.text()).includes(f.total),'Denied DI response leaked total');
 } finally {await context.close();}
 pass('DI BOUNDED READ PASS: '+v.width+': actual owner navigation/form, locked-only company total/source, draft/foreign/private-detail exclusion, unknown payment not zero, arbitrary query422 and cashier403; no model/provider/fiscal calls');
}
