'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
test('actual service worker bypasses every pairing secret and Agent download navigation',()=>{
  const events={};let cacheCalls=0;
  const self={addEventListener:(name,handler)=>{events[name]=handler;}};
  const context={self,location:{origin:'https://fictional.example'},URL,Request,Response,
    caches:new Proxy({},{get:()=>{cacheCalls++;throw Error('Must not access cache');}})};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/sw.js'),'utf8'),context);
  for(const pathname of ['/pos/desktop/agent-pairing','/fbr-pos/desktop/agent-pairing','/pos/desktop/agent-config','/download/agent']) {
    let intercepted=false;
    events.fetch({request:{url:'https://fictional.example'+pathname,method:'GET',mode:'navigate'},
      respondWith:()=>{intercepted=true;},waitUntil:()=>{throw Error('Must not change caches');}});
    assert.equal(intercepted,false,pathname+' must remain network-only');
  }
  assert.equal(cacheCalls,0);
});
