import { openCollectionReceipt } from './open-collection-receipt.mjs';
import {scrolledPixelDetail} from './scrolled-pixel-detail.mjs';
import { collectionControlChecks } from './collection-controls-browser.mjs';
import { collectionSharedWorkspaceChecks } from './collection-shared-workspace-browser.mjs';
import { privateCollectionCacheCheck } from './collection-private-cache-browser.mjs';
import { randomUUID } from 'node:crypto';

// Disposable loopback fixture only. Synthetic references are not health records.
export async function hospitalWorkflow(page, t, v, { baseUrl, saveEvidenceScreenshot, pass }) {
  if (new URL(page.url()).pathname !== '/fbr-pos/hospital') throw new Error('Hospital login did not reach native collection desk');
  await hospitalWorkspaceChecks(page, v, { baseUrl, saveEvidenceScreenshot });
  await collectionSharedWorkspaceChecks(page,'hospital',v,{baseUrl,saveEvidenceScreenshot});
  const configuredService=await hospitalRateChecks(page,v,{baseUrl,saveEvidenceScreenshot});
  const reference = 'SYN-PAT-' + v.width + '-' + randomUUID().slice(0, 8);
  const create = page.locator('#hospital-collection');
  await create.waitFor();
  const csrf = await create.locator('[name="_token"]').inputValue();
  const branch = await create.locator('[name="expected_branch_key"]').inputValue();
  const initialKey = await create.locator('[name="request_key"]').inputValue();
  const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf };
  const loseResponseAndRefresh = async (form, url, payload) => {
    let persistedId;
    const lose = async route => {
      if (route.request().method() !== 'POST') return route.continue();
      const response = await route.fetch();
      const result = await response.json();
      if (!response.ok() || !result.success) throw new Error('Hospital lost-response request was not saved');
      persistedId = Number(result.receipt_id);
      await route.abort('blockedbyclient');
    };
    await page.route(url, lose);
    await form.locator('button[type="submit"]').click();
    await page.locator('#hospital-recovery').filter({ hasText: 'Do not collect again' }).waitFor();
    if (!persistedId) throw new Error('Hospital response was not lost after persisted save');
    await page.unroute(url, lose);
    const entries = await page.evaluate(() => Object.entries(sessionStorage).filter(([name]) => name.startsWith('taxnest.hospital.pending.')));
    if (entries.length !== 1 || entries[0][1] !== payload.request_key) throw new Error('Hospital pending storage did not preserve UUID only');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await form.waitFor();
    if (await form.locator('[name="request_key"]').inputValue() !== payload.request_key) throw new Error('Hospital refresh minted a replacement payment key');
    if ('service_lines[0][service_head]' in payload) {
      await page.locator('#hospital-multi-mode').check();
      await page.locator('#hospital-add-service').click();
    }
    for (const [name, value] of Object.entries(payload)) {
      if (['request_key', 'expected_branch_key'].includes(name) || (name==='total_amount' && 'service_lines[0][service_head]' in payload)) continue;
      const field = form.locator(`[name="${name}"]`);
      const kind = await field.evaluate(el => el.tagName === 'SELECT' ? 'select' : el.type);
      if (kind === 'checkbox') await field.check();
      else if (kind === 'select') await field.selectOption(value);
      else await field.fill(value);
    }
    return persistedId;
  };
  const initialPayload = { request_key: initialKey, expected_branch_key: branch, patient_reference: reference,
    patient_name: 'Synthetic patient '+v.width, service_head: 'consultation', total_amount: '1000.00', amount: '400.00', payment_method: 'cash', payment_confirmed: '1',
    'service_lines[0][service_head]':configuredService,'service_lines[0][amount]':'500.00','service_lines[1][service_head]':'lab','service_lines[1][amount]':'500.00' };
  await create.locator('[name="patient_reference"]').fill(reference);
  await create.locator('[name="patient_name"]').fill(initialPayload.patient_name);
  await page.locator('#hospital-multi-mode').check();
  await page.locator('#hospital-add-service').click();
  for(const name of Object.keys(initialPayload).filter(k=>k.startsWith('service_lines'))) {
    const field=create.locator(`[name="${name}"]`);
    if(name.endsWith('[service_head]')) await field.selectOption(initialPayload[name]); else await field.fill(initialPayload[name]);
  }
  await create.locator('[name="service_head"]').selectOption('consultation');
  if(Number(await create.locator('[name="total_amount"]').inputValue())!==1000) throw new Error('Multi-service total did not recalculate');
  await create.locator('[name="amount"]').fill('400.00');
  await create.locator('[name="payment_method"]').selectOption('cash');
  await create.locator('[name="payment_confirmed"]').check();
  if (await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth)) > v.width + 2) throw new Error('Hospital form overflows viewport');
  await collectionControlChecks(page, '#hospital-collection');
  await saveEvidenceScreenshot(page, 'hospital-cash-entry-' + v.width);
  const lostInitialId = await loseResponseAndRefresh(create, baseUrl + '/fbr-pos/hospital', initialPayload);
  await openCollectionReceipt(page, create.locator('button[type="submit"]'), /\/fbr-pos\/hospital\/receipts\/\d+$/);
  const firstReceipt = new URL(page.url()).pathname;
  const firstId = Number(firstReceipt.split('/').pop());
  if (firstId !== lostInitialId) throw new Error('Hospital initial refresh recovery returned another receipt');
  const assertReceipt = async (amount, balance) => {
    if (Number(await page.locator('#hospital-payment-amount').getAttribute('data-paisa')) !== amount
        || Number(await page.locator('#hospital-receipt-balance').getAttribute('data-paisa')) !== balance) throw new Error('Hospital receipt payment/balance snapshot incorrect');
    const body = await page.locator('body').innerText();
    if (!body.includes(reference) || !/not submitted|not sent|local receipt|local collection/i.test(body)) throw new Error('Hospital local receipt missing reference/fiscal warning');
    if (await page.locator('article canvas,article svg,article img:not([data-hospital-logo])').count()) throw new Error('Hospital receipt unexpectedly renders fiscal QR');
  };
  await assertReceipt(40000, 60000);
  await scrolledPixelDetail(page,['.hospital-receipt dl','#hospital-print-receipt'],'hospital-cash-receipt-details-'+v.width,saveEvidenceScreenshot);
  const invoiceHref = await page.locator('a[href*="/hospital/invoices/"]').first().getAttribute('href');
  if (!invoiceHref || !/\/invoices\/\d+$/.test(invoiceHref)) throw new Error('Hospital receipt has no invoice balance link');
  const invoiceUrl = new URL(invoiceHref, baseUrl).href;
  const invoiceId = Number(invoiceHref.split('/').pop());
  const replay = await page.request.post(baseUrl + '/fbr-pos/hospital', { headers, form: initialPayload });
  if (!replay.ok() || Number((await replay.json()).receipt_id) !== firstId) throw new Error('Initial hospital payment retry duplicated receipt');
  const changed = await page.request.post(baseUrl + '/fbr-pos/hospital', { headers, form: { ...initialPayload, patient_reference: reference + '-CHANGED' } });
  if (changed.status() !== 422) throw new Error('Hospital accepted conflicting initial retry');

  let cardPayload;
  let cardReceipt;
  for (const [method, remaining] of [['card', 30000], ['bank', 0]]) {
    await page.goto(invoiceUrl, { waitUntil: 'domcontentloaded' });
    const payment = page.locator('#hospital-payment');
    await payment.waitFor();
    const key = await payment.locator('[name="request_key"]').inputValue();
    const payload = { request_key: key, expected_branch_key: branch, amount: '300.00', payment_method: method, payment_reference: 'SYN-'+method+'-'+v.width, payment_confirmed: '1' };
    if (method === 'bank') {
      const overpay = await page.request.post(invoiceUrl + '/payments', { headers, form: { ...payload, request_key: randomUUID(), amount: '300.01' } });
      if (overpay.status() !== 422) throw new Error('Hospital accepted payment exceeding balance');
    }
    await payment.locator('[name="amount"]').fill('300.00');
    await payment.locator('[name="payment_method"]').selectOption(method);
    await payment.locator('[name="payment_reference"]').fill(payload.payment_reference);
    await payment.locator('[name="payment_confirmed"]').check();
    const lostPartialId = await loseResponseAndRefresh(payment, invoiceUrl + '/payments', payload);
    await openCollectionReceipt(page, payment.locator('button[type="submit"]'), /\/fbr-pos\/hospital\/receipts\/\d+$/);
    if (lostPartialId && Number(new URL(page.url()).pathname.split('/').pop()) !== lostPartialId) throw new Error('Hospital partial-payment refresh recovery returned another receipt');
    await assertReceipt(30000, remaining);
    const pendingKeys=await page.evaluate(()=>Object.keys(sessionStorage).filter(name=>name.startsWith('taxnest.hospital.pending.')));
    if(pendingKeys.length) throw new Error('Hospital saved receipt did not clear pending payment key');
    if (!(await page.locator('article').innerText()).includes(payload.payment_reference)) throw new Error('Hospital receipt missing bank/card reference');
    if (method === 'card') { cardPayload = payload; cardReceipt = Number(new URL(page.url()).pathname.split('/').pop()); }
    await saveEvidenceScreenshot(page, 'hospital-' + method + '-receipt-' + v.width);
    await scrolledPixelDetail(page,['.hospital-receipt dl','#hospital-print-receipt'],'hospital-'+method+'-receipt-details-'+v.width,saveEvidenceScreenshot);
  }
  const cardRetry = await page.request.post(invoiceUrl + '/payments', { headers, form: cardPayload });
  if (!cardRetry.ok() || Number((await cardRetry.json()).receipt_id) !== cardReceipt) throw new Error('Hospital saved partial-payment retry duplicated receipt');
  for (const change of [{ amount: '299.00' }, { payment_method: 'cash' }, { payment_reference: 'CHANGED' }]) {
    const conflict = await page.request.post(invoiceUrl + '/payments', { headers, form: { ...cardPayload, ...change } });
    if (conflict.status() !== 422) throw new Error('Hospital partial-payment conflict was not rejected');
  }
  await page.goto(invoiceUrl, { waitUntil: 'domcontentloaded' });
  if (Number(await page.locator('#hospital-balance').getAttribute('data-paisa')) !== 0) throw new Error('Hospital balance did not settle to zero');
  if(!await page.locator('#hospital-payment button[type="submit"]').isDisabled()) throw new Error('Settled Hospital bill allowed a fresh installment');
  await page.goto(baseUrl+'/fbr-pos/hospital/history?search=HI-'+invoiceId+'&status=settled',{waitUntil:'domcontentloaded'});
  if(!(await page.locator('body').innerText()).includes(reference)) throw new Error('Displayed HI number/settled filter missed invoice');
  await page.goto(baseUrl+'/fbr-pos/hospital/history?search=HI-'+invoiceId+'&status=partial',{waitUntil:'domcontentloaded'});
  if((await page.locator('body').innerText()).includes(reference)) throw new Error('Settled invoice remains in partial filter');
  await page.goto(baseUrl+'/fbr-pos/hospital',{waitUntil:'domcontentloaded'});
  if(!(await page.locator('#hospital-patient-lookup').innerHTML()).includes(reference)) throw new Error('Minimal patient lookup lost recorded reference');
  await page.goto(baseUrl + firstReceipt, { waitUntil: 'domcontentloaded' });
  await assertReceipt(40000, 60000); // Original receipt must not acquire the current balance.
  await page.evaluate(() => { window.__hospitalPrint = 0; window.print = () => { window.__hospitalPrint++; }; });
  await page.getByRole('button', { name: /print/i }).click();
  if (await page.evaluate(() => window.__hospitalPrint) !== 1) throw new Error('Hospital reprint did not invoke print');
  await page.emulateMedia({ media: 'print' });
  await saveEvidenceScreenshot(page, 'hospital-immutable-reprint-' + v.width);
  await page.emulateMedia({ media: 'screen' });
  await page.goto(baseUrl + '/fbr-pos/hospital/history?search=' + encodeURIComponent(reference), { waitUntil: 'domcontentloaded' });
  if (!await page.locator('body').innerText().then(body => body.includes(reference))) throw new Error('Hospital history missing patient reference');

  // A cash close must also preserve an invoice still awaiting a later payment.
  const partial=await page.request.post(baseUrl+'/fbr-pos/hospital',{headers,form:{request_key:randomUUID(),expected_branch_key:branch,patient_reference:'SYN-PARTIAL-'+v.width,service_head:'consultation',total_amount:'200.00',amount:'50.00',payment_method:'bank',payment_reference:'SYN-PARTIAL-BANK-'+v.width,payment_confirmed:'1'}});
  const partialSaved=await partial.json();
  if(!partial.ok()||!partialSaved.success) throw new Error('Hospital partial closing fixture failed');
  await page.goto(baseUrl+'/fbr-pos/hospital/receipts/'+partialSaved.receipt_id,{waitUntil:'domcontentloaded'});
  const partialInvoice=await page.locator('a[href*="/hospital/invoices/"]').first().getAttribute('href');
  await saveEvidenceScreenshot(page,'hospital-open-partial-before-close-'+v.width);
  await page.goto(baseUrl + '/fbr-pos/hospital/day-close', { waitUntil: 'domcontentloaded' });
  const totals = async () => ({ cash: Number(await page.locator('#hospital-cash-total').getAttribute('data-paisa')),
    card: Number(await page.locator('#hospital-card-total').getAttribute('data-paisa')),
    bank: Number(await page.locator('#hospital-bank-total').getAttribute('data-paisa')) });
  const before = await totals();
  if (before.cash !== 40000 || before.card !== 30000 || before.bank !== 35000) throw new Error('Hospital day close did not separate cash/card/bank exactly once');
  const close = page.locator('#hospital-close');
  await close.locator('[name="counted_cash"]').fill('399.00');
  await close.locator('[name="variance_note"]').fill('Synthetic one rupee count variance');
  await close.locator('[name="confirm"]').check();
  const closePayload = await close.locator('input,textarea').evaluateAll(inputs => Object.fromEntries(inputs.filter(input => input.name).map(input => [input.name, input.value])));
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), close.locator('button').click()]);
  if (Number(await page.locator('#hospital-variance').getAttribute('data-paisa')) !== -100) throw new Error('Hospital counted variance snapshot incorrect');
  const repeated = await page.request.post(baseUrl + '/fbr-pos/hospital/day-close', { headers, form: closePayload });
  if (!repeated.ok()) throw new Error('Hospital close idempotent retry failed');
  const postClose = await page.request.post(baseUrl + '/fbr-pos/hospital', { headers, form: initialPayload });
  if (!postClose.ok() || Number((await postClose.json()).receipt_id) !== firstId) throw new Error('Hospital postclose saved replay lost receipt');
  const fresh = await page.request.post(baseUrl + '/fbr-pos/hospital', { headers, form: { ...initialPayload, request_key: randomUUID() } });
  if (fresh.status() !== 422) throw new Error('Hospital fresh payment entered closed date');
  await page.reload({ waitUntil: 'domcontentloaded' });
  if (JSON.stringify(await totals()) !== JSON.stringify(before) || Number(await page.locator('#hospital-variance').getAttribute('data-paisa')) !== -100) throw new Error('Hospital closing snapshot mutated after retries');
  await saveEvidenceScreenshot(page, 'hospital-final-close-' + v.width);
  await scrolledPixelDetail(page,['#hospital-cash-total','#hospital-card-total','#hospital-bank-total','#hospital-variance'],'hospital-closed-totals-details-'+v.width,saveEvidenceScreenshot);
  await page.goto(new URL(partialInvoice,baseUrl).href,{waitUntil:'domcontentloaded'});
  if(Number(await page.locator('#hospital-balance').getAttribute('data-paisa'))!==15000) throw new Error('Closing changed outstanding partial invoice balance');
  await privateCollectionCacheCheck(page,{baseUrl,path:'/fbr-pos/hospital',screenshotName:'hospital-offline-private-cache-'+v.width,saveEvidenceScreenshot});
  pass('HOSPITAL COLLECTION PASS: ' + v.width + ': initial and partial-payment lostresponse/refresh recovery, patient reference, consultation, cash/card/bank partial payments and zero balance, immutable receipt/reprint, conflict/overpay/idempotent retry, history and counted-cash day-close; fiscal remains disabled; synthetic only');
}


