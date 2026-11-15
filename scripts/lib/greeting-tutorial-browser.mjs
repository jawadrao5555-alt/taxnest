export async function greetingTutorialWorkflow(page,t,v,{baseUrl,saveEvidenceScreenshot,pass}) {
  const check=(ok,message)=>{if(!ok)throw Error(message);};
  await page.goto(baseUrl+'/profile/greetings',{waitUntil:'domcontentloaded'});
  const prefs=()=>page.locator('form[action$="/profile/greetings"]');
  check(!await prefs().locator('input[type="checkbox"]').isChecked(),'Greeting preference must start OFF');
  await prefs().locator('textarea[name="templates[welcome]"]').fill('Invalid {secret}');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),prefs().getByRole('button').click()]);
  await page.locator('#greeting-errors[role="alert"]').waitFor();
  check(await page.locator('#greeting-errors[role="alert"]').evaluate(el=>el===document.activeElement),'Validation error must receive keyboard focus');
  await saveEvidenceScreenshot(page,'greetings-validation-'+v.width);
  await prefs().locator('textarea[name="templates[welcome]"]').fill('Hello {name} from {company}');
  // Keep the production-like preference OFF. Consent recording itself never activates delivery.
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),prefs().getByRole('button').click()]);
  check(!await prefs().locator('input[type="checkbox"]').isChecked(),'Saving templates silently enabled greetings');
  const preview=page.locator('form[action$="/preview"]');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),preview.getByRole('button').click()]);
  const result=JSON.parse(await page.locator('body').innerText());
  check(result.sent===false&&result.preview.includes('Example recipient'),'Template preview sent or failed fictional rendering');
  await page.goto(baseUrl+'/profile/greetings',{waitUntil:'domcontentloaded'});
  let recipient=page.locator('form[action$="/recipient"]').last();
  await recipient.getByLabel('Name',{exact:true}).fill('Synthetic consent recipient');
  await recipient.getByLabel('Email',{exact:true}).fill('synthetic-consent-'+v.width+'@example.invalid');
  await recipient.getByLabel('Event',{exact:true}).selectOption('birthday');
  await recipient.getByLabel('Event date',{exact:true}).fill('2000-02-29');
  await recipient.locator('input[type="checkbox"]').check();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),recipient.getByRole('button').click()]);
  recipient=page.locator('form[action$="/recipient"]').first();
  check(await recipient.locator('input[type="checkbox"]').isChecked(),'Explicit recipient consent not retained');
  await saveEvidenceScreenshot(page,'greetings-consent-'+v.width);
  await recipient.locator('input[type="checkbox"]').uncheck();
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),recipient.getByRole('button').click()]);
  check(!await page.locator('form[action$="/recipient"]').first().locator('input[type="checkbox"]').isChecked(),'Consent revocation failed');
  check(!await prefs().locator('input[type="checkbox"]').isChecked(),'Consent changes enabled delivery');
  await page.goto(baseUrl+'/tutorials',{waitUntil:'domcontentloaded'});
  await page.locator('#tutorial-search').fill('Synthetic searchable guide');
  await page.locator('#tutorial-search').press('Enter');
  await page.waitForURL(/q=/);
  const video=page.locator('video[aria-label="Synthetic searchable guide"]');
  await video.waitFor();
  check(await video.locator('track[kind="captions"][srclang="en"]').count()===1,'Supplied caption metadata missing');
  for(const path of ['/storage/rc-browser-tutorials/rc-onboarding-synthetic.mp4','/storage/rc-browser-tutorials/rc-onboarding-synthetic.vtt']){
    const asset=await page.context().request.get(baseUrl+path);check(asset.ok(),'Isolated synthetic tutorial asset is not browser-readable');
    check((await asset.text()).includes(path.endsWith('.vtt')?'Synthetic caption fixture':'synthetic file placeholder'),'Synthetic tutorial asset ownership/content mismatch');
  }
  const details=page.locator('details').filter({hasText:'Synthetic transcript only'});
  await details.locator('summary').focus();await page.keyboard.press('Enter');
  check(await details.getAttribute('open')!==null,'Transcript is not keyboard expandable');
  check((await page.locator('body').innerText()).includes('synthetic-build'),'Tutorial version missing');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Onboarding screen horizontal overflow');
  await saveEvidenceScreenshot(page,'tutorial-accessibility-'+v.width);
  pass('GREETINGS/TUTORIAL PASS: '+v.width+': default OFF, error focus, fake preview, explicit consent/revoke, scoped search, captions and keyboard transcript');
}
