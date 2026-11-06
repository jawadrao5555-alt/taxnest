'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { createRequire } = require('node:module');
const { DiagnosticBuffer, SAMPLE_MS } = require('../src/diagnostic-buffer');
const source = path.resolve(__dirname, '../src/agent.js');
const actualRequire = createRequire(source);
const tick = () => new Promise(resolve => setImmediate(resolve));

function harness(t) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pra-beat-evidence-'));
  t.after(() => fs.rmSync(root, {recursive:true, force:true}));
  let clock = Date.parse('2026-11-03T12:00:00Z');
  const requests = [];
  const axios = {get:async()=>({data:{invoices:[]}}), post:(url,body)=>new Promise((resolve,reject)=>requests.push({url,body,resolve,reject}))};
  const box = {module:{exports:{}}, console:{log(){}}, process, Buffer, Date, setTimeout:()=>1, clearTimeout(){}, setInterval:()=>1, clearInterval(){},
    require(name) {
      if(name==='axios')return axios;
      if(name==='./ims-discovery')return {discover:async()=>[]};
      if(name==='./pra-ims-bridge')return {createBridge:()=>async()=>{}};
      if(name==='os')return {...os,homedir:()=>root};
      if(name==='./printer')return {startPrinting(){},stopPrinting(){},getPrintStatus:()=>({}),reportPrinters:async()=>{},nudgePrintPoll(){}};
      if(name==='./diagnostic-buffer')return {DiagnosticBuffer:class extends DiagnosticBuffer {constructor(dir,c){super(dir,c,()=>clock);}}};
      return actualRequire(name);
    }};
  vm.runInNewContext(fs.readFileSync(source,'utf8'),box,{filename:source});
  const agent=box.module.exports;
  const config={serverUrl:'https://synthetic.invalid/api/agent',companyId:1,deviceUid:'device-one',apiKey:'synthetic-key'};
  const buffer=(c=config)=>new DiagnosticBuffer(path.join(root,'.taxnest-pra-agent'),c,()=>clock);
  const reply=(request,accepted)=>request.resolve({data:{company:{id:request.body.company_id},diagnostic_evidence_ack:{version:1,stream_id:request.body.diagnostic_evidence.stream_id,accepted}}});
  t.after(()=>agent.stopAgent());
  agent.setHeartbeatExtraProvider(()=>({agent_diagnostics:{printer:{printing_enabled:true,healthy:false,printers_reported:2},queue:{pending_callbacks:3}}}));
  return {agent,config,requests,buffer,reply,advance:()=>{clock+=SAMPLE_MS;}};
}

test('actual heartbeat retains offline samples and only removes server-acknowledged evidence',async t=>{
  const h=harness(t);h.agent.startAgent(h.config);await tick();
  assert.equal(h.requests[0].body.diagnostic_evidence.events.length,0);
  h.reply(h.requests[0],[]);await tick();
  h.advance();h.agent.wakeAgent();await tick();
  const failed=h.requests[1];assert.equal(failed.body.diagnostic_evidence.events[0].pending_callbacks,3);
  failed.reject(new Error('Synthetic network cut'));await tick();
  assert.equal(h.buffer().batch().events.length,1);
  h.advance();h.agent.wakeAgent();await tick();
  assert.equal(h.requests[2].body.diagnostic_evidence.events.length,2);
  h.reply(h.requests[2],[1]);await tick();
  assert.deepEqual(h.buffer().batch().events.map(e=>e.sequence),[2]);
  assert.equal(h.agent.getStatus().connected,true);
});

test('late acknowledgement after company switch cannot consume either pairing buffer',async t=>{
  const h=harness(t);h.agent.startAgent(h.config);await tick();h.reply(h.requests[0],[]);await tick();
  h.advance();h.agent.wakeAgent();await tick();const old=h.requests[1];
  const next={...h.config,companyId:2,deviceUid:'device-two',apiKey:'other-synthetic-key'};
  h.agent.startAgent(next);await tick();
  h.reply(old,[1]);await tick();
  assert.equal(h.buffer().batch().events.length,1);
  assert.equal(h.buffer(next).state.enabled,false);
  h.reply(h.requests[2],[]);await tick();
  assert.equal(h.buffer(next).state.enabled,true);
  assert.equal(h.agent.getStatus().serverInfo.id,2);
});
