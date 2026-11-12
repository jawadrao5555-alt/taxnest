import test from 'node:test';import assert from 'node:assert/strict';import fs from 'node:fs';import vm from 'node:vm';
const s=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');const a=s.indexOf('        async editCustomerContact(');const method=s.slice(a,s.indexOf('\n        },',a)+11).replace(/\{\{[\s\S]*?\}\}/g,'fixture-csrf');
function fixture(prompts=['Corrected','03000000001'],response={ok:true,success:true,customer:{id:1,name:'Corrected',phone:'03000000001',address:'Keep address'}}){const calls=[],toasts=[];const customer={id:1,name:'Old',phone:'03000000000',address:'Keep address'};const u=vm.runInNewContext('({'+method+'})',{window:{TXT:{},prompt:()=>prompts.shift()},fetch:async(url,options)=>{calls.push({url,...JSON.parse(options.body)});return{ok:response.ok,json:async()=>response};}});Object.assign(u,{selectedCustomer:customer,allCustomers:[{...customer}],customerPhoneResults:[{...customer}],editingCustomerId:null,showToast:(...args)=>toasts.push(args)});return{u,customer,calls,toasts};}
test('explicit correction keeps ID/address, updates active DTOs and sends old phone for conflict detection',async()=>{const f=fixture();await f.u.editCustomerContact(f.customer);assert.deepEqual(f.calls,[{url:'/pos/api/customers/1/contact',name:'Corrected',phone:'03000000001',expected_phone:'03000000000'}]);assert.equal(f.customer.id,1);assert.equal(f.customer.address,'Keep address');assert.equal(f.customer.phone,'03000000001');assert.equal(f.u.allCustomers[0].phone,'03000000001');assert.equal(f.u.customerPhoneResults[0].name,'Corrected');assert.equal(f.u.editingCustomerId,null);});
test('cancelled prompt never sends a partial name-only or phone-only request',async()=>{for(const prompts of [[null],['Changed',null]]){const f=fixture(prompts);await f.u.editCustomerContact(f.customer);assert.equal(f.calls.length,0);assert.equal(f.customer.name,'Old');}});
test('server rejection leaves existing identity/DTOs unchanged and allows retry',async()=>{const f=fixture(undefined,{ok:false,success:false,message:'Fixture collision'});await f.u.editCustomerContact(f.customer);assert.equal(f.customer.phone,'03000000000');assert.equal(f.customer.name,'Old');assert.equal(f.u.editingCustomerId,null);assert.equal(f.toasts[0][0],'Fixture collision');});

const deliveryStart = s.indexOf('        get orderCustomerName()');
const deliveryEnd = s.indexOf('        async editCustomerContact(', deliveryStart);
const deliveryEditor = vm.runInNewContext('({' + s.slice(deliveryStart, deliveryEnd) + '})');

test('delivery edit preloads contact, saves a bill-only snapshot and ignores stale edits', () => {
    const profile = { id: 1, name: 'Saved customer', phone: '03000000000', address: 'Saved address' };
    const editor = Object.create(deliveryEditor);
    Object.assign(editor, {
        selectedCustomer: profile, selectedDeliveryAddress: profile.address, customerAddresses: [],
        hasDeliveryPortion: () => true,
        $refs: { deliveryContactEditor: { showModal() {}, close() {} } },
    });
    editor.openDeliveryContactEditor();
    assert.equal(editor.deliveryContactDraft.phone, profile.phone);
    assert.equal(editor.deliveryContactDraft.address, profile.address);
    Object.assign(editor.deliveryContactDraft, { name: 'Recipient', phone: '03111111111', address: 'New landmark' });
    editor.saveDeliveryContact();
    assert.equal(editor.orderCustomerName, 'Recipient');
    assert.equal(editor.orderCustomerPhone, '03111111111');
    assert.equal(editor.selectedDeliveryAddress, 'New landmark');
    assert.equal(profile.phone, '03000000000');
    assert.equal(profile.address, 'Saved address');
    editor.openDeliveryContactEditor();
    editor.selectedCustomer = { id: 2, name: 'Next', phone: '03222222222' };
    editor.selectedDeliveryAddress = 'Next address';
    editor.saveDeliveryContact();
    assert.equal(editor.orderCustomerPhone, '03222222222');
    assert.equal(editor.selectedDeliveryAddress, 'Next address');
});
