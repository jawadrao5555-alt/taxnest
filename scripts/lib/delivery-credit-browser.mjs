// Fictional disposable delivery decisions only; no external regulator or rider app.
export async function deliveryCreditWorkflow(page,t,v,{baseUrl,login,dismiss,waitForOperationalSurface,saveEvidenceScreenshot,pass,loopback}) {
  const fixture=t.deliveryCredit[v.width];
  const context=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  await context.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
  const rider=await context.newPage();
  try {
    await login(rider,{name:'synthetic-credit-rider',login:fixture.riderLogin,password:t.password,loginPath:'/pos/login'});
    await rider.goto(baseUrl+'/pos/rider',{waitUntil:'domcontentloaded'}); await waitForOperationalSurface(rider); await dismiss(rider);
    for(const mode of ['received','credit']) {
      const form=rider.locator(`form[action$="/pos/rider/deliveries/${fixture.ids[mode]}/delivered"]`);
      await form.waitFor();
      const options=await form.locator('[name="received_amount"] option').evaluateAll(nodes=>nodes.map(n=>Number(n.value)));
      if(JSON.stringify(options)!==JSON.stringify([100,0]))throw new Error('Rider decision must offer full amount or full credit only');
      const payload=await form.locator('input').evaluateAll(nodes=>Object.fromEntries(nodes.filter(n=>n.name).map(n=>[n.name,n.value])));
      payload.received_amount=mode==='credit'?'0':'100';
      const endpoint=baseUrl+`/pos/rider/deliveries/${fixture.ids[mode]}/delivered`;
      const partial=await rider.request.post(endpoint,{headers:{Accept:'application/json'},form:{...payload,received_amount:'50'},maxRedirects:0});
      if(partial.status()!==422)throw new Error('Rider accepted a partial delivery payment');
      await form.locator('[name="received_amount"]').selectOption(payload.received_amount);
      await saveEvidenceScreenshot(rider,'delivery-'+mode+'-decision-'+v.width);
      await Promise.all([rider.waitForNavigation({waitUntil:'domcontentloaded'}),form.locator('button[type="submit"]').click()]);
      const replay=await rider.request.post(endpoint,{headers:{Accept:'application/json'},form:payload,maxRedirects:0});
      if(replay.status()!==302)throw new Error('Exact delivered decision replay failed');
      const changed=await rider.request.post(endpoint,{headers:{Accept:'application/json'},form:{...payload,received_amount:mode==='credit'?'100':'0'},maxRedirects:0});
      if(changed.status()!==409)throw new Error('Changed delivered decision was not rejected');
    }
    const forbidden=await rider.request.get(baseUrl+'/pos/delivery-accounts',{headers:{Accept:'application/json'},maxRedirects:0});
    const target=new URL(forbidden.headers().location||'',baseUrl);
    if(forbidden.status()!==302||target.origin!==new URL(baseUrl).origin||target.pathname!=='/pos/rider')
      throw new Error('Rider confinement did not redirect to its own portal: '+forbidden.status());
    const deniedBody=await forbidden.text();
    if(deniedBody.includes('SYN-CREDIT-PRIVATE-')||deniedBody.includes('Synthetic credit customer'))throw new Error('Rider denial leaked recovery ledger data');
    await rider.goto(target.href,{waitUntil:'domcontentloaded'});
    if(new URL(rider.url()).pathname!=='/pos/rider'||await rider.locator('form[action*="/delivery-accounts/"]').count())throw new Error('Rider redirect exposed owner recovery controls');
  } finally {await context.close();}
  await page.goto(baseUrl+'/pos/delivery-accounts',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
  const article=page.locator('article').filter({hasText:'Synthetic credit customer '+v.width});
  if(await article.count()!==1 || !(await article.innerText()).includes('Balance Rs. 100.00'))throw new Error('Full credit did not create exactly one retained receivable');
  if(await page.locator('article').filter({hasText:'Synthetic received customer '+v.width}).count())throw new Error('Full received delivery incorrectly created customer debt');
  const assertPrivate=async()=>{
    const body=await page.locator('body').innerText();
    if(body.includes('SYN-CREDIT-PRIVATE-')||body.includes('Synthetic private invoice item'))throw new Error('Recovery ledger exposes source invoice details');
  };
  await assertPrivate();
  const collect=article.locator('form');
  const endpoint=await collect.getAttribute('action');
  let payload=await collect.locator('input').evaluateAll(nodes=>Object.fromEntries(nodes.filter(n=>n.name).map(n=>[n.name,n.value])));
  payload.amount='40.00';
  // Remove only this fictional local source bill. The retained financial ledger
  // must still support owner recovery and must not re-expose deleted item detail.
  const csrf=payload._token;
  const deleted=await page.request.post(baseUrl+`/pos/api/provisional-bills/${fixture.ids.credit}/delete`,{headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},data:{}});
  if(!deleted.ok()||!(await deleted.json()).success)throw new Error('Synthetic local bill cleanup failed');
  await page.reload({waitUntil:'domcontentloaded'});
  await assertPrivate();
  await article.locator('[name="amount"]').fill('40.00');
  payload=await article.locator('form input').evaluateAll(nodes=>Object.fromEntries(nodes.filter(n=>n.name).map(n=>[n.name,n.value])));
  // The actual UI request key is replayed below without creating a second recovery.
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),article.getByRole('button',{name:'Record recovery',exact:true}).click()]);
  if(!(await article.innerText()).includes('Balance Rs. 60.00'))throw new Error('Owner recovery did not reduce retained balance');
  const replay=await page.request.post(endpoint,{headers:{Accept:'application/json'},form:payload,maxRedirects:0});
  if(replay.status()!==302)throw new Error('Recovery replay failed');
  const changed=await page.request.post(endpoint,{headers:{Accept:'application/json'},form:{...payload,amount:'41.00'},maxRedirects:0});
  if(changed.status()!==409)throw new Error('Changed recovery retry was not rejected');
  await page.reload({waitUntil:'domcontentloaded'});
  if(!(await article.innerText()).includes('Balance Rs. 60.00') || await article.getByText(/Recovered.*40\.00/).count()!==1)throw new Error('Recovery retry duplicated financial history');
  await assertPrivate();
  await saveEvidenceScreenshot(page,'delivery-credit-retained-recovery-'+v.width);
  pass('DELIVERY CREDIT PASS '+v.width+': full received/credit, partial rejection, exact/changed decision retries, owner-only retained recovery after synthetic source cleanup, no duplicate events or source invoice detail');
}
