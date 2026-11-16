import test from 'node:test';
import assert from 'node:assert/strict';
import {verifyAiUsageAdminThemes} from '../lib/ai-usage-theme-browser.mjs';
function fixture(fail=false){
 const events=[], saved={theme:'rose',stored:null,dark:true};
 return {events,page:{
  evaluate:async(fn,arg)=>{if(!arg&&fn.toString().includes('getAdminTheme'))return saved;if(arg){events.push(arg);return;}if(fn.toString().includes('const rgb='))return fail?['Synthetic contrast failure']:[];throw Error('Unexpected evaluation');},
  locator:selector=>({scrollIntoViewIfNeeded:async()=>{},click:async options=>{assert.deepEqual(options,{trial:true});events.push(selector);},boundingBox:async()=>({x:20,width:180,height:40})})
 }};
}
test('six palettes, both modes, five controls and original preference restore',async()=>{
 const {events,page}=fixture();let passed=0;await verifyAiUsageAdminThemes(page,()=>passed++,390);
 assert.equal(passed,1);assert.equal(events.filter(x=>typeof x==='string').length,60);
 assert.deepEqual(events.filter(x=>typeof x==='object'&&!('stored'in x)),['indigo','emerald','cyan','rose','amber','purple'].flatMap(theme=>[false,true].map(dark=>({theme,dark}))));
 assert.deepEqual(events.at(-1),{theme:'rose',stored:null,dark:true});
});
test('contrast failure cannot claim pass and still restores preference',async()=>{
 const {events,page}=fixture(true);await assert.rejects(verifyAiUsageAdminThemes(page,()=>assert.fail('No pass allowed'),1366),/Synthetic contrast failure/);
 assert.deepEqual(events.at(-1),{theme:'rose',stored:null,dark:true});
});
