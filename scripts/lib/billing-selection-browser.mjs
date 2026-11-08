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
  await signupFormsWorkflow(page,t,v,{baseUrl,saveEvidenceScreenshot,pass});
  pass('BILLING SELECTION PASS: '+v.width+': actual package selection, labelled receipt upload and unchanged paid package pending review');
}

async function signupFormsWorkflow(existingPage,t,v,{baseUrl,saveEvidenceScreenshot,pass}) {
  for(const [product,path] of [['di','/register'],['pos','/pos/register'],['fbrpos','/fbr-pos/register']]) {
    const context=await existingPage.context().browser().newContext({viewport:{width:v.width,height:900}});
    try {
      const page=await context.newPage();
      await page.goto(baseUrl+path,{waitUntil:'domcontentloaded'});
      const form=page.locator('form[method="POST"]').filter({has:page.locator('input[name="company_name"]')});
      if(product==='pos')await form.getByText('Starter',{exact:true}).click();
      for(const [name,value] of Object.entries({company_name:'Fictional signup '+product+' '+v.width,
        company_ntn:'910'+String(v.width).padStart(4,'0'),name:'Fictional signup owner',
        email:'signup-'+product+'-'+v.width+'@rc-browser.invalid',password:t.password,password_confirmation:t.password})) {
        await form.locator('input[name="'+name+'"]').fill(value);
      }
      const [response]=await Promise.all([
        page.waitForResponse(r=>new URL(r.url()).pathname===path&&r.request().method()==='POST'),
        page.waitForNavigation({waitUntil:'domcontentloaded'}),
        form.locator('button[type="submit"]').click()
      ]);
      const location=response.headers().location||'';
      if(response.status()!==302||!location||new URL(location,baseUrl).pathname===path)
        throw Error('Actual '+product+' signup did not complete at '+v.width);
      if(product==='di'&&new URL(page.url()).pathname!=='/login')throw Error('DI signup must retain pending-approval login flow');
      await saveEvidenceScreenshot(page,'signup-'+product+'-'+v.width);
      pass('SIGNUP FORMS PASS: '+product+' '+v.width+': actual labelled registration and existing pending-approval redirect; trial/document invariants checked separately in HTTP/native probes');
    } finally { await context.close(); }
  }
}
