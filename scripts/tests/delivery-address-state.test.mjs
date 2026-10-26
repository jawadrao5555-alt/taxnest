import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');
function method(name){const start=source.search(new RegExp('^        (?:async )?'+name+'\\(', 'm'));assert(start>=0);return source.slice(start,source.indexOf('\n        },',start)+11).trim();}
function fixture(){
 const requests=[];const fetch=(url)=>new Promise((resolve,reject)=>requests.push({url,resolve,reject}));
 const context=vm.createContext({fetch,console:{error(){}},window:{TXT:{}},setTimeout,clearTimeout});
 const names=['hasDeliveryPortion','loadCustomerAddresses','saveNewAddress','selectLookedUpCustomer','selectCustomerWithStats','selectCustomerFromPhone','_selectQuickCustomer'];
 for(const name of ['waitForDeliveryAddress'])if(source.includes('        async '+name+'('))names.push(name);
 const ui=vm.runInContext('({'+names.map(method).join('\n')+'})',context);
 Object.assign(ui,{selectedCustomer:{id:1,name:'A',address:'A default'},orderType:'delivery',customerAddresses:[],selectedDeliveryAddress:'',showToast(){},$nextTick(){},_fetchWithTimeout:fetch});
 return {ui,requests,reply(n,addresses){requests[n].resolve({ok:true,json:async()=>({addresses})});}};
}
test('customer default is available immediately, before address-book response',async()=>{
 const f=fixture();const pending=f.ui.loadCustomerAddresses();assert.equal(f.ui.selectedDeliveryAddress,'A default');
 f.reply(0,[{id:0,address:'A default'}]);await pending;
});
test('a slower previous customer response never replaces the newer delivery address',async()=>{
 const f=fixture();const a=f.ui.loadCustomerAddresses();f.ui.selectedCustomer={id:2,name:'B',address:'B default'};const b=f.ui.loadCustomerAddresses();
 f.reply(1,[{id:0,address:'B default'}]);await b;f.reply(0,[{id:0,address:'A default'}]);await a;
 assert.equal(f.ui.selectedDeliveryAddress,'B default');assert.equal(f.ui.customerAddresses[0].address,'B default');
});
test('existing held/provisional snapshot stays selected while mutable address book loads',async()=>{
 const f=fixture();const pending=f.ui.loadCustomerAddresses('Original alternate address');
 assert.equal(f.ui.selectedDeliveryAddress,'Original alternate address');f.reply(0,[{id:0,address:'Changed customer default'}]);await pending;
 assert.equal(f.ui.selectedDeliveryAddress,'Original alternate address');
});
test('picker customer path initializes delivery selection without waiting for stats',async()=>{
 const f=fixture();const pending=f.ui.selectCustomerWithStats({id:2,name:'B',address:'B address'});
 assert.equal(f.ui.selectedDeliveryAddress,'B address');f.reply(0,[{id:0,address:'B address'}]);await pending;
});
test('phone lookup customer path initializes delivery selection',async()=>{
 const f=fixture();f.ui.customerLookupResult={found:true,stats:{},customer:{id:3,name:'C',address:'C address'}};
 f.ui.selectLookedUpCustomer();assert.equal(f.ui.selectedDeliveryAddress,'C address');f.reply(0,[{id:0,address:'C address'}]);
});
test('network failure preserves known default and does not invent another address',async()=>{
 const f=fixture();const pending=f.ui.loadCustomerAddresses();f.requests[0].reject(Error('offline'));await pending;
 assert.equal(f.ui.selectedDeliveryAddress,'A default');
});

test('submission barrier waits for the current address lookup before snapshotting',async()=>{
 const f=fixture();f.ui.selectedCustomer={id:4,name:'D'};f.ui.loadCustomerAddresses();
 let serialized=false;const payment=(async()=>{await f.ui.waitForDeliveryAddress();serialized=true;return f.ui.selectedDeliveryAddress;})();
 await Promise.resolve();assert.equal(serialized,false);f.reply(0,[{id:0,address:'D loaded address'}]);
 assert.equal(await payment,'D loaded address');
});
test('manual alternate choice is not overwritten by a delayed default response',async()=>{
 const f=fixture();const pending=f.ui.loadCustomerAddresses();f.ui.selectedDeliveryAddress='Manually selected alternate';
 f.ui._deliveryAddressChoiceVersion=1;f.reply(0,[{id:0,address:'A default'}]);await pending;
 assert.equal(f.ui.selectedDeliveryAddress,'Manually selected alternate');
});
test('cleared/new cart cannot receive previous customer address',async()=>{
 const f=fixture();const pending=f.ui.loadCustomerAddresses();f.ui.selectedCustomer=null;f.ui.selectedDeliveryAddress='';f.ui.customerAddresses=[];
 f.reply(0,[{id:0,address:'A default'}]);await pending;assert.equal(f.ui.selectedDeliveryAddress,'');
});
test('explicitly clearing the picker remains empty after a delayed response',async()=>{
 const f=fixture();f.ui.selectedCustomer={id:5,name:'E'};const pending=f.ui.loadCustomerAddresses();
 f.ui._deliveryAddressChoiceVersion=1;f.reply(0,[{id:0,address:'E default'}]);await pending;assert.equal(f.ui.selectedDeliveryAddress,'');
});

test('failed lookup without a known address blocks saving rather than silently omitting it',async()=>{
 const f=fixture();f.ui.selectedCustomer={id:4,name:'D'};f.ui.loadCustomerAddresses();
 const result=f.ui.waitForDeliveryAddress();f.requests[0].reject(Error('offline'));
 assert.equal(await result,false);assert.equal(f.ui.selectedDeliveryAddress,'');
});
test('a confirmed empty address book keeps delivery-without-address optional',async()=>{
 const f=fixture();f.ui.selectedCustomer={id:4,name:'D'};f.ui.loadCustomerAddresses();
 const result=f.ui.waitForDeliveryAddress();f.reply(0,[]);assert.equal(await result,true);
});

test('typed one-off address remains billable if saving the address book fails offline',async()=>{
 const f=fixture();f.ui.newAddrText='Cashier entered offline address';f.ui.newAddrLabel='Office';
 const pending=f.ui.saveNewAddress();assert.equal(f.ui.selectedDeliveryAddress,'Cashier entered offline address');
 f.requests[0].reject(Error('offline'));await pending;
 assert.equal(f.ui.selectedDeliveryAddress,'Cashier entered offline address');
 assert.equal(await f.ui.waitForDeliveryAddress(),true);
});
test('confirmed address-book save replaces its temporary one-off without duplicate options',async()=>{
 const f=fixture();f.ui.newAddrText='Saved office';f.ui.newAddrLabel='Office';
 const pending=f.ui.saveNewAddress();f.requests[0].resolve({json:async()=>({success:true,address:{id:7,address:'Saved office'}})});await pending;
 assert.equal(f.ui.customerAddresses.filter(a=>a.address==='Saved office').length,1);
 assert.equal(f.ui.customerAddresses.find(a=>a.address==='Saved office').id,7);
});

test('delivery portion on a Dine In bill accepts and awaits the same customer address response',async()=>{
 const f=fixture();f.ui.orderType='dine_in';f.ui.cart=[{fulfillment:'delivery'}];f.ui.selectedCustomer={id:22,name:'Mixed guest'};
 f.ui.loadCustomerAddresses();const wait=f.ui.waitForDeliveryAddress();f.reply(0,[{id:0,address:'Mixed destination'}]);
 assert.equal(await wait,true);assert.equal(f.ui.selectedDeliveryAddress,'Mixed destination');
});
