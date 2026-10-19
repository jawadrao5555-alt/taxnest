import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');
function method(name) {
 const start=source.search(new RegExp('^        (?:async )?'+name+'\\(', 'm'));
 assert(start>=0,'real sale method '+name+' exists');
 return source.slice(start,source.indexOf('\n        },',start)+11).trim();
}
function fixture(items,query='deal') {
 let callback;
 const context=vm.createContext({setTimeout:fn=>{callback=fn;return 1;},clearTimeout:()=>{}});
 const ui=vm.runInContext('({'+['searchTokens','nameMatchRank','onSearchInput','isExactCodeMatch','moveHighlight'].map(method).join('\n')+'})',context);
 Object.assign(ui,{allDeals:items.filter(x=>x.type==='deal'),allProducts:items.filter(x=>x.type==='product'),allServices:[],searchQuery:query,searchAnyWord:false,filterProducts(){},highlightIndex:0,$refs:{},$nextTick:fn=>fn()});
 ui.onSearchInput(); callback();return ui;
}
const deals=Array.from({length:16},(_,i)=>({id:i+1,type:'deal',name:'Deal No '+(i+1),price:100}));
test('all sixteen matching deals remain available in global search',()=>{
 const ui=fixture(deals);assert.equal(ui.searchSuggestions.length,16);
 for(let id=1;id<=16;id++)assert(ui.searchSuggestions.some(x=>x.id===id));
});
test('an exact scanner match beyond the old twelve-result cutoff ranks first',()=>{
 const products=Array.from({length:30},(_,i)=>({id:100+i,type:'product',name:'Other',price:10,sku:'CODE-42-'+i}));
 products.push({id:999,type:'product',name:'Exact scanner target',price:10,barcode:'42'});
 const ui=fixture(products,'42');assert.equal(ui.searchSuggestions[0].id,999);
});
test('prefix and any-word fallback preserve matching and exclude non-saleable products',()=>{
 const ui=fixture([{id:1,type:'product',name:'Chicken Deal',price:20},{id:2,type:'product',name:'Deal Regular',price:30},{id:3,type:'product',name:'Deal Free',price:0}]);
 assert.deepEqual(Array.from(ui.searchSuggestions,x=>x.id),[2]);
 const fallback=fixture([{id:1,type:'product',name:'Chicken Wings',price:20}],'wings');
 assert.equal(fallback.searchSuggestions[0].id,1);
});

test('keyboard navigation exposes matches after the first rendering batch',()=>{
 const ui=fixture(Array.from({length:85},(_,i)=>({id:i,type:'deal',name:'Deal '+i,price:100})));
 assert.equal(ui.searchSuggestions.length,85);assert.equal(ui.searchDisplayCount,60);
 ui.highlightIndex=59;ui.moveHighlight(1);assert.equal(ui.highlightIndex,60);assert.equal(ui.searchDisplayCount,61);
});
test('fallback name and code match do not duplicate a dropdown item',()=>{
 const ui=fixture([{id:1,type:'product',name:'QA Code42',price:10,sku:'CODE42'}],'code42');
 assert.equal(ui.searchSuggestions.length,1);
});
