export async function diInventoryWorkflow(page,t,v,{baseUrl,saveEvidenceScreenshot,pass}) {
  const f=t.diInventoryCases[v.width];
  const check=(ok,message)=>{if(!ok)throw Error(message);};
  let fiscalRequests=0;
  const blockFiscal=async route=>{fiscalRequests++;await route.abort('blockedbyclient');};
  await page.route(/\/invoice\/\d+\/(submit|retry|resubmit-fbr|validate-fbr|confirm-fbr|update-fbr-number)(\?|$)/,blockFiscal);
  const stock=async()=>Number((await (await page.request.get(baseUrl+'/api/inventory/stock/'+f.product,{headers:{Accept:'application/json'}})).json()).quantity);
  const movements=async(reference,quantity,balance)=>{
    await page.goto(baseUrl+'/inventory/product/'+f.product+'/movements',{waitUntil:'domcontentloaded'});
    const row=page.locator('tbody tr').filter({hasText:reference});
    check(await row.count()===1,'Exactly one stock movement required for '+reference);
    check((await row.locator('td').nth(3).innerText()).trim()===quantity,'Posted base quantity incorrect');
    check((await row.locator('td').nth(5).innerText()).trim()===balance,'Saved stock balance incorrect');
  };
  check(await stock()===50,'Sandbox or held fixture changed physical stock');
  await page.goto(baseUrl+'/invoice/'+f.invoices.posted.id,{waitUntil:'domcontentloaded'});
  await page.locator('#di-inventory-posted').waitFor();
  check((await page.locator('body').innerText()).includes(f.invoices.posted.ack),'Synthetic acknowledgement not retained');
  await saveEvidenceScreenshot(page,'di-inventory-posted-'+v.width);
  await movements(f.invoices.posted.reference,'-50','50');
  await saveEvidenceScreenshot(page,'di-inventory-movement-'+v.width);
  await page.goto(baseUrl+'/invoice/'+f.invoices.sandbox.id,{waitUntil:'domcontentloaded'});
  await page.locator('#di-inventory-sandbox').waitFor();
  check(await stock()===50,'Sandbox view mutated physical inventory');
  await saveEvidenceScreenshot(page,'di-inventory-sandbox-'+v.width);
  await page.goto(baseUrl+'/invoice/'+f.invoices.hold.id,{waitUntil:'domcontentloaded'});
  await page.locator('#di-inventory-hold').waitFor();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Retry local stock posting only',exact:true}).click()]);
  await page.locator('#di-inventory-posted').waitFor();
  check((await page.locator('body').innerText()).includes(f.invoices.hold.ack),'Local stock retry changed fiscal acknowledgement');
  check(await stock()===39.8766,'Local retry did not post exact fractional quantity');
  const replay=await page.evaluate(async id=>{
    const response=await fetch('/invoice/'+id+'/retry-inventory',{method:'POST',headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}});
    return response.status;
  },f.invoices.hold.id);
  check(replay===200,'Exact local-only retry failed');
  check(await stock()===39.8766,'Repeated local stock retry deducted again');
  await movements(f.invoices.hold.reference,'-10.1234','39.8766');
  await saveEvidenceScreenshot(page,'di-inventory-local-retry-'+v.width);
  for(const path of ['/invoice/'+f.foreignInvoice,'/inventory/product/'+f.foreignProduct+'/movements']) {
    const denied=await page.request.get(baseUrl+path,{headers:{Accept:'application/json'},maxRedirects:0});
    check([403,404].includes(denied.status()),'Foreign tenant document was accessible: '+path);
    check(!(await denied.text()).includes('SYNTHETIC-NOT-REGULATOR'),'Foreign acknowledgement leaked in denial');
  }
  check(fiscalRequests===0,'Inventory acceptance attempted fiscal endpoint');
  await page.unroute(/\/invoice\/\d+\/(submit|retry|resubmit-fbr|validate-fbr|confirm-fbr|update-fbr-number)(\?|$)/,blockFiscal);
  pass('DI INVENTORY PASS: '+v.width+': posted base quantity/balance/status, sandbox no stock, local-only idempotent retry, tenant isolation; no regulator call');
}
