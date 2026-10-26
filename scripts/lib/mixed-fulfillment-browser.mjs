// Single-type bills with ordinary per-item Parcel notes; historical mixed snapshots stay server-compatible.
export async function mixedFulfillmentWorkflow(page,t,v,helpers) {
  const {baseUrl,waitForOperationalSurface,dismiss,saveEvidenceScreenshot,pass}=helpers;
  const config=t.mixedFulfillment,root=()=>page.locator('[data-tn-sale-root]');
  const popup=await page.request.get(baseUrl+'/pos/api/provisional-bills');
  if(!popup.ok())throw Error('Historical mixed popup failed: '+popup.status());
  const popupData=await popup.json();
  for(const rows of [popupData.bills,popupData.final_deliveries]) {
    if(rows.filter(row=>row.delivery_items?.some(item=>item.name==='Synthetic historical delivery line')).length!==2)
      throw Error('Historical Local/Final delivery lines missing from popup');
  }
  const board=await page.goto(baseUrl+'/pos/deliveries',{waitUntil:'domcontentloaded'});
  if(!board?.ok()||!await page.getByText('Synthetic historical delivery line',{exact:false}).count())
    throw Error('Historical mixed deliveries board failed to render');
  await saveEvidenceScreenshot(page,'historical-mixed-deliveries-'+v.width);
  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);await dismiss(page);
  pass(t.name+'/'+v.width+': historical mixed Local/Final popup and actual Deliveries page render');
  const openCart=async()=>{ if(v.width<768) {const button=root().locator('[data-cart-menu-open]:visible');if(await button.count())await button.click();} };
  const checkCart=async()=>{
    const rows=root().locator('[data-cart-index]');
    if(await root().locator('[x-ref="cartList"] [data-cart-index]').count()!==await rows.count())throw Error('Cart rows escaped the cart list');
    const name=rows.last().locator('span[x-text="item.item_name"]');await name.scrollIntoViewIfNeeded();
    const box=await name.boundingBox();if(!box||box.width<40||box.x<0||box.x+box.width>v.width+2)throw Error('Cart item name is clipped outside the viewport');
    if(await page.evaluate(()=>document.documentElement.scrollWidth)>v.width+2)throw Error('Sale screen has horizontal overflow');
  };
  const hold=async()=>{
    const [r]=await Promise.all([page.waitForResponse(r=>r.url()===baseUrl+'/pos/restaurant/orders/hold'&&r.request().method()==='POST'),root().locator('[data-hold-order]').click()]);
    const b=await r.json();if(!r.ok()||!b.success)throw Error('Simple note hold failed: '+JSON.stringify(b));return b.order;
  };
  for(const type of ['dine_in','delivery']) {
    const table=config.tables[String(v.width)][type==='dine_in'?'counter':'rider'];
    const choose=async()=>{
      await page.locator('[data-video="counter-dine-in"]').click();
      await page.locator('[data-video="counter-table"]:visible').filter({hasText:'T-'+table.number}).click();
    };
    await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
    if(type==='dine_in')await choose();
    else await root().locator('button').filter({hasText:/^Delivery$/}).first().click();
    const phone=root().locator('[x-ref="customerPhoneInput"]:visible').first();await phone.fill(config.phone);
    await root().locator('[data-cust-row] button').filter({hasText:config.customer}).first().click();
    await root().locator('[x-ref="searchInput"]:visible').fill(config.product);
    const product=root().locator('.prod-card').filter({hasText:config.product}).first();await product.waitFor({state:'visible'});
    await product.click();await product.click();await openCart();
    if(await root().locator('[data-fulfillment-controls],[data-split-line]').count())throw Error('Mixed portion controls remain');
    await checkCart();
    const first=await hold();
    if(first.order_type!==type||first.items.some(i=>i.fulfillment)||Number(first.total_amount)!==200)throw Error('New bill is not single-type');
    const initial=await page.request.get(baseUrl+'/pos/restaurant/orders/'+first.id+'/kitchen-ticket?delta=1&auto_print=1');
    if(!initial.ok())throw Error('Initial KOT failed');
    // Recall through the real occupied-table UI, or the held-order recall for Delivery.
    await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
    if(type==='dine_in')await choose();
    else {
      // Existing public held-order recall used by the UI (no fixture-only API).
      await page.evaluate(id=>window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).recallOrder(window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).heldOrders.find(o=>o.id===id)),first.id);
    }
    await openCart();
    await page.waitForFunction(()=>window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).cart.length>0);
    if(v.width<768)await root().locator('[data-cart-menu-back]').click();
    await root().locator('[x-ref="searchInput"]:visible').fill(config.product);await product.click();
    await openCart();
    const rows=root().locator('[data-cart-index]');await rows.last().locator('[data-item-note-button]').click();
    const note=root().locator('[data-item-note]:visible').last();await note.fill('Parcel');await note.press('Enter');
    await checkCart();
    await saveEvidenceScreenshot(page,'simple-item-notes-'+type+'-'+v.width+'.png');
    const second=await hold();const delta=second.items.filter(i=>!i.kot_printed_at&&!i.skip_kitchen);
    if(delta.length!==1||Number(delta[0].quantity)!==1||delta[0].special_notes!=='Parcel'||delta[0].fulfillment||Number(second.total_amount)!==300)throw Error('Parcel note duplicated sent food or changed bill type: '+JSON.stringify(delta));
    const addon=await page.request.get(baseUrl+'/pos/restaurant/orders/'+second.id+'/kitchen-ticket?delta=1&auto_print=1');const html=await addon.text();
    if(!addon.ok()||!html.includes('Parcel')||html.includes('PACK / DELIVERY')||html.includes('PACK / TAKEAWAY'))throw Error('New KOT lost note or still contains portions');
    await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
    if(type==='dine_in')await choose();
    else await page.evaluate(id=>{const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));d.recallOrder(d.heldOrders.find(o=>o.id===id));},second.id);
    await openCart();await root().locator('[data-video="open-payment"]').click();
    if(await page.locator('[data-pay-delivery-collection]:visible').count())throw Error('New bill still asks for portion collection');
    const [paid]=await Promise.all([page.waitForResponse(r=>/\/pos\/restaurant\/orders\/\d+\/pay$/.test(r.url())&&r.request().method()==='POST'),page.locator('[data-video="cash-payment"]:visible').click()]);
    const result=await paid.json();if(!paid.ok()||!result.success||Number(result.total_amount)!==300)throw Error('Separate bill payment failed: '+JSON.stringify(result));
    const receipt=await page.request.get(baseUrl+'/pos/transaction/'+result.transaction_id+'/receipt');const text=await receipt.text();
    if(!receipt.ok()||!text.includes('Parcel'))throw Error('Receipt lost item note');
    const forged=await page.request.post(baseUrl+'/pos/restaurant/orders/hold',{headers:{'X-CSRF-TOKEN':await page.locator('meta[name="csrf-token"]').getAttribute('content'),'Accept':'application/json'},data:{order_type:'dine_in',items:[{item_type:'product',item_id:first.items[0].item_id,item_name:config.product,quantity:1,unit_price:100,fulfillment:'delivery',line_uid:'forged-portion'}],customer_phone:config.phone,delivery_address:'Synthetic rejected destination'}});
    const rejected=await forged.json();
    if(forged.status()!==422||!JSON.stringify(rejected.errors?.items).includes('Use one order type'))throw Error('Stale client did not hit the single-type guard: '+JSON.stringify(rejected));
    pass(t.name+'/'+v.width+': '+type+' single-type bill, sent-line recall, one Parcel-note add-on, payment and receipt passed');
  }
}
