import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const s=fs.readFileSync(new URL('../../resources/views/pos/products.blade.php',import.meta.url),'utf8');
function fixture(){const a=s.indexOf('                get filtered()');const b=s.indexOf('                get stats()',a);const ui=vm.runInNewContext('({'+s.slice(a,b)+'})');Object.assign(ui,{products:[{id:1,name:'Exempt drink',is_tax_exempt:true,is_third_schedule:false,is_active:true,category:'Drink'},{id:2,name:'Taxable meal',is_tax_exempt:false,is_active:true,category:'Meal'},{id:3,name:'Third drink',is_tax_exempt:true,is_third_schedule:true,is_active:false,category:'Drink'}],search:'',catFilter:'',statusFilter:'',sortKey:'name',sortDir:'asc'});return ui;}
test('Exempt Items includes ordinary and third-schedule exempt products, excludes taxable',()=>{const u=fixture();u.statusFilter='exempt';assert.deepEqual(Array.from(u.filtered,p=>p.id),[1,3]);});
test('exempt filter composes with search/category and does not modify product flags',()=>{const u=fixture();const before=JSON.stringify(u.products);u.statusFilter='exempt';u.search='third';u.catFilter='Drink';assert.deepEqual(Array.from(u.filtered,p=>p.id),[3]);assert.equal(JSON.stringify(u.products),before);u.catFilter='Meal';assert.equal(u.filtered.length,0);});
test('All, Active and Third Schedule retain their existing meanings',()=>{const u=fixture();assert.equal(u.filtered.length,3);u.statusFilter='active';assert.deepEqual(Array.from(u.filtered,p=>p.id),[1,2]);u.statusFilter='third';assert.deepEqual(Array.from(u.filtered,p=>p.id),[3]);});
