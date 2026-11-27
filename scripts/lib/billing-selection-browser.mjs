export async function billingSelectionWorkflow(page, t, v, {baseUrl, saveEvidenceScreenshot, pass}) {
  const f=t.billingSelection[v.width];
  await page.goto(baseUrl+'/billing/plans',{waitUntil:'domcontentloaded'});
  const legacy=page.locator('[data-billing-policy-summary][data-policy-version=legacy]');
  await legacy.locator('[data-policy-legacy]').waitFor({state:'visible'});
  if(await legacy.locator('time').count())throw Error('Legacy contract displays invented policy dates');
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
  await packageCheckoutWorkflow(page,t,v,{baseUrl,saveEvidenceScreenshot,pass,original});
  await signupFormsWorkflow(page,t,v,{baseUrl,saveEvidenceScreenshot,pass});
  pass('BILLING SELECTION PASS: '+v.width+': actual package selection, labelled receipt upload and unchanged paid package pending review');
}

export async function signupFormsWorkflow(existingPage,t,v,{baseUrl,saveEvidenceScreenshot,pass}) {
  for(const [product,path] of [['di','/register'],['pos','/pos/register'],['fbrpos','/fbr-pos/register'],['erps','/health/register']]) {
    const context=await existingPage.context().browser().newContext({viewport:v});
    await context.route('**/*',r=>new URL(r.request().url()).origin===new URL(baseUrl).origin?r.continue():r.abort('blockedbyclient'));
    try {
      const page=await context.newPage();
      const entry=await page.goto(baseUrl+path,{waitUntil:'domcontentloaded'});
      if(!entry?.ok()||new URL(page.url()).pathname!==path)throw Error(product+' synthetic signup entry unavailable: HTTP '+entry?.status()+' path '+new URL(page.url()).pathname);
      const form=page.locator('form[method="POST"]').filter({has:page.locator('input[name="company_name"]')});
      const submitsPlanId = product === 'pos' || product === 'erps';
      const field = submitsPlanId ? 'pricing_plan_id' : 'requested_plan';
      const picker=form.locator('select[name="'+field+'"]');
      if(!await picker.evaluate(el=>el.required))throw Error(product+' signup package is not required');
      await picker.selectOption('');
      if(await picker.evaluate(el=>el.checkValidity()))throw Error(product+' signup accepted missing package');
      const chosen=t.billingSelection[v.width].publicPlans[product];
      const chosenValue=submitsPlanId ? String(chosen.id) : chosen.name;
      const available=await picker.locator('option').evaluateAll(options=>options.map(o=>o.value));
      if(!available.includes(chosenValue))throw Error(product+' synthetic signup package is not in the actual server catalogue');
      await picker.selectOption(chosenValue);
      if(!await picker.evaluate(el=>el.checkValidity()))throw Error(product+' selected package remains invalid');
      await form.locator('[data-prospective-signup-policy]').waitFor({state:'visible'});
      if(product==='fbrpos'&&!await form.locator('button[type="submit"]').isEnabled())throw Error('Quoted FBR package signup button incorrectly disabled');
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
      if (new URLSearchParams(response.request().postData() || '').get(field) !== chosenValue) {
        throw Error(product+' signup did not submit the selected package in '+field);
      }
      const location=response.headers().location||'';
      if(response.status()!==302||!location||new URL(location,baseUrl).pathname===path)
        throw Error('Actual '+product+' signup did not complete at '+v.width);
      if(product==='di'&&new URL(page.url()).pathname!=='/login')throw Error('DI signup must retain pending-approval login flow');
      await saveEvidenceScreenshot(page,'signup-'+product+'-'+v.width);
      pass('SIGNUP FORMS PASS: '+product+' '+v.width+': actual required package selection, labelled registration and existing pending-approval redirect; prospective trial/document invariants checked separately in HTTP/native probes');
    } finally { await context.close(); }
  }
}

async function packageCheckoutWorkflow(page,t,v,{baseUrl,saveEvidenceScreenshot,pass,original}) {
  const f=t.billingSelection[v.width];
  await page.goto(baseUrl+'/billing/checkout?plan_id='+f.planId,{waitUntil:'domcontentloaded'});
  const form=page.locator('[data-checkout-plan="'+f.planId+'"]');
  const choice=form.locator('select[name="billing_cycle"]');
  await choice.selectOption('annual');
  const displayed=(await choice.locator('option:checked').innerText()).split('PKR ')[1];
  if(!displayed)throw Error('Checkout did not display an exact payable total');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button').click()]);
  const quote=page.locator('#selected-package-quote');
  if(!(await quote.innerText()).includes('PKR '+displayed))throw Error('Saved quote differs from selected payable total');
  await quote.getByLabel('Transaction reference',{exact:true}).fill('SYNTHETIC-CHECKOUT-'+v.width);
  await quote.getByLabel('Payment receipt (JPG, PNG or PDF; up to 5 MB)',{exact:true}).setInputFiles({name:'synthetic-checkout.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n% Synthetic checkout only\n%%EOF\n')});
  await saveEvidenceScreenshot(page,'package-checkout-'+v.width);
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),quote.getByRole('button',{name:'Submit receipt for review'}).click()]);
  await page.goto(baseUrl+'/billing/plans',{waitUntil:'domcontentloaded'});
  if(!(await page.locator('body').innerText()).includes(original))throw Error('Checkout receipt replaced paid package before approval');
  pass('PACKAGE CHECKOUT PASS: '+v.width+': chosen total, frozen quote, synthetic upload, unchanged paid package');
}
