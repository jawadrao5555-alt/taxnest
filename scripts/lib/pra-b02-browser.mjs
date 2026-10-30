export async function b02DealWorkflow(page,t,v,helpers) {
  const {baseUrl,waitForOperationalSurface,dismiss,saveEvidenceScreenshot,pass}=helpers;
  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page);await dismiss(page);
  const root=page.locator('[data-tn-sale-root]');
  await root.locator('[x-ref="searchInput"]:visible').fill(t.b02Deal.name);
  const tile=root.locator('.prod-card').filter({hasText:t.b02Deal.name}).first();
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
}
