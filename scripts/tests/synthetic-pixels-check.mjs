import {assertDetailBounds} from '../lib/scrolled-pixel-detail.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';
import {pixelName,pixelOrigin,verifyMarker,initializeSyntheticPixels,captureSyntheticPixels,validateCaptureContext,fixtureIdentity} from '../lib/synthetic-pixel-capture.mjs';
test('only exact known screenshot names',()=>{assert.equal(pixelName('school-fee-cash-receipt-390'),'school-fee-cash-receipt-390.png');for(const n of ['../secret','school-fee-cash-receipt-1280','arbitrary-390','school-fee-cash-receipt-390.png'])assert.throws(()=>pixelName(n));});
test('loopback origin only, no credentials, paths or query',()=>{assert.equal(pixelOrigin('http://127.0.0.1:8273'),'http://127.0.0.1:8273');for(const u of ['https://taxnest.pk','http://localhost.evil','http://a:b@localhost','http://localhost/path','http://localhost/?token=a','http://localhost/#x','file:///tmp/a'])assert.throws(()=>pixelOrigin(u));});
test('marker binds exact fixture hash and nonce',()=>{const marker={version:1,synthetic:true,nonce:'nonce',fixture_sha256:'hash',fixture_kind:'standalone-browser',database:'taxnest_rc_browser'};verifyMarker(marker,'hash','nonce','standalone-browser','taxnest_rc_browser');for(const r of [{...marker,synthetic:false},{...marker,nonce:'other'},{...marker,fixture_sha256:'other'}])assert.throws(()=>verifyMarker(r,'hash','nonce','standalone-browser','taxnest_rc_browser'));});
test('disabled by default and unguarded fixture denied',async()=>{assert.equal(initializeSyntheticPixels({}),false);assert.equal(await captureSyntheticPixels({},'school-fee-cash-receipt-390'),false);assert.throws(()=>initializeSyntheticPixels({flag:'1',browser:{},sourceSha:'a'.repeat(40),fixturePath:'/tmp/arbitrary.json',baseUrl:'http://localhost'}));});

test('capture rejects changed browser, origin, frames and viewport',()=>{
 const browser={}, session={browser,origin:'http://127.0.0.1:8000'}, viewport={width:390,height:844};
 const page={context:()=>({browser:()=>browser}),url:()=>session.origin+'/receipt',frames:()=>[{url:()=>session.origin+'/receipt'}],viewportSize:()=>viewport};
 validateCaptureContext(page,session,viewport);
 for(const override of [{context:()=>({browser:()=>({})})},{url:()=> 'https://taxnest.pk/receipt'},{frames:()=>[{url:()=> 'https://external.test'}]},{viewportSize:()=>({width:1280,height:900})}])assert.throws(()=>validateCaptureContext({...page,...override},session,viewport));
});

test('ERP fixture is an exact second identity, never a generic alternate database',()=>{
 const p='/tmp/taxnest-erp-demo-1000-abc123/safe-runtime/browser-state/fixture.json';
 const f={fixture_kind:'hospital-erp-demo',database:'taxnest_erp_demo',socket:'/tmp/taxnest-rc-mariadb-erp-demo-1000-abc123/run/mariadb.sock'};
 assert.equal(fixtureIdentity(p,f),'hospital-erp-demo');
 for(const override of [{fixture_kind:'standalone-browser'},{database:'production'},{database:'taxnest_rc_browser'},{socket:'/tmp/taxnest-rc-mariadb-erp-demo-1000-other/run/mariadb.sock'}])assert.throws(()=>fixtureIdentity(p,{...f,...override}));
 for(const path of ['/tmp/arbitrary/fixture.json',p.replace('1000-abc123','1000-abc123/../other'),p.replace('taxnest-erp-demo','taxnest-rc-browser')])assert.throws(()=>fixtureIdentity(path,f));
 assert.throws(()=>verifyMarker({version:1,synthetic:true,nonce:'n',fixture_sha256:'h',fixture_kind:'standalone-browser',database:'taxnest_rc_browser'},'h','n','hospital-erp-demo','taxnest_erp_demo'));
});

test('scrolled detail proof refuses clipped, missing or masked-footer-region content',()=>{
 const viewport={width:390,height:844},good={x:20,y:20,width:300,height:600};
 assertDetailBounds([good],viewport);
 for(const rect of [null,{...good,y:-1},{...good,height:830},{...good,x:100},{...good,width:0}])assert.throws(()=>assertDetailBounds([rect],viewport));
 assert.throws(()=>assertDetailBounds([],viewport));
});

test('AI rate controls permit only explicit product names and fixed viewports',()=>{
 for(const product of ['pos','fbr'])for(const width of [1366,390])assert.equal(pixelName(`ai-usage-controls-${product}-${width}`),`ai-usage-controls-${product}-${width}.png`);
 for(const name of ['ai-usage-controls-erp-390','ai-usage-controls-pos-1280','ai-usage-controls-fbr-390.png','ai-usage-controls-../secret-390'])assert.throws(()=>pixelName(name));
});

test('escalation proof permits only the two exact retry states',()=>{
 for(const state of ['pending','recovered'])for(const width of [1366,390])assert.equal(pixelName(`madadgar-retry-${state}-${width}`),`madadgar-retry-${state}-${width}.png`);
 for(const name of ['madadgar-retry-arbitrary-390','madadgar-retry-pending-1280','madadgar-retry-recovered-390.png','../madadgar-retry-pending-390'])assert.throws(()=>pixelName(name));
});

test('DI bounded read evidence allows only two reviewed names at existing viewports',()=>{
 for(const prefix of ['di-read-billing','di-read-payments-unknown'])for(const width of [1366,390])assert.equal(pixelName(`${prefix}-${width}`),`${prefix}-${width}.png`);
 for(const name of ['di-read-custom-390','di-read-billing-1280','di-read-payments-unknown-390.png','../di-read-billing-390'])assert.throws(()=>pixelName(name));
});
