import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source = readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');
function harness() {
 const handlers={}, entries=new Map(), writes=[];
 const key=r=>typeof r==='string'?new URL(r,'https://synthetic.test').href:r.url;
 let network=async()=>new Response('fresh',{headers:{'Cache-Control':'private, no-store'}});
 const cache={delete:async r=>entries.delete(key(r)), put:async(r,v)=>{writes.push(key(r));entries.set(key(r),v);},match:async r=>entries.get(key(r))};
 const context={self:{addEventListener:(n,fn)=>handlers[n]=fn},location:{origin:'https://synthetic.test'},URL,Response,Request,Headers,Promise,console,
 caches:{keys:async()=>['old-runtime'],open:async()=>cache,match:async r=>cache.match(r)},fetch:(...a)=>network(...a)};
 vm.runInNewContext(source,context);
 return {entries,writes,setNetwork:fn=>network=fn,async navigate(path){let result;const request={url:'https://synthetic.test'+path,method:'GET',mode:'navigate',headers:new Headers()};handlers.fetch({request,respondWith:p=>result=p,waitUntil:()=>{}});return result;}};
}
test('collection and shared FBR landing paths evict stale retail responses and fail closed offline', async()=>{
 for(const path of ['/fbr-pos/school-fees','/fbr-pos/school-fees/1/receipt','/fbr-pos/hospital','/fbr-pos/hospital/receipts/1','/fbr-pos/dashboard','/fbr-pos/transactions?date=2026-11-15','/fbr-pos/day-close']) {
 const h=harness();h.entries.set('https://synthetic.test'+path,new Response('old retail secret'));
 h.setNetwork(async()=>{throw new Error('offline');}); const response=await h.navigate(path);
 assert.equal(response.status,503,path);assert.doesNotMatch(await response.text(),/old retail secret/);assert.equal(h.entries.size,0);assert.equal(h.writes.length,0);
 }
});
test('generic navigation honors no-store/private, removes prior cached response, and never falls back to private content',async()=>{
 for(const policy of ['private','no-store','private, max-age=60']) {
 const h=harness(),path='/reports';h.entries.set('https://synthetic.test'+path,new Response('old snapshot'));
 h.setNetwork(async()=>new Response('new private',{headers:{'Cache-Control':policy}}));
 assert.equal(await (await h.navigate(path)).text(),'new private');assert.equal(h.entries.size,0);assert.equal(h.writes.length,0);
 h.entries.set('https://synthetic.test'+path,new Response('bad stored private',{headers:{'Cache-Control':policy}}));
 h.setNetwork(async()=>{throw new Error('offline');});assert.equal((await h.navigate(path)).status,503);assert.equal(h.entries.size,0);
 }
});
test('public generic documents can still be cached; dedicated retail sale handlers remain intact',async()=>{
 const h=harness();h.setNetwork(async()=>new Response('public page',{headers:{'Cache-Control':'public,max-age=60'}}));
 assert.equal(await(await h.navigate('/public-help')).text(),'public page');assert.equal(h.writes.length,1);
 assert.match(source,/SALE_DOCUMENT_MARKERS\[url.pathname\]/);assert.match(source,/url.pathname === '\/pos\/restaurant\/tables'/);assert.match(source,/url.pathname === WAITER_PAGE/);
});
