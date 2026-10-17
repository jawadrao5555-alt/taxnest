'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

async function submit(fail) {
  const calls = [];
  const context = {module: {exports: {}}, console: {log() {}}, setTimeout, clearTimeout, setInterval, clearInterval,
    require(name) {
      if (name === 'axios') return {async post(url, body) {
        if (url.endsWith('/submit-result')) {calls.push(body); return {data: {ok: true}};}
        if (fail) throw Object.assign(new Error('connection refused'), {code: 'ECONNREFUSED'});
        return {data: {Code: 100, InvoiceNumber: '900000000001'}};
      }};
      if (name === './printer') return {};
      if (name.startsWith('./')) return require(path.join(__dirname, '../src', name));
      return require(name);
    }};
  const source = fs.readFileSync(path.join(__dirname, '../src/agent.js'), 'utf8');
  vm.runInNewContext(source + '\nmodule.exports.labSubmit = async () => { currentConfig = {serverUrl:"http://127.0.0.1/api/agent",appVersion:"test"}; await submitToPra({transaction_id:91,payload:{},submission_claim:"claim-test"},"http://127.0.0.1:8524/api/IMSFiscal/GetInvoiceNumberByModel","","fiscal_device","production"); };', context);
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
