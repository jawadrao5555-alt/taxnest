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
  if(payload.Items.some(line=>line.PCTCode!=='00000000'))throw Error('Manual IMS no-catalogue PCT default missing');
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
  await page.goto(baseUrl+'/pos/tax-integrations/automatic',{waitUntil:'domcontentloaded'});
  if(await page.locator('#auto-enabled').inputValue()!=='0')throw Error('Automatic reporting must start OFF');
  await page.locator('#auto-enabled').selectOption('1');await page.locator('#auto-hs').fill('1234.5678');await page.locator('#auto-rate').fill('18');
  await page.locator('[name="all_items_confirmed"]').check();await page.locator('[name="mapping_reviewed"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Save automatic reporting',exact:true}).click()]);
  if(await page.locator('#auto-enabled').inputValue()!=='1')throw Error('Reviewed mapping did not save');
  if(!(await page.locator('body').innerText()).includes('No automatic reports yet.'))throw Error('Enabling reporting backfilled historical bills');
  await saveEvidenceScreenshot(page,'automatic-federal-mapping-'+v.width);
  await page.getByLabel('Reviewed PCT code (optional)',{exact:true}).fill('');
  await page.locator('#auto-rate').fill('');
  await page.locator('[name="all_items_confirmed"]').check();await page.locator('[name="mapping_reviewed"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Save automatic reporting',exact:true}).click()]);
  if(await page.locator('#auto-enabled').inputValue()!=='1'||await page.locator('#auto-hs').inputValue()!=='')throw Error('IMS blank catalogue PCT did not save');
  if(await page.locator('#auto-rate').inputValue()!=='')throw Error('Blank IMS rate was silently changed to a numeric rate');
  if(!(await page.locator('body').innerText()).includes('No automatic reports yet.'))throw Error('Blank-code setup backfilled historical bills');
  await saveEvidenceScreenshot(page,'automatic-ims-without-catalogue-pct-'+v.width);
  await page.locator('#auto-enabled').selectOption('0');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Save automatic reporting',exact:true}).click()]);
  if(await page.locator('#auto-enabled').inputValue()!=='0')throw Error('Automatic reporting pause failed');
  await page.getByRole('link',{name:'Tax Integrations',exact:true}).click();
  await page.getByRole('link',{name:'FBR POS configuration',exact:true}).click();
  await page.getByLabel('Completed POS bill',{exact:true}).selectOption(String(data.legacyBill));
  await page.locator('[name="confirm_copy"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Prepare IMS record',exact:true}).click()]);
  if(!(await page.locator('body').innerText()).includes('Original bill amounts were recovered'))throw Error('Complete stored original bill could not be recovered');
  if(!(await page.locator('body').innerText()).includes('No submission.'))throw Error('Recovery unexpectedly dispatched a fiscal invoice');
  await saveEvidenceScreenshot(page,'ims-stored-original-recovery-'+v.width);
  await page.goto(baseUrl+'/pos/tax-integrations/fbr-pos/'+data.portal.id,{waitUntil:'domcontentloaded'});
  await page.getByRole('heading',{name:'Recover an invoice already visible in FBR',exact:true}).waitFor();
  const fillPortal=async total=>{
    for(const [label,value] of [['FBR invoice number from portal',data.portal.reference],['Portal POS ID','12345'],
      ['Portal USIN / original bill number',data.portal.usin],['Portal invoice date',data.portal.date],
      ['Portal sale value','100'],['Portal tax charged','16'],['Portal total amount',total],['Portal total quantity','2'],
      ['Evidence note (report date and how you checked)','Synthetic FBR portal fixture; all invoice fields independently checked.']])
      await page.getByLabel(label,{exact:true}).fill(value);
    await page.getByLabel('Portal environment',{exact:true}).selectOption('sandbox');
    await page.getByLabel('Portal invoice type',{exact:true}).selectOption('1');
    await page.locator('[name="confirm_portal"]').check();
  };
  await fillPortal('999');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Save verified portal evidence — do not resend',exact:true}).click()]);
  if(!(await page.locator('body').innerText()).includes('Portal identity and amounts must match'))throw Error('Mismatched portal total was accepted');
  await fillPortal('116');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Save verified portal evidence — do not resend',exact:true}).click()]);
  const reconciled=await page.locator('body').innerText();
  if(!reconciled.includes('FBR portal evidence recorded. No invoice was sent.')||!reconciled.includes(data.portal.reference))throw Error('Portal reference not recovered');
  if(await page.locator('[name="confirm_portal"]').count())throw Error('Recovered invoice still offers reconciliation');
  await saveEvidenceScreenshot(page,'ims-portal-recovery-'+v.width);
  await page.getByRole('link',{name:'80mm receipt preview',exact:true}).click();
  await page.getByAltText('FBR IMS acknowledgement QR',{exact:true}).waitFor();
  if(!(await page.locator('body').innerText()).includes(data.portal.reference))throw Error('Recovered receipt lacks FBR reference');
  await saveEvidenceScreenshot(page,'ims-portal-recovered-receipt-'+v.width);
  await page.goto(baseUrl+'/pos/transaction/'+data.dualBill+'/receipt',{waitUntil:'domcontentloaded'});
  await page.locator('.dual-fiscal').waitFor();
  for(const [kind,reference] of [['PRA','SYN-PRA-'+v.width],['FBR','SYN-FBR-'+v.width]]){
    const image=page.getByAltText(kind+' fiscal QR',{exact:true});
    await image.evaluate(img=>img.decode());
    const number=page.locator(kind==='PRA'?'.pra-fiscal-reference':'.fbr-fiscal-reference');
    if(await number.innerText()!==reference)throw Error('Incorrect '+kind+' acknowledgement on original receipt');
    const a=await image.boundingBox(),b=await number.boundingBox();if(b.y<a.y+a.height-1)throw Error(kind+' reference is not beneath its QR');
  }
  const cells=await page.locator('.dual-fiscal td').evaluateAll(els=>els.map(e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right};}));
  if(cells.length!==2||Math.abs(cells[0].y-cells[1].y)>1||cells[0].right>cells[1].x+1)throw Error('Fiscal QR cells are not side by side');
  await saveEvidenceScreenshot(page,'original-dual-fiscal-receipt-'+v.width);
  pass(t.name+'/'+v.width+': Customize → three integration cards → save independent DI URLs/tokens → select IMS without resetting DI → review original bill → 58/80mm combined previews; receipt settings has no connection setup. Portal recovery rejects mismatched amounts and restores the synthetic receipt acknowledgement. No fiscal dispatch.');
}
