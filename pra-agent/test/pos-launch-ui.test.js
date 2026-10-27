'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { bindPosLaunch } = require('../src/pos-launch-ui');
const { posBrowserUrl } = require('../src/pos-browser-url');
function button() { return { disabled:false,addEventListener(event,fn){this.click=fn;} }; }
function fixture(api){const button1=button(),browser=button(),status={textContent:''};bindPosLaunch(button1,browser,status,api);return{button:button1,browser,status};}
test('a refused/failed launch gives visible retry feedback without silently falling back',async()=>{
 let browserCalls=0;for(const action of [async()=>({ok:false}),async()=>{throw Error('redacted fixture');},async()=>undefined]){
  const f=fixture({openPosWindow:action,openPosInBrowser:async()=>{browserCalls++;return{ok:true};}});await f.button.click();assert.match(f.status.textContent,/POS nahi khul saki/);assert.equal(f.button.disabled,false);assert.equal(browserCalls,0);await f.browser.click();assert.equal(browserCalls,1);browserCalls=0;
 }
});
test('pending launch prevents double-click and success reports request acceptance only',async()=>{
 let resolve,calls=0;const f=fixture({openPosWindow:()=>{calls++;return new Promise(r=>resolve=r);}});const p=f.button.click();await f.button.click();assert.equal(calls,1);assert.equal(f.button.disabled,true);resolve({ok:true});await p;assert.equal(f.button.disabled,false);assert.match(f.status.textContent,/darkhwast/);
});
test('manual browser recovery uses only the configured origin and fixed POS path',()=>{
 assert.equal(posBrowserUrl('https://fixture.invalid/another?secret=fixture#fragment'),'https://fixture.invalid/pos/invoice/create');assert.equal(posBrowserUrl('http://127.0.0.1:8000/app'),'http://127.0.0.1:8000/pos/invoice/create');assert.equal(posBrowserUrl('https://fixture:fake@fixture.invalid/path'),'https://fixture.invalid/pos/invoice/create');for(const url of ['javascript:alert(1)','file:///tmp/fixture','broken'])assert.throws(()=>posBrowserUrl(url));
});
