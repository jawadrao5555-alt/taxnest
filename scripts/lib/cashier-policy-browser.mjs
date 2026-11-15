export async function cashierPolicyWorkflow(page,t,v,{baseUrl,login,dismiss,waitForOperationalSurface,saveEvidenceScreenshot,pass,loopback}) {
  const fixture=t.cashierPolicy[v.width];
  const context=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  await context.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
  const owner=await context.newPage();
  const send=async (target,path,method,data)=>target.evaluate(async ({path,method,data})=>{
    const response=await fetch(path,{method,headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(data)});
    return {status:response.status,data:await response.json().catch(()=>({}))};
  },{path,method,data});
  // Deliberate denial probes use the same authenticated browser context's API client.
  // They assert exact status below without creating expected browser console errors;
  // ordinary page HTTP/console diagnostics remain untouched.
  const denied=async (target,path,method,data)=>{
    const csrf=await target.locator('meta[name="csrf-token"]').getAttribute('content');
    const response=await target.request.fetch(new URL(path,baseUrl).href,{method,headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf},data});
    return {status:response.status(),data:await response.json().catch(()=>({}))};
  };
  const sale=(method,extra={})=>({items:[{name:'Synthetic policy fee',item_name:'Synthetic policy fee',item_type:'manual',type:'manual',item_id:null,quantity:1,unit_price:100}],payment_method:method,discount_type:'amount',discount_value:0,cash_received:200,order_type:'takeaway',offline_uuid:crypto.randomUUID(),...extra});
  const check=(ok,message)=>{if(!ok)throw Error(message);};
  try {
    await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'}); await waitForOperationalSurface(page); await dismiss(page);
    await login(owner,{...t,login:fixture.owner});
    await owner.goto(baseUrl+'/pos/team/online-payment-policy',{waitUntil:'domcontentloaded'}); await waitForOperationalSurface(owner); await dismiss(owner);
    const form=owner.locator('form[action$="/online-payment-policy/'+fixture.cashierId+'"]');
    check(!await form.locator('input[type="checkbox"]').isChecked(),'New cashier policy must default OFF');
    const initial=await send(page,'/pos/invoice/store','POST',sale('qr_payment'));
    check(initial.status===200&&initial.data.success,'Default OFF must preserve cashier QR payment: '+JSON.stringify(initial));
    await form.locator('input[type="checkbox"]').check();
    await Promise.all([owner.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button',{name:'Save',exact:true}).click()]);
    await waitForOperationalSurface(owner);
    check(await form.locator('input[type="checkbox"]').isChecked(),'Owner policy not persisted');
    await saveEvidenceScreenshot(owner,'cashier-policy-owner-enabled-'+v.width+'.png');
    // Keep the existing cashier session alive: no login or refresh may be necessary.
    const forged=await denied(page,'/pos/invoice/store','POST',sale('cash',{online_payment_confirmed:true}));
    check(forged.status===403,'Stale cashier session accepted forged online confirmation');
    const queued=await denied(page,'/pos/invoice/store','POST',sale('qr_payment',{offline_queued_at:new Date().toISOString(),offline_queued_by:fixture.cashierId}));
    check(queued.status===403,'Queued cashier QR payment bypassed current policy');
    for(const method of ['cash','card']) {
      const result=await send(page,'/pos/invoice/store','POST',sale(method));
      check(result.status===200&&result.data.success,'Enabled policy changed '+method+' flow: '+JSON.stringify(result));
    }
    const ownerBill=await send(owner,'/pos/invoice/store','POST',sale('qr_payment',{online_payment_confirmed:true}));
    check(ownerBill.status===200&&ownerBill.data.success,'Owner own-session confirmation failed: '+JSON.stringify(ownerBill));
    const cross=await denied(owner,'/pos/team/online-payment-policy/'+fixture.foreignId,'PUT',{requires_approval:true});
    check([403,404].includes(cross.status),'Cross-company cashier policy changed');
    await context.clearCookies();
    await login(owner,{...t,login:fixture.admin});
    const adminSettings=await denied(owner,'/pos/team/online-payment-policy/'+fixture.cashierId,'PUT',{requires_approval:false});
    check(adminSettings.status===403,'Standalone POS admin configured owner-only policy');
    await saveEvidenceScreenshot(page,'cashier-policy-cashier-sale-'+v.width+'.png');
    pass('cashier-policy/'+v.width+': default OFF, owner toggle, stale/queued/forged cashier denied, cash/card preserved, owner own-session online success, POS admin and foreign target denied; synthetic reporting OFF');
  } finally {await context.close();}
}
