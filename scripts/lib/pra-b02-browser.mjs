export async function b02DealWorkflow(page,t,v,helpers) {
  const {baseUrl,waitForOperationalSurface,dismiss,saveEvidenceScreenshot,pass}=helpers;
  const mutations=[];
  page.on('response',response=>{if(response.request().method()==='POST')mutations.push({path:new URL(response.request().url()).pathname,status:response.status()});});
  const responseFor=(predicate,label)=>page.waitForResponse(predicate).catch(async error=>{
    const state=await page.evaluate(()=>{const root=document.querySelector('[data-tn-sale-root]');if(!root)return {page:location.pathname};const d=window.Alpine.$data(root);return {page:location.pathname,restaurant:d.isRestaurantMode,total:d.roundedTotal,submitting:d.submitting,paymentOpen:d.showPayModal,toast:d.toast?.message};});
    throw Error(label+': '+error.message+'; '+JSON.stringify({state,mutations:mutations.slice(-12)}));
  });
  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);await dismiss(page);
  const root=page.locator('[data-tn-sale-root]');
  // Match the tile's own title, never the component names shown inside a deal.
  const catalogTile=name=>root.locator('.prod-card').filter({has:page.getByText(name,{exact:true}).and(page.locator('p[x-text="item.name"]'))});
  await root.locator('[x-ref="searchInput"]:visible').fill(t.b02Deal.name);
  const tile=catalogTile(t.b02Deal.name);
  await tile.waitFor({state:'visible'});await tile.click();
  if(v.width<768) {
    const cart=root.locator('[data-cart-menu-open]:visible');
    if(await cart.count())await cart.click();
  }
  await root.locator('button:has(span[x-text*="pct_discount"])').click();
  await root.locator('[x-ref="billDiscountInput"]:visible').fill('10');
  await page.waitForFunction(()=>{
    const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));
    return d.discountAmount===80&&d.roundedTotal===812&&d.taxAmount===92;
  });
  if(await page.evaluate(()=>document.documentElement.scrollWidth)>v.width+2)throw Error('B02 cart exceeds viewport');
  await saveEvidenceScreenshot(page,'pra-b02-mixed-deal-'+v.width+'.png');
  await root.locator('[data-video="open-payment"]').click();
  // No print intent: acceptance records the bill, never invokes an external printer.
  await page.evaluate(()=>{window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).payPrintReceipt=false;});
  const [paid]=await Promise.all([
    page.waitForResponse(r=>r.url()===baseUrl+'/pos/invoice/store'&&r.request().method()==='POST'),
    page.locator('[data-video="cash-payment"]:visible').click(),
  ]);
  const bill=await paid.json();
  if(!paid.ok()||!bill.success||Number(bill.total_amount)!==812)throw Error('Mixed deal preview/post mismatch: '+JSON.stringify(bill));
  const receipt=await page.request.get(baseUrl+'/pos/transaction/'+bill.transaction_id+'/receipt');
  if(!receipt.ok()||!(await receipt.text()).includes(t.b02Deal.name))throw Error('Stored mixed deal receipt missing');
  pass(t.name+'/'+v.width+': actual deal tile, discount control, payment and stored receipt agree at Rs 812');
  // Exercise the actual scheduled-offer editor and checkout, independently of bundle pricing.
  await page.goto(baseUrl+'/pos/promotions',{waitUntil:'domcontentloaded'});
  await dismiss(page);
  const create=page.locator('details').filter({has:page.locator('input[name="form_context"][value="new"]')});
  await create.locator('summary').click();
  const form=create.locator('form');
  const offerName='Synthetic offer '+v.width;
  await form.locator('[name="title"]').fill(offerName);
  await form.locator('[name="percent"]').fill('10');
  await form.locator('[name="priority"]').fill('10');
  await form.locator('[name="timezone"]').fill('Asia/Karachi');
  await form.locator('[name="starts_at"]').fill('2000-01-01T00:00');
  await form.locator('[name="ends_at"]').fill('2099-01-01T00:00');
  await form.locator('input[type="checkbox"][name="is_active"]').check();
  await Promise.all([page.waitForURL(baseUrl+'/pos/promotions'), form.locator('button[type="submit"]').click()]);
  await page.locator('summary').filter({hasText:offerName}).waitFor();
  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);await dismiss(page);
  await root.locator('[x-ref="searchInput"]:visible').fill(t.b02Deal.product);
  await catalogTile(t.b02Deal.product).click();
  await page.waitForFunction(name=>{const cart=window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).cart;return cart.length===1&&cart[0].item_type==='product'&&cart[0].item_name===name;},t.b02Deal.product);
  if(v.width<768) { const cart=root.locator('[data-cart-menu-open]:visible');if(await cart.count())await cart.click(); }
  await page.waitForFunction(()=>window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).roundedTotal===835).catch(async error=>{
    const state=await page.evaluate(()=>{const d=window.Alpine.$data(document.querySelector('[data-tn-sale-root]'));return {total:d.roundedTotal,tax:d.taxAmount,discount:d.discountAmount,items:d.cart.map(i=>({name:i.item_name,price:i.unit_price,qty:i.quantity,offer:i.promotion_quote?.title||null}))};});
    throw Error('Promotion preview did not reach Rs 835: '+JSON.stringify(state)+'; '+error.message);
  });
  await saveEvidenceScreenshot(page,'pra-b02-promotion-'+v.width+'.png');
  await root.locator('[data-video="open-payment"]').click();
  await page.evaluate(()=>{window.Alpine.$data(document.querySelector('[data-tn-sale-root]')).payPrintReceipt=false;});
  // This fictional restaurant uses the normal hold-then-pay path for products;
  // deals above intentionally bypass hold and use invoice/store. Assert both
  // actual requests, without switching the company or forcing a client mode.
  const [offerHeld,offerPaid]=await Promise.all([
    responseFor(r=>r.url()===baseUrl+'/pos/restaurant/orders/hold'&&r.request().method()==='POST','Promotion hold'),
    responseFor(r=>/^\/pos\/restaurant\/orders\/\d+\/pay$/.test(new URL(r.url()).pathname)&&r.request().method()==='POST','Promotion payment'),
    page.locator('[data-video="cash-payment"]:visible').click(),
  ]);
  const heldOffer=await offerHeld.json();
  if(!offerHeld.ok()||!heldOffer.success||!heldOffer.order?.id||new URL(offerPaid.url()).pathname!=='/pos/restaurant/orders/'+heldOffer.order.id+'/pay')throw Error('Promotion did not settle its actual held order');
  const offerBill=await offerPaid.json();
  if(!offerPaid.ok()||!offerBill.success||Number(offerBill.total_amount)!==835)throw Error('Promotion preview/post mismatch: '+JSON.stringify(offerBill));
  const offerReceipt=await page.request.get(baseUrl+'/pos/transaction/'+offerBill.transaction_id+'/receipt');
  if(!offerReceipt.ok()||!(await offerReceipt.text()).includes(offerName))throw Error('Frozen promotion missing from receipt');
  pass(t.name+'/'+v.width+': standalone promoted product, real restaurant hold/pay and stored receipt agree at Rs 835');
  await page.goto(baseUrl+'/pos/promotions',{waitUntil:'domcontentloaded'});
  const saved=page.locator('details').filter({has:page.locator('summary').filter({hasText:offerName})});
  await saved.locator('summary').click();
  await saved.locator('input[type="checkbox"][name="is_active"]').uncheck();
  await Promise.all([responseFor(r=>r.request().url().includes('/pos/promotions/')&&r.request().method()==='POST','Disable promotion'),saved.locator('button[type="submit"]').click()]);
  const historical=await page.request.get(baseUrl+'/pos/transaction/'+offerBill.transaction_id+'/receipt');
  if(!(await historical.text()).includes(offerName))throw Error('Disabling campaign changed historical receipt');
  pass(t.name+'/'+v.width+': real promotion creation, automatic preview, paid receipt and disable preserve Rs 835');
  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);await dismiss(page);

}
