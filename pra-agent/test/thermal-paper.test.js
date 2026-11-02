'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { normalizePaperProfiles, measuredPageSize } = require('../src/thermal-paper');

test('paper profiles require explicit width and preserve exact queue names', () => {
  assert.deepEqual(normalizePaperProfiles(null), {});
  assert.deepEqual(normalizePaperProfiles(['58']), {});
  assert.deepEqual(normalizePaperProfiles({'Kitchen (1)':58, 'Counter':80, 'POS-58':'58', 'A4':210, '':58}), {'Kitchen (1)':58, Counter:80});
  assert.equal(Object.getPrototypeOf(normalizePaperProfiles(JSON.parse('{"__proto__":58}'))), Object.prototype);
});
test('measured page includes footer allowance without imposing multi-metre driver length', () => {
  assert.deepEqual(measuredPageSize(58, 96), {width:58000,height:29000});
  assert.deepEqual(measuredPageSize(80, 960), {width:80000,height:257000});
  assert.deepEqual(measuredPageSize(58, 1), {width:58000,height:20000});
  for (const invalid of [NaN, Infinity, -1, 0, 100000]) assert.throws(() => measuredPageSize(58,invalid));
  assert.throws(() => measuredPageSize(210,96));
});

test('real settings IPC preserves PC profiles across legacy saves and separate companies', async () => {
  const fs=require('node:fs');const vm=require('node:vm');const path=require('node:path');
  const main=fs.readFileSync(path.join(__dirname,'../main.js'),'utf8');
  const source=main.slice(main.indexOf("ipcMain.handle('get-config'"),main.indexOf('// Task 1187: enumerate this PC'));
  const data={printerPaperProfiles:{Kitchen:58},config:{companyId:'first',receiptPrinter:'Kitchen'}};
  const handlers={};let active;
  vm.runInNewContext(source,{
    ipcMain:{handle:(name,fn)=>{handlers[name]=fn;}},
    store:{get:(key,fallback)=>data[key]??fallback,set:(key,value)=>{data[key]=value;}},
    normalizePaperProfiles,setPaperProfiles:value=>{active=value;},
    getMigratedAgentConfig:()=>data.config,canonicalAgentServerUrl:value=>value,
    DEFAULT_SERVER_URL:'http://127.0.0.1',stopAgent:()=>{},startAgent:()=>{},withAppMeta:value=>value,
    sendStatusUpdate:()=>{},handleAgentUpdate:()=>{},
  });
  await handlers['save-config'](null,{companyId:'second',receiptPrinter:'Counter'});
  assert.deepEqual(data.printerPaperProfiles,{Kitchen:58});
  assert.equal(handlers['get-config']().printerPaperProfiles.Kitchen,58);
  await handlers['save-config'](null,{companyId:'second',receiptPrinter:'Counter',printerPaperProfiles:{Kitchen:80}});
  assert.deepEqual(data.printerPaperProfiles,{Kitchen:80});assert.deepEqual(active,{Kitchen:80});
  assert.equal(Object.hasOwn(data.config,'printerPaperProfiles'),false,'never store physical queue settings in company configuration');
  await handlers['save-config'](null,{companyId:'second',receiptPrinter:'Counter',printerPaperProfiles:{}});
  assert.deepEqual(data.printerPaperProfiles,{});
});
