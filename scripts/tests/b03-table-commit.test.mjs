import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');
const start=source.indexOf('        async selectTable(table, opts) {');
const method=source.slice(start,source.indexOf('\n        },',start)+11).replace(/{{[\s\S]*?}}/g,'fixture');
function fixture(approve=false,defer=false){const requests=[],holds=[],prompts=[];const ui=vm.runInNewContext('({'+method+'})',{fetch:()=>new Promise(resolve=>{requests.push(resolve);if(!defer)resolve({ok:true,json:async()=>({success:true})});}),window:{TXT:{auto_kot_table_confirm:'Send these items now?',auto_kot_draft_kept:'Draft kept'}},confirm:message=>{prompts.push(message);return approve;}});Object.assign(ui,{cart:[{id:1,quantity:2}],selectedTable:null,orderType:'takeaway',kitchenSettings:{dine_in_auto_kot:true},editingBillId:null,recalledOrderId:null,incomingOrderId:null,incomingForTable:()=>null,hasUnsentCart:()=>true,holdBlockedByManual:()=>false,hasDealItems:()=>false,showToast:()=>{},loadTableStatus:()=>{},releaseTable:()=>{},holdOrder:async o=>{holds.push(o);return true;},guidedFlow:false});return{ui,requests,holds,prompts};}
const table={id:7,table_number:'7',status:'available'};
test('auto-KOT preference asks explicitly; declining retains the selected table and cart without sending',async()=>{const f=fixture();const cart=f.ui.cart;await f.ui.selectTable(table);assert.equal(f.prompts.length,1);assert.equal(f.holds.length,0);assert.equal(f.ui.cart,cart);assert.equal(f.ui.selectedTable.id,7);});
test('two overlapping table clicks reserve and dispatch at most once',async()=>{const f=fixture(true,true);const a=f.ui.selectTable(table),b=f.ui.selectTable(table);assert.equal(f.requests.length,1);f.requests[0]({ok:true,json:async()=>({success:true})});await Promise.all([a,b]);assert.equal(f.holds.length,1);assert.equal(f.prompts.length,1);assert.equal(f.ui._tableSelectionBusy,false);});
test('auto-KOT OFF remains a draft selection without a prompt or dispatch',async()=>{const f=fixture(true);f.ui.kitchenSettings.dine_in_auto_kot=false;await f.ui.selectTable(table);assert.equal(f.prompts.length,0);assert.equal(f.holds.length,0);});
