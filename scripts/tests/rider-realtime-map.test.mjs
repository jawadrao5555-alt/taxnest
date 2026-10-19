import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../../resources/views/pos/rider-tracking.blade.php',import.meta.url),'utf8');
const js=source.slice(source.indexOf('    function riderTracking(cfg) {'),source.lastIndexOf('    </script>'));
function fixture(){
 const requests=[],timers=new Map();let counter=0;
 const document={hidden:false,removeEventListener(){}};
 const context=vm.createContext({document,window:{removeEventListener(){}},AbortController,
  fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject})),
  setTimeout:(fn,ms)=>{timers.set(++counter,{fn,ms});return counter;},clearTimeout:id=>timers.delete(id)});
 const ui=vm.runInContext(js+';riderTracking({dataUrl:"/tracking/data",i18n:{on_duty:"On duty"},shopLat:null,shopLng:null})',context);
 ui.renderMarkers=()=>{};ui.clearTrail=()=>{};return {ui,requests,timers,document,reply(i,riders,status=200){requests[i].resolve({ok:status===200,status,json:async()=>({ok:true,riders})});}};
}
test('visible map schedules five seconds AFTER completion with no cached response',async()=>{
 const f=fixture(),pending=f.ui.load();assert.equal(f.requests[0].options.cache,'no-store');
 assert.equal(f.timers.size,1);f.reply(0,[{id:1,on_duty:true}]);await pending;
 assert.equal(f.ui.riders[0].id,1);assert.deepEqual([...f.timers.values()].map(x=>x.ms),[5000]);
});
test('late older request cannot replace latest map state',async()=>{
 const f=fixture(),old=f.ui.load(),recent=f.ui.load();assert(f.requests[0].options.signal.aborted);
 f.reply(1,[{id:2,on_duty:true}]);await recent;f.reply(0,[{id:1}]);await old;
 assert.equal(f.ui.riders[0].id,2);assert.equal(f.timers.size,1);
});
test('network failure backs off and recovery restores cadence',async()=>{
 const f=fixture(),a=f.ui.load();f.requests[0].reject(Error('offline'));await a;
 assert.deepEqual([...f.timers.values()].map(x=>x.ms),[10000]);
 const b=f.ui.load();f.reply(1,[]);await b;
 assert.equal(f.ui.loadFailures,0);assert.deepEqual([...f.timers.values()].map(x=>x.ms),[5000]);
});
test('package loss clears retained rider positions and stops retrying',async()=>{
 const f=fixture();f.ui.riders=[{id:9}];const a=f.ui.load();f.reply(0,[],403);await a;
 assert.equal(f.ui.riders.length,0);assert.equal(f.timers.size,0);await f.ui.load();assert.equal(f.requests.length,1);
});
test('hidden and destroyed pages do not fetch; destroy ignores outstanding results',async()=>{
 const f=fixture();f.document.hidden=true;await f.ui.load();assert.equal(f.requests.length,0);
 f.document.hidden=false;const a=f.ui.load();f.ui.destroy();assert(f.requests[0].options.signal.aborted);
 f.reply(0,[{id:4}]);await a;assert.equal(f.ui.riders.length,0);assert.equal(f.timers.size,0);
});
