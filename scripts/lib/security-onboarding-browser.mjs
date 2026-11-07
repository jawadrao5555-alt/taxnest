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
  pass('SECURITY ONBOARDING PASS: '+v.width+': incomplete setup refused, explicit skip, configured completion, actual password form and independent old-session revocation/fresh login');
}
