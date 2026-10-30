'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const context = {};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/js/pos-deal-allocation.js'), 'utf8'), context);
const a = context.PosDealAllocation;
const snapshot = [
    {allocation_version:1,allocation_weight:800,qty:1,tax_facts:{is_tax_exempt:false}},
    {allocation_version:1,allocation_weight:200,qty:1,tax_facts:{is_tax_exempt:true}},
];
const cases = [[false,null,16,812,92,576],[true,null,16,720,79.45,496.55],[true,16,5,665,24.83,496.55]];
for (const [inclusive,menu,rate,total,tax,taxable] of cases) {
    const result=a.bill([{snapshot,subtotal:800,exempt:false}],80,rate,inclusive,menu);
    assert.equal(result.total_amount,total);assert.equal(result.tax_amount,tax);assert.equal(result.exempt_amount,144);
    assert.equal(result.lines[0].snapshot[0].allocation.taxable_base,taxable);
    assert.equal(result.lines[0].snapshot[1].allocation.tax,0);
}
// Allocation has to conserve pennies, including zero-price components and equal-weight ties.
for (let cents=0;cents<250;cents++) {
    const values=a.cents(cents,[1,1,1]);
    assert.equal(values.reduce((x,y)=>x+y,0),cents);
    assert.ok(Math.max(...values)-Math.min(...values)<=1);
}
const small=a.bill([{snapshot,subtotal:.03},{subtotal:.02,exempt:true}],.01,16,true,null);
assert.equal(small.lines.reduce((sum,line)=>sum+Math.round(line.discount*100),0),1);
assert.equal(Math.round((small.subtotal_col-small.discount_amount+small.tax_amount)*100),4);
assert.equal(a.bill([{subtotal:100,exempt:false}],0,16,false,null),null,'legacy billing keeps its existing calculator');
console.log('PRA deal preview: mixed taxes, three pricing modes and paisa conservation pass');
