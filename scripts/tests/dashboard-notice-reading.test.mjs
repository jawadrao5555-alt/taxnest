import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../../resources/views/pos/restaurant/dashboard.blade.php',import.meta.url),'utf8');
function fixture(){
 let open=false,hidden=false,reloads=0,requests=0,release;
 const methods=source.slice(source.indexOf('        hasOpenDashboardOverlay()'),source.indexOf('        renderSalesChart()'));
 const ui=vm.runInNewContext('({refreshing:false,'+methods+'})',{
  document:{querySelectorAll:()=>[{getClientRects:()=>open?[{}]:[]},{getClientRects:()=>hidden?[{}]:[]}]},
  window:{location:{href:'http://localhost/pos/restaurant/dashboard',reload:()=>reloads++}},
  fetch:()=>{requests++;return new Promise(resolve=>{release=resolve;});},
 });
 return {ui,open:value=>{open=value;},reply:ok=>release({ok}),get requests(){return requests;},get reloads(){return reloads;}};
}
test('reading an open notice or bell skips refresh entirely',async()=>{
 const f=fixture();f.open(true);await f.ui.refreshDashboard();assert.equal(f.requests,0);assert.equal(f.reloads,0);assert.equal(f.ui.refreshing,false);
});
test('notice opened during reachability probe survives the response',async()=>{
 const f=fixture();const pending=f.ui.refreshDashboard();assert.equal(f.requests,1);f.open(true);f.reply(true);await pending;assert.equal(f.reloads,0);assert.equal(f.ui.refreshing,false);
});
test('after dismissal normal refresh resumes, hidden dialogs do not block it',async()=>{
 const f=fixture();f.open(true);await f.ui.refreshDashboard();f.open(false);const pending=f.ui.refreshDashboard();f.reply(true);await pending;assert.equal(f.reloads,1);
});
test('failed reachability and concurrent refresh do not reload or duplicate requests',async()=>{
 const f=fixture();const pending=f.ui.refreshDashboard();await f.ui.refreshDashboard();assert.equal(f.requests,1);f.reply(false);await pending;assert.equal(f.reloads,0);assert.equal(f.ui.refreshing,false);
});
