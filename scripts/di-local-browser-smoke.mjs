import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {assertLocalOnlyBaseUrl,launchLocalBrowser,saveEvidenceScreenshot} from './lib/local-browser.mjs';
const mode=process.argv[2], fixture=JSON.parse(readFileSync(process.argv[3],'utf8'));
assert.ok(['financial','withholding'].includes(mode));
const base=assertLocalOnlyBaseUrl('http://127.0.0.1:5919');
const {browser}=await launchLocalBrowser();
try {
 const page=await browser.newPage({serviceWorkers: 'block'}); const errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',route=>new URL(route.request().url()).hostname==='127.0.0.1' ? route.continue() : route.abort());
 await page.goto(base+'/login');
 await page.locator('input[name=login]').fill(fixture.login);await page.locator('input[name=password]').fill(fixture.password);
 await page.locator('button[type=submit]').click();await page.waitForLoadState('networkidle');
 await page.goto(base+'/invoice/create');
 await page.waitForFunction(()=>window.Alpine && document.querySelector('form[x-data]')?._x_dataStack);
 if (mode === 'financial') {
  const picker = page.locator('details').filter({has: page.getByText('Search FBR tax references', {exact: true})}).first();
  await picker.locator('summary').click();
  const type = picker.getByLabel('Reference type');
  const search = picker.getByRole('searchbox');
  const results = picker.getByRole('list', {name: 'Tax reference results'});

  await search.fill('01012100');
  const hsCode = results.getByRole('listitem').filter({hasText: '0101.2100'});
  await hsCode.getByRole('button', {name: 'Use', exact: true}).click();
  await picker.getByText(/Selected 0101\.2100/).waitFor();
  assert.equal(await page.locator('[name="items[0][hs_code]"]').inputValue(), '0101.2100');

  await type.selectOption('rate');
  await search.fill('18% along with rupees 60');
  const mixedRate = results.getByRole('listitem').filter({hasText: '18% along with rupees 60 per kilogram'});
  await mixedRate.getByText('Reference only').waitFor();
  assert.equal(await mixedRate.getByRole('button', {name: 'Use', exact: true}).count(), 0);

  await type.selectOption('sale_type');
  await search.fill('Goods at Reduced Rate');
  await results.getByRole('listitem').filter({hasText: 'Goods at Reduced Rate'})
    .getByRole('button', {name: 'Use', exact: true}).click();
  assert.equal(await page.locator('[name="items[0][schedule_type]"]').inputValue(), 'reduced');

  await type.selectOption('sro');
  await search.fill('EIGHTH SCHEDULE Table 1');
  await results.getByRole('listitem').filter({hasText: 'EIGHTH SCHEDULE Table 1'})
    .getByRole('button', {name: 'Use', exact: true}).click();
  assert.equal(await page.locator('[name="items[0][sro_schedule_no]"]').inputValue(), 'EIGHTH SCHEDULE Table 1');

  await type.selectOption('serial');
  await search.fill('70');
  const serial = results.getByRole('listitem').filter({has: page.getByText('70', {exact: true})});
  await serial.getByRole('button', {name: 'Use', exact: true}).click();
  assert.equal(await page.locator('[name="items[0][serial_no]"]').inputValue(), '70');

  const isReferenceLookup = url => url.pathname === '/invoice/fbr-reference-options';
  const specialRateLabels = ['18% along with rupees 60 per kilogram', 'Rs.3'];
  await page.route(isReferenceLookup, route => {
   const params = new URL(route.request().url()).searchParams;
   assert.equal(params.get('hs'), '0101.2100');
   const changed = params.get('invoice_date') === '2026-08-02';
   return route.fulfill({json: {province: 'Punjab', options: {
    rates: [
     {id: 1, label: changed ? '7%' : '5%'},
     ...specialRateLabels.map((label, index) => ({id: index + 2, label})),
    ], schedules: [], serials: [], units: [],
   }}});
  });
  await type.selectOption('rate');
  const referenceRequest = page.waitForRequest(request => isReferenceLookup(new URL(request.url())));
  await picker.getByLabel('Use FBR options for this invoice').check();
  await referenceRequest;
  await results.getByText('5%', {exact: true}).waitFor();
  const date = page.locator('input[name="invoice_date"]');
  const originalDate = await date.inputValue();
  await date.fill('2026-08-02');
  await results.getByText('7%', {exact: true}).waitFor();
  assert.equal(await results.getByText('5%', {exact: true}).count(), 0);
  await results.getByRole('button', {name: 'Use', exact: true}).click();
  assert.equal(await page.locator('[name="items[0][tax_rate]"]').inputValue(), '7');
  assert.equal(await page.locator('[name="items[0][fbr_reference_checked]"]').inputValue(), '1');
  for (const label of specialRateLabels) {
   await search.fill(label);
   const row = results.getByRole('listitem').filter({hasText: label});
   await row.getByText('Reference only', {exact: true}).waitFor();
   assert.equal(await row.getByRole('button', {name: 'Use', exact: true}).count(), 0);
   assert.equal(await page.locator('[name="items[0][tax_rate]"]').inputValue(), '7');
  }
  await picker.getByLabel('Use FBR options for this invoice').uncheck();
  await page.unroute(isReferenceLookup);
  await date.fill(originalDate);

  // The financial fixture below verifies a different item.
  await page.locator('[name="items[0][serial_no]"]').fill('');
  await page.locator('[name="items[0][sro_schedule_no]"]').fill('');
  await picker.locator('summary').click();
 }
 await page.locator('input[name=buyer_name]').fill('Synthetic Buyer');
 await page.locator('[name=buyer_address]').fill('Synthetic local address');
 await page.locator('[name=buyer_ntn]').fill('1234567');
 const item=field=>page.locator(`[name="items[0][${field}]"]`);
 const hsLookup=page.waitForResponse(r=>r.url().includes('/api/hs-lookup?') && r.status()===200);
 await item('hs_code').fill('33049900');await item('description').fill('Synthetic taxable line');
 await hsLookup;
 await page.waitForFunction(()=>Alpine.$data(document.querySelector('form[x-data]')).items[0].show_st_withheld===true);
 await item('quantity').fill('1');await item('price').fill(mode==='financial'?'80':'10000');
 if(mode==='financial') {
  await item('schedule_type').selectOption('3rd_schedule');
  const rate=item('tax_rate'); if(await rate.evaluate(e=>e.tagName)==='SELECT') await rate.selectOption('18');else await rate.fill('18');
  await item('mrp').fill('100');
  await page.waitForFunction(()=>Alpine.$data(document.querySelector('form[x-data]')).grandTotal()==='98.00');
  assert.equal(await item('tax').inputValue(),'18');
 } else {
  await item('st_withheld_at_source').check();
  const amount=item('st_withheld_amount');await amount.waitFor({state:'visible'});
  assert.equal(await amount.getAttribute('type'),'number');await amount.fill('500.25');
  assert.equal(await amount.inputValue(),'500.25');
 }
 await saveEvidenceScreenshot(page,'di-'+mode+'-draft-form');
 await page.getByRole('button',{name:/Create Invoice/i}).first().click();
 await page.waitForURL(url=>url.pathname==='/invoices');
 await assert.doesNotReject(()=>page.getByRole('main').getByText('Invoice created successfully.',{exact:true}).waitFor({state:'visible'}));
 if (mode === 'financial') {
  const edit = page.locator('a[href^="/invoice/"][href$="/edit"]').first();
  await edit.waitFor();
  await page.goto(base + await edit.getAttribute('href'));
  await page.waitForFunction(() => window.Alpine && document.querySelector('form[x-data]')?._x_dataStack);
  assert.equal(await item('fbr_reference_checked').inputValue(), '1');

  const profile = 'a'.repeat(64);
  const product = {
   id: 9001, name: 'Synthetic verified defaults', hs_code: '0101.2100', pct_code: '0101.2100',
   uom: 'Kilograms', schedule_type: 'reduced', default_tax_rate: '5.50',
   sro_reference: 'Synthetic Schedule', serial_number: '70', default_price: '80', di_tax_profile_revision: profile,
  };
  await page.route(url => url.pathname === '/api/products/search', route => route.fulfill({json: [product]}));
  await page.route(url => url.pathname === '/invoice/fbr-reference-options', route => {
   const params = new URL(route.request().url()).searchParams;
   if (!params.has('product_id')) return route.continue();
   assert.equal(params.get('product_id'), String(product.id));
   assert.equal(params.get('profile_revision'), profile);
   return route.fulfill({json: {province: 'Punjab', errors: params.get('invoice_date') === '2026-08-02'
    ? ['This rate is no longer available.'] : []}});
  });

  const checkProductDefaults = async () => {
   await page.locator('input[name="invoice_date"]').fill('2026-08-01');
   await page.getByPlaceholder('Search products...').first().fill(product.name);
   await page.getByRole('button', {name: /Synthetic verified defaults/}).first().click();
   await page.getByText(/Saved product tax defaults confirmed for Punjab/).waitFor();
   assert.equal(Number(await item('tax_rate').inputValue()), 5.5);
   assert.equal(await item('sro_schedule_no').inputValue(), 'Synthetic Schedule');
   assert.equal(await item('serial_no').inputValue(), '70');
   assert.equal(await item('fbr_reference_checked').inputValue(), '1');
   await page.locator('input[name="invoice_date"]').fill('2026-08-02');
   await page.getByText(/This rate is no longer available/).waitFor();
   assert.equal(Number(await item('tax_rate').inputValue()), 5.5);
  };
  await checkProductDefaults();
  await page.goto(base+'/invoice/create');
  await checkProductDefaults();

  await page.goto(base+'/invoice/create');
  await page.waitForFunction(() => window.Alpine && document.querySelector('form[x-data]')?._x_dataStack);
  await page.locator('input[name="invoice_date"]').fill('2026-08-01');
  await page.locator('input[name=buyer_name]').fill('Synthetic cement buyer');
  await page.locator('[name=buyer_address]').fill('Synthetic local address');
  await page.locator('[name=buyer_ntn]').fill('1234567');
  await item('hs_code').fill('2523.2900');
  await item('description').fill('Synthetic cement line');
  await item('schedule_type').selectOption('di_cement_retail_kg');
  assert.equal(await item('default_uom').inputValue(), 'Kilograms');
  await item('quantity').fill('2.5');
  await item('price').fill('400');
  await item('mrp').fill('500');
  await page.waitForFunction(() => Alpine.$data(document.querySelector('form[x-data]')).grandTotal() === '1235.00');
  assert.equal(Number(await item('tax').inputValue()), 225);
  await page.getByRole('button', {name: /Create Invoice/i}).first().click();
  await page.waitForURL(url => url.pathname === '/invoices');
  await page.getByRole('main').getByText('Invoice created successfully.', {exact: true}).waitFor();
  const cementRow = page.getByRole('row').filter({hasText: 'Synthetic cement buyer'});
  const cementEdit = cementRow.locator('a[href^="/invoice/"][href$="/edit"]');
  await page.goto(base + await cementEdit.getAttribute('href'));
  await page.waitForFunction(() => window.Alpine && document.querySelector('form[x-data]')?._x_dataStack);
  assert.equal(await item('schedule_type').inputValue(), 'di_cement_retail_kg');
  await item('mrp').fill('600');
  await page.waitForFunction(() => Alpine.$data(document.querySelector('form[x-data]')).grandTotal() === '1280.00');
  assert.equal(Number(await item('tax').inputValue()), 270);
 }
 assert.deepEqual(errors,[]);
 console.log('DI '+mode+' real form interaction and draft save passed.');
} finally {await browser.close();}
