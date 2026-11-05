import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');
function method(name){
 const start=source.search(new RegExp('^        (?:get )?'+name+'\\(','m'));
 assert.ok(start>=0,name);
 return source.slice(start,source.indexOf('\n        },',start)+11).trim();
}
function fixture(){
 const methods=['imsFee','totalAmount','totalBeforeImsFee','cartTotalForMethod','cartTotalBeforeImsFee','cardSaveTotalForRate','cardSaveBeforeImsFee','heldOrderEstimate'];
 const ui=vm.runInNewContext('({'+methods.map(method).join('\n')+'})');
 Object.assign(ui,{cart:[{}],imsFeeConfigured:1,praEnabled:true,saveAsProvisional:false,editingBillId:null,
  taxInclusive:false,taxMenuRate:null,taxRate:16,taxAmount:16,taxableSubtotal:100,effectiveSubtotal:100,discountAmount:0,
  taxRules:{cash:16,debit_card:5},r2:v=>Math.round(v*100)/100,allocatedDealBill:()=>null,cardSaveMode:()=>false});
 return ui;
}
test('one fee in cart and cash/card choices; fee is outside unchanged tax',()=>{
 const ui=fixture();assert.equal(ui.totalAmount,117);assert.equal(ui.taxAmount,16);
 assert.equal(ui.cartTotalForMethod('cash'),117);assert.equal(ui.cartTotalForMethod('card'),106);
 ui.saveAsProvisional=true;assert.equal(ui.totalAmount,116);
 ui.saveAsProvisional=false;ui.praEnabled=false;assert.equal(ui.totalAmount,116);
 ui.praEnabled=true;ui.editingBillId=1;ui.editingBillData={fbr_service_charge:0};assert.equal(ui.totalAmount,116);
 ui.imsFeeConfigured=0;ui.editingBillData.fbr_service_charge=1;assert.equal(ui.totalAmount,117);
});
test('inclusive, card-save and deal totals include fee once',()=>{
 const ui=fixture();ui.taxInclusive=true;ui.effectiveSubtotal=116;assert.equal(ui.totalAmount,117);
 ui.taxMenuRate=16;ui.taxRate=5;ui.taxableSubtotal=116;ui.cardSaveMode=()=>true;
 assert.equal(ui.totalAmount,106);assert.equal(ui.cartTotalForMethod('card'),106);assert.equal(ui.cardSaveTotalForRate(5),106);
 ui.allocatedDealBill=()=>({total_amount:232});assert.equal(ui.totalAmount,233);assert.equal(ui.cartTotalForMethod('cash'),233);
});
test('held-order payment modal includes the same single fee',()=>{
 const ui=fixture();ui.cart=[];ui.payingHeldOrderId=3;
 ui.heldOrders=[{id:3,discount_amount:0,items:[{subtotal:100,is_tax_exempt:false}]}];
 assert.equal(ui.heldOrderEstimate('cash'),117);assert.equal(ui.heldOrderEstimate('card'),106);
 ui.saveAsProvisional=true;assert.equal(ui.heldOrderEstimate('cash'),116);
});
