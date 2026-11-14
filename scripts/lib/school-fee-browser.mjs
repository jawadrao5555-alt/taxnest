import { randomUUID } from 'node:crypto';

// Called only by rc-browser-acceptance's guarded loopback/MariaDB fixture.
// This checks an actual persisted local receipt, not an FBR acknowledgement.
export async function schoolFeeWorkflow(page, t, v, { baseUrl, saveEvidenceScreenshot, pass }) {
  if (new URL(page.url()).pathname !== '/fbr-pos/school-fees') throw new Error('School login did not land on Collect Fee');
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

  const legacyId = t.legacyInvoices?.[v.width];
  if (!legacyId) throw new Error('Missing synthetic historical retail invoice fixture');
  await page.goto(baseUrl + '/fbr-pos/school-fees/legacy-retail', { waitUntil: 'domcontentloaded' });
  await page.getByRole('link', { name: 'SYN-SCHOOL-LEGACY-' + v.width, exact: true }).click();
  await page.waitForURL('**/fbr-pos/school-fees/legacy-retail/' + legacyId);
  const legacyBody = await page.locator('body').innerText();
  for (const value of ['SYN-SCHOOL-LEGACY-' + v.width, 'SYNTHETIC-LEGACY-' + v.width,
    'Synthetic legacy test item', '1180.00', '180.00', 'Read-only historical retail record']) {
    if (!legacyBody.includes(value)) throw new Error('Legacy invoice omitted original stored value: ' + value);
  }
  for (const secret of ['SYN-LEGACY-PRIVATE-RESPONSE', 'SYN-LEGACY-PRIVATE-ID']) {
    if (legacyBody.includes(secret)) throw new Error('Legacy invoice exposed private stored data');
  }
  if (await page.locator('a[href*="retry-fbr"], a[href*="edit-failed"], form[action*="retry"], form[action*="school-fees/legacy-retail"]').count()) {
    throw new Error('Legacy invoice exposed a mutation action');
  }
  await page.evaluate(() => { window.__legacyPrint = 0; window.print = () => { window.__legacyPrint++; }; });
  await page.getByRole('button', { name: 'Print / Reprint', exact: true }).click();
  if (await page.evaluate(() => window.__legacyPrint) !== 1) throw new Error('Legacy reprint did not invoke browser print');
  await saveEvidenceScreenshot(page, 'school-fee-legacy-readonly-' + v.width);
  const reportTotals = async path => {
    const response = await page.goto(baseUrl + path, { waitUntil: 'domcontentloaded' });
    if (!response?.ok()) throw new Error('School cash report failed: ' + path);
    await page.locator('#school-receipt-count').waitFor();
    return {
      count: Number(await page.locator('#school-receipt-count').innerText()),
      paisa: Number(await page.locator('#school-cash-total').getAttribute('data-paisa')),
    };
  };
  const dashboardTotals = async () => {
    await page.goto(baseUrl + '/fbr-pos/dashboard', { waitUntil: 'domcontentloaded' });
    await page.locator('#school-today-count').waitFor();
    for (const label of ['Collect Fee', 'Receipts / Reprint', 'Cash Day Close']) {
      if (!await page.getByRole('link', { name: label, exact: true }).count()) throw new Error('School navigation missing ' + label);
    }
    for (const retail of ['products', 'stock', 'deals', 'loyalty', 'tax-reports', 'fail-queue', 'services', 'pharmacy', 'tables', 'kitchen']) {
      if (await page.locator(`a[href="/fbr-pos/${retail}"], a[href^="/fbr-pos/${retail}/"], a[href^="/fbr-pos/${retail}?"]`).count()) {
        throw new Error('School navigation exposed retail menu: ' + retail);
      }
    }
    if (await page.locator('#school-campus-switcher').count()) throw new Error('Single-campus school rendered unnecessary campus switcher');
    return { count: Number(await page.locator('#school-today-count').innerText()),
      paisa: Number(await page.locator('#school-today-cash').getAttribute('data-paisa')),
      status: (await page.locator('#school-today-status').innerText()).trim() };
  };
  const dashboardBefore = await dashboardTotals();
  const reportPaths = ['/fbr-pos/transactions', '/fbr-pos/day-close'];
  const beforeReports = new Map();
  for (const path of reportPaths) beforeReports.set(path, await reportTotals(path));
  await page.goto(baseUrl + '/fbr-pos/create', { waitUntil: 'domcontentloaded' });
  await page.waitForURL('**/fbr-pos/school-fees');
  const form = page.locator('#school-collection');
  await form.waitFor();
  // If tab storage cannot preserve the idempotency key, fail before POST.
  let storageFailurePosts = 0;
  const countStorageFailurePost = request => {
    if (request.method() === 'POST' && new URL(request.url()).pathname === '/fbr-pos/school-fees') storageFailurePosts++;
  };
  page.on('request', countStorageFailurePost);
  await page.evaluate(() => { Storage.prototype.setItem = () => { throw new DOMException('Synthetic storage unavailable', 'QuotaExceededError'); }; });
  await form.locator('[name="student_reference"]').fill('SYN-NOT-SENT');
  await form.locator('[name="amount"]').fill('1.00');
  await form.locator('[name="cash_confirmed"]').check();
  await form.getByRole('button', { name: 'Record cash received', exact: true }).click();
  await page.locator('#school-recovery-notice').filter({ hasText: 'No new request was sent' }).waitFor();
  if (storageFailurePosts !== 0 || !await form.getByRole('button', { name: 'Record cash received', exact: true }).isDisabled()) {
    throw new Error('Storage failure did not fail closed before collection');
  }
  page.off('request', countStorageFailurePost);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await form.waitFor();
  const reference = 'SYN-' + v.width + '-' + randomUUID().slice(0, 8);
  const key = await form.locator('[name="request_key"]').inputValue();
  await form.locator('[name="student_reference"]').fill(reference);
  await form.locator('[name="fee_head"]').selectOption('tuition');
  await form.locator('[name="amount"]').fill('1250.50');
  await form.locator('[name="cash_confirmed"]').check();
  const overflow = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth));
  if (overflow > v.width + 2) throw new Error('School fee form overflows viewport');
  await saveEvidenceScreenshot(page, 'school-fee-cash-entry-' + v.width);
  // Commit the real server request, then deliberately lose its response.
  // The cashier must refresh and explicitly recover, never send a new key.
  let lostReceiptId;
  const loseSavedResponse = async route => {
    if (route.request().method() !== 'POST') return route.continue();
    const response = await route.fetch();
    const saved = await response.json();
    if (!response.ok() || !saved.success) throw new Error('Lost-response setup did not actually save cash');
    lostReceiptId = Number(saved.receipt_id);
    await route.abort('blockedbyclient');
  };
  await page.route(baseUrl + '/fbr-pos/school-fees', loseSavedResponse);
  await form.getByRole('button', { name: 'Record cash received', exact: true }).click();
  await page.locator('#school-recovery-notice').filter({ hasText: 'Do not collect cash again' }).waitFor();
  if (!lostReceiptId) throw new Error('Response was not lost after a real persisted save');
  const pendingEntries = await page.evaluate(() => Object.entries(sessionStorage).filter(([name]) => name.startsWith('taxnest.school.pending.')));
  if (pendingEntries.length !== 1 || pendingEntries[0][1] !== key
      || !/^taxnest\.school\.pending\.\d+\.\d+\.\d+$/.test(pendingEntries[0][0])) {
    throw new Error('Recovery storage must contain only a tenant/campus/user scoped UUID');
  }
  const pendingStorageKey = pendingEntries[0][0];
  await page.unroute(baseUrl + '/fbr-pos/school-fees', loseSavedResponse);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await form.waitFor();
  if (await form.locator('[name="request_key"]').inputValue() !== key) throw new Error('Refresh replaced the uncertain saved payment key');
  await page.locator('#school-recovery-notice').filter({ hasText: 'Do not collect cash again' }).waitFor();
  await form.locator('[name="student_reference"]').fill(reference);
  await form.locator('[name="fee_head"]').selectOption('tuition');
  await form.locator('[name="amount"]').fill('1250.50');
  await form.locator('[name="cash_confirmed"]').check();
  await saveEvidenceScreenshot(page, 'school-fee-lost-response-recovery-' + v.width);
  const posted = page.waitForResponse(response => new URL(response.url()).pathname === '/fbr-pos/school-fees'
    && response.request().method() === 'POST');
  await Promise.all([
    page.waitForURL(/\/fbr-pos\/school-fees\/\d+\/receipt$/, { waitUntil: 'domcontentloaded' }),
    form.getByRole('button', { name: 'Record cash received', exact: true }).click(),
  ]);
  const save = await posted;
  if (save.status() !== 200) throw new Error('Cash recovery did not return its persisted receipt');
  const receiptPath = new URL(page.url()).pathname;
  const receiptId = Number(receiptPath.match(/\/(\d+)\/receipt$/)[1]);
  if (receiptId !== lostReceiptId) throw new Error('Refresh recovery created another receipt');
  if (await page.evaluate(name => sessionStorage.getItem(name), pendingStorageKey) !== null) throw new Error('Matching receipt did not clear pending recovery key');
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
    form: { request_key: key, student_reference: reference, fee_head: 'tuition', amount: '1250.50', payment_method: 'cash', expected_branch_key: '0' },
  });
  const replayed = await replay.json();
  if (!replay.ok() || !replayed.success || Number(replayed.receipt_id) !== receiptId) throw new Error('Lost-response retry did not return the same persisted receipt');
  // A saved-but-lost response may be retried, but changing its immutable
  // payment details must return a clear 422 without changing the receipt.
  const originalReceipt = await (await page.request.get(baseUrl + receiptPath)).text();
  for (const change of [{ amount: '999.00' }, { fee_head: 'exam' }, { student_reference: reference + '-CHANGED' }]) {
    const conflict = await page.request.post(baseUrl + '/fbr-pos/school-fees', {
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      form: { request_key: key, student_reference: reference, fee_head: 'tuition', amount: '1250.50', payment_method: 'cash', expected_branch_key: '0', ...change },
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
    form: { request_key: key, student_reference: reference, fee_head: 'tuition', amount: '1250.50', payment_method: 'cash', expected_branch_key: '0' },
  });
  if (!postCloseRetry.ok() || Number((await postCloseRetry.json()).receipt_id) !== receiptId) throw new Error('Saved retry failed after final close');
  const repeatedClose = await page.request.post(closeUrl, {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, form: { ...closeFields, confirm: '1' },
  });
  if (!repeatedClose.ok() || !(await repeatedClose.json()).success) throw new Error('Repeated final close failed');
  for (const change of [{ request_key: randomUUID(), cash_confirmed: '1' }, { amount: '999.00' }, { fee_head: 'exam' }, { student_reference: reference + '-CHANGED' }]) {
    const blocked = await page.request.post(baseUrl + '/fbr-pos/school-fees', {
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      form: { request_key: key, student_reference: reference, fee_head: 'tuition', amount: '1250.50', payment_method: 'cash', expected_branch_key: '0', ...change },
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
  // The closed desk still permits only a known pending saved-request replay.
  await page.evaluate(({ name, key }) => sessionStorage.setItem(name, key), { name: pendingStorageKey, key });
  await page.goto(baseUrl + '/fbr-pos/school-fees', { waitUntil: 'domcontentloaded' });
  await page.reload({ waitUntil: 'domcontentloaded' });
  if (await form.locator('[name="request_key"]').inputValue() !== key
      || await form.getByRole('button', { name: 'Record cash received', exact: true }).isDisabled()) {
    throw new Error('Closed desk stranded the known saved-request recovery');
  }
  await form.locator('[name="student_reference"]').fill(reference);
  await form.locator('[name="fee_head"]').selectOption('tuition');
  await form.locator('[name="amount"]').fill('1250.50');
  await form.locator('[name="cash_confirmed"]').check();
  await Promise.all([
    page.waitForURL('**' + receiptPath),
    form.getByRole('button', { name: 'Record cash received', exact: true }).click(),
  ]);
  if (await page.evaluate(name => sessionStorage.getItem(name), pendingStorageKey) !== null) throw new Error('Closed-day matching receipt did not clear recovery');
  await page.goto(baseUrl + '/fbr-pos/school-fees', { waitUntil: 'domcontentloaded' });
  if (await form.locator('[name="request_key"]').inputValue() === key
      || !await form.getByRole('button', { name: 'Record cash received', exact: true }).isDisabled()) {
    throw new Error('Closed day enabled a fresh collection without a pending saved key');
  }
  const dashboardAfter = await dashboardTotals();
  if (dashboardAfter.count !== dashboardBefore.count + 1 || dashboardAfter.paisa !== dashboardBefore.paisa + 125050
      || dashboardBefore.status !== 'Open' || dashboardAfter.status !== 'Closed') throw new Error('School dashboard totals/status disagree with final cash close');
  pass('SCHOOL FEE PASS: ' + v.width + ': public category selection, real cash POST with lost response, refresh recovery to same receipt, UUID-only storage/failure guard, closed-day recovery, persisted receipt/history, same-key retry, print CSS and reprint action; no fiscal submission and no physical printer claim');
}
