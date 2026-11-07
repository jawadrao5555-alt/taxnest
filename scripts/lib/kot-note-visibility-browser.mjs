/** Real disposable desktop/mobile journey; never sends a print job or sale. */
export async function kotNoteVisibilityWorkflow(originalPage,t,v,{baseUrl,waitForOperationalSurface,dismiss,saveEvidenceScreenshot,pass,openMobileCart,loopback,login,attachDiagnostics}) {
  // This journey proves a persisted server setting with an ordinary reload.
  // Isolate it from the cache-first page's separate fingerprint-refresh reload;
  // the general PWA/service-worker journeys remain enabled and unchanged.
  const context=await originalPage.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  await context.route('**/*',route=>loopback(new URL(route.request().url()).hostname)?route.continue():route.abort('blockedbyclient'));
  const page=await context.newPage(), diagnostics=attachDiagnostics(page);
  try {
  await login(page,t);
  const ticket=baseUrl+'/pos/restaurant/orders/'+t.kotNoteVisibility.orderId+'/kitchen-ticket';
  // Use the real settings form so each viewport independently reproduces OFF.
  await page.goto(baseUrl+'/pos/restaurant/kitchen-settings',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  const form=page.locator('form[action$="/pos/restaurant/kitchen-settings"]');
  const toggle=form.locator('input[type="checkbox"][name="kot_show_kitchen_notes"]');
  if(await toggle.isChecked())await toggle.locator('..').click();
  if(await toggle.isChecked())throw Error('Could not disable KOT order notes through the visible switch');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.locator('button[type="submit"]').click()]);
  const before=await page.request.get(ticket+'?auto_print=0');
  if(!before.ok()||(await before.text()).includes('Pack separately'))throw Error('KOT notes OFF reproduction failed');

  // Let the initial real boot fingerprint request finish before changing its
  // setting. Otherwise the 1.5s boot check can reload halfway through this flow.
  const boot=page.waitForResponse(r=>new URL(r.url()).pathname==='/pos/api/boot-check'&&r.request().method()==='GET');
  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
  const bootResponse=await boot;
  if(!bootResponse.ok()||(await bootResponse.json()).ok!==true)throw Error('Initial POS boot check did not succeed');
  await openMobileCart(page,v,['Current Order']);
  await page.locator('[data-kot-note-toggle]:visible').click();
  await page.locator('textarea[name="pos_cart_note_nofill"]:visible').fill('Pack separately');
  const status=page.locator('[data-kot-note-status]:visible').first();
  await status.getByText('Order notes are OFF on kitchen slips.',{exact:false}).waitFor();
  const [response]=await Promise.all([
    page.waitForResponse(r=>new URL(r.url()).pathname==='/pos/restaurant/kitchen-settings/notes/enable'&&r.request().method()==='POST'),
    status.locator('[data-kot-note-enable]').click(),
  ]);
  if(!response.ok()||(await response.json()).enabled!==true)throw Error('Explicit enable failed');
  await status.getByText('Order notes are ON for new kitchen slips.',{exact:true}).waitFor();
  const after=await page.request.get(ticket+'?auto_print=0');
  if(!after.ok()||!(await after.text()).includes('Pack separately'))throw Error('Original KOT note remains missing after enable');
  await saveEvidenceScreenshot(page,'kot-note-enabled-'+v.width+'.png');
  await page.reload({waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
  await openMobileCart(page,v,['Current Order']);
  // The draft survives reload; explicitly open the editor if its fold was reset.
  const editor=page.locator('textarea[name="pos_cart_note_nofill"]');
  if(!await editor.isVisible())await page.locator('[data-kot-note-toggle]:visible').click();
  await editor.fill('Pack separately');
  await page.locator('[data-kot-note-status]:visible').first().getByText('Order notes are ON for new kitchen slips.',{exact:true}).waitFor();
  const unexpectedConsole=diagnostics.consoleErrors.filter(x=>!x.includes('ERR_BLOCKED_BY_CLIENT'));
  const unexpectedRequests=diagnostics.failedRequests.filter(x=>!x.includes('ERR_BLOCKED_BY_CLIENT'));
  if(diagnostics.pageErrors.length||unexpectedConsole.length||unexpectedRequests.length||diagnostics.httpErrors.length)
    throw Error('KOT note journey diagnostics failed: '+JSON.stringify({page:diagnostics.pageErrors,console:unexpectedConsole,requests:unexpectedRequests,http:diagnostics.httpErrors}));
  pass(t.name+'/'+v.width+': real OFF reproduction, visible warning, explicit enable, persisted network reload and original kitchen-ticket note rendered; no physical print');
  } finally {await context.close();}
}
