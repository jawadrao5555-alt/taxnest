export async function staffDiscountWorkflow(page,t,v,{baseUrl,login,dismiss,waitForOperationalSurface,saveEvidenceScreenshot,pass,loopback}) {
  const fixture=t.staffDiscount[v.width];
  const ownerContext=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  await ownerContext.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
  const owner=await ownerContext.newPage();
  try {
    await login(owner,{...t,login:fixture.owner});
    await owner.goto(baseUrl+'/pos/staff-discounts',{waitUntil:'domcontentloaded'}); await waitForOperationalSurface(owner); await dismiss(owner);
    const settings=owner.locator('form[action$="/staff-discounts/settings"]');
    if(await settings.locator('select').inputValue()!=='0')throw Error('New staff approval feature must default OFF');
    await settings.locator('select').selectOption('1');
    await Promise.all([owner.waitForNavigation({waitUntil:'domcontentloaded'}),settings.getByRole('button',{name:'Save preference'}).click()]);
    await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'}); await waitForOperationalSurface(page); await dismiss(page);
    const root=page.locator('[data-tn-sale-root]');
    await root.evaluate(el=>{const d=Alpine.$data(el);d.cart=[{cart_uid:'staff-browser-meal',item_id:null,item_type:'manual',item_name:'Fictional staff meal',quantity:1,unit_price:1000,is_tax_exempt:false}];d.orderType='takeaway';d.showPayModal=true;d.payPrintReceipt=false;d.saveAsProvisional=false;});
    await page.getByRole('button',{name:'Request staff / family discount',exact:true}).click();
    await page.getByLabel('Employee',{exact:true}).selectOption(String(fixture.employee));
    await page.getByLabel('Staff or family',{exact:true}).selectOption('family');
    await page.getByLabel('Discount reason',{exact:true}).fill('Fictional employee family meal');
    await page.getByLabel('Staff discount value',{exact:true}).fill('10');
    const [pending]=await Promise.all([page.waitForResponse(r=>new URL(r.url()).pathname==='/pos/invoice/store'&&r.request().method()==='POST'),page.locator('[data-video="cash-payment"]:visible').click()]);
    const proposal=await pending.json();
    if(pending.status()!==202||!proposal.approval_pending||proposal.transaction_id)throw Error('Proposal must remain pending with no final transaction');
    await owner.reload({waitUntil:'domcontentloaded'}); await waitForOperationalSurface(owner); await dismiss(owner);
    const request=owner.locator('[data-staff-request="'+proposal.request_id+'"]');
    await request.getByText('Fictional staff meal',{exact:false}).waitFor();
    await request.locator('select[name="discount_type"]').selectOption('amount');
    await request.getByLabel('Approved discount').fill('125');
    await Promise.all([owner.waitForNavigation({waitUntil:'domcontentloaded'}),request.getByRole('button',{name:'Approve / revise',exact:true}).click()]);
    await page.getByRole('button',{name:'Check approval',exact:true}).click();
    await page.waitForFunction(()=>{const el=document.querySelector('[data-tn-sale-root]');return el&&Alpine.$data(el).staffDiscountRequest?.status==='approved';});
    // Reload and explicitly resume the server-held request; ordinary carts do not auto-restore.
    await page.goto(baseUrl+'/pos/invoice/create?staff_request='+proposal.request_id,{waitUntil:'domcontentloaded'}); await waitForOperationalSurface(page); await dismiss(page);
    await page.waitForFunction(()=>{const d=Alpine.$data(document.querySelector('[data-tn-sale-root]'));return d.staffDiscountRequest?.status==='approved'&&d.cart.length===1;});
    await root.evaluate(el=>{const d=Alpine.$data(el);d.payPrintReceipt=false;d.lastPayTime=0;});
    const [saved]=await Promise.all([page.waitForResponse(r=>new URL(r.url()).pathname==='/pos/invoice/store'&&r.request().method()==='POST'),page.locator('[data-video="cash-payment"]:visible').click()]);
    const bill=await saved.json();
    if(!saved.ok()||!bill.success||!bill.transaction_id||Number(bill.total_amount)!==1015)throw Error('Approved amount must finalize once through normal tax/payment calculation: '+JSON.stringify(bill));
    await owner.reload({waitUntil:'domcontentloaded'}); await waitForOperationalSurface(owner);
    if(!await owner.locator('[data-staff-request="'+proposal.request_id+'"]', {hasText:'finalized'}).count())throw Error('Finalized approval lineage missing');
    await saveEvidenceScreenshot(page,'staff-discount-approved-'+v.width+'.png');
    pass('staff-discount/'+v.width+': actual cashier proposal, owner amount revision, explicit resume and single normal final bill; no printer or fiscal submission');
  } finally {await ownerContext.close();}
}
