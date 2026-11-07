/** Real disposable desktop/mobile journey; never sends a print job or sale. */
export async function kotNoteVisibilityWorkflow(page,t,v,{baseUrl,waitForOperationalSurface,dismiss,saveEvidenceScreenshot,pass,openMobileCart}) {
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

  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});
  await waitForOperationalSurface(page); await dismiss(page);
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
  pass(t.name+'/'+v.width+': real OFF reproduction, visible warning, explicit enable, persisted reload and original kitchen-ticket note rendered; no physical print');
}
