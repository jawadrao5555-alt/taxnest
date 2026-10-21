import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const s=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');const a=s.indexOf('        filteredReprintBills()');const b=s.indexOf('\n        },',a)+11;
function fixture(){const u=vm.runInNewContext('({'+s.slice(a,b)+'})');Object.assign(u,{reprintSearch:'',reprintOrderType:'',reprintPayment:'',reprintBills:[{id:1,order_type:'delivery',payment_method:'cash',customer_name:'Fixture Alpha'},{id:2,order_type:'takeaway',payment_method:'card'},{id:3,order_type:'delivery',payment_method:'debit_card',customer_name:'Fixture Alpha'},{id:4,order_type:'dine_in',payment_method:'credit_card'},{id:5,order_type:'delivery',payment_method:'qr_payment'},{id:6,order_type:null,payment_method:'bank_transfer'}]});return u;}
const ids=u=>Array.from(u.filteredReprintBills(),b=>b.id);
test('order-type filter combines with cash/card and search',()=>{const u=fixture();u.reprintOrderType='delivery';assert.deepEqual(ids(u),[1,3,5]);u.reprintPayment='card';u.reprintSearch='alpha';assert.deepEqual(ids(u),[3]);u.reprintPayment='cash';assert.deepEqual(ids(u),[1]);});
test('card includes legacy/debit/credit aliases without treating QR as card',()=>{const u=fixture();u.reprintPayment='card';assert.deepEqual(ids(u),[2,3,4]);});
test('All preserves other/unknown order and payment kinds and leaves bills immutable',()=>{const u=fixture();const snapshot=JSON.stringify(u.reprintBills);assert.equal(ids(u).length,6);u.reprintOrderType='dine_in';u.reprintPayment='cash';assert.equal(ids(u).length,0);u.reprintOrderType='';u.reprintPayment='';assert.equal(ids(u).length,6);assert.equal(JSON.stringify(u.reprintBills),snapshot);});
