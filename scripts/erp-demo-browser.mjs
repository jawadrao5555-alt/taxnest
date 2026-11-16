import assert from 'node:assert/strict';
import {readFileSync,lstatSync,realpathSync,writeFileSync,mkdirSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {assertLocalOnlyBaseUrl,launchLocalBrowser} from './lib/local-browser.mjs';
import {initializeSyntheticPixels,captureSyntheticPixels} from './lib/synthetic-pixel-capture.mjs';
const path=process.env.ERP_DEMO_FIXTURE||'';
assert.match(path,/^\/tmp\/taxnest-erp-demo-[0-9]+-[A-Za-z0-9]+\/safe-runtime\/browser-state\/fixture\.json$/);
const st=lstatSync(path);assert(st.isFile()&&!st.isSymbolicLink()&&st.nlink===1&&(st.mode&0o077)===0&&st.size<65536);assert.equal(realpathSync(path),path);
const fixture=JSON.parse(readFileSync(path,'utf8'));
assert.equal(fixture.fixture_kind,'hospital-erp-demo');assert.equal(fixture.database,'taxnest_erp_demo');assert.equal(fixture.synthetic,true);
assert.match(fixture.pixel_proof_nonce,/^[a-f0-9]{64}$/);assert(fixture.password.length>=16);
const matrix={owner:['/health/dashboard','/health/settings'],reception:['/health/patients','/health/clinical'],doctor:['/health/clinical','/health/settings/modules'],cashier:['/health/billing','/health/clinical'],pharmacy:['/health/pharmacy','/health/accounts/journals'],lab:['/health/patients','/health/clinical'],nursing:['/health/ipd','/health/settings/modules'],accounts:['/health/accounts/journals','/health/clinical'],auditor:['/health/audit','/health/settings/modules'],hr:['/health/hr','/health/patients']};
assert.deepEqual(fixture.roles,Object.keys(matrix));
const baseUrl=assertLocalOnlyBaseUrl(process.env.BASE_URL);assert.equal(baseUrl,'http://127.0.0.1:5927');
const {browser}=await launchLocalBrowser();const results=[];
try {
  initializeSyntheticPixels({flag:'1',fixturePath:path,baseUrl,browser,sourceSha:process.env.ERP_DEMO_SOURCE_SHA});
  for(const width of [1366,390]) for(const [role,[allowed,denied]] of Object.entries(matrix)) {
    const context=await browser.newContext({viewport:{width,height:width===390?844:900},serviceWorkers:'block'});
    await context.route('**/*',route=>{const u=new URL(route.request().url());return u.origin===baseUrl?route.continue():route.abort();});
    const page=await context.newPage();
    try {
      assert.equal((await page.goto(`${baseUrl}/health/login`)).status(),200);
      await page.locator('[name=login]').fill(`erp.${role}@example.test`);
      await page.locator('[name=password]').fill(fixture.password);
      await Promise.all([page.waitForURL(u=>u.pathname!=='/health/login'),page.locator('form[action$="/health/login"] button[type=submit]').click()]);
      const response=await page.goto(baseUrl+allowed);assert.equal(response.status(),200,`${role} allowed route`);
      assert.equal(page.url(),baseUrl+allowed,`${role} must render its actual allowed route, not a redirect destination`);
      assert(!/SQLSTATE|Whoops|Stack trace|Internal Server Error/.test(await page.locator('body').innerText()));
      // This is acceptance of access + rendering, not a completed clinical journey.
      assert.equal(await captureSyntheticPixels(page,`erp-demo-${role}-${width}`),true);
      const deniedResponse=await context.request.get(baseUrl+denied,{maxRedirects:0});
      assert.equal(deniedResponse.status(),role==='owner'?200:403,`${role} denied route`);
      results.push({role,width,allowed_status:200,denied_status:deniedResponse.status(),synthetic:true});
    } finally { await context.close(); }
  }
} finally {await browser.close();}
mkdirSync('.local/erp-demo-evidence',{recursive:true,mode:0o700});
writeFileSync('.local/erp-demo-evidence/results.json',JSON.stringify({source_sha:process.env.ERP_DEMO_SOURCE_SHA,fixture_sha256:createHash('sha256').update(readFileSync(path)).digest('hex'),database:'taxnest_erp_demo',scope:'role access and rendering only; no clinical transactions',results},null,2),{mode:0o600});
console.log(`PASS: ${results.length} synthetic ERP desktop/mobile role access cases. Clinical end-to-end/native concurrency is not implied.`);
