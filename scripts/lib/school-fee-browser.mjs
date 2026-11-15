import { privateCollectionCacheCheck } from './collection-private-cache-browser.mjs';
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

  await schoolWorkspaceChecks(page, v, { baseUrl, saveEvidenceScreenshot });

  const legacyId = t.legacyInvoices?.[v.width];
  if (!legacyId) throw new Error('Missing synthetic historical retail invoice fixture');
  const otherLegacyId = t.legacyInvoices?.[v.width === 390 ? 1366 : 390];
  const otherLegacy = await page.request.get(baseUrl + '/fbr-pos/school-fees/legacy-retail/' + otherLegacyId, { headers: { Accept: 'application/json' }, maxRedirects: 0 });
  const otherLegacyBody = await otherLegacy.text();
  if (![403,404].includes(otherLegacy.status()) || otherLegacyBody.includes('SYN-SCHOOL-LEGACY-') || otherLegacyBody.includes('SYNTHETIC-LEGACY-') || otherLegacyBody.includes('Synthetic legacy test item')) throw new Error('Other-company legacy receipt exposed through direct URL');
  await assertPrivateReceiptHtmlRedirect(page,baseUrl,'/fbr-pos/school-fees/legacy-retail/'+otherLegacyId);
  const deniedHtml = await page.request.get(baseUrl + '/fbr-pos/school-fees/legacy-retail/' + otherLegacyId, { headers: { Accept: 'text/html' }, maxRedirects: 0 });
  if (deniedHtml.status() !== 302 || new URL(deniedHtml.headers().location, baseUrl).pathname !== '/fbr-pos/dashboard' || (await deniedHtml.text()).includes('SYN-SCHOOL-LEGACY-')) throw new Error('Denied legacy HTML did not safely return to own panel');
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
  // Configure real school controls before taking the cash baseline.
  const campusName = 'Synthetic Campus ' + v.width;
  const customLabel = 'Synthetic Transport ' + v.width;
  await page.goto(baseUrl + '/fbr-pos/school-fees/settings', { waitUntil: 'domcontentloaded' });
  for (const name of [campusName, campusName + ' Annex']) {
    const campus = page.locator('#school-add-campus');
    await campus.locator('[name="name"]').fill(name);
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), campus.locator('button[type="submit"]').click()]);
  }
  const headForm = page.locator('#school-add-head');
  await headForm.locator('[name="label"]').fill(customLabel);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), headForm.locator('button[type="submit"]').click()]);
  await page.goto(baseUrl + '/fbr-pos/school-fees', { waitUntil: 'domcontentloaded' });
  const switcher = page.locator('#school-campus-switcher');
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), switcher.locator('select').selectOption({ label: campusName })]);
  const branchKey = await page.locator('#school-collection [name="expected_branch_key"]').inputValue();
  if (branchKey === '0') throw new Error('Configured campus did not bind collection');
  const feePeriod = await page.locator('#school-collection [name="fee_period"]').inputValue();
  if (!/^\d{4}-\d{2}$/.test(feePeriod)) throw new Error('School fee period default is invalid');
  await page.locator('#school-collection [name="fee_head"]').selectOption({ label: customLabel });
  const feeHead = await page.locator('#school-collection [name="fee_head"]').inputValue();
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
    if (await page.locator('#school-campus-switcher select option').count() !== 2) throw new Error('Configured campus selector does not contain both authorized campuses');
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
  await form.locator('[name="fee_period"]').fill(feePeriod);
  await form.locator('[name="amount"]').fill('1.00');
  await form.locator('[name="cash_confirmed"]').check();
  await form.getByRole('button', { name: 'Record fee payment', exact: true }).click();
  await page.locator('#school-recovery-notice').filter({ hasText: 'No new request was sent' }).waitFor();
  if (storageFailurePosts !== 0 || !await form.getByRole('button', { name: 'Record fee payment', exact: true }).isDisabled()) {
    throw new Error('Storage failure did not fail closed before collection');
  }
  page.off('request', countStorageFailurePost);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await form.waitFor();
  const reference = 'SYN-' + v.width + '-' + randomUUID().slice(0, 8);
  const key = await form.locator('[name="request_key"]').inputValue();
  await form.locator('[name="student_reference"]').fill(reference);
  await form.locator('[name="fee_head"]').selectOption(feeHead);
  await form.locator('[name="fee_period"]').fill(feePeriod);
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
  await form.getByRole('button', { name: 'Record fee payment', exact: true }).click();
  await page.locator('#school-recovery-notice').filter({ hasText: 'Do not collect payment again' }).waitFor();
  if (!lostReceiptId) throw new Error('Response was not lost after a real persisted save');
  const pendingEntries = await page.evaluate(() => Object.entries(sessionStorage).filter(([name]) => name.startsWith('taxnest.school.pending.')));
  if (pendingEntries.length !== 1 || pendingEntries[0][1] !== key
      || !/^taxnest\.school\.pending\.\d+\.\d+\.\d+$/.test(pendingEntries[0][0])) {
    throw new Error('Recovery storage must contain only a tenant/campus/user scoped UUID');
  }
  const pendingStorageKey = pendingEntries[0][0];
  await page.unroute(baseUrl + '/fbr-pos/school-fees', loseSavedResponse);
  const staleRecovery = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
  await staleRecovery;
  await page.reload({ waitUntil: 'domcontentloaded' });
  await form.waitFor();
  if (await form.locator('[name="request_key"]').inputValue() !== key) throw new Error('Refresh replaced the uncertain saved payment key');
  await page.locator('#school-recovery-notice').filter({ hasText: 'Do not collect payment again' }).waitFor();
  await form.locator('[name="student_reference"]').fill(reference);
  await form.locator('[name="fee_head"]').selectOption(feeHead);
  await form.locator('[name="fee_period"]').fill(feePeriod);
  await form.locator('[name="amount"]').fill('1250.50');
  await form.locator('[name="cash_confirmed"]').check();
  await saveEvidenceScreenshot(page, 'school-fee-lost-response-recovery-' + v.width);
  const posted = page.waitForResponse(response => new URL(response.url()).pathname === '/fbr-pos/school-fees'
    && response.request().method() === 'POST');
  await Promise.all([
    page.waitForURL(/\/fbr-pos\/school-fees\/\d+\/receipt$/, { waitUntil: 'domcontentloaded' }),
    form.getByRole('button', { name: 'Record fee payment', exact: true }).click(),
  ]);
  const save = await posted;
  if (save.status() !== 200) throw new Error('Cash recovery did not return its persisted receipt');
  const receiptPath = new URL(page.url()).pathname;
  const receiptId = Number(receiptPath.match(/\/(\d+)\/receipt$/)[1]);
  if (receiptId !== lostReceiptId) throw new Error('Refresh recovery created another receipt');
  if (await page.evaluate(name => sessionStorage.getItem(name), pendingStorageKey) !== null) throw new Error('Matching receipt did not clear pending recovery key');
  const assertReceipt = async () => {
    const body = await page.locator('body').innerText();
    for (const text of [reference, customLabel, feePeriod, campusName, v.width < 768 ? 'Synthetic Mobile School Owner' : 'Synthetic School Owner', 'Cash', 'PKR 1,250.50', 'LOCAL RECEIPT ONLY', 'Not submitted to FBR']) {
      if (!body.includes(text)) throw new Error('School receipt missing: ' + text);
    }
    if (await page.locator('article canvas,article svg,article img:not([data-school-logo])').count()) throw new Error('Local school receipt unexpectedly rendered a fiscal QR/image');
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
  const historyRow = page.locator('#school-receipt-history').getByRole('row').filter({ hasText: reference });
  if (await historyRow.count() !== 1) throw new Error('Persisted school collection missing or duplicated in history');
  if (await form.locator('[name="request_key"]').inputValue() === key) throw new Error('New collection reused the previous request key');
  const csrf = await form.locator('[name="_token"]').inputValue();
  const replay = await page.request.post(baseUrl + '/fbr-pos/school-fees', {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    form: { request_key: key, student_reference: reference, fee_head: feeHead, fee_period: feePeriod, amount: '1250.50', payment_method: 'cash', expected_branch_key: branchKey },
  });
  const replayed = await replay.json();
  if (!replay.ok() || !replayed.success || Number(replayed.receipt_id) !== receiptId) throw new Error('Lost-response retry did not return the same persisted receipt');
  // A saved-but-lost response may be retried, but changing its immutable
  // payment details must return a clear 422 without changing the receipt.
  const originalReceipt = await (await page.request.get(baseUrl + receiptPath)).text();
  for (const change of [{ fee_period: '2000-01' }, { amount: '999.00' }, { fee_head: 'exam' }, { student_reference: reference + '-CHANGED' }]) {
    const conflict = await page.request.post(baseUrl + '/fbr-pos/school-fees', {
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      form: { request_key: key, student_reference: reference, fee_head: feeHead, fee_period: feePeriod, amount: '1250.50', payment_method: 'cash', expected_branch_key: branchKey, ...change },
    });
    const rejected = await conflict.json();
    if (conflict.status() !== 422 || !rejected.errors?.request_key?.[0]?.includes('different details')) {
      throw new Error('Changed saved-payment retry did not return clear 422 conflict');
    }
    const unchanged = await (await page.request.get(baseUrl + receiptPath)).text();
    if (unchanged !== originalReceipt) throw new Error('Rejected retry changed the saved receipt');
  }
  await page.reload({ waitUntil: 'domcontentloaded' });
  if (await page.locator('#school-receipt-history').getByRole('row').filter({ hasText: reference }).count() !== 1) throw new Error('Retry duplicated school cash collection');
  await page.locator('#school-receipt-history').getByRole('row').filter({ hasText: reference }).getByRole('link', { name: 'SF-' + receiptId, exact: true }).click();
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
  await page.goto(baseUrl + '/fbr-pos/transactions?search=' + encodeURIComponent(reference), { waitUntil: 'domcontentloaded' });
  if (await page.getByRole('row').filter({ hasText: reference }).count() !== 1) throw new Error('Receipt search missed collection');
  await page.goto(baseUrl + '/fbr-pos/transactions?search=NO-MATCH-SYNTHETIC', { waitUntil: 'domcontentloaded' });
  if (await page.getByRole('row').filter({ hasText: reference }).count()) throw new Error('Receipt search ignored filter');
  await reportTotals('/fbr-pos/day-close');
  const closeForm = page.locator('#school-close-form');
  await closeForm.waitFor();
  const closeUrl = new URL(await closeForm.getAttribute('action'), baseUrl).href;
  await closeForm.locator('[name="counted_cash"]').fill('1250.00');
  await closeForm.locator('[name="variance_reason"]').fill('Synthetic counting difference');
  await closeForm.locator('[name="handover_to"]').fill('Synthetic supervisor');
  await closeForm.locator('[name="handover_note"]').fill('Synthetic sealed cash bag');
  const closeFields = await closeForm.locator('input').evaluateAll(inputs => Object.fromEntries(
    inputs.filter(input => input.name).map(input => [input.name, input.value])));
  await closeForm.locator('[name="confirm"]').check();
  await Promise.all([
    page.waitForResponse(response => response.url() === closeUrl && response.request().method() === 'POST'),
    closeForm.getByRole('button', { name: 'Confirm day close', exact: true }).click(),
  ]);
  await page.locator('#school-close-status').filter({ hasText: 'Closed' }).waitFor();
  const closedTotals = await reportTotals('/fbr-pos/day-close');
  if (Number(await page.locator('#school-counted-total').getAttribute('data-paisa')) !== 125000
      || Number(await page.locator('#school-variance').getAttribute('data-paisa')) !== -50) throw new Error('Counted school cash/variance snapshot incorrect');
  const postCloseRetry = await page.request.post(baseUrl + '/fbr-pos/school-fees', {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    form: { request_key: key, student_reference: reference, fee_head: feeHead, fee_period: feePeriod, amount: '1250.50', payment_method: 'cash', expected_branch_key: branchKey },
  });
  if (!postCloseRetry.ok() || Number((await postCloseRetry.json()).receipt_id) !== receiptId) throw new Error('Saved retry failed after final close');
  const repeatedClose = await page.request.post(closeUrl, {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, form: { ...closeFields, confirm: '1' },
  });
  if (!repeatedClose.ok() || !(await repeatedClose.json()).success) throw new Error('Repeated final close failed');
  for (const change of [{ request_key: randomUUID(), cash_confirmed: '1' }, { amount: '999.00' }, { fee_head: 'exam' }, { student_reference: reference + '-CHANGED' }]) {
    const blocked = await page.request.post(baseUrl + '/fbr-pos/school-fees', {
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      form: { request_key: key, student_reference: reference, fee_head: feeHead, fee_period: feePeriod, amount: '1250.50', payment_method: 'cash', expected_branch_key: branchKey, ...change },
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
      || await form.getByRole('button', { name: 'Record fee payment', exact: true }).isDisabled()) {
    throw new Error('Closed desk stranded the known saved-request recovery');
  }
  await form.locator('[name="student_reference"]').fill(reference);
  await form.locator('[name="fee_head"]').selectOption(feeHead);
  await form.locator('[name="fee_period"]').fill(feePeriod);
  await form.locator('[name="amount"]').fill('1250.50');
  await form.locator('[name="cash_confirmed"]').check();
  await Promise.all([
    page.waitForURL('**' + receiptPath),
    form.getByRole('button', { name: 'Record fee payment', exact: true }).click(),
  ]);
  if (await page.evaluate(name => sessionStorage.getItem(name), pendingStorageKey) !== null) throw new Error('Closed-day matching receipt did not clear recovery');
  await page.goto(baseUrl + '/fbr-pos/school-fees', { waitUntil: 'domcontentloaded' });
  if (await form.locator('[name="request_key"]').inputValue() === key
      || !await form.getByRole('button', { name: 'Record fee payment', exact: true }).isDisabled()) {
    throw new Error('Closed day enabled a fresh collection without a pending saved key');
  }
  const dashboardAfter = await dashboardTotals();
  if (dashboardAfter.count !== dashboardBefore.count + 1 || dashboardAfter.paisa !== dashboardBefore.paisa + 125050
      || dashboardBefore.status !== 'Open' || dashboardAfter.status !== 'Closed') throw new Error('School dashboard totals/status disagree with final cash close');
  await schoolLedgerChecks(page, v, { baseUrl, saveEvidenceScreenshot }, { campusName, receiptPath, feePeriod });
  for(const path of ['/fbr-pos/school-fees','/fbr-pos/dashboard']) await privateCollectionCacheCheck(page, { baseUrl, path, screenshotName:'school-offline-private-cache-'+path.split('/').pop()+'-'+v.width, saveEvidenceScreenshot });
  pass('SCHOOL FEE PASS: ' + v.width + ': campus setup/custom fee head/month and cashier snapshots, receipt search/counted-cash variance, school-only settings and no-store/BFCache recovery, working logo/contact and 58mm/A4/80mm preview, multi-head bank partial/card balance with lost-response explicit recovery and changed-payload rejection, noncash exclusion from cash total, cross-campus receipt refusal, public category selection, real cash POST with lost response, refresh recovery to same receipt, UUID-only storage/failure guard, closed-day recovery, persisted receipt/history, same-key retry, print CSS and reprint action; no fiscal submission and no physical printer claim');
}


async function schoolWorkspaceChecks(page, v, { baseUrl, saveEvidenceScreenshot }) {
  for (const path of ['/fbr-pos/school-fees', '/fbr-pos/school-fees/settings', '/fbr-pos/school-fees/printing']) {
    const response = await page.goto(baseUrl + path, { waitUntil: 'domcontentloaded' });
    if (!response?.ok()) throw new Error('School workspace failed: ' + path);
    if (!(response.headers()['cache-control'] || '').includes('no-store')) throw new Error('School workspace is cacheable: ' + path);
    await page.locator('#school-fiscal-status').waitFor();
    const body = await page.locator('body').innerText();
    if (/FBR ON|FBR CERTIFIED|automatically enable/i.test(body)) throw new Error('School workspace has misleading fiscal status');
    if (await page.locator('a[href="/fbr-pos/kitchen"],a[href="/fbr-pos/products"],a[href="/fbr-pos/wholesale"],a[href="/fbr-pos/delivery"]').count()) throw new Error('School workspace leaked retail operations');
    if (await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth)) > v.width + 2) throw new Error('School workspace overflow: ' + path);
    await saveEvidenceScreenshot(page, 'school-workspace-' + path.split('/').pop() + '-' + v.width);
  }
  for(const language of ['ur','rur','en']) {
    await page.locator('#school-workspace-menu summary').click();
    await page.locator('#school-language').selectOption(language);
    const languageForm=page.locator('#school-language').locator('..');
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),languageForm.locator('button').click()]);
    if(await page.locator('#school-language').inputValue()!==language || await page.locator('html').getAttribute('lang')!==(language==='ur'?'ur':'en')) throw new Error('school language preference did not apply');
    if(language==='ur') await saveEvidenceScreenshot(page,'school-urdu-workspace-'+v.width);
  }
  await schoolPrintingChecks(page, v, { baseUrl, saveEvidenceScreenshot });
  await page.goto(baseUrl + '/fbr-pos/school-fees', { waitUntil: 'domcontentloaded' });
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
  await navigation;
  if (new URL(page.url()).pathname !== '/fbr-pos/school-fees') throw new Error('Stale School workspace recovered to retail');
  await page.locator('#school-fiscal-status').waitFor();
}


