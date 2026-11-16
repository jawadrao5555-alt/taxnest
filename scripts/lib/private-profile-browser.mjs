export async function privateProfileWorkflow(page,t,v,{baseUrl,saveEvidenceScreenshot,pass}) {
  const check=(ok,message)=>{if(!ok)throw Error(message);};
  const fixture=t.privateProfileCases[v.width];
  const account=()=>page.locator('form').filter({has:page.locator('input[name="_method"][value="patch"]')});
  const load=()=>page.goto(baseUrl+'/profile',{waitUntil:'domcontentloaded'});
  await load();
  check(await account().locator('#date_of_birth').inputValue()==='','Synthetic DOB must start empty');
  await account().locator('#date_of_birth').fill('2000-02-29');
  await account().locator('#profile-phone').fill(v.width===1366?'0300-0001366':'0300-0000390');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),account().locator('button[type="submit"]').click()]);
  await load();
  check(await account().locator('#date_of_birth').inputValue()==='2000-02-29','Valid DOB not persisted');
  const phone=await account().locator('#profile-phone').inputValue();
  check(phone.includes(v.width===1366?'1366':'0390'),'Valid phone not persisted');
  const business=()=>page.locator('form[action$="/profile/company"]');
  await business().locator('input[name="cnic"]').fill('00000-0000000-0');
  await business().locator('input[name="phone"]').fill('042-00000000');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),business().locator('button[type="submit"]').click()]);
  await load();
  check(await business().locator('input[name="cnic"]').inputValue()==='00000-0000000-0','Formatted synthetic CNIC not persisted');
  const businessPayload=await business().evaluate(form=>Object.fromEntries(new FormData(form)));
  const badBusiness=await page.context().request.post(baseUrl+'/profile/company',{form:{...businessPayload,cnic:'invalid',phone:'not-a-phone'},headers:{Accept:'application/json'},maxRedirects:0});
  check(badBusiness.status()===422,'Invalid business profile must be rejected');
  const badBusinessData=await badBusiness.json();
  check(badBusinessData.errors?.cnic&&badBusinessData.errors?.phone,'Business format validation errors missing');
  await load();
  check(await business().locator('input[name="cnic"]').inputValue()==='00000-0000000-0','Invalid CNIC overwrote saved value');
  check(await business().locator('input[name="phone"]').inputValue()==='042-00000000','Invalid business phone overwrote saved value');
  await saveEvidenceScreenshot(page,'private-profile-saved-'+v.width);
  const request=async extra=>{
    const payload=await account().evaluate(form=>Object.fromEntries(new FormData(form)));
    delete payload.date_of_birth;
    const res=await page.context().request.post(baseUrl+'/profile',{form:{...payload,...extra},headers:{Accept:'application/json'},maxRedirects:0});
    return res;
  };
  for(const invalid of [{date_of_birth:'2999-01-01'},{date_of_birth:'2001-02-29'},{phone:'not-a-phone'}]){
    const res=await request(invalid);
    check(res.status()===422,'Invalid profile input must be rejected');
    const data=await res.json();
    check(Object.hasOwn(data.errors??{},Object.keys(invalid)[0]),'Wrong validation boundary');
  }
  await load();
  check(await account().locator('#date_of_birth').inputValue()==='2000-02-29','Rejected DOB mutated profile');
  check(await account().locator('#profile-phone').inputValue()===phone,'Rejected phone mutated profile');
  const omitted=await request({role:'super_admin',company_id:99999999});
  check(omitted.status()===302,'Omitted DOB update failed');
  await load();
  check(await account().locator('#date_of_birth').inputValue()==='2000-02-29','Omitted DOB unexpectedly cleared');
  check(await page.locator('form[action$="/profile/company"] input[name="name"]').inputValue()===fixture.companyName,'Forged company reassigned account');
  // A persisted role escalation would grant SaaS entry. The original owner remains denied.
  const denied=await page.context().request.get(baseUrl+'/admin/companies',{headers:{Accept:'application/json'},maxRedirects:0});
  check([302,403].includes(denied.status()),'Profile unexpectedly granted SaaS admin access');
  await account().locator('#date_of_birth').fill('');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),account().locator('button[type="submit"]').click()]);
  await load();
  check(await account().locator('#date_of_birth').inputValue()==='','Explicit blank DOB must clear');
  await page.goto(baseUrl+'/profile/greetings',{waitUntil:'domcontentloaded'});
  check(!await page.locator('form[action$="/profile/greetings"] input[type="checkbox"]').isChecked(),'Saving DOB must not enable greetings');
  check(await page.locator('input[name="email"]').count()===1,'Profile must not automatically add a greeting recipient');
  pass('PRIVATE PROFILE PASS: '+v.width+': actual form, persisted/invalid/omitted/cleared DOB, phone validation, tenant and admin boundaries, no automatic greeting opt-in');
}
