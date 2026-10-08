import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const root = new URL('../../',import.meta.url);
const uuid = 'f1208d40-7bb6-4cee-9c94-9e9dd38bdcaf';
function context(extension=true, status=200) {
  const listeners = new Set(), replies=[], calls=[];
  const location={origin:'https://taxnest.pk',pathname:'/pos/hotel',href:'https://taxnest.pk/pos/hotel'};
  const window={addEventListener:(type,fn)=>listeners.add(fn),removeEventListener:(type,fn)=>listeners.delete(fn)};
  window.postMessage=(data,origin)=>{replies.push(data);for(const fn of [...listeners]) fn({source:window,origin,data});};
  const sandbox={window,location,URL,Response,crypto:{randomUUID:()=>uuid},setTimeout:()=>1,clearTimeout:()=>{},
    document:{querySelectorAll:()=>[]},fetch:async(url,options)=>{calls.push({url,options});return new Response(JSON.stringify({success:status===200,job_id:12,reason:'agent_offline'}),{status});}};
  const ctx=vm.createContext(sandbox);
  if(extension)vm.runInContext(readFileSync(new URL('browser-print-extension/content.js',root),'utf8'),ctx);
  return {ctx,window,replies,calls};
}
test('manifest is first-party, top-frame, permission-free MV3',()=>{
  const m=JSON.parse(readFileSync(new URL('browser-print-extension/manifest.json',root)));
  assert.equal(m.manifest_version,3);assert.equal(m.permissions,undefined);
  assert.equal(m.content_scripts[0].all_frames,false);
  assert.deepEqual(m.content_scripts[0].matches,['https://taxnest.pk/pos/*','https://www.taxnest.pk/pos/*']);
});
test('installed extension is detected; healthy Agent preferred; no duplicate enqueue',async()=>{
  const h=context();vm.runInContext(readFileSync(new URL('public/js/nestpos-print-bridge.js',root),'utf8'),h.ctx);
  assert.equal((await h.window.nestposPrintBridge.detect()).installed,true);
  const url='https://taxnest.pk/pos/hotel/stays/1/bills/2/print';
  const body={print_attempt_uuid:uuid};
  await h.window.nestposPrintBridge.enqueue(url,body,'x'.repeat(40),true);
  assert.equal(h.calls.length,1);
  assert.equal(h.replies.filter(x=>x.action==='enqueue').length,0);
  const a=await h.window.nestposPrintBridge.enqueue(url,body,'x'.repeat(40),false);
  const b=await h.window.nestposPrintBridge.enqueue(url,body,'x'.repeat(40),false);
  assert.equal((await a.json()).job_id,12);assert.equal((await b.json()).job_id,12);
  assert.equal(h.calls.length,2);assert.equal(JSON.parse(h.calls[1].options.body).print_attempt_uuid,uuid);
});
test('foreign origins and arbitrary printer/receipt commands cannot use bridge',async()=>{
  const h=context();
  for(const url of ['https://evil.invalid/pos/hotel/stays/1/bills/2/print','https://taxnest.pk/api/agent/submit-result','https://taxnest.pk/pos/hotel/stays/1/bills/2/print?printer=other']){
    h.window.postMessage({channel:'nestpos-print-bridge-v1',direction:'request',requestId:uuid,action:'enqueue',url,body:{print_attempt_uuid:uuid},csrf:'x'.repeat(40)},h.ctx.location.origin);
  }
  await Promise.resolve();assert.equal(h.calls.length,0);
  assert.equal(h.replies.filter(x=>x.direction==='reply'&&x.result.error==='invalid_request').length,3);
});
test('Agent rejection remains rejection and does not trigger another route',async()=>{
  const h=context(true,409);vm.runInContext(readFileSync(new URL('public/js/nestpos-print-bridge.js',root),'utf8'),h.ctx);
  const response=await h.window.nestposPrintBridge.enqueue('https://taxnest.pk/pos/hotel/stays/1/bills/2/print',{print_attempt_uuid:uuid},'x'.repeat(40),false);
  assert.equal(response.status,409);assert.equal((await response.json()).reason,'agent_offline');assert.equal(h.calls.length,1);
});

test('uncertain extension enqueue does not automatically fall back to Agent',async()=>{
  const h=context();h.ctx.fetch=async()=>{h.calls.push({uncertain:true});throw new Error('lost response');};
  vm.runInContext(readFileSync(new URL('public/js/nestpos-print-bridge.js',root),'utf8'),h.ctx);
  const args=['https://taxnest.pk/pos/hotel/stays/1/bills/2/print',{print_attempt_uuid:uuid},'x'.repeat(40),false];
  await assert.rejects(h.window.nestposPrintBridge.enqueue(...args),/enqueue_uncertain/);
  assert.equal(h.calls.length,1);
  await assert.rejects(h.window.nestposPrintBridge.enqueue(...args),/enqueue_uncertain/);
  assert.equal(h.calls.length,2);
});
