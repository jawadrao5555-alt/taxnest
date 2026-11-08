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

  // The owner-facing delete control must create a verified company request,
  // never delete only the owner's login or silently remove business records.
  await page.goto(baseUrl+'/profile',{waitUntil:'domcontentloaded'});
  await page.getByRole('button',{name:'Request Company Deletion',exact:true}).click();
  await page.getByLabel('Reason (optional)',{exact:true}).fill('Synthetic browser lifecycle verification only.');
  await page.locator('form[action$="/profile"] input[name="password"]').fill(fixture.reset.newPassword);
  await Promise.all([
    page.waitForNavigation(),
    page.locator('form[action$="/profile"]').getByRole('button',{name:'Submit Request',exact:true}).click(),
  ]);
  await page.locator('main .bg-green-50')
    .filter({hasText:'Company deletion request recorded and password-verified.'})
    .first().waitFor();
  await page.getByText(/Request #\d+ is already recorded \(pending review\)\./).waitFor();
  await page.goto(baseUrl+'/onboarding',{waitUntil:'domcontentloaded'});
  if(new URL(page.url()).pathname==='/login')throw Error('Company deletion request removed the owner login or company immediately');
  await saveEvidenceScreenshot(page,'rc-security-deletion-request-'+v.width);

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
    const editForm=adminPage.locator('form[x-show="edit"][action$="/local-viewer/'+viewerFixture.userId+'"]');
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
  await page.goto(baseUrl+'/admin/all-users',{waitUntil:'domcontentloaded'});
  await page.getByText('User and company assignments are read-only for this account.',{exact:false}).waitFor();
  if(await page.locator('#addUserModal,form[action="/admin/all-users"]').count())throw Error('Viewer offered cross-company user provisioning controls');
  await saveEvidenceScreenshot(page,'rc-security-user-history-viewer-'+v.width);
  await page.goto(baseUrl+'/admin/plans',{waitUntil:'domcontentloaded'});
  await page.getByRole('heading',{name:'Subscription Plans',exact:true}).waitFor();
  if(await page.locator('form[action*="/admin/plans"]').count())throw Error('Viewer offered package write form');
  await page.goto(baseUrl+'/admin/sales',{waitUntil:'domcontentloaded'});
  await page.getByText('Sale history is read-only for this account.',{exact:true}).waitFor();
  if(await page.locator('form[action*="/admin/sales"]').count())throw Error('Viewer offered sale release controls');
  await page.goto(baseUrl+'/admin/consultants',{waitUntil:'domcontentloaded'});
  await page.getByText('Consultant, link and payout history is read-only for this account.',{exact:true}).waitFor();
  if(await page.locator('form[action*="/admin/consultant"]').count())throw Error('Viewer offered consultant or payout controls');
  await page.goto(baseUrl+'/admin/groups',{waitUntil:'domcontentloaded'});
  await page.getByText('Business-group membership and notes are read-only for this account.',{exact:true}).waitFor();
  if(await page.locator('form[action*="/admin/groups"]').count())throw Error('Viewer offered business-group controls');
  await page.goto(baseUrl+'/admin/app-updates',{waitUntil:'domcontentloaded'});
  await page.getByText('Release history is read-only for this account.',{exact:false}).waitFor();
  if(await page.locator('form[action="/admin/app-updates/feature-toggle"],form[action$="/toggle"],form[action$="/reannounce"],form[action$="/delete"]').count())throw Error('Viewer offered customer-update release controls');
  if(await page.locator('#addUpdateModal,#editUpdateModal').count())throw Error('Viewer received hidden customer-update write modals');
  await saveEvidenceScreenshot(page,'rc-security-release-history-viewer-'+v.width);
  await page.goto(baseUrl+'/admin/tutorial-videos',{waitUntil:'domcontentloaded'});
  await page.getByText('Tutorial release controls are read-only for this account.',{exact:false}).waitFor();
  if(await page.locator('form[action*="/admin/tutorial-videos/"]').count())throw Error('Viewer offered tutorial publication or audience controls');
  await saveEvidenceScreenshot(page,'rc-security-tutorial-history-viewer-'+v.width);
  await page.goto(baseUrl+'/admin/announcements',{waitUntil:'domcontentloaded'});
  await page.getByText('Announcement history is read-only.',{exact:false}).waitFor();
  if(await page.locator('form[action^="/admin/announcements/"]').count())throw Error('Viewer offered announcement release controls');
  if(await page.locator('#addAnnouncementModal').count())throw Error('Viewer received hidden announcement write modal');
  await saveEvidenceScreenshot(page,'rc-security-announcement-history-viewer-'+v.width);
  await page.goto(baseUrl+'/admin/surveys',{waitUntil:'domcontentloaded'});
  await page.getByText('Survey release history and results are read-only.',{exact:false}).waitFor();
  if(await page.locator('form[action*="/admin/surveys"]').count())throw Error('Viewer offered survey release controls');
  if(await page.locator('#createSurveyModal,#editSurveyModal').count())throw Error('Viewer received hidden survey write modals');
  await saveEvidenceScreenshot(page,'rc-security-survey-history-viewer-'+v.width);
  await page.goto(baseUrl+'/admin/settings',{waitUntil:'domcontentloaded'});
  await page.getByText('Sensitive configuration is read-only for this account.',{exact:true}).waitFor();
  if(await page.locator('form[action="/admin/settings"],form[action="/admin/settings/smtp"],form[action="/admin/settings/whatsapp"],form[action="/admin/settings/test-email"]').count())throw Error('Viewer received sensitive configuration forms');
  const settingsForbidden=await page.request.post(baseUrl+'/admin/settings/test-email',{form:{_token:billingCsrf},maxRedirects:0});
  if(settingsForbidden.status()!==403)throw Error('Viewer sensitive configuration POST not refused');
  await page.goto(baseUrl+'/admin/risk-settings',{waitUntil:'domcontentloaded'});
  await page.getByText('Risk configuration is read-only for this account.',{exact:true}).waitFor();
  if(await page.locator('form[action$="/admin/risk-settings"]').count())throw Error('Viewer received risk configuration form');
  await saveEvidenceScreenshot(page,'rc-security-risk-settings-viewer-'+v.width);
  await page.goto(baseUrl+'/admin/madadgar-chats',{waitUntil:'domcontentloaded'});
  await page.getByText('Madadgar configuration is read-only for this account.',{exact:false}).waitFor();
  if(await page.locator('form[action$="/admin/madadgar-settings"]').count())throw Error('Viewer received Madadgar configuration form');
  if(await page.getByText('Admin panel (encrypted)',{exact:true}).count())throw Error('Viewer received Madadgar key source');
  await saveEvidenceScreenshot(page,'rc-security-madadgar-settings-viewer-'+v.width);
  const legacyCompany=fixture.viewer.companyId;
  await page.goto(baseUrl+'/admin/company/'+legacyCompany,{waitUntil:'domcontentloaded'});
  await page.getByText('Legacy company history is read-only for this account.',{exact:false}).waitFor();
  if(await page.locator('form[action^="/admin/company/'+legacyCompany+'/"],form[action^="'+baseUrl+'/admin/company/'+legacyCompany+'/"]').count())throw Error('Viewer received legacy company write controls');
  await saveEvidenceScreenshot(page,'rc-security-legacy-company-history-viewer-'+v.width);
  await page.goto(baseUrl+'/admin/company/'+legacyCompany+'/pos-features',{waitUntil:'domcontentloaded'});
  await page.getByText('POS feature configuration is read-only for this account.',{exact:false}).waitFor();
  if(await page.locator('form[action*="/admin/company/'+legacyCompany+'/pos-features"]').count())throw Error('Viewer received legacy POS feature write controls');
  await saveEvidenceScreenshot(page,'rc-security-legacy-pos-features-viewer-'+v.width);
  const legacyCreateRefused=await page.request.get(baseUrl+'/admin/di-companies/create',{maxRedirects:0});
  if(legacyCreateRefused.status()!==403)throw Error('Viewer received legacy company create form');
  await page.goto(baseUrl+'/admin/companies/'+legacyCompany,{waitUntil:'domcontentloaded'});
  await page.getByText('Read-only company access.',{exact:false}).waitFor();
  if(await page.locator([
    'form[action$="/limits"]','form[action$="/ai-pages"]','form[action$="/change-type"]',
    'form[action$="/clone-product"]','form[action*="/override/"]','form[action$="/reset-password"]',
    'form[action$="/impersonate"]',
  ].join(',')).count())throw Error('Viewer received company profile write controls');
  await saveEvidenceScreenshot(page,'rc-security-company-history-viewer-'+v.width);
  for(const path of ['/admin/companies/create','/admin/companies/'+legacyCompany+'/edit']) {
    const refused=await page.request.get(baseUrl+path,{maxRedirects:0});
    if(refused.status()!==403)throw Error('Viewer received company lifecycle form: '+path);
  }
  for(const path of [
    '/admin/company/'+legacyCompany+'/suspend',
    '/admin/company/'+legacyCompany+'/reject',
    '/admin/company/'+legacyCompany+'/toggle-watermark',
    '/admin/company/'+legacyCompany+'/toggle-inventory',
    '/admin/company/'+legacyCompany+'/pos-features/extras',
    '/admin/company/'+legacyCompany+'/toggle-fbr-pos',
    '/admin/risk-settings',
    '/admin/madadgar-settings',
    '/admin/invoice-override/999999',
    '/admin/sales',
    '/admin/consultants/min-payout',
    '/admin/groups/resync',
    '/admin/all-users',
    '/admin/companies/'+legacyCompany+'/ai-pages',
    '/admin/companies/'+legacyCompany+'/change-type',
    '/admin/companies/'+legacyCompany+'/clone-product',
    '/admin/companies/'+legacyCompany+'/reveal-access-code',
    '/admin/companies/'+legacyCompany+'/reset-password',
    '/admin/companies/'+legacyCompany+'/impersonate',
    '/admin/hs-rate-links',
    '/admin/hs-rate-links-import',
    '/admin/hs-master',
    '/admin/hs-master/seed',
    '/admin/hs-master/map-unmapped',
    '/admin/medicine-catalogue/sync',
    '/admin/medicine-catalogue/sync-cancel',
    '/admin/medicine-catalogue/import',
    '/admin/medicine-catalogue',
    '/admin/hs-unmapped/999999/map',
    '/admin/hs-unmapped/999999/reject',
    '/admin/hs-unmapped/999999/regenerate',
    '/admin/hs-mapping-engine/import',
    '/admin/hs-mapping-engine',
  ]) {
    const refused=await page.request.post(baseUrl+path,{form:{_token:billingCsrf},maxRedirects:0});
    if(refused.status()!==403)throw Error('Viewer legacy admin mutation not refused: '+path);
  }
  const featureRefused=await page.request.put(baseUrl+'/admin/company/'+legacyCompany+'/pos-features',{form:{_token:billingCsrf},maxRedirects:0});
  if(featureRefused.status()!==403)throw Error('Viewer legacy POS feature mutation not refused');
  const companyUpdateRefused=await page.request.put(baseUrl+'/admin/companies/'+legacyCompany,{form:{_token:billingCsrf},maxRedirects:0});
  if(companyUpdateRefused.status()!==403)throw Error('Viewer company profile mutation not refused');
  const groupRefused=await page.request.put(baseUrl+'/admin/groups/999999',{form:{_token:billingCsrf},maxRedirects:0});
  if(groupRefused.status()!==403)throw Error('Viewer business-group mutation not refused');
  for(const path of [
    '/admin/hs-rate-links/999999',
    '/admin/hs-master/999999',
    '/admin/medicine-catalogue/999999',
    '/admin/hs-mapping-engine/999999',
  ]) {
    const refused=await page.request.put(baseUrl+path,{form:{_token:billingCsrf},maxRedirects:0});
    if(refused.status()!==403)throw Error('Viewer global fiscal configuration mutation not refused: '+path);
  }
  const fiscalDeleteRefused=await page.request.delete(baseUrl+'/admin/hs-mapping-engine/999999',{form:{_token:billingCsrf},maxRedirects:0});
  if(fiscalDeleteRefused.status()!==403)throw Error('Viewer global fiscal configuration delete not refused');
  await saveEvidenceScreenshot(page,'rc-security-sensitive-settings-viewer-'+v.width);
  await page.goto(baseUrl+'/admin/system-control',{waitUntil:'domcontentloaded'});
  await page.getByText('System health is visible, but emergency controls are read-only for this account.',{exact:true}).waitFor();
  if(await page.locator('form[action*="/admin/system-control/"]').count())throw Error('Viewer received emergency system-control form');
  const systemForbidden=await page.request.post(baseUrl+'/admin/system-control/synthetic-browser-refusal/toggle',{form:{_token:billingCsrf},maxRedirects:0});
  if(systemForbidden.status()!==403)throw Error('Viewer emergency system-control POST not refused');
  await saveEvidenceScreenshot(page,'rc-security-system-controls-viewer-'+v.width);
  for(const path of ['/admin/live-ops','/admin/live-activity','/admin/agent-claims','/admin/reporting/oauth/authorize','/admin/deployment-approval']) {
    const superAdminOnly=await page.request.get(baseUrl+path,{maxRedirects:0});
    if(superAdminOnly.status()!==403)throw Error('Viewer reached super-admin-only operation: '+path);
  }
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
  pass('SECURITY ONBOARDING PASS: '+v.width+': incomplete setup refused, explicit skip, configured completion, actual password form and independent old-session revocation/fresh login; optional two-step enrollment, fresh authenticator login, single-use recovery and disable; password-verified company deletion request retains the owner/company pending review; Local Bills Viewer password reset revokes the old browser and permits a fresh login; billing, legacy company/feature/configuration mutations, customer-update, tutorial, announcement, survey, sensitive configuration and emergency system controls stay read-only for viewer; Live Ops, Live Activity, agent claims, reporting consent and deployment approval stay super-admin-only; durable support ownership, explicit resolution build and reopen timeline');
}
