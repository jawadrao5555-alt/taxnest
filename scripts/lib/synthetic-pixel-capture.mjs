/** Separate fail-closed synthetic pixel lane. Normal evidence remains text-only. */
import {readFileSync,lstatSync,realpathSync,mkdirSync,writeFileSync,existsSync,constants} from 'node:fs';
import {createHash,createHmac,randomBytes} from 'node:crypto';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const policy=JSON.parse(readFileSync(new URL('./synthetic-pixel-policy.json',import.meta.url),'utf8'));
const allowed=new Map(policy.names.flatMap(n=>Object.values(policy.viewports).map(v=>[`${n}-${v.width}`,v])));
const maskSelectors='input, textarea, pre, code, [contenteditable="true"], [data-sensitive], [data-secret], [data-token], [data-credential], [autocomplete="one-time-code"], canvas, iframe';
let session=null;
export function pixelName(name){if(!allowed.has(name))throw Error('Pixel proof filename is not allowlisted');return `${name}.png`;}
export function pixelOrigin(raw){const u=new URL(raw);if(u.protocol!=='http:'||u.username||u.password||!['127.0.0.1','localhost','[::1]'].includes(u.hostname)||u.pathname!=='/'||u.search||u.hash)throw Error('Pixel proof requires exact HTTP loopback origin');return u.origin;}
export function safeRegular(file,max=policy.max_file_bytes){const s=lstatSync(file);if(!s.isFile()||s.isSymbolicLink()||s.nlink!==1||s.size>max||realpathSync(file)!==file)throw Error('Unsafe pixel proof file');return s;}
function safeDirectory(dir){mkdirSync(dir,{recursive:true,mode:0o700});if(!lstatSync(dir).isDirectory()||realpathSync(dir)!==dir)throw Error('Unsafe pixel proof directory');}
function writeSafe(file,data){if(existsSync(file))safeRegular(file);writeFileSync(file,data,{mode:0o600,flag:constants.O_WRONLY|constants.O_CREAT|constants.O_TRUNC|constants.O_NOFOLLOW});}
export function fixtureIdentity(fixturePath,fixture){
 const standard=/^\/tmp\/taxnest-rc-browser-[0-9]+(?:-[A-Za-z0-9_.-]+)?\/safe-runtime\/browser-state\/fixture\.json$/.test(fixturePath);
 const erp=fixturePath.match(/^\/tmp\/taxnest-erp-demo-([0-9]+-[A-Za-z0-9]+)\/safe-runtime\/browser-state\/fixture\.json$/);
 if(standard&&fixture.fixture_kind==='standalone-browser'&&fixture.database==='taxnest_rc_browser'&&/^\/tmp\/taxnest-rc-mariadb-browser-[0-9]+\/run\/mariadb\.sock$/.test(fixture.socket||''))return 'standalone-browser';
 if(erp&&fixture.fixture_kind==='hospital-erp-demo'&&fixture.database==='taxnest_erp_demo'&&fixture.socket===`/tmp/taxnest-rc-mariadb-erp-demo-${erp[1]}/run/mariadb.sock`)return 'hospital-erp-demo';
 throw Error('Untrusted synthetic fixture kind/database/socket/path');
}
export function verifyMarker(marker,fixtureHash,nonce,fixtureKind,database){if(marker?.version!==1||marker.synthetic!==true||marker.nonce!==nonce||marker.fixture_sha256!==fixtureHash||marker.fixture_kind!==fixtureKind||marker.database!==database)throw Error('Synthetic server marker mismatch');}
export function initializeSyntheticPixels({flag,fixturePath,baseUrl,browser,sourceSha}){
  if(!flag||flag==='0')return false;
  if(flag!=='1'||!browser||!/^[a-f0-9]{40}$/.test(sourceSha))throw Error('Invalid synthetic pixel initialization');
  if(!/^\/tmp\/taxnest-rc-browser-[0-9]+(?:-[A-Za-z0-9_.-]+)?\/safe-runtime\/browser-state\/fixture\.json$/.test(fixturePath)&&!/^\/tmp\/taxnest-erp-demo-[0-9]+-[A-Za-z0-9]+\/safe-runtime\/browser-state\/fixture\.json$/.test(fixturePath))throw Error('Untrusted fixture path');
  safeRegular(fixturePath,4194304);const bytes=readFileSync(fixturePath),fixture=JSON.parse(bytes);
  if(fixture.synthetic!==true||!Array.isArray(fixture.readOnlyJourneys)||!Array.isArray(fixture.transactionalJourneys)||!/^[a-f0-9]{64}$/.test(fixture.pixel_proof_nonce||''))throw Error('Pixel capture requires fresh synthetic fixture marker');
  const fixtureKind=fixtureIdentity(fixturePath,fixture);
  const origin=pixelOrigin(baseUrl),dir=path.join(root,'.local','synthetic-pixel-evidence'),keyPath=path.join(root,'.local','synthetic-pixel-private.key');
  safeDirectory(path.join(root,'.local'));safeDirectory(dir);
  if(!existsSync(keyPath))writeFileSync(keyPath,randomBytes(32),{mode:0o600,flag:'wx'});
  const stat=safeRegular(keyPath,32);if(stat.size!==32||(stat.mode&0o077))throw Error('Unsafe pixel signing key');
  session={origin,browser,dir,fixtureKind,database:fixture.database,key:readFileSync(keyPath),fixtureHash:createHash('sha256').update(bytes).digest('hex'),nonce:fixture.pixel_proof_nonce,sourceSha};
  return true;
}
export function validateCaptureContext(page,s,v){
  if(page.context().browser()!==s.browser)throw Error('Pixel capture refused inherited browser/session');
  const url=new URL(page.url());if(url.origin!==s.origin||url.username||url.password)throw Error('Pixel capture page origin changed');
  for(const frame of page.frames()){const u=frame.url();if(u&&!u.startsWith('about:')&&new URL(u).origin!==s.origin)throw Error('Pixel capture foreign frame');}
  const viewport=page.viewportSize();if(viewport?.width!==v.width||viewport?.height!==v.height)throw Error('Pixel viewport not allowlisted');
}
export async function captureSyntheticPixels(page,name){
  if(!session||!allowed.has(name))return false;
  const s=session,v=allowed.get(name);
  if(name.startsWith('erp-demo-')!==(s.fixtureKind==='hospital-erp-demo'))throw Error('Screenshot name does not belong to fixture kind');
  validateCaptureContext(page,s,v);
  const response=await page.context().request.get(`${s.origin}/.rc-synthetic-proof.json`,{maxRedirects:0,timeout:5000});
  if(response.status()!==200||new URL(response.url()).origin!==s.origin)throw Error('Synthetic marker request failed');
  const bytes=await response.body();if(bytes.length>1024)throw Error('Synthetic marker too large');verifyMarker(JSON.parse(bytes.toString('utf8')),s.fixtureHash,s.nonce,s.fixtureKind,s.database);
  const watermark=await page.evaluate(()=>{if(document.querySelector('#rc-synthetic-pixel-watermark'))throw Error('Existing proof watermark');const node=document.createElement('div');node.id='rc-synthetic-pixel-watermark';node.textContent='SYNTHETIC TEST DATA · NOT LIVE · VISUAL REVIEW REQUIRED';node.style.cssText='position:fixed;left:0;bottom:0;width:100%;z-index:2147483647;background:#fff;color:#111;font:bold 12px sans-serif;padding:4px;text-align:center;';document.body.append(node);return node.id;});
  try{
    // Mask all editable credentials/references and explicit code/secret blocks; rendered synthetic receipt amounts remain visible.
    const png=await page.screenshot({fullPage:false,type:'png',animations:'disabled',mask:[page.locator(maskSelectors)],maskColor:'#555555'});
    validateCaptureContext(page,s,v);
    if(new URL(page.url()).origin!==s.origin||png.length>policy.max_file_bytes)throw Error('Pixel origin/size changed during capture');
    const file=pixelName(name);const record={version:1,fixture_kind:s.fixtureKind,file,source_sha:s.sourceSha,fixture_sha256:s.fixtureHash,origin:s.origin,synthetic:true,server_marker_verified:true,browser_scope:'ephemeral-fixture-browser',mask_policy:policy.mask_policy,width:v.width,height:v.height,bytes:png.length,sha256:createHash('sha256').update(png).digest('hex'),review_status:'NOT_VISUALLY_REVIEWED'};
    const payload=JSON.stringify(record),signature=createHmac('sha256',s.key).update(payload).digest('hex');
    writeSafe(path.join(s.dir,file),png);writeSafe(path.join(s.dir,`${name}.json`),JSON.stringify({payload,signature})+'\n');
    return true;
  }finally{await page.evaluate(id=>document.getElementById(id)?.remove(),watermark);}
}