async function hospitalWorkspaceChecks(page,v,{baseUrl,saveEvidenceScreenshot}) {
  for(const path of ['/fbr-pos/hospital','/fbr-pos/hospital/printing','/fbr-pos/hospital/history']) {
    const response=await page.goto(baseUrl+path,{waitUntil:'domcontentloaded'});
    if(!response?.ok() || !(response.headers()['cache-control']||'').includes('no-store')) throw new Error('Hospital private workspace unavailable/cacheable: '+path);
    const body=await page.locator('body').innerText();
    if(/FBR ON|FBR CERTIFIED|automatically enable/i.test(body)) throw new Error('Hospital fiscal wording misleading');
    if(await page.locator('a[href="/fbr-pos/kitchen"],a[href="/fbr-pos/products"],a[href="/fbr-pos/delivery"]').count()) throw new Error('Hospital menu exposes retail operations');
    if(await page.evaluate(()=>Math.max(document.documentElement.scrollWidth,document.body.scrollWidth))>v.width+2) throw new Error('Hospital workspace overflows');
    await saveEvidenceScreenshot(page,'hospital-workspace-'+path.split('/').pop()+'-'+v.width);
  }
  for(const language of ['ur','rur','en']) {
    await page.locator('#hospital-workspace-menu summary').click();
    await page.locator('#hospital-language').selectOption(language);
    const languageForm=page.locator('#hospital-language').locator('..');
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),languageForm.locator('button').click()]);
    if(await page.locator('#hospital-language').inputValue()!==language || await page.locator('html').getAttribute('lang')!==(language==='ur'?'ur':'en')) throw new Error('hospital language preference did not apply');
    if(language==='ur') await saveEvidenceScreenshot(page,'hospital-urdu-workspace-'+v.width);
  }
  await page.goto(baseUrl+'/fbr-pos/hospital/printing',{waitUntil:'domcontentloaded'});
  const form=page.locator('#hospital-print-settings');
  await form.locator('[name="phone"]').fill('000-000-0000');
  await form.locator('[name="address"]').fill('Synthetic hospital address');
  await form.locator('[name="printer_label"]').fill('Synthetic hospital desk printer');
  await form.locator('[name="show_contact"]').check();
  await form.locator('[name="show_logo"]').check();
  await form.locator('[name="logo"]').setInputFiles({name:'synthetic-hospital-logo.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aO2kAAAAASUVORK5CYII=','base64')});
  for(const paper of ['58mm','a4','80mm']) {
    await form.locator('[name="paper"]').selectOption(paper);
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.locator('button[type="submit"]').click()]);
    if(await form.locator('[name="paper"]').inputValue()!==paper || await form.locator('[name="printer_label"]').inputValue()!=='Synthetic hospital desk printer') throw new Error('Hospital print preferences did not persist');
    const popup=page.waitForEvent('popup'); await page.locator('#hospital-preview-link').click(); const preview=await popup;
    await preview.waitForLoadState('domcontentloaded');
    if(await preview.locator('article').getAttribute('data-paper')!==paper) throw new Error('Hospital preview paper incorrect');
    for(const value of ['000-000-0000','Synthetic hospital address']) if(!(await preview.locator('body').innerText()).includes(value)) throw new Error('Hospital preview missing '+value);
    await preview.waitForFunction(()=>{const img=document.querySelector('[data-hospital-logo]');return img?.complete&&img.naturalWidth>0;});
    await preview.emulateMedia({media:'print'});await saveEvidenceScreenshot(preview,'hospital-preview-'+paper+'-'+v.width);await preview.close();
  }
  await page.goto(baseUrl+'/fbr-pos/hospital',{waitUntil:'domcontentloaded'});
}


