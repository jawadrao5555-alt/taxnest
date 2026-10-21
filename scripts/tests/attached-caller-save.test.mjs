import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const s=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');
function method(name){const a=s.indexOf('        '+name+'(');return s.slice(a,s.indexOf('\n        },',a)+11);}
function fixture(customer){let focus=0,dismiss=0;const u=vm.runInNewContext('({'+method('saveAttachedCallerCustomer')+method('callerSaveCustomer')+'})');Object.assign(u,{selectedCustomer:customer,cart:[{id:1,quantity:4}],dismissCallerPopup(){dismiss++;},$nextTick:fn=>fn(),$refs:{newCustomerNameInput:{focus(){focus++;}}}});return{u,get focus(){return focus;},get dismiss(){return dismiss;}};}
test('attached unsaved phone opens existing name form with a blank name and preserves cart',()=>{const f=fixture({id:null,phone:'03000000000',name:'03000000000'});const cart=f.u.cart;f.u.saveAttachedCallerCustomer();assert.equal(f.u.newCustomerPhone,'03000000000');assert.equal(f.u.newCustomerName,'');assert.equal(f.u.showNewCustomerInline,true);assert.equal(f.u.customerPhoneDropdown,true);assert.equal(f.focus,1);assert.equal(f.u.cart,cart);});
test('a real incoming caller name is retained, formatted phone follows existing save flow',()=>{const f=fixture({id:null,phone:'+92 300-0000000',name:'Fixture caller'});f.u.saveAttachedCallerCustomer();assert.equal(f.u.newCustomerPhone,'+923000000000');assert.equal(f.u.newCustomerName,'Fixture caller');});
test('saved customers or missing phone cannot open an accidental duplicate-create form',()=>{for(const c of [null,{id:12,phone:'03000000000'},{id:null,phone:''}]){const f=fixture(c);f.u.saveAttachedCallerCustomer();assert.equal(f.focus,0);assert.equal(f.u.showNewCustomerInline,undefined);}});