async function schoolPrintingChecks(page, v, { baseUrl, saveEvidenceScreenshot }) {
  await page.goto(baseUrl + '/fbr-pos/school-fees/printing', { waitUntil: 'domcontentloaded' });
  const form = page.locator('#school-print-settings');
  await form.locator('[name="phone"]').fill('000-000-0000');
  await form.locator('[name="address"]').fill('Synthetic campus address');
  await form.locator('[name="printer_label"]').fill('Synthetic front desk printer');
  await form.locator('[name="show_contact"]').check();
  await form.locator('[name="show_logo"]').check();
  await form.locator('[name="logo"]').setInputFiles({name:'synthetic-logo.png', mimeType:'image/png', buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aO2kAAAAASUVORK5CYII=', 'base64')});
  for (const paper of ['58mm', 'a4', '80mm']) {
    await form.locator('[name="paper"]').selectOption(paper);
    await Promise.all([page.waitForNavigation({ waitUntil:'domcontentloaded' }), form.locator('button[type="submit"]').click()]);
    if (await form.locator('[name="paper"]').inputValue() !== paper || await form.locator('[name="printer_label"]').inputValue() !== 'Synthetic front desk printer') throw new Error('School printing preferences did not persist');
    const popupPromise = page.waitForEvent('popup');
    await page.locator('#school-preview-link').click();
    const preview = await popupPromise;
    await preview.waitForLoadState('domcontentloaded');
    if (await preview.locator('article').getAttribute('data-paper') !== paper) throw new Error('School preview ignored paper preference');
    const body = await preview.locator('body').innerText();
    for (const value of ['DEMO-001', '000-000-0000', 'Synthetic campus address', 'LOCAL RECEIPT ONLY']) if (!body.includes(value)) throw new Error('School preview missing '+value);
    await preview.locator('[data-school-logo]').waitFor();
    await preview.waitForFunction(() => { const img=document.querySelector('[data-school-logo]'); return img?.complete && img.naturalWidth > 0; });
    await preview.emulateMedia({media:'print'});
    await saveEvidenceScreenshot(preview, 'school-receipt-preview-'+paper+'-'+v.width);
    await preview.close();
  }
}

async function schoolLedgerChecks(page, v, { baseUrl, saveEvidenceScreenshot }, {campusName, receiptPath, feePeriod}) {
  await page.goto(baseUrl+'/fbr-pos/school-fees', {waitUntil:'domcontentloaded'});
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}), page.locator('#school-campus-switcher select').selectOption({label:campusName+' Annex'})]);
  const otherCampus = await page.request.get(baseUrl+receiptPath, {headers:{Accept:'application/json'},maxRedirects:0});
  const otherCampusBody = await otherCampus.text();
  if (otherCampus.status() !== 404 || otherCampusBody.includes('student_reference') || otherCampusBody.includes('SF-') || otherCampusBody.includes(campusName)) throw new Error('Other-campus receipt opened through direct URL');
  await assertPrivateReceiptHtmlRedirect(page,baseUrl,receiptPath);
  const form=page.locator('#school-collection');
  const ref='SYN-MULTI-'+v.width;
  await form.locator('[name="student_reference"]').fill(ref);
  await form.locator('[name="student_name"]').fill('Synthetic learner '+v.width);
  await form.locator('[name="amount"]').fill('500.00');
  await form.locator('[name="payment_method"]').selectOption('bank');
  await page.locator('#school-multi-mode').check();
  await page.locator('.school-line-head').first().selectOption('tuition');
  await page.locator('.school-line-amount').first().fill('1000.00');
  await page.locator('#school-add-line').click();
  await page.locator('.school-line-head').nth(1).selectOption('exam');
  await page.locator('.school-line-amount').nth(1).fill('500.00');
  await form.locator('[name="cash_confirmed"]').check();
  await saveEvidenceScreenshot(page,'school-multihead-bank-entry-'+v.width);
  await Promise.all([page.waitForURL(/\/fbr-pos\/school-fees\/\d+\/receipt$/),form.locator('button[type="submit"]').click()]);
  const firstPath=new URL(page.url()).pathname;
  const firstHtml = await (await page.request.get(baseUrl+firstPath)).text();
  const first=await page.locator('article').innerText();
  if(!/bank/i.test(await page.locator('#school-receipt-method').innerText())) throw new Error('Bank receipt has wrong payment method');
  for (const value of [ref,'1,500.00','500.00','1,000.00']) if(!first.includes(value)) throw new Error('Partial multi-head receipt missing '+value);
  await saveEvidenceScreenshot(page,'school-multihead-bank-receipt-'+v.width);
  await page.goto(baseUrl+'/fbr-pos/school-fees?student_search='+ref,{waitUntil:'domcontentloaded'});
  const balance=page.locator('tr').filter({hasText:ref}).filter({has:page.locator('.school-pay-balance')});
  if(await balance.count()!==1) throw new Error('Student lookup did not return exactly one outstanding fee account');
  await balance.locator('.school-pay-balance').click();
  await form.locator('[name="amount"]').fill('1000.00');
  await form.locator('[name="payment_method"]').selectOption('card');
  await form.locator('[name="cash_confirmed"]').check();
  const originalPayload = await form.evaluate(f => Object.fromEntries(new FormData(f)));
  const feeRows = await page.locator('.school-line').evaluateAll(rows => rows.map(r => ({ fee_head:r.querySelector('select').value,amount:r.querySelector('input').value })));
  feeRows.forEach((line,i) => { originalPayload[`fee_lines[${i}][fee_head]`]=line.fee_head; originalPayload[`fee_lines[${i}][amount]`]=line.amount; });
  let installmentReceipt;
  const loseInstallment = async route => {
    if(route.request().method()!=='POST') return route.continue();
    const response=await route.fetch(); const saved=await response.json();
    if(!response.ok()||!saved.success) throw new Error('Installment lost-response setup did not save');
    installmentReceipt=Number(saved.receipt_id); await route.abort('blockedbyclient');
  };
  await page.route(baseUrl+'/fbr-pos/school-fees',loseInstallment);
  await form.locator('button[type="submit"]').click();
  await page.locator('#school-recovery-notice').filter({hasText:'Do not collect payment again'}).waitFor();
  await page.unroute(baseUrl+'/fbr-pos/school-fees',loseInstallment);
  await page.reload({waitUntil:'domcontentloaded'});
  let recoveryPosts=0;
  const countRecoveryPosts=req=>{if(req.method()==='POST'&&new URL(req.url()).pathname==='/fbr-pos/school-fees')recoveryPosts++;};
  page.on('request',countRecoveryPosts);
  await Promise.all([page.waitForURL('**/fbr-pos/school-fees/'+installmentReceipt+'/receipt'),page.locator('#school-recover-payment').click()]);
  page.off('request',countRecoveryPosts);
  if(recoveryPosts!==0) throw new Error('Saved installment recovery submitted another payment');
  if(new URL(page.url()).pathname===firstPath) throw new Error('Second installment reused first receipt');
  const replay=await page.request.post(baseUrl+'/fbr-pos/school-fees',{headers:{Accept:'application/json'},form:originalPayload});
  if(!replay.ok()||Number((await replay.json()).receipt_id)!==installmentReceipt) throw new Error('Exact installment retry did not return same receipt');
  for(const change of [{payment_method:'cash'},{account_id:''},{account_id:String(Number(originalPayload.account_id)+1)},{'fee_lines[0][amount]':'999.00'}]) {
    const conflict=await page.request.post(baseUrl+'/fbr-pos/school-fees',{headers:{Accept:'application/json'},form:{...originalPayload,...change}});
    if(conflict.status()!==422 || !(await conflict.json()).errors?.request_key) throw new Error('Changed installment retry was not clearly rejected');
  }
  if(!/card/i.test(await page.locator('#school-receipt-method').innerText())) throw new Error('Card receipt has wrong payment method');
  await saveEvidenceScreenshot(page,'school-card-balance-paid-'+v.width);
  if(await (await page.request.get(baseUrl+firstPath)).text() !== firstHtml) throw new Error('Later installment changed first partial receipt snapshot');
  if(Number((await page.locator('#school-receipt-balance').innerText()).replace(/[^0-9.]/g,'')) !== 0) throw new Error('Final card installment did not settle balance');
  await page.goto(baseUrl+'/fbr-pos/day-close',{waitUntil:'domcontentloaded'});
  if(Number(await page.locator('#school-cash-total').getAttribute('data-paisa'))!==0) throw new Error('Recorded bank/card payments contaminated cash close total');
  await saveEvidenceScreenshot(page,'school-noncash-cash-total-'+v.width);
}


async function assertPrivateReceiptHtmlRedirect(page,baseUrl,path) {
  // HTML not-found is intentionally a safe same-panel redirect; do not mistake
  // its followed dashboard200 for authorization to read a private receipt.
  const response=await page.request.get(baseUrl+path,{headers:{Accept:'text/html'},maxRedirects:0});
  const location=response.headers().location;
  if(response.status()!==302 || !location || new URL(location,baseUrl).origin!==new URL(baseUrl).origin || new URL(location,baseUrl).pathname!=='/fbr-pos/dashboard') throw new Error('Denied receipt HTML did not redirect safely within its own panel');
}
