export async function billingSelectionWorkflow(page, t, v, {baseUrl, saveEvidenceScreenshot, pass}) {
  const f=t.billingSelection[v.width];
  await page.goto(baseUrl+'/billing/plans',{waitUntil:'domcontentloaded'});
  const original='Current Plan: '+f.original;
  if(!(await page.locator('body').innerText()).includes(original))throw Error('Original paid package missing before selection');
  const select=page.locator('form[action="/billing/subscribe"]').filter({has:page.locator('input[name="plan_id"][value="'+f.planId+'"]')});
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),select.getByRole('button').click()]);
  const panel=page.locator('section[aria-labelledby="selected-package-payment"]');
  await panel.waitFor({state:'visible'});
  if(!(await page.locator('body').innerText()).includes(original))throw Error('Selecting unpaid package replaced existing package');
  await panel.getByLabel('Amount you paid (PKR)',{exact:true}).fill('1');
  await panel.getByLabel('Transaction reference',{exact:true}).fill('SYNTHETIC-SELECTION-'+v.width);
  await panel.getByLabel('Payment receipt (JPG, PNG or PDF; up to 5 MB)',{exact:true}).setInputFiles({
    name:'synthetic-receipt.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n% Synthetic local receipt only\n%%EOF\n')
  });
  await saveEvidenceScreenshot(page,'billing-selection-'+v.width);
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),panel.getByRole('button',{name:'Submit receipt for review'}).click()]);
  if(!(await page.locator('body').innerText()).includes(original))throw Error('Pending receipt replaced current paid package');
  pass('BILLING SELECTION PASS: '+v.width+': actual package selection, labelled receipt upload and unchanged paid package pending review');
}
