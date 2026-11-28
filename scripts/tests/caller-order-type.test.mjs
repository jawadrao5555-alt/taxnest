import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const html=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');
function callerMethod(name) {
 const start=html.indexOf('        '+name+'(ev) {');
 return html.slice(start,html.indexOf('\n        },',start)+11);
}
function fixture(types=['takeaway','delivery'],orderType='takeaway'){
 const calls=[];
 const window={TXT:{customer_prefix:'Customer: '}};
 const context=vm.createContext({window});
 vm.runInContext(fs.readFileSync(new URL('../../public/js/pos-phone-identity.js',import.meta.url),'utf8'),context);
 const methods=['callerBillFrom','callerNumberOf','callerVerifiedMatch'].map(callerMethod).join('');
 const ui=vm.runInContext('({'+methods+'})',context);
 Object.assign(ui,{orderType,cart:[{id:1,quantity:3}],guidedOrderTypes:()=>types,selectCustomerWithStats(customer){this.selectedCustomer=customer;},setOrderType(type){calls.push(type);this.orderType=type;},hasDeliveryPortion(){return this.orderType==='delivery';},loadCustomerAddresses(){this.selectedDeliveryAddress='';},showToast(){}});
 return {ui,calls};
}
test('explicit Bill for an unsaved caller selects Delivery and preserves existing cart',()=>{
 const {ui,calls}=fixture();const cart=ui.cart;ui.callerBillFrom({phone:'03000000000'});assert.equal(ui.orderType,'delivery');assert.deepEqual(calls,['delivery']);assert.equal(ui.selectedCustomer.phone,'03000000000');assert.equal(ui.selectedCustomer.id,null);assert.equal(ui.cart,cart);
});
test('existing matched caller keeps the same Delivery policy and saved identity',()=>{
 const {ui,calls}=fixture();ui.callerBillFrom({phone:'+92 300 0000001',match:{customer_id:12,name:'Fixture',phone:'03000000001',address:'Fixture lane'}});assert.deepEqual(calls,['delivery']);assert.equal(ui.selectedCustomer.id,12);assert.equal(ui.selectedCustomer.address,'Fixture lane');
});
test('a different incoming number never selects the saved customer or their delivery address',()=>{
 const {ui}=fixture(undefined,'delivery');
 ui.selectedDeliveryAddress='Previous customer address';
 const event={phone:'0300-0000067',name:'Caller',match:{customer_id:12,name:'Old match',phone:'03000000089',address:'Wrong lane'}};
 ui.callerBillFrom(event);
 assert.equal(ui.callerNumberOf(event),'0300-0000067');
 assert.equal(ui.selectedCustomer.phone,'0300-0000067');
 assert.equal(ui.selectedCustomer.id,null);
 assert.equal(ui.selectedDeliveryAddress,'');
});
test('a name-only ring cannot replace the current customer or supply a callback number',()=>{
 const {ui}=fixture();
 const selected=ui.selectedCustomer={id:1,name:'Current customer',phone:'03000000000'};
 const event={name:'Caller',match:{customer_id:12,matched_by:'name',phone:'03000000089',address:'Wrong lane'}};
 ui.callerBillFrom(event);
 assert.equal(ui.selectedCustomer,selected);
 assert.equal(ui.callerNumberOf(event),'');
});
test('a shop without Delivery retains its supported order type',()=>{
 const {ui,calls}=fixture(['takeaway']);ui.callerBillFrom({phone:'03000000000'});assert.equal(ui.orderType,'takeaway');assert.equal(calls.length,0);
});
test('already Delivery and empty caller never cause a redundant transition',()=>{
 const {ui,calls}=fixture(undefined,'delivery');ui.callerBillFrom(null);ui.callerBillFrom({phone:'03000000000'});assert.equal(calls.length,0);
});
