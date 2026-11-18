export async function reviewedDeviceProofWorkflow(page,t,v,{baseUrl,login,waitForOperationalSurface,saveEvidenceScreenshot,pass,loopback}) {
  const f=t.reviewedDeviceProof[v.width];
  const path='/admin/companies/'+f.companyId+'/device-proofs';
  // These are only explicit denial probes; same session and CSRF, exact status asserted.
  // Positive review/revoke continue through real browser forms and normal diagnostics.
  const send=async(target,url,data)=>{
    const csrf=await target.locator('meta[name="csrf-token"]').getAttribute('content');
    const response=await target.request.post(new URL(url,baseUrl).href,{headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf},data});
    return response.status();
  };
  const assert=(ok,msg)=>{if(!ok)throw Error(msg);};
  await page.goto(baseUrl+path,{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);
  await page.getByText('Without reviewed Production evidence',{exact:false}).waitFor();
  assert(await page.locator('[data-proof-id]').count()===0,'Fresh synthetic company unexpectedly has reviewed proof');
  const form=page.locator('form[action$="/device-proofs"]');
  await form.getByLabel('Device fingerprint',{exact:true}).fill('Synthetic-Browser-Device-'+v.width);
  await form.getByLabel('POS ID',{exact:true}).fill('SYN-'+v.width);
  await form.getByLabel('Non-secret evidence reference',{exact:true}).fill('Synthetic browser fixture, not real device acceptance');
  await form.getByLabel('Evidence SHA-256',{exact:true}).fill('a'.repeat(64));
  await form.getByLabel('Environment',{exact:true}).selectOption('sandbox');
  await form.getByLabel('Review findings',{exact:true}).fill('Synthetic independent review flow only; no central verification or fiscal transmission.');
  await form.locator('input[type="checkbox"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button',{name:'Record reviewed proof'}).click()]);
  await waitForOperationalSurface(page);
  const row=page.locator('[data-proof-id]').first();const id=await row.getAttribute('data-proof-id');
  await row.getByText('Reviewed evidence recorded; not central verification',{exact:true}).waitFor();
  await saveEvidenceScreenshot(page,'reviewed-device-proof-approved-'+v.width+'.png');
  const cross=await send(page,'/admin/companies/'+f.foreignCompanyId+'/device-proofs/'+id+'/revoke',{reason:'Synthetic cross-company attempt'});
  assert(cross===404,'Cross-company proof revoked');
  await row.getByLabel('Revocation reason',{exact:true}).fill('Synthetic evidence revoked after review');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),row.getByRole('button',{name:'Revoke proof'}).click()]);
  await waitForOperationalSurface(page);
  assert(await page.locator('[data-proof-id]').count()===2,'Revocation did not retain append-only review history');
  assert(await page.locator('[data-proof-current-state]').filter({hasText:'HOLD: no current reviewed evidence'}).count()===2,'Revoked scope does not show HOLD');
  const stale=await send(page,path+'/'+id+'/revoke',{reason:'Synthetic stale open form replay'});
  assert(stale===409,'Stale revoked form replay not rejected');
  await saveEvidenceScreenshot(page,'reviewed-device-proof-revoked-'+v.width+'.png');
  const ctx=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  await ctx.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
  try{
    const support=await ctx.newPage();await login(support,{...t,login:f.support});
    const refused=await send(support,path,{device_uid:'Synthetic-forged',pos_id:'SYN',environment:'sandbox',evidence_reference:'Synthetic unprivileged payload',evidence_sha256:'a'.repeat(64),review_note:'Forged self-reported review rejected',independent_evidence_reviewed:true,reviewer_role:'super_admin'});
    assert(refused===403,'Non-superadmin recorded reviewed device proof');
  }finally{await ctx.close();}
  pass('reviewed-device-proof/'+v.width+': default HOLD, synthetic superadmin review, append-only revoke, stale form409, foreign404, non-superadmin403; no real device/central acceptance claim');
}
