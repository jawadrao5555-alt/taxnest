export async function taxIntegrationsWorkflow(page,t,v,{baseUrl,waitForOperationalSurface,dismiss,saveEvidenceScreenshot,pass}) {
  const data=t.taxIntegrations[v.width];
  await page.goto(baseUrl+'/pos/customize',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
  await page.getByRole('link',{name:/Tax Integrations/}).click();
  for(const text of ['PRA POS','FBR POS · IMS','FBR Digital Invoice'])await page.getByRole('heading',{name:text,exact:true}).waitFor();
  await saveEvidenceScreenshot(page,'tax-integrations-hub-'+v.width);
  await page.getByRole('link',{name:'Digital Invoice configuration',exact:true}).click();
  await page.getByLabel('Enable DI alongside PRA',{exact:true}).selectOption('1');
  await page.getByLabel('DI environment',{exact:true}).selectOption('sandbox');
  await page.getByLabel('FBR Registration No',{exact:true}).fill('1234567');
  await page.getByLabel('FBR Business Name',{exact:true}).fill('Synthetic federal name');
  await page.locator('#sandbox-token').fill('synthetic-di-browser-token');
  if(!(await page.locator('#sandbox-url').inputValue()).endsWith('postinvoicedata_sb'))throw Error('Sandbox URL missing');
  if(!(await page.locator('#production-url').inputValue()).endsWith('postinvoicedata'))throw Error('Production URL missing');
  await page.locator('[name="confirm_sandbox_setup"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Save DI setup',exact:true}).click()]);
  if(!(await page.locator('body').innerText()).includes('DI settings saved.'))throw Error('DI configuration failed');
  await page.locator('#sandbox-token').waitFor();if(await page.locator('#sandbox-token').inputValue())throw Error('Saved token displayed');
  await saveEvidenceScreenshot(page,'tax-integrations-di-config-'+v.width);
  await page.getByRole('link',{name:'Tax Integrations',exact:true}).click();
  await page.getByRole('link',{name:'FBR POS configuration',exact:true}).click();
  await page.getByLabel('Enable FBR POS alongside PRA',{exact:true}).selectOption('1');
  await page.getByLabel('Active environment',{exact:true}).selectOption('sandbox');
  await page.getByLabel('IMS connection',{exact:true}).selectOption('cloud');
  await page.getByLabel('Sandbox POS ID',{exact:true}).fill('12345');
  await page.locator('#sandbox-token').fill('synthetic-ims-browser-token');
  await page.locator('[name="confirm_setup"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Save FBR POS setup',exact:true}).click()]);
  if(!(await page.locator('body').innerText()).includes('FBR POS settings saved.'))throw Error('IMS configuration failed');
  await page.getByLabel('Completed POS bill',{exact:true}).selectOption(String(data.bill));
  await page.locator('[name="confirm_copy"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Prepare IMS record',exact:true}).click()]);
  const payload=JSON.parse(await page.getByLabel('Exact IMS JSON payload',{exact:true}).inputValue());
  if(payload.TotalTaxCharged!==null)throw Error('PRA tax copied into federal tax');
  for(const line of payload.Items){line.PCTCode='12345678';line.TaxRate=18;line.TaxCharged=Math.round(line.SaleValue*18)/100;line.TotalAmount=line.SaleValue+line.TaxCharged;}
  payload.TotalTaxCharged=payload.Items.reduce((sum,l)=>sum+l.TaxCharged,0);payload.TotalBillAmount=payload.TotalSaleValue+payload.TotalTaxCharged;
  await page.getByLabel('Exact IMS JSON payload',{exact:true}).fill(JSON.stringify(payload,null,2));
  await page.locator('[name="mapping_reviewed"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Save payload for review',exact:true}).click()]);
  if(!(await page.locator('body').innerText()).includes('IMS payload saved.'))throw Error('IMS mapping failed');
  const review=page.url();
  for(const width of [58,80]){
    await page.getByRole('link',{name:width+'mm receipt preview',exact:true}).click();
    const body=await page.locator('body').innerText();if(!body.includes('SANDBOX TEST COPY')||!body.includes('PRA')||!body.includes('FBR POS'))throw Error('Combined preview missing authority/environment');
    if(await page.locator('[alt="FBR IMS acknowledgement QR"]').count())throw Error('Unaccepted invoice has FBR QR');
    await saveEvidenceScreenshot(page,'tax-integrations-ims-preview-'+width+'-'+v.width);
    await page.goto(review,{waitUntil:'domcontentloaded'});
  }
  await page.locator('[name="confirm_connection"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Refresh connection and review again',exact:true}).click()]);
  const reset=JSON.parse(await page.getByLabel('Exact IMS JSON payload',{exact:true}).inputValue());
  if(reset.TotalTaxCharged!==null||reset.TotalSaleValue!==payload.TotalSaleValue||reset.USIN!==payload.USIN)throw Error('Explicit connection refresh lost source values or retained a stale mapping');
  await page.goto(baseUrl+'/pos/di-sandbox/setup',{waitUntil:'domcontentloaded'});
  if(!(await page.locator('body').innerText()).includes('Disabled'))throw Error('Both federal channels stayed enabled');
  if(!(await page.locator('label[for="sandbox-token"]').innerText()).includes('Saved'))throw Error('DI token lost when selecting IMS');
  await page.goto(baseUrl+'/pos/receipt-settings',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
  if(await page.locator('main a[href*="di-sandbox"],main a[href*="tax-integrations"]').count())throw Error('Integration configuration remains in Receipt Display');
  pass(t.name+'/'+v.width+': Customize → three integration cards → save independent DI URLs/tokens → select IMS without resetting DI → review original bill → 58/80mm combined previews; receipt settings has no connection setup. No fiscal dispatch.');
}
