import test from 'node:test';
import assert from 'node:assert/strict';
import {pixelName,pixelOrigin,verifyMarker,initializeSyntheticPixels,captureSyntheticPixels,validateCaptureContext} from '../lib/synthetic-pixel-capture.mjs';
test('only exact known screenshot names',()=>{assert.equal(pixelName('school-fee-cash-receipt-390'),'school-fee-cash-receipt-390.png');for(const n of ['../secret','school-fee-cash-receipt-1280','arbitrary-390','school-fee-cash-receipt-390.png'])assert.throws(()=>pixelName(n));});
test('loopback origin only, no credentials, paths or query',()=>{assert.equal(pixelOrigin('http://127.0.0.1:8273'),'http://127.0.0.1:8273');for(const u of ['https://taxnest.pk','http://localhost.evil','http://a:b@localhost','http://localhost/path','http://localhost/?token=a','http://localhost/#x','file:///tmp/a'])assert.throws(()=>pixelOrigin(u));});
test('marker binds exact fixture hash and nonce',()=>{const marker={version:1,synthetic:true,nonce:'nonce',fixture_sha256:'hash'};verifyMarker(marker,'hash','nonce');for(const r of [{...marker,synthetic:false},{...marker,nonce:'other'},{...marker,fixture_sha256:'other'}])assert.throws(()=>verifyMarker(r,'hash','nonce'));});
test('disabled by default and unguarded fixture denied',async()=>{assert.equal(initializeSyntheticPixels({}),false);assert.equal(await captureSyntheticPixels({},'school-fee-cash-receipt-390'),false);assert.throws(()=>initializeSyntheticPixels({flag:'1',browser:{},sourceSha:'a'.repeat(40),fixturePath:'/tmp/arbitrary.json',baseUrl:'http://localhost'}));});

test('capture rejects changed browser, origin, frames and viewport',()=>{
 const browser={}, session={browser,origin:'http://127.0.0.1:8000'}, viewport={width:390,height:844};
 const page={context:()=>({browser:()=>browser}),url:()=>session.origin+'/receipt',frames:()=>[{url:()=>session.origin+'/receipt'}],viewportSize:()=>viewport};
 validateCaptureContext(page,session,viewport);
 for(const override of [{context:()=>({browser:()=>({})})},{url:()=> 'https://taxnest.pk/receipt'},{frames:()=>[{url:()=> 'https://external.test'}]},{viewportSize:()=>({width:1280,height:900})}])assert.throws(()=>validateCaptureContext({...page,...override},session,viewport));
});
