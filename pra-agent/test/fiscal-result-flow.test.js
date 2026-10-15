'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

async function submit(response) {
  const calls = [];
  const source = fs.readFileSync(path.join(__dirname, '../src/agent.js'), 'utf8');
  const context = {
    module: { exports: {} }, console: { log() {} },
    setTimeout, clearTimeout, setInterval, clearInterval,
    require(name) {
      if (name === 'axios') return { async post(url, body) {
        if (url.endsWith('/submit-result')) { calls.push(body); return { data: { ok: true } }; }
        return { data: response };
      }};
      if (name === './printer') return {};
      if (name.startsWith('./')) return require(path.join(__dirname, '../src', name));
      return require(name);
    },
  };
  vm.runInNewContext(source + '\nmodule.exports.labSubmit = async () => { currentConfig = {serverUrl:"http://127.0.0.1/api/agent",appVersion:"test"}; await submitToPra({transaction_id:91,payload:{}},"http://127.0.0.1:8524/api/IMSFiscal/GetInvoiceNumberByModel","","fiscal_device","production"); };', context);
  await context.module.exports.labSubmit();
  return calls[0];
}

test('success text is never accepted as a fiscal number', async () => {
  const result = await submit({ Code: 100, Response: 'Invoice generated successfully' });
  assert.equal(result.success, false);
  assert.equal(result.pra_invoice_number, null);
});
test('FBRInvoiceNumber is recognized and preserved', async () => {
  const result = await submit({ Code: 100, FBRInvoiceNumber: '123456ABC20260001' });
  assert.equal(result.success, true);
  assert.equal(result.pra_invoice_number, '123456ABC20260001');
});
test('existing InvoiceNumber and string code stay compatible', async () => {
  const result = await submit({ Code: '100', InvoiceNumber: '12345620260001' });
  assert.equal(result.success, true);
  assert.equal(result.pra_invoice_number, '12345620260001');
});
