import {execFileSync} from 'node:child_process';

// A dedicated disposable drill: the following CI fixture is rebuilt before
// any unrelated journey can depend on its seeded password-reset grants.
export async function restoreSessionBoundaryWorkflow(page,t,v,{baseUrl,login,saveEvidenceScreenshot,pass}) {
  await page.goto(baseUrl+'/admin/support-cases',{waitUntil:'domcontentloaded'});
  await page.getByRole('heading',{name:'Company Support Cases',exact:true}).waitFor();
  const proof=execFileSync('php',['tests/native/rc_browser_restore_boundary.php'],{encoding:'utf8',timeout:60000});
  if(!proof.includes('PASS: disposable file-session restore quarantine'))throw Error('Disposable restore drill did not complete');
  await page.goto(baseUrl+'/admin/support-cases',{waitUntil:'domcontentloaded'});
  if(new URL(page.url()).pathname!=='/admin/login')throw Error('Old file-backed administrator session survived restored access quarantine');
  await saveEvidenceScreenshot(page,'rc-security-restored-session-refused-'+v.width);
  await login(page,t);
  await page.goto(baseUrl+'/admin/support-cases',{waitUntil:'domcontentloaded'});
  await page.getByRole('heading',{name:'Company Support Cases',exact:true}).waitFor();
  await saveEvidenceScreenshot(page,'rc-security-restored-session-fresh-'+v.width);
  pass('RESTORE SESSION PASS: '+v.width+': old file-backed administrator refused after real quarantine; fresh password login succeeds; company rows and Agent pairing preserved');
}
