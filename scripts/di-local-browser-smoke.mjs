import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {assertLocalOnlyBaseUrl,launchLocalBrowser,saveEvidenceScreenshot} from './lib/local-browser.mjs';
const mode=process.argv[2], fixture=JSON.parse(readFileSync(process.argv[3],'utf8'));
assert.ok(['financial','withholding'].includes(mode));
const base=assertLocalOnlyBaseUrl('http://127.0.0.1:5919');
const {browser}=await launchLocalBrowser();
try {
 const page=await browser.newPage(); const errors=[];
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
 assert.deepEqual(errors,[]);
 console.log('DI '+mode+' real form interaction and draft save passed.');
} finally {await browser.close();}
