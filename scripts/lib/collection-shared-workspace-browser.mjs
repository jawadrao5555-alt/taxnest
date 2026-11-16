// Synthetic owner-only shared workspace proof. No live company/user is used.
export async function collectionSharedWorkspaceChecks(page, kind, v, {baseUrl, saveEvidenceScreenshot}, campusLabel=null) {
  for(const path of ['/fbr-pos/team','/fbr-pos/my-profile','/fbr-pos/billing','/fbr-pos/suggestions']) {
    const response=await page.goto(baseUrl+path,{waitUntil:'domcontentloaded'});
    if(!response?.ok() || new URL(page.url()).pathname!==path) throw new Error(kind+' shared workspace did not open its exact path: '+path);
    if(!(response.headers()['cache-control']||'').includes('no-store')) throw new Error(kind+' shared workspace response cacheable');
    await page.locator('#'+kind+'-fiscal-status').waitFor();
    if(await page.locator('a[href="/fbr-pos/products"],a[href="/fbr-pos/kitchen"],a[href="/fbr-pos/delivery"],a[href="/fbr-pos/wholesale"]').count()) throw new Error(kind+' shared workspace leaked retail operations');
    if(await page.evaluate(()=>Math.max(document.documentElement.scrollWidth,document.body.scrollWidth))>v.width+2) throw new Error(kind+' shared workspace overflow: '+path);
    await saveEvidenceScreenshot(page,kind+'-shared-'+path.split('/').pop()+'-'+v.width);
  }
  await page.goto(baseUrl+'/fbr-pos/team',{waitUntil:'domcontentloaded'});
  const email='synthetic-'+kind+'-cashier-'+v.width+'@rc-browser.invalid';
  const create=page.locator('form[action$="/fbr-pos/team"]');
  await create.locator('[name="name"]').fill('Synthetic '+kind+' cashier '+v.width);
  await create.locator('[name="email"]').fill(email);
  await create.locator('[name="password"]').fill('SyntheticOnly123!');
  await create.locator('[name="pos_role"]').selectOption('pos_cashier');
  if(campusLabel) await create.locator('[name="default_branch_id"]').selectOption({label:campusLabel});
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),create.locator('button[type="submit"]').click()]);
  const row=page.locator('[data-pilot-team-member]').filter({hasText:email});
  if(await row.count()!==1) throw new Error(kind+' synthetic cashier creation failed');
  await row.locator('summary').click();
  const edit=page.locator('form').filter({has:page.locator(`input[name="email"][value="${email}"]`)});
  await edit.locator('[name="name"]').waitFor({state:'visible'});
  const savedName=await edit.locator('[name="name"]').inputValue();
  await edit.locator('[name="name"]').fill('Unsaved synthetic edit');
  await edit.getByRole('button',{name:'Cancel',exact:true}).click();
  await edit.waitFor({state:'hidden'});
  await page.reload({waitUntil:'domcontentloaded'});
  if((await row.innerText()).includes('Unsaved synthetic edit')) throw new Error(kind+' cancelled team edit persisted');
  await row.locator('summary').click();
  await edit.locator('[name="name"]').fill(savedName+' updated');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),edit.locator('button[type="submit"]').click()]);
  if(!(await row.innerText()).includes(savedName+' updated')) throw new Error(kind+' team edit did not persist');
  await row.locator('summary').click();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),row.getByRole('button',{name:'Deactivate',exact:true}).click()]);
  await row.locator('summary').click();
  await row.getByRole('button',{name:'Activate',exact:true}).waitFor();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),row.getByRole('button',{name:'Activate',exact:true}).click()]);
  await row.locator('summary').click();
  await row.getByRole('button',{name:'Deactivate',exact:true}).waitFor();
  await saveEvidenceScreenshot(page,kind+'-team-edit-active-'+v.width);
}
