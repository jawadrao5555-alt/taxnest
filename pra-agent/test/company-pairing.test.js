'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { EventEmitter } = require('node:events');
const { companyPairing } = require('../src/company-pairing');
const { wireCompanyLogin } = require('../src/company-login-hook');
const origin = 'https://fictional.example';
const key = 'fictional-local-test-key';
function setup(overrides = {}) {
  const calls = [];
  const preview = { success: true, company_id: 41, company_name: 'Fictional Shop', nonce: 'n'.repeat(64),
    csrf_token: 'fictional-csrf', agent_enabled: true, credential_digest: crypto.createHash('sha256').update(key).digest('hex') };
  const session = { fetch: async (url, opts) => {
    calls.push({ url, opts });
    return { ok: true, json: async () => opts.method === 'POST'
      ? { success: true, company_id: 41, server_url: origin + '/api/agent', api_key: key, ...overrides.result }
      : { ...preview, ...overrides.preview } };
  } };
  return { calls, options: { session, origin, confirm: async () => true, ...overrides.options } };
}
for (const panel of ['pos', 'fbrpos']) test(panel + ' confirms identity before a session/CSRF-bound credential exchange', async () => {
  const { calls, options } = setup(); let confirmations = 0;
  const result = await companyPairing({ ...options, panel, confirm: async info => {
    confirmations++; assert.deepEqual(Object.keys(info).sort(), ['companyId','companyName','switching']);
    assert.equal(calls.length, 1); assert.equal(info.companyName, 'Fictional Shop'); return true;
  } });
  assert.equal(confirmations, 1); assert.equal(result.config.companyId, 41);
  assert.equal(calls[0].url, origin + (panel === 'pos' ? '/pos' : '/fbr-pos') + '/desktop/agent-pairing');
  assert.equal(calls[1].opts.headers['X-CSRF-TOKEN'], 'fictional-csrf');
  assert.equal(JSON.parse(calls[1].opts.body).company_id, 41);
  assert.equal(calls[1].opts.redirect, 'error'); assert.ok(!calls[1].url.includes('nonce'));
});
test('same-company/key reinstall does not rewrite settings or prompt', async () => {
  const {calls,options}=setup(); const result=await companyPairing({...options,
    existing:{companyId:41,apiKey:key,serverUrl:origin+'/api/agent',receiptPrinter:'Kitchen A'},
    confirm:()=>{throw Error('Must not prompt');}});
  assert.equal(result.unchanged,true);assert.equal(result.config,undefined);assert.equal(calls.length,1);
});
test('cancelled company switch never fetches credentials or overwrites config', async () => {
  const {calls,options}=setup();const result=await companyPairing({...options,
    existing:{companyId:42,apiKey:'old'},confirm:async info=>{assert.equal(info.switching,true);return false;}});
  assert.equal(result.cancelled,true);assert.equal(result.config,undefined);assert.equal(calls.length,1);
});
test('rotated key refresh keeps printer and device preferences', async () => {
  const {options}=setup();const result=await companyPairing({...options,
    existing:{companyId:41,apiKey:'old-key',receiptPrinter:'Existing printer',pcName:'Counter 2'}});
  assert.equal(result.config.receiptPrinter,'Existing printer');assert.equal(result.config.pcName,'Counter 2');
  assert.equal(result.config.apiKey,key);
});
for (const patch of [{company_id:42},{server_url:'https://evil.example/api/agent'},{api_key:''}]) {
  test('refuses changed/invalid confirmed identity '+JSON.stringify(patch),async()=>{
    const {options}=setup({result:patch});assert.equal((await companyPairing(options)).ok,false);
  });
}
test('logout while confirmation is open makes result ineligible',async()=>{
  const {calls,options}=setup();let current=true;
  const result=await companyPairing({...options,isCurrent:()=>current,confirm:async()=>{current=false;return true;}});
  assert.equal(result.ok,false);assert.equal(calls.length,1);
});
test('expired/replayed server rejection never returns local config',async()=>{
  const {options}=setup();const fetch=options.session.fetch;options.session.fetch=async(u,o)=>o.method==='POST'?{ok:false}:fetch(u,o);
  assert.equal((await companyPairing(options)).ok,false);
});
test('old server missing new endpoint fails closed without legacy secret fallback',async()=>{
  const {calls,options}=setup();options.session.fetch=async(u,o)=>{calls.push(u);return{ok:false};};
  assert.equal((await companyPairing(options)).ok,false);assert.equal(calls.length,1);
});
test('non-TLS public origin is rejected before any session request',async()=>{
  const {calls,options}=setup();await assert.rejects(companyPairing({...options,origin:'http://public.example'}));assert.equal(calls.length,0);
});
for(const panel of ['pos','fbrpos']) test(panel+' login hook rearms and invalidates a stale in-flight login',async()=>{
  const prefix=panel==='pos'?'/pos/':'/fbr-pos/';const wc=new EventEmitter();let url=origin+prefix+'dashboard';
  wc.getURL=()=>url;wc.session={};const win={webContents:wc,isDestroyed:()=>false};let calls=0,current,finish;
  wireCompanyLogin(win,origin,panel,async(_s,_o,isCurrent)=>{calls++;current=isCurrent;return new Promise(r=>{finish=r;});});
  wc.emit('did-finish-load');assert.equal(calls,1);assert.equal(current(),true);
  url=origin+prefix+'login';wc.emit('did-navigate',{},url);assert.equal(current(),false);finish(true);
  await new Promise(r=>setImmediate(r));url=origin+prefix+'dashboard';wc.emit('did-finish-load');assert.equal(calls,2);
  finish(true);await new Promise(r=>setImmediate(r));wc.emit('did-finish-load');assert.equal(calls,2);
});
