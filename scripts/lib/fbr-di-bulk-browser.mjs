export async function fbrDiBulkWorkflow(page,t,v,{baseUrl,saveEvidenceScreenshot,pass,diagnostics}) {
  const fixture=t.fbrDiBulk[v.width];
  await page.goto(baseUrl+'/billing/plans',{waitUntil:'domcontentloaded'});
  await page.getByLabel('Billing period',{exact:true}).selectOption('quarterly');
  const quote=page.locator('form[action$="/billing/subscribe"]').filter({has:page.locator('input[name="plan_id"][value="'+fixture.planId+'"]')});
  await Promise.all([page.waitForURL(u=>u.pathname==='/billing/plans'),quote.locator('button[type="submit"]').click()]);
  await page.locator('form[action$="/payment-proof"] input[name="billing_cycle"][value="quarterly"]').waitFor({state:'attached'});
  await saveEvidenceScreenshot(page,'rc-di-quarterly-selection-'+v.width);

  const name='Synthetic Packing Bag '+v.width;
  await page.goto(baseUrl+'/products/create',{waitUntil:'domcontentloaded'});
  const product=page.locator('form[action="/products"]');
  await product.locator('[name="name"]').fill(name);
  await product.locator('[name="hs_code"]').fill('99010000');
  await product.locator('[name="schedule_type"]').selectOption('standard');
  await product.locator('[name="default_tax_rate"]').fill('18');
  await product.locator('[name="default_price"]').fill('100');
  await product.locator('[name="uom"]').selectOption('Kilograms');
  await product.getByRole('button',{name:'Add packing',exact:true}).click();
  await product.getByLabel('Packing label',{exact:true}).fill('25 KG bag');
  await product.getByLabel('Base quantity per packing',{exact:true}).fill('25');
  await product.getByLabel('Packing price (optional)',{exact:true}).fill('2500');
  const invalid=await product.evaluate(form=>Array.from(form.elements).filter(e=>e.willValidate&&!e.validity.valid).map(e=>({name:e.name,value:e.value,message:e.validationMessage})));
  if(invalid.length)throw new Error('Product packing form invalid: '+JSON.stringify(invalid));
  const submissions=[];
  const record=response=>{const req=response.request();if(req.method()==='POST'&&new URL(req.url()).pathname==='/products')submissions.push({status:response.status(),location:response.headers().location||'',data:Array.from(new URLSearchParams(req.postData()||'').entries()).filter(([name])=>name!=='_token')});};
  page.on('response',record);
  try {
    await Promise.all([page.waitForURL(u=>u.pathname==='/products',{waitUntil:'domcontentloaded'}),product.getByRole('button',{name:'Create Product',exact:true}).click()]);
  } catch(error) {
    const errors=await page.locator('#productForm .text-red-700').allTextContents();
    const fields=await product.evaluate(form=>Array.from(new FormData(form).entries()).filter(([name])=>name!=='_token'));
    throw new Error('Product packing save failed at '+new URL(page.url()).pathname+': '+errors.join('; ')+'; fields='+JSON.stringify(fields)+'; submissions='+JSON.stringify(submissions)+'; pageErrors='+JSON.stringify(diagnostics?.pageErrors||[])+'; '+error.message);
  } finally { page.off('response',record); }
  await page.getByText('Product created successfully.',{exact:true}).waitFor();
  await page.goto(baseUrl+'/invoice/create',{waitUntil:'domcontentloaded'});
  const invoice=page.locator('form[action="/invoice/store"]');
  await invoice.locator('[name="buyer_name"]').fill('Synthetic Packing Browser Buyer');
  await invoice.locator('[name="buyer_address"]').fill('Synthetic Lahore address');
  await invoice.locator('[name="destination_province"]').selectOption('Punjab');
  await invoice.getByPlaceholder('Search products...').first().fill(name);
  await invoice.getByRole('button',{name:new RegExp(name)}).first().click();
  await invoice.locator('select[name="items[0][selling_packing_id]"]').selectOption({label:'25 KG bag'});
  await invoice.locator('[name="items[0][quantity]"]').fill('2');
  await invoice.getByText(/^Reporting quantity: 50 (Kilograms|KG)$/).waitFor();
  await saveEvidenceScreenshot(page,'rc-di-packing-entry-'+v.width);
  await Promise.all([page.waitForURL(u=>u.pathname==='/invoices'),invoice.locator('button[type="submit"]').filter({hasText:'Save'}).first().click()]);
  await page.getByText('Invoice created successfully.',{exact:true}).waitFor();
  const link=page.locator('a[href*="/invoice/"]').filter({hasText:/View/i}).first();
  if(await link.count())await link.click();
  else {
    const row=page.getByRole('row').filter({hasText:'Synthetic Packing Browser Buyer'}).first();
    await row.locator('a[href*="/invoice/"]').first().click();
  }
  const line=page.getByRole('row').filter({hasText:name}).first();
  await line.getByRole('cell',{name:'50',exact:true}).waitFor();
  await line.getByRole('cell',{name:'100.000000',exact:true}).waitFor();
  await page.getByText('PKR 5,900.00',{exact:true}).first().waitFor();
  await saveEvidenceScreenshot(page,'rc-di-packing-saved-base-'+v.width);
  pass('DI BILLING/PACKING PASS: '+v.width+': actual configured quarterly selection, product packing creation, two-bag entry, persisted 50 base units and unchanged legal decimal total; no fiscal submission or printing');
}
