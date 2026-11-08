export async function fbrDiBulkWorkflow(page,t,v,{baseUrl,saveEvidenceScreenshot,pass}) {
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
  await Promise.all([page.waitForURL(u=>u.pathname==='/products'),product.getByRole('button',{name:'Create Product',exact:true}).click()]);
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
  await invoice.getByText('Reporting quantity: 50 Kilograms',{exact:true}).waitFor();
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
