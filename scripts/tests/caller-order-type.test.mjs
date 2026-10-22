import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const html=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');
const start=html.indexOf('        callerBillFrom(ev) {');
const method=html.slice(start,html.indexOf('\n        },',start)+11);
function fixture(types=['takeaway','delivery'],orderType='takeaway'){
 const calls=[];
 const ui=vm.runInNewContext('({'+method+'})',{window:{TXT:{customer_prefix:'Customer: '}}});
 Object.assign(ui,{orderType,cart:[{id:1,quantity:3}],guidedOrderTypes:()=>types,selectCustomerWithStats(customer){this.selectedCustomer=customer;},setOrderType(type){calls.push(type);this.orderType=type;},showToast(){}});
 return {ui,calls};
}
test('explicit Bill for an unsaved caller selects Delivery and preserves existing cart',()=>{
 const {ui,calls}=fixture();const cart=ui.cart;ui.callerBillFrom({phone:'03000000000'});assert.equal(ui.orderType,'delivery');assert.deepEqual(calls,['delivery']);assert.equal(ui.selectedCustomer.phone,'03000000000');assert.equal(ui.selectedCustomer.id,null);assert.equal(ui.cart,cart);
});
test('existing matched caller keeps the same Delivery policy and saved identity',()=>{
 const {ui,calls}=fixture();ui.callerBillFrom({phone:'other',match:{customer_id:12,name:'Fixture',phone:'03000000001',address:'Fixture lane'}});assert.deepEqual(calls,['delivery']);assert.equal(ui.selectedCustomer.id,12);assert.equal(ui.selectedCustomer.address,'Fixture lane');
});
test('a shop without Delivery retains its supported order type',()=>{
 const {ui,calls}=fixture(['takeaway']);ui.callerBillFrom({phone:'03000000000'});assert.equal(ui.orderType,'takeaway');assert.equal(calls.length,0);
});
test('already Delivery and empty caller never cause a redundant transition',()=>{
 const {ui,calls}=fixture(undefined,'delivery');ui.callerBillFrom(null);ui.callerBillFrom({phone:'03000000000'});assert.equal(calls.length,0);
});
