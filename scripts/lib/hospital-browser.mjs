import { randomUUID } from 'node:crypto';

// Disposable loopback fixture only. Synthetic references are not health records.
export async function hospitalWorkflow(page, t, v, { baseUrl, saveEvidenceScreenshot, pass }) {
  if (new URL(page.url()).pathname !== '/fbr-pos/hospital') throw new Error('Hospital login did not reach native collection desk');
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
    for (const [name, value] of Object.entries(payload)) {
      if (['request_key', 'expected_branch_key'].includes(name)) continue;
      const field = form.locator(`[name="${name}"]`);
      const kind = await field.evaluate(el => el.tagName === 'SELECT' ? 'select' : el.type);
      if (kind === 'checkbox') await field.check();
      else if (kind === 'select') await field.selectOption(value);
      else await field.fill(value);
    }
    return persistedId;
  };
  const initialPayload = { request_key: initialKey, expected_branch_key: branch, patient_reference: reference,
    service_head: 'consultation', total_amount: '1000.00', amount: '400.00', payment_method: 'cash', payment_confirmed: '1' };
  await create.locator('[name="patient_reference"]').fill(reference);
  await create.locator('[name="service_head"]').selectOption('consultation');
  await create.locator('[name="total_amount"]').fill('1000.00');
  await create.locator('[name="amount"]').fill('400.00');
  await create.locator('[name="payment_method"]').selectOption('cash');
  await create.locator('[name="payment_confirmed"]').check();
  if (await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth)) > v.width + 2) throw new Error('Hospital form overflows viewport');
  await saveEvidenceScreenshot(page, 'hospital-cash-entry-' + v.width);
  const lostInitialId = await loseResponseAndRefresh(create, baseUrl + '/fbr-pos/hospital', initialPayload);
  await Promise.all([page.waitForURL(/\/fbr-pos\/hospital\/receipts\/\d+$/), create.locator('button[type="submit"]').click()]);
  const firstReceipt = new URL(page.url()).pathname;
  const firstId = Number(firstReceipt.split('/').pop());
  if (firstId !== lostInitialId) throw new Error('Hospital initial refresh recovery returned another receipt');
  const assertReceipt = async (amount, balance) => {
    if (Number(await page.locator('#hospital-payment-amount').getAttribute('data-paisa')) !== amount
        || Number(await page.locator('#hospital-receipt-balance').getAttribute('data-paisa')) !== balance) throw new Error('Hospital receipt payment/balance snapshot incorrect');
    const body = await page.locator('body').innerText();
    if (!body.includes(reference) || !/not submitted|not sent|local receipt|local collection/i.test(body)) throw new Error('Hospital local receipt missing reference/fiscal warning');
    if (await page.locator('article canvas,article svg,article img').count()) throw new Error('Hospital receipt unexpectedly renders fiscal QR');
  };
  await assertReceipt(40000, 60000);
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
    const payload = { request_key: key, expected_branch_key: branch, amount: '300.00', payment_method: method, payment_confirmed: '1' };
    if (method === 'bank') {
      const overpay = await page.request.post(invoiceUrl + '/payments', { headers, form: { ...payload, request_key: randomUUID(), amount: '300.01' } });
      if (overpay.status() !== 422) throw new Error('Hospital accepted payment exceeding balance');
    }
    await payment.locator('[name="amount"]').fill('300.00');
    await payment.locator('[name="payment_method"]').selectOption(method);
    await payment.locator('[name="payment_confirmed"]').check();
    const lostPartialId = method === 'card' ? await loseResponseAndRefresh(payment, invoiceUrl + '/payments', payload) : null;
    await Promise.all([page.waitForURL(/\/fbr-pos\/hospital\/receipts\/\d+$/), payment.locator('button[type="submit"]').click()]);
    if (lostPartialId && Number(new URL(page.url()).pathname.split('/').pop()) !== lostPartialId) throw new Error('Hospital partial-payment refresh recovery returned another receipt');
    await assertReceipt(30000, remaining);
    if (method === 'card') { cardPayload = payload; cardReceipt = Number(new URL(page.url()).pathname.split('/').pop()); }
    await saveEvidenceScreenshot(page, 'hospital-' + method + '-receipt-' + v.width);
  }
  const cardRetry = await page.request.post(invoiceUrl + '/payments', { headers, form: cardPayload });
  if (!cardRetry.ok() || Number((await cardRetry.json()).receipt_id) !== cardReceipt) throw new Error('Hospital saved partial-payment retry duplicated receipt');
  for (const change of [{ amount: '299.00' }, { payment_method: 'cash' }]) {
    const conflict = await page.request.post(invoiceUrl + '/payments', { headers, form: { ...cardPayload, ...change } });
    if (conflict.status() !== 422) throw new Error('Hospital partial-payment conflict was not rejected');
  }
  await page.goto(invoiceUrl, { waitUntil: 'domcontentloaded' });
  if (Number(await page.locator('#hospital-balance').getAttribute('data-paisa')) !== 0) throw new Error('Hospital balance did not settle to zero');
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

  await page.goto(baseUrl + '/fbr-pos/hospital/day-close', { waitUntil: 'domcontentloaded' });
  const totals = async () => ({ cash: Number(await page.locator('#hospital-cash-total').getAttribute('data-paisa')),
    card: Number(await page.locator('#hospital-card-total').getAttribute('data-paisa')),
    bank: Number(await page.locator('#hospital-bank-total').getAttribute('data-paisa')) });
  const before = await totals();
  if (before.cash !== 40000 || before.card !== 30000 || before.bank !== 30000) throw new Error('Hospital day close did not separate cash/card/bank exactly once');
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
  pass('HOSPITAL COLLECTION PASS: ' + v.width + ': initial and partial-payment lostresponse/refresh recovery, patient reference, consultation, cash/card/bank partial payments and zero balance, immutable receipt/reprint, conflict/overpay/idempotent retry, history and counted-cash day-close; fiscal remains disabled; synthetic only');
}
