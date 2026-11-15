'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

async function submit(fail, numberOnly = false, soap = false) {
  const calls = [];
  const context = {module: {exports: {}}, console: {log() {}}, setTimeout, clearTimeout, setInterval, clearInterval,
    require(name) {
      if (name === 'axios') return {async post(url, body) {
        if (url.endsWith('/submit-result')) {calls.push(body); return {data: {ok: true}};}
        if (fail) throw Object.assign(new Error('connection refused'), {code: 'ECONNREFUSED'});
        return {status:200,data: numberOnly ? {FBRInvoiceNumber:'654321ABC20260001'} : {Code: 100, InvoiceNumber: '900000000001'}};
      }};
      if (name === './printer') return {};
      if (numberOnly && name === './ims-discovery') return {discover:async()=>[]};
      if (numberOnly && name === './ims-soap') return {endpoint:()=>soap,submit:async()=>({status:200,data:{FBRInvoiceNumber:'654321ABC20260001'}})};
      if (name.startsWith('./')) return require(path.join(__dirname, '../src', name));
      return require(name);
    }};
  const source = fs.readFileSync(path.join(__dirname, '../src/agent.js'), 'utf8');
  vm.runInNewContext(source + '\nmodule.exports.labSubmit = async () => { currentConfig = {serverUrl:"http://127.0.0.1/api/agent",appVersion:"test",deviceUid:"LAB-PC"}; await submitToPra({transaction_id:91,payload:{},submission_claim:"claim-test",requested_environment:"production"},"http://127.0.0.1:8524/api/IMSFiscal/GetInvoiceNumberByModel","","fiscal_device","production",' + JSON.stringify(numberOnly) + '); };', context);
  await context.module.exports.labSubmit();
  return calls[0];
}
test('actual agent preserves the exclusive claim on success callback', async () => {
  const result = await submit(false);
  assert.equal(result.submission_claim, 'claim-test');
  assert.equal(result.success, true);
});
test('actual agent preserves the exclusive claim on transport failure callback', async () => {
  const result = await submit(true);
  assert.equal(result.submission_claim, 'claim-test');
  assert.equal(result.success, false);
});

for (const soap of [false,true]) test(`number-only ${soap?'SOAP':'REST'} preserves transport shape without fabricating acceptance`, async()=>{
 const result=await submit(false,true,soap);
 assert.equal(result.success,false);assert.equal(result.outcome_unknown,true);
 assert.equal(result.response.Code,undefined);assert.equal(result.pra_invoice_number,'654321ABC20260001');
 assert.equal(result.device_uid,'LAB-PC');assert.equal(result.submission_claim,'claim-test');
 assert.equal(result.ims_diagnostic,'response_received');
 assert.equal(JSON.stringify(result.ims_response_fields),JSON.stringify(['FBRInvoiceNumber:string']));
});
