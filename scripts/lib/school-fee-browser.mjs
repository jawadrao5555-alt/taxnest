import { randomUUID } from 'node:crypto';

// Called only by rc-browser-acceptance's guarded loopback/MariaDB fixture.
// This checks an actual persisted local receipt, not an FBR acknowledgement.
export async function schoolFeeWorkflow(page, t, v, { baseUrl, saveEvidenceScreenshot, pass }) {
  const browser = page.context().browser();
  const guest = await browser.newContext({ viewport: v });
  await guest.route('**/*', route => ['127.0.0.1', 'localhost', '::1', '[::1]'].includes(new URL(route.request().url()).hostname)
    ? route.continue() : route.abort('blockedbyclient'));
  try {
    const registration = await guest.newPage();
    const opened = await registration.goto(baseUrl + '/fbr-pos/register', { waitUntil: 'domcontentloaded' });
    if (!opened?.ok()) throw new Error('School registration category page did not load');
    await registration.waitForFunction(() => document.querySelector('input[name="pos_type"]')?.value === 'retail');
    await registration.getByText('School / Education', { exact: true }).click();
    await registration.waitForFunction(() => document.querySelector('input[name="pos_type"]')?.value === 'education');
    if (await registration.locator('input[name="pos_type"]').inputValue() !== 'education') throw new Error('School category selection did not reach signup value');
    await saveEvidenceScreenshot(registration, 'school-fee-registration-' + v.width);
  } finally { await guest.close(); }

  const reportTotals = async path => {
    const response = await page.goto(baseUrl + path, { waitUntil: 'domcontentloaded' });
    if (!response?.ok()) throw new Error('School cash report failed: ' + path);
    await page.locator('#school-receipt-count').waitFor();
    return {
      count: Number(await page.locator('#school-receipt-count').innerText()),
      paisa: Number(await page.locator('#school-cash-total').getAttribute('data-paisa')),
    };
  };
  const reportPaths = ['/fbr-pos/transactions', '/fbr-pos/day-close'];
  const beforeReports = new Map();
  for (const path of reportPaths) beforeReports.set(path, await reportTotals(path));
  await page.goto(baseUrl + '/fbr-pos/create', { waitUntil: 'domcontentloaded' });
  await page.waitForURL('**/fbr-pos/school-fees');
  const form = page.locator('#school-collection');
  await form.waitFor();
  const reference = 'SYN-' + v.width + '-' + randomUUID().slice(0, 8);
  const key = await form.locator('[name="request_key"]').inputValue();
  await form.locator('[name="student_reference"]').fill(reference);
  await form.locator('[name="fee_head"]').selectOption('tuition');
  await form.locator('[name="amount"]').fill('1250.50');
  const overflow = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth));
  if (overflow > v.width + 2) throw new Error('School fee form overflows viewport');
  await saveEvidenceScreenshot(page, 'school-fee-cash-entry-' + v.width);
  const posted = page.waitForResponse(response => new URL(response.url()).pathname === '/fbr-pos/school-fees'
    && response.request().method() === 'POST');
  await Promise.all([
    page.waitForURL(/\/fbr-pos\/school-fees\/\d+\/receipt$/, { waitUntil: 'domcontentloaded' }),
    form.getByRole('button', { name: 'Record cash received', exact: true }).click(),
  ]);
  const save = await posted;
  if (![302, 303].includes(save.status())) throw new Error('Cash collection did not redirect to its persisted receipt');
  const receiptPath = new URL(page.url()).pathname;
  const receiptId = Number(receiptPath.match(/\/(\d+)\/receipt$/)[1]);
  const assertReceipt = async () => {
    const body = await page.locator('body').innerText();
    for (const text of [reference, 'Tuition', 'Cash', 'PKR 1,250.50', 'LOCAL RECEIPT ONLY', 'Not submitted to FBR']) {
      if (!body.includes(text)) throw new Error('School receipt missing: ' + text);
    }
    if (await page.locator('article canvas,article svg,article img').count()) throw new Error('Local school receipt unexpectedly rendered a fiscal QR/image');
  };
  await assertReceipt();
  await saveEvidenceScreenshot(page, 'school-fee-cash-receipt-' + v.width);
  await page.evaluate(() => { window.__schoolPrintCount = 0; window.print = () => { window.__schoolPrintCount++; }; });
  await page.getByRole('button', { name: 'Print / Reprint', exact: true }).click();
  if (await page.evaluate(() => window.__schoolPrintCount) !== 1) throw new Error('Receipt print action did not invoke browser print');
  await page.emulateMedia({ media: 'print' });
  await saveEvidenceScreenshot(page, 'school-fee-print-layout-' + v.width);
  await page.emulateMedia({ media: 'screen' });

  await page.getByRole('link', { name: 'New collection / History', exact: true }).click();
  await form.waitFor();
  const historyRow = page.getByRole('row').filter({ hasText: reference });
  if (await historyRow.count() !== 1) throw new Error('Persisted school collection missing or duplicated in history');
  if (await form.locator('[name="request_key"]').inputValue() === key) throw new Error('New collection reused the previous request key');
  const csrf = await form.locator('[name="_token"]').inputValue();
  const replay = await page.request.post(baseUrl + '/fbr-pos/school-fees', {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    form: { request_key: key, student_reference: reference, fee_head: 'tuition', amount: '1250.50', payment_method: 'cash' },
  });
  const replayed = await replay.json();
  if (!replay.ok() || !replayed.success || Number(replayed.receipt_id) !== receiptId) throw new Error('Lost-response retry did not return the same persisted receipt');
  // A saved-but-lost response may be retried, but changing its immutable
  // payment details must return a clear 422 without changing the receipt.
  const originalReceipt = await (await page.request.get(baseUrl + receiptPath)).text();
  for (const change of [{ amount: '999.00' }, { fee_head: 'exam' }, { student_reference: reference + '-CHANGED' }]) {
    const conflict = await page.request.post(baseUrl + '/fbr-pos/school-fees', {
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      form: { request_key: key, student_reference: reference, fee_head: 'tuition', amount: '1250.50', payment_method: 'cash', ...change },
    });
    const rejected = await conflict.json();
    if (conflict.status() !== 422 || !rejected.errors?.request_key?.[0]?.includes('different details')) {
      throw new Error('Changed saved-payment retry did not return clear 422 conflict');
    }
    const unchanged = await (await page.request.get(baseUrl + receiptPath)).text();
    if (unchanged !== originalReceipt) throw new Error('Rejected retry changed the saved receipt');
  }
  await page.reload({ waitUntil: 'domcontentloaded' });
  if (await page.getByRole('row').filter({ hasText: reference }).count() !== 1) throw new Error('Retry duplicated school cash collection');
  await page.getByRole('row').filter({ hasText: reference }).getByRole('link', { name: 'SF-' + receiptId, exact: true }).click();
  await page.waitForURL('**' + receiptPath);
  await assertReceipt();
  await page.evaluate(() => { window.__schoolPrintCount = 0; window.print = () => { window.__schoolPrintCount++; }; });
  await page.getByRole('button', { name: 'Print / Reprint', exact: true }).click();
  if (await page.evaluate(() => window.__schoolPrintCount) !== 1) throw new Error('Saved receipt reprint action did not invoke browser print');
  await saveEvidenceScreenshot(page, 'school-fee-history-reprint-' + v.width);
  for (const path of reportPaths) {
    const after = await reportTotals(path);
    const before = beforeReports.get(path);
    if (after.count !== before.count + 1 || after.paisa !== before.paisa + 125050) {
      throw new Error('School cash report did not count the immutable payment exactly once: ' + path);
    }
    if (await page.getByRole('row').filter({ hasText: reference }).count() !== 1) {
      throw new Error('School cash report omitted or duplicated the new receipt: ' + path);
    }
    await saveEvidenceScreenshot(page, 'school-fee-' + (path.endsWith('transactions') ? 'sales-history-' : 'cash-preview-') + v.width);
  }
  const closeForm = page.locator('#school-close-form');
  await closeForm.waitFor();
  const closeUrl = new URL(await closeForm.getAttribute('action'), baseUrl).href;
  const closeFields = await closeForm.locator('input').evaluateAll(inputs => Object.fromEntries(
    inputs.filter(input => input.name).map(input => [input.name, input.value])));
  await closeForm.locator('[name="confirm"]').check();
  await Promise.all([
    page.waitForResponse(response => response.url() === closeUrl && response.request().method() === 'POST'),
    closeForm.getByRole('button', { name: 'Confirm day close', exact: true }).click(),
  ]);
  await page.locator('#school-close-status').filter({ hasText: 'Closed' }).waitFor();
  const closedTotals = await reportTotals('/fbr-pos/day-close');
  const postCloseRetry = await page.request.post(baseUrl + '/fbr-pos/school-fees', {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    form: { request_key: key, student_reference: reference, fee_head: 'tuition', amount: '1250.50', payment_method: 'cash' },
  });
  if (!postCloseRetry.ok() || Number((await postCloseRetry.json()).receipt_id) !== receiptId) throw new Error('Saved retry failed after final close');
  const repeatedClose = await page.request.post(closeUrl, {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, form: { ...closeFields, confirm: '1' },
  });
  if (!repeatedClose.ok() || !(await repeatedClose.json()).success) throw new Error('Repeated final close failed');
  for (const change of [{ request_key: randomUUID() }, { amount: '999.00' }, { fee_head: 'exam' }, { student_reference: reference + '-CHANGED' }]) {
    const blocked = await page.request.post(baseUrl + '/fbr-pos/school-fees', {
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      form: { request_key: key, student_reference: reference, fee_head: 'tuition', amount: '1250.50', payment_method: 'cash', ...change },
    });
    if (blocked.status() !== 422) throw new Error('Closed school day accepted late or changed payment');
  }
  const finalTotals = await reportTotals('/fbr-pos/day-close');
  if (finalTotals.count !== closedTotals.count || finalTotals.paisa !== closedTotals.paisa
      || (await page.locator('#school-close-status').innerText()).trim() !== 'Closed') {
    throw new Error('Final school close snapshot changed after retry');
  }
  if (await (await page.request.get(baseUrl + receiptPath)).text() !== originalReceipt) throw new Error('Final close changed original receipt');
  await saveEvidenceScreenshot(page, 'school-fee-final-close-' + v.width);
  pass('SCHOOL FEE PASS: ' + v.width + ': public category selection, real cash POST, persisted receipt/history, same-key retry, print CSS and reprint action; no fiscal submission and no physical printer claim');
}
