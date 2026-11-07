import {createHmac} from 'node:crypto';

export function authenticatorCode(secret, timestamp=Date.now()) {
  const alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  if(!/^[A-Z2-7]{32}$/.test(secret))throw Error('Invalid synthetic authenticator key');
  const bits=[...secret].map(c=>alphabet.indexOf(c).toString(2).padStart(5,'0')).join('');
  const key=Buffer.from(bits.match(/.{8}/g).map(b=>parseInt(b,2)));
  const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(timestamp/30000)));
  const mac=createHmac('sha1',key).update(counter).digest();
  return String((mac.readUInt32BE(mac[19]&15)&0x7fffffff)%1000000).padStart(6,'0');
}

export async function securityOnboardingWorkflow(page,t,v,{baseUrl,login,saveEvidenceScreenshot,pass,loopback}) {
  const fixture=t.securityOnboarding[v.width];
  const signIn=async(loginName,password=t.password)=>{
    await page.context().clearCookies();
    await login(page,{...t,login:loginName,password});
    await page.goto(baseUrl+'/onboarding',{waitUntil:'domcontentloaded'});
    await page.getByRole('heading',{name:"Let's set up your account",exact:true}).waitFor();
  };
  await signIn(fixture.incomplete);
  if(await page.locator('form[action="/onboarding/complete"]').count())throw Error('Incomplete setup exposes completion');
  const csrf=await page.locator('form[action="/onboarding/skip"] input[name="_token"]').inputValue();
  const rejected=await page.request.post(baseUrl+'/onboarding/complete',{form:{_token:csrf},maxRedirects:0});
  if(rejected.status()!==302||new URL(rejected.headers().location,baseUrl).pathname!=='/onboarding')throw Error('Incomplete setup bypassed validation');
  await page.reload({waitUntil:'domcontentloaded'});
  await page.getByRole('heading',{name:"Let's set up your account",exact:true}).waitFor();
  await saveEvidenceScreenshot(page,'rc-security-incomplete-'+v.width);
  await Promise.all([page.waitForURL(u=>u.pathname==='/dashboard'),page.getByRole('button',{name:'Skip Setup',exact:true}).click()]);
  await page.goto(baseUrl+'/onboarding',{waitUntil:'domcontentloaded'});
  if(new URL(page.url()).pathname!=='/dashboard')throw Error('Explicit owner skip was not retained');

  await signIn(fixture.ready);
  const complete=page.locator('form[action="/onboarding/complete"]');
  await complete.getByRole('button',{name:'Go to Dashboard',exact:true}).waitFor();
  await saveEvidenceScreenshot(page,'rc-security-ready-'+v.width);
  await Promise.all([page.waitForURL(u=>u.pathname==='/dashboard'),complete.getByRole('button',{name:'Go to Dashboard',exact:true}).click()]);
  await page.goto(baseUrl+'/onboarding',{waitUntil:'domcontentloaded'});
  if(new URL(page.url()).pathname!=='/dashboard')throw Error('Configured completion was not retained');

  // Two independent browser sessions. The reset context never signs in as
  // the owner and uses a seeded, fictional local-only reset link; no mail or
  // regulator request is needed to exercise the real reset form.
  await signIn(fixture.reset.email);
  const recoveryContext=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  await recoveryContext.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
  const recovery=await recoveryContext.newPage();
  const errors=[];
  const redirects=[];
  recovery.on('pageerror',e=>errors.push(e.message));
  recovery.on('response',r=>{if(r.status()>=400&&loopback(new URL(r.url()).hostname))errors.push('HTTP '+r.status()+' '+new URL(r.url()).pathname);});
  recovery.on('response',r=>{if(r.request().isNavigationRequest())redirects.push(r.status()+':'+new URL(r.url()).pathname);});
  try {
    await recovery.goto(baseUrl+'/reset-password-link?token='+encodeURIComponent(fixture.reset.token)+'&email='+encodeURIComponent(fixture.reset.email)+'&panel=di',{waitUntil:'domcontentloaded'});
    if(new URL(recovery.url()).pathname!=='/reset-password')throw Error('Reset form redirect failed: '+redirects.join(' -> '));
    await recovery.locator('input[name="password"]').fill(fixture.reset.newPassword);
    await recovery.locator('input[name="password_confirmation"]').fill(fixture.reset.newPassword);
    await Promise.all([recovery.waitForURL(u=>u.pathname==='/login'),recovery.locator('form[action$="/reset-password"] button[type="submit"]').click()]);
    await page.goto(baseUrl+'/onboarding',{waitUntil:'domcontentloaded'});
    if(new URL(page.url()).pathname!=='/login')throw Error('Old browser retained access after password recovery');
    await saveEvidenceScreenshot(page,'rc-security-reset-revoked-'+v.width);
    await login(page,{...t,login:fixture.reset.email,password:fixture.reset.newPassword});
    await page.goto(baseUrl+'/onboarding',{waitUntil:'domcontentloaded'});
    await page.getByRole('heading',{name:"Let's set up your account",exact:true}).waitFor();
    if(errors.length)throw Error('Reset browser errors: '+errors.join('; '));
  } finally {await recoveryContext.close();}
  const advanceRequests=[];
  const watchAdvance=request=>{if(request.method()==='POST'&&new URL(request.url()).pathname==='/compliance/audit-packs/'+fixture.reset.pendingPackId+'/advance')advanceRequests.push(true);};
  page.on('request',watchAdvance);
  await page.goto(baseUrl+'/compliance',{waitUntil:'domcontentloaded'});
  await page.getByText('Expired export — history retained',{exact:true}).waitFor();
  const expiredPack=fixture.reset.expiredPackId;
  if(await page.locator('a[href$="/audit-packs/'+expiredPack+'/download"]').count())throw Error('Expired export offered download');
  const expiredStatus=await page.request.get(baseUrl+'/compliance/audit-packs/'+expiredPack+'/status');
  if(expiredStatus.status()!==200)throw Error('Compliance GET removed export history');
  const retained=await expiredStatus.json();
  if(retained.status!=='expired'||retained.download_url!==null||retained.advance_url!==null)throw Error('Expired export status is misleading');
  const expiredDownload=await page.request.get(baseUrl+'/compliance/audit-packs/'+expiredPack+'/download',{maxRedirects:0});
  if(expiredDownload.status()!==410)throw Error('Expired export still downloadable');
  await page.locator('a[href$="/audit-packs/'+fixture.reset.pendingPackId+'/download"]').waitFor({state:'visible',timeout:90000});
  page.off('request',watchAdvance);
  if(!advanceRequests.length)throw Error('Workerless export did not use an explicit authenticated POST');
  const readyExport=await page.request.get(baseUrl+'/compliance/audit-packs/'+fixture.reset.pendingPackId+'/status');
  const readyData=await readyExport.json();
  if(readyData.status!=='ready'||readyData.processed!==1)throw Error('Explicit export progress did not finish its fictional invoice');
  await saveEvidenceScreenshot(page,'rc-security-read-only-export-'+v.width);
  // Opt in through actual forms. The fictional key/recovery values stay in
  // memory and never enter screenshots, log messages or evidence manifests.
  await page.goto(baseUrl+'/security/two-step/di',{waitUntil:'domcontentloaded'});
  await page.getByLabel('Current password',{exact:true}).fill(fixture.reset.newPassword);
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Set up two-step login',exact:true}).click()]);
  const key=(await page.locator('[data-two-step-setup-key]').innerText()).trim();
  const enrolledStep=Math.floor(Date.now()/30000);
  await page.getByLabel('Authenticator code',{exact:true}).fill(authenticatorCode(key));
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Confirm and enable',exact:true}).click()]);
  const codes=await page.locator('section[aria-labelledby="recovery-title"] li code').allTextContents();
  if(codes.length!==10)throw Error('Two-step setup did not issue recovery codes');
  await page.reload({waitUntil:'domcontentloaded'});
  if(await page.locator('section[aria-labelledby="recovery-title"]').count())throw Error('Recovery codes displayed twice');
  await saveEvidenceScreenshot(page,'rc-security-two-step-enabled-'+v.width);
  await page.context().clearCookies();
  await login(page,{...t,login:fixture.reset.email,password:fixture.reset.newPassword});
  await page.goto(baseUrl+'/onboarding',{waitUntil:'domcontentloaded'});
  if(new URL(page.url()).pathname!=='/security/two-step/di/challenge')throw Error('Password bypassed second factor');
  await saveEvidenceScreenshot(page,'rc-security-two-step-challenge-'+v.width);
  // Wait for a genuinely fresh step rather than replaying the setup code.
  const delay=(enrolledStep+1)*30000-Date.now()+150;
  if(delay>0)await page.waitForTimeout(delay);
  await page.getByLabel('Authenticator or recovery code',{exact:true}).fill(authenticatorCode(key));
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Verify login',exact:true}).click()]);
  if(new URL(page.url()).pathname!=='/dashboard')throw Error('Fresh authenticator code did not complete login');
  await page.context().clearCookies();
  await login(page,{...t,login:fixture.reset.email,password:fixture.reset.newPassword});
  await page.goto(baseUrl+'/security/two-step/di/challenge',{waitUntil:'domcontentloaded'});
  await page.getByLabel('Authenticator or recovery code',{exact:true}).fill(codes[0]);
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Verify login',exact:true}).click()]);
  if(new URL(page.url()).pathname!=='/dashboard')throw Error('Recovery code did not complete login');
  await page.context().clearCookies();
  await login(page,{...t,login:fixture.reset.email,password:fixture.reset.newPassword});
  await page.goto(baseUrl+'/security/two-step/di/challenge',{waitUntil:'domcontentloaded'});
  await page.getByLabel('Authenticator or recovery code',{exact:true}).fill(codes[0]);
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Verify login',exact:true}).click()]);
  await page.getByRole('alert').waitFor();
  if(new URL(page.url()).pathname!=='/security/two-step/di/challenge')throw Error('Recovery code replay accepted');
  await page.getByLabel('Authenticator or recovery code',{exact:true}).fill(codes[1]);
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Verify login',exact:true}).click()]);
  await page.goto(baseUrl+'/security/two-step/di',{waitUntil:'domcontentloaded'});
  await page.getByLabel('Current password',{exact:true}).fill(fixture.reset.newPassword);
  await page.getByLabel('Authenticator or recovery code',{exact:true}).fill(codes[2]);
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Turn off two-step login',exact:true}).click()]);
  await page.getByRole('button',{name:'Set up two-step login',exact:true}).waitFor();

  // A password reset performed from the SaaS company page must revoke an
  // already-open Local Bills Viewer browser. Use two genuinely independent
  // contexts so this proves cached-session refusal rather than a logout in
  // the administrator's own browser.
  const viewerFixture=fixture.viewer;
  const viewerContext=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  const adminContext=await page.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  for(const context of [viewerContext,adminContext]) {
    await context.route('**/*',r=>loopback(new URL(r.request().url()).hostname)?r.continue():r.abort('blockedbyclient'));
  }
  const viewerPage=await viewerContext.newPage();
  const adminPage=await adminContext.newPage();
  try {
    await login(viewerPage,{...t,loginPath:'/pos/login',login:viewerFixture.email,password:t.password});
    await viewerPage.goto(baseUrl+'/pos/local-bills',{waitUntil:'domcontentloaded'});
    if(new URL(viewerPage.url()).pathname!=='/pos/local-bills')throw Error('Synthetic Local Bills Viewer could not open its read-only portal');

    await login(adminPage,{...t,loginPath:'/admin/login',login:viewerFixture.admin,password:t.password});
    await adminPage.goto(baseUrl+'/admin/companies/'+viewerFixture.companyId,{waitUntil:'domcontentloaded'});
    const editForm=adminPage.locator('form[action$="/local-viewer/'+viewerFixture.userId+'"]');
    await editForm.waitFor({state:'attached'});
    await editForm.locator('input[name="password"]').fill(viewerFixture.newPassword);
    await Promise.all([adminPage.waitForNavigation(),editForm.evaluate(form=>form.requestSubmit())]);

    await viewerPage.goto(baseUrl+'/pos/local-bills',{waitUntil:'domcontentloaded'});
    if(new URL(viewerPage.url()).pathname!=='/pos/login')throw Error('Old Local Bills Viewer browser survived an administrator password reset');
    await login(viewerPage,{...t,loginPath:'/pos/login',login:viewerFixture.email,password:viewerFixture.newPassword});
    await viewerPage.goto(baseUrl+'/pos/local-bills',{waitUntil:'domcontentloaded'});
    if(new URL(viewerPage.url()).pathname!=='/pos/local-bills')throw Error('Fresh Local Bills Viewer login failed after password reset');
    await saveEvidenceScreenshot(viewerPage,'rc-security-viewer-revoked-'+v.width);
  } finally {
    await viewerContext.close();
    await adminContext.close();
  }

  await page.context().clearCookies();
  await login(page,{...t,loginPath:'/admin/login',login:fixture.billingViewer,password:t.password});
  await page.goto(baseUrl+'/admin/subscriptions',{waitUntil:'domcontentloaded'});
  await page.getByRole('heading',{name:'Subscriptions',exact:true}).waitFor();
  if(await page.locator('form[action*="/subscriptions/assign"],form[action$="/toggle"]').count())throw Error('Viewer offered subscription write form');
  const billingCsrf=await page.locator('form[action$="/admin/logout"] input[name="_token"]').first().inputValue();
  const forbidden=await page.request.post(baseUrl+'/admin/subscriptions/assign',{form:{_token:billingCsrf},maxRedirects:0});
  if(forbidden.status()!==403)throw Error('Viewer billing POST not refused');
  await saveEvidenceScreenshot(page,'rc-security-billing-viewer-'+v.width);
  await page.goto(baseUrl+'/admin/plans',{waitUntil:'domcontentloaded'});
  await page.getByRole('heading',{name:'Subscription Plans',exact:true}).waitFor();
  if(await page.locator('form[action*="/admin/plans"]').count())throw Error('Viewer offered package write form');
  await page.context().clearCookies();
  await login(page,{...t,loginPath:'/admin/login',login:fixture.supportAdmin,password:t.password});
  await page.goto(baseUrl+'/admin/support-cases/'+fixture.supportCaseId,{waitUntil:'domcontentloaded'});
  await page.getByRole('heading',{name:new RegExp('^Case #'+fixture.supportCaseId)}).waitFor();
  const build='rc-security-'+v.width;
  await page.getByLabel('Resolution build / release',{exact:true}).fill(build);
  await page.getByLabel('Resolution evidence',{exact:true}).fill('Synthetic desktop/mobile support acceptance passed.');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Resolve explicitly',exact:true}).click()]);
  await page.getByText('RESOLVED',{exact:true}).waitFor();
  if(await page.getByText(build,{exact:true}).count()<2)throw Error('Support resolution build was not retained in case and timeline');
  await page.getByLabel('Reopen evidence',{exact:true}).fill('Synthetic recurrence received after explicit resolution.');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Reopen case',exact:true}).click()]);
  await page.getByText('REOPENED',{exact:true}).waitFor();
  if(await page.getByText('Synthetic recurrence received after explicit resolution.',{exact:true}).count()!==1)throw Error('Support reopen evidence missing from timeline');
  await saveEvidenceScreenshot(page,'rc-security-support-case-'+v.width);
  pass('SECURITY ONBOARDING PASS: '+v.width+': incomplete setup refused, explicit skip, configured completion, actual password form and independent old-session revocation/fresh login; optional two-step enrollment, fresh authenticator login, single-use recovery and disable; Local Bills Viewer password reset revokes the old browser and permits a fresh login; durable support ownership, explicit resolution build and reopen timeline');
}