async function hospitalRateChecks(page,v,{baseUrl,saveEvidenceScreenshot}) {
  await page.goto(baseUrl+'/fbr-pos/hospital/settings',{waitUntil:'domcontentloaded'});
  const add=page.locator('#hospital-add-service');
  const label='Synthetic consultation '+v.width;
  await add.locator('[name="label"]').fill(label);
  await add.locator('[name="service_head"]').selectOption('consultation');
  await add.locator('[name="rate"]').fill('500.00');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),add.locator('button').click()]);
  const saved=page.locator('.hospital-edit-service').filter({has:page.locator(`[name="label"][value="${label}"]`)});
  if(await saved.count()!==1 || Number(await saved.locator('[name="rate"]').inputValue())!==500) throw new Error('Hospital service catalog rate did not persist');
  const code=await saved.locator('[name="code"]').inputValue();
  await saveEvidenceScreenshot(page,'hospital-configured-services-'+v.width);
  await page.goto(baseUrl+'/fbr-pos/hospital',{waitUntil:'domcontentloaded'});
  await page.locator('#hospital-multi-mode').check();
  await page.locator('.hospital-service-line select').first().selectOption(code);
  if(Number(await page.locator('.hospital-service-line input').first().inputValue())!==500) throw new Error('Configured Hospital rate did not populate billing');
  await page.reload({waitUntil:'domcontentloaded'});
  return code;
}
