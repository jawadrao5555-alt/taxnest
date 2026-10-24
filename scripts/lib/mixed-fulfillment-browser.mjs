export async function mixedFulfillmentWorkflow(page,t,v,helpers) {
  const {baseUrl,waitForOperationalSurface,dismiss,openMobileCart,saveEvidenceScreenshot,pass}=helpers;
  const config=t.mixedFulfillment;
  const root=()=>page.locator('[data-tn-sale-root]');
  const chooseTable=async expected=>{
    await page.locator('[data-video="counter-dine-in"]').click();
    await page.locator('[data-video="counter-table"]:visible').filter({hasText:'T-'+expected.number}).click();
    await page.waitForFunction(id=>Number(window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).selectedTable?.id)===id,Number(expected.id));
  };
  const hold=async()=>{
    const [response]=await Promise.all([page.waitForResponse(r=>r.url()===baseUrl+'/pos/restaurant/orders/hold'&&r.request().method()==='POST'),root().locator('[data-hold-order]').click()]);
    const body=await response.json();if(!response.ok()||!body.success)throw new Error('Mixed hold failed: '+JSON.stringify(body));return body.order;
  };
  for(const mode of ['counter','rider']) {
    const expected=config.tables[String(v.width)][mode];
    await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
    await chooseTable(expected);
    const phone=root().locator('[x-ref="customerPhoneInput"]:visible').first();await phone.fill(config.phone);
    await root().locator('[data-cust-row] button').filter({hasText:config.customer}).first().click();
    await root().locator('[x-ref="searchInput"]:visible').fill(config.product);
    const product=root().locator('.prod-card').filter({hasText:config.product});await product.first().waitFor({state:'visible'});
    for(let i=0;i<3;i++)await product.first().click();
    await openMobileCart(page,v,['Current Order']);
    await root().locator('[data-split-line]').first().click();
    for(const value of ['dine_in','takeaway','delivery'])await root().locator('[data-split-mode="'+value+'"]').fill('1');
    await root().locator('[data-apply-split]').click();
    await page.waitForFunction(()=>window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).cart.length===3);
    await root().locator('[data-delivery-collection]').selectOption(mode);
    await saveEvidenceScreenshot(page,'mixed-fulfillment-initial-'+mode+'-'+v.width+'.png');
    const first=await hold().catch(async error=>{const state=await page.evaluate(()=>{const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));return {cartCount:d.cart.length,type:d.orderType,table:!!d.selectedTable,phone:!!d.selectedCustomer?.phone,address:!!d.selectedDeliveryAddress,submitting:d.submitting,stockError:d.stockError,body:document.body.innerText.slice(-900)}});throw new Error(error.message+' '+JSON.stringify(state));});
    if(first.order_type!=='dine_in'||Number(first.table_id)!==Number(expected.id)||first.items.length!==3||Number(first.total_amount)!==300)throw new Error('Mixed hold changed bill/table/total');
    const ticket=await page.request.get(baseUrl+'/pos/restaurant/orders/'+first.id+'/kitchen-ticket?delta=1&auto_print=1');const html=await ticket.text();
    if(!ticket.ok()||!['SERVE HERE','PACK / TAKEAWAY','PACK / DELIVERY'].every(label=>html.includes(label)))throw new Error('Initial mixed KOT lost service instructions');
    // Reload and use the actual occupied-table action to recover server snapshots.
    await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);await chooseTable(expected);
    await openMobileCart(page,v,['Current Order']);
    await page.waitForFunction(()=>window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).cart.length===3);
    await root().locator('[data-cart-fulfillment="takeaway"] .tn-qty-increment').click();
    const second=await hold();
    const unseen=second.items.filter(i=>!i.kot_printed_at&&!i.skip_kitchen);
    if(unseen.length!==1||unseen[0].fulfillment!=='takeaway'||Number(unseen[0].quantity)!==1||Number(second.total_amount)!==400)throw new Error('Add-on replayed already-sent food or lost its service identity: '+JSON.stringify({unseen:unseen.map(i=>({quantity:i.quantity,fulfillment:i.fulfillment})),total:second.total_amount}));
    const addon=await page.request.get(baseUrl+'/pos/restaurant/orders/'+second.id+'/kitchen-ticket?delta=1&auto_print=1');const addonHtml=await addon.text();
    if(!addon.ok()||!addonHtml.includes('PACK / TAKEAWAY')||addonHtml.includes('SERVE HERE')||addonHtml.includes('PACK / DELIVERY'))throw new Error('Add-on KOT includes an old portion');
    await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);await chooseTable(expected);
    await openMobileCart(page,v,['Current Order']);
    await root().locator('[data-video="open-payment"]').click();
    await page.locator('[data-pay-delivery-collection]:visible').selectOption(mode);
    await saveEvidenceScreenshot(page,'mixed-fulfillment-payment-'+mode+'-'+v.width+'.png');
    const [paid]=await Promise.all([page.waitForResponse(r=>/\/pos\/restaurant\/orders\/\d+\/pay$/.test(r.url())&&r.request().method()==='POST'),page.locator('[data-video="cash-payment"]:visible').click()]);
    const bill=await paid.json();const due=mode==='rider'?100:0;
    if(!paid.ok()||!bill.success||Number(bill.total_amount)!==400||Number(bill.rider_collection_amount)!==due||Number(bill.counter_amount)!==400-due)throw new Error('Mixed payment allocation failed: '+JSON.stringify(bill));
    const receipt=await page.request.get(baseUrl+'/pos/transaction/'+bill.transaction_id+'/receipt');const text=await receipt.text();
    if(!receipt.ok()||!['SERVE HERE','PACK / TAKEAWAY','PACK / DELIVERY','RIDER TO COLLECT','Synthetic mixed delivery lane'].every(label=>text.includes(label)))throw new Error('Saved receipt lost service/contact/collection snapshots');
    pass(t.name+'/'+v.width+': actual quantity split, initial KOT, persisted table recall, parcel-only add-on, '+mode+' payment and receipt passed');
  }
}
