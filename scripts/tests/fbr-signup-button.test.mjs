import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const view=readFileSync(new URL('../../resources/views/fbr-pos/auth/register.blade.php',import.meta.url),'utf8');
const expression=view.match(/:disabled="([^"]+)"/)[1].replaceAll('&amp;','&');
// Run the installed Alpine binding implementation, not an invented truthiness rule.
const alpine=readFileSync(new URL('../../node_modules/alpinejs/dist/module.esm.js',import.meta.url),'utf8');
const binding=alpine.slice(alpine.indexOf('function bindAttribute(el,'),alpine.indexOf('function setPropertyIfChanged('));
function disabled(packageName,billingCycle,cycleQuotes,source=expression){
 const attrs=new Map();
 const el={removeAttribute:k=>attrs.delete(k),setAttribute:(k,v)=>attrs.set(k,v),getAttribute:k=>attrs.get(k)};
 const sandbox={el,packageName,billingCycle,cycleQuotes,isBooleanAttr:n=>n==='disabled',attributeShouldntBePreservedIfFalsy:()=>true};
 vm.runInNewContext(binding+'\nbindAttribute(el,"disabled",('+source+'));',sandbox);
 return attrs.has('disabled');
}
test('actual Alpine empty-string binding reproduction and explicit trial boolean fix',()=>{
 assert.equal(disabled('','annual',{},"packageName && (cycleQuotes[packageName] || {})[billingCycle] === undefined"),true);
 assert.equal(disabled('','annual',{}),false);
});
test('paid package unavailable period remains blocked while quoted zero and positive totals work',()=>{
 assert.equal(disabled('Starter','annual',{}),true);
 assert.equal(disabled('Starter','quarterly',{Starter:{annual:123}}),true);
 assert.equal(disabled('Starter','annual',{Starter:{annual:123}}),false);
 assert.equal(disabled('Starter','annual',{Starter:{annual:0}}),false);
});
