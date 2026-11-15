// Only the model/card response is synthetic. Explicit confirmation reaches the real
// loopback controller/database; no provider or external support service is called.
export async function madadgarEscalationRecovery(page,f,v,{baseUrl,root,open,ask,saveEvidenceScreenshot,check}) {
  const messagePath='/fbr-pos/madadgar/message', escalationPath='/fbr-pos/madadgar/escalate';
  const card={title:'Synthetic retry acceptance '+v.width,summary:'Fictional local-only issue for duplicate confirmation proof.',kind:'problem'};
  const cardRoute=async route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({reply:'Synthetic confirmation card. No external model used.',escalation:card,remaining:29})});
  await page.route('**'+messagePath,cardRoute);
  await ask(page,'Synthetic escalation confirmation test');
  await page.unroute('**'+messagePath,cardRoute);
  // Use the component's exact confirmation action, independent of translated wording.
  const confirmButton=()=>root(page).locator('xpath=.//button[@*[name()="@click"]="confirmEscalation()"]');
  await confirmButton().waitFor({state:'visible'});
  let postCount=0,firstPayload,saved,resolveCommit,rejectCommit;
  const committed=new Promise((resolve,reject)=>{resolveCommit=resolve;rejectCommit=reject;});
  const drop=async route=>{
    postCount++;
    firstPayload=route.request().postDataJSON();
    try {
      const response=await route.fetch();
      check(response.ok(),'Synthetic first confirmation did not commit');
      saved=await response.json();
      check(Number.isInteger(saved.ref),'Saved escalation reference missing');
      await route.abort('blockedbyclient');
      resolveCommit();
    } catch(error) {rejectCommit(error);await route.abort('blockedbyclient');}
  };
  const watch=request=>{if(new URL(request.url()).pathname===escalationPath&&request.method()==='POST')postCount++;};
  await page.route('**'+escalationPath,drop);
  await confirmButton().click();
  await committed;
  await page.waitForFunction(()=>!window.Alpine.$data(document.querySelector('[x-data^="tnMadadgar("]')).busy);
  await page.unroute('**'+escalationPath,drop);
  page.on('request',watch);
  await open(page); // Full refresh in the same tab preserves the pending UUID, not an automatic POST.
  await confirmButton().waitFor({state:'visible'});
  await page.waitForTimeout(500);
  check(postCount===1,'Restoring pending confirmation automatically submitted');
  const pending=await root(page).evaluate(el=>window.Alpine.$data(el).pending);
  check(pending?.request_id===firstPayload.request_id,'Refresh did not retain original request UUID');
  await confirmButton().scrollIntoViewIfNeeded();
  await confirmButton().click({trial:true});
  await saveEvidenceScreenshot(page,'madadgar-retry-pending-'+v.width);
  const [retryResponse]=await Promise.all([
    page.waitForResponse(r=>new URL(r.url()).pathname===escalationPath&&r.request().method()==='POST'),
    confirmButton().click()
  ]);
  check(retryResponse.ok(),'Explicit retry failed');
  const retried=await retryResponse.json(),retryPayload=retryResponse.request().postDataJSON();
  check(retried.ref===saved.ref&&retried.reply===saved.reply,'Retry returned a different receipt/reference');
  check(retryPayload.request_id===firstPayload.request_id,'Retry changed its request ID');
  check(postCount===2,'Unexpected extra confirmation POST');
  await page.waitForFunction(()=>!window.Alpine.$data(document.querySelector('[x-data^="tnMadadgar("]')).busy);
  const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const post=payload=>page.context().request.post(baseUrl+escalationPath,{data:payload,headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},maxRedirects:0});
  for(const changed of [{title:'Changed title'},{summary:'Changed summary'},{kind:'feature_request'}]){
    const response=await post({...firstPayload,...changed});
    check(response.status()===409,'Changed escalation details were accepted');
  }
  const exact=await post({...firstPayload,company_id:f.foreignCompany});
  check(exact.ok()&&(await exact.json()).ref===saved.ref,'Client-supplied company changed original scope/reference');
  const history=await page.context().request.get(baseUrl+'/fbr-pos/madadgar/history?session_id='+firstPayload.session_id);
  const messages=(await history.json()).messages;
  check(messages.filter(m=>m.escalated&&m.content===saved.reply).length===1,'Saved confirmation message duplicated');
  await root(page).locator('[x-ref="mbody"]').evaluate(el=>{el.scrollTop=el.scrollHeight;});
  await saveEvidenceScreenshot(page,'madadgar-retry-recovered-'+v.width);
  page.off('request',watch);
  // Owner metadata must contain one suggestion reference, not an inferred solved state.
  await page.goto(baseUrl+'/fbr-pos/madadgar/usage',{waitUntil:'domcontentloaded'});
  check(await page.locator('li').filter({hasText:'Ref #'+saved.ref+' · pending'}).count()===1,'Escalation suggestion not single/pending in own company');
  return {sessionId:firstPayload.session_id,reply:saved.reply,ref:saved.ref};
}
