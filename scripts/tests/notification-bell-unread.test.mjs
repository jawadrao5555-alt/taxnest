import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const html=fs.readFileSync(new URL('../../resources/views/partials/whats-new-detail-modals.blade.php',import.meta.url),'utf8');
function fixture(fetch,readonly=false){
 const match=html.match(/x-data="([\s\S]*?)"\s+@whats-new-seen/);assert(match);
 const expression=match[1].replace(/\{\{ in_array\([^\n]+\}\}/g,'false')
  .replaceAll('{{ (int) $detailUpdate->id }}','123').replace('@json(empty($seenEndpoint))',readonly?'true':'false');
 const events=[];const ui=vm.runInNewContext('('+expression+')',{fetch,
  window:{TnModalA11y:{open(){},close(){}},dispatchEvent:e=>events.push(e)},
  document:{querySelector:()=>({content:'synthetic-csrf'})},CustomEvent:class{constructor(type,args){this.type=type;this.detail=args.detail;}}});
 ui.$refs={detailDialog:{}};ui.$nextTick=fn=>fn();ui.$el={closest:()=>({dataset:{tnSeenEndpoint:'/pos/whats-new/seen'}})};
 return {ui,events};
}
test('opening a particular notification acknowledges it and decrements only after success',async()=>{
 let calls=0;const f=fixture(async(url,options)=>{calls++;assert.equal(url,'/pos/whats-new/seen');assert.equal(JSON.parse(options.body).update_id,123);return {ok:true,json:async()=>({ok:true})};});
 await f.ui.openDetail();assert.equal(calls,1);assert.equal(f.ui.wasSeen,true);assert.equal(f.events.length,1);
});
test('read is confirmed once despite concurrent open, close and reopen',async()=>{
 let resolve;let calls=0;const f=fixture(()=>{calls++;return new Promise(r=>resolve=r);});
 const pending=f.ui.openDetail();await f.ui.openDetail();f.ui.dismissDetail();
 assert.equal(calls,1);assert.equal(f.ui.wasSeen,false);assert.equal(f.events.length,0);
 resolve({ok:true,json:async()=>({ok:true})});await pending;await f.ui.openDetail();
 assert.equal(calls,1);assert.equal(f.events.length,1);
});
for(const failure of ['http','payload','network'])test(failure+' failure keeps unread and allows retry',async()=>{
 let calls=0;const f=fixture(async()=>{calls++;if(calls===1){if(failure==='network')throw new Error('offline');return {ok:failure!=='http',json:async()=>({ok:false})};}return {ok:true,json:async()=>({ok:true})};});
 await f.ui.openDetail();assert.equal(f.ui.wasSeen,false);assert.equal(f.ui.seenError,true);assert.equal(f.events.length,0);
 await f.ui.markDetailSeen();assert.equal(f.ui.wasSeen,true);assert.equal(f.ui.seenError,false);assert.equal(f.events.length,1);
});
test('read-only impersonation opens details without acknowledgement',async()=>{
 const f=fixture(()=>{throw new Error('must not send');},true);await f.ui.openDetail();
 assert.equal(f.ui.open,true);assert.equal(f.ui.wasSeen,false);assert.equal(f.events.length,0);
});
for(const layout of ['pos-app','fbr-pos-app'])test(layout+': repeated and unrelated acknowledgement never double decrements',()=>{
 const source=fs.readFileSync(new URL('../../resources/views/layouts/'+layout+'.blade.php',import.meta.url),'utf8');
 const handler=source.match(/@whats-new-seen.window="(unreadIds = [^"]+)"/)[1];
 const state={unreadIds:[12,13,14],unseen:3,$event:{detail:{id:13}}};
 vm.runInNewContext(handler,state);assert.equal(state.unseen,2);
 vm.runInNewContext(handler,state);assert.equal(state.unseen,2);
 state.$event.detail.id=999;vm.runInNewContext(handler,state);assert.equal(state.unseen,2);
});
