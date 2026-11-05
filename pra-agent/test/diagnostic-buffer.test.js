'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const {DiagnosticBuffer, SAMPLE_MS, RETAIN_MS} = require('../src/diagnostic-buffer');
const config = {serverUrl:'http://127.0.0.1/api/agent', companyId:1, deviceUid:'fictional-device', apiKey:'FICTIONAL-SECRET'};
const raw = {printer:{printing_enabled:true,healthy:false,printers_reported:2},queue:{pending_callbacks:3},fiscal_connectivity:{state:'unreachable'},error:'PRIVATE-CUSTOMER'};
function fixture(t) {const root=fs.mkdtempSync(path.join(os.tmpdir(),'tn-diagnostic-'));t.after(()=>fs.rmSync(root,{recursive:true,force:true}));return root;}
function enable(b) {const sent=b.batch();b.acknowledge({version:1,stream_id:sent.stream_id,accepted:[]},sent);}

test('seven offline days survive restart; exact acknowledgements deduplicate without replaying business operations',t=>{
  const root=fixture(t);let at=Date.parse('2026-11-03T00:00:00Z');
  let b=new DiagnosticBuffer(root,config,()=>at);enable(b);
  for(let i=0;i<7*24*12;i++){b.capture(raw);at+=SAMPLE_MS;}
  b=new DiagnosticBuffer(root,config,()=>at);
  assert.equal(b.batch().pending,2016);assert.equal(b.batch().events.length,50);
  const sent=b.batch();b.acknowledge({version:1,stream_id:'another-stream',accepted:[1]},sent);
  assert.equal(b.batch().pending,2016);
  b.acknowledge({version:1,stream_id:sent.stream_id,accepted:[1,2,99999]},sent);
  assert.equal(new DiagnosticBuffer(root,config,()=>at).batch().pending,2014);
  const disk=fs.readFileSync(b.file,'utf8');assert.ok(!disk.includes('FICTIONAL-SECRET'));assert.ok(!disk.includes('PRIVATE-CUSTOMER'));assert.ok(!disk.includes('fictional-device'));
});
test('sampling is bounded and old/unsupported servers cannot silently acknowledge or enable collection',t=>{
  const root=fixture(t);let at=Date.now();const b=new DiagnosticBuffer(root,config,()=>at);
  b.capture(raw);assert.equal(b.batch().pending,0);b.acknowledge({},b.batch());b.capture(raw);assert.equal(b.batch().pending,0);
  enable(b);b.capture(raw);b.capture(raw);assert.equal(b.batch().pending,1);
  at+=RETAIN_MS+SAMPLE_MS;b.capture(raw);assert.equal(b.batch().pending,1);assert.equal(b.batch().dropped,1);
});
test('pairing identity isolates company, device, server and credential rotation',t=>{
  const root=fixture(t);const b=new DiagnosticBuffer(root,config);enable(b);b.capture(raw);
  for(const change of [{companyId:2},{deviceUid:'second'},{apiKey:'ROTATED'},{serverUrl:'http://127.0.0.1/other'}]){
    const other=new DiagnosticBuffer(root,{...config,...change});assert.notEqual(b.file,other.file);assert.equal(other.batch().pending,0);
  }
});
test('disk failures and corrupt state are explicit, with no success claim or uncaught failure',t=>{
  const root=fixture(t);const b=new DiagnosticBuffer(root,config);enable(b);
  const broken={...fs,writeFileSync(){throw new Error('disk full');}};
  const fail=new DiagnosticBuffer(root,config,Date.now,broken);fail.capture(raw);
  assert.equal(fail.batch().storage_status,'storage_unavailable');
  fs.writeFileSync(b.file,'{bad');const corrupt=new DiagnosticBuffer(root,config);
  assert.equal(corrupt.batch().storage_status,'buffer_reset');assert.equal(corrupt.batch().resets,1);
  assert.equal(fs.readFileSync(b.file,'utf8'),'{bad');
});
