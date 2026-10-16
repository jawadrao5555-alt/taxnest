'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

async function submit(error, isFbrPos = true, response = null) {
  const calls = [];
  const source = fs.readFileSync(path.join(__dirname, '../src/agent.js'), 'utf8');
  const context = {
    isFbrPos, module: { exports: {} }, console: { log() {} },
    setTimeout, clearTimeout, setInterval, clearInterval,
    require(name) {
      if (name === 'axios') return { async post(url, body) {
        if (url.endsWith('/submit-result')) { calls.push(body); return { data: { ok: true } }; }
        if (response !== null) return {data: response};
        throw error;
      }};
      if (name === './printer') return {};
      if (name.startsWith('./')) return require(path.join(__dirname, '../src', name));
      return require(name);
    },
  };
  vm.runInNewContext(source + '\nmodule.exports.labSubmit = async () => { currentConfig = {serverUrl:"http://127.0.0.1/api/agent",appVersion:"test"}; await submitToPra({transaction_id:91,payload:{},submission_claim:"claimed-91"},"http://127.0.0.1:8524/api/IMSFiscal/GetInvoiceNumberByModel","","fiscal_device","production",isFbrPos); };', context);
  await context.module.exports.labSubmit();
  return calls[0];
}

test('FBR timeout reports unknown outcome instead of rejection', async () => {
 const result = await submit(Object.assign(new Error('timeout of 30000ms exceeded'), {code:'ECONNABORTED'}));
 assert.equal(result.outcome_unknown, true);
 assert.equal(result.submission_claim, "claimed-91");
 assert.equal(result.success, false);
});
test('FBR connection refusal remains safe to queue', async () => {
 const result = await submit(Object.assign(new Error('connect ECONNREFUSED'), {code:'ECONNREFUSED'}));
 assert.equal(result.outcome_unknown, undefined);
 assert.equal(result.offline, true);
});
test('PRA legacy transport behavior remains unchanged', async () => {
 const result = await submit(Object.assign(new Error('timeout of 30000ms exceeded'), {code:'ECONNABORTED'}), false);
 assert.equal(result.outcome_unknown, undefined);
 assert.equal(result.offline, true);
});
test('FBR HTTP 503 reports unknown outcome instead of safe rejection', async () => {
 const result = await submit(Object.assign(new Error('service unavailable'), {response:{status:503,data:{message:'upstream unavailable'}}}));
 assert.equal(result.outcome_unknown, true);
 assert.equal(result.submission_claim, "claimed-91");
 assert.equal(result.success, false);
});
test('actual callback queue replay retains the unknown-outcome flag', async () => {
 const calls = [];
 const context = {module:{exports:{}},console:{log(){}},setTimeout,clearTimeout,setInterval,clearInterval,
  require(name) {
   if (name === 'axios') return {async post(url,body){calls.push(body);return {data:{ok:true}};}};
   if (name === './printer') return {};
   if (name.startsWith('./')) return require(path.join(__dirname,'../src',name));
   return require(name);
  }};
 const source = fs.readFileSync(path.join(__dirname,'../src/agent.js'),'utf8');
 vm.runInNewContext(source + '\nloadQueue = () => [{transaction_id:91,success:false,outcome_unknown:true,submission_claim:"queued-91",_enqueuedAt:new Date().toISOString(),_attempts:0}]; saveQueue = () => {}; module.exports.labFlush = async () => {currentConfig={serverUrl:"http://127.0.0.1/api/agent",appVersion:"test"}; await flushCallbackQueue();};',context);
 await context.module.exports.labFlush();
 assert.equal(calls.length,1);
 assert.equal(calls[0].outcome_unknown,true);
 assert.equal(calls[0].submission_claim,"queued-91");
});

test('FBR malformed acknowledgement is held while explicit rejection remains a rejection', async () => {
 const malformed = await submit(null, true, '<html>upstream</html>');
 assert.equal(malformed.outcome_unknown, true);
 assert.equal(malformed.submission_claim, 'claimed-91');
 const rejected = await submit(null, true, {Code:111,Response:'Invalid item'});
 assert.equal(rejected.outcome_unknown, undefined);
 assert.equal(rejected.success, false);
 assert.equal(rejected.submission_claim, 'claimed-91');
});
