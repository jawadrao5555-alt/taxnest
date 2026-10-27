import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {launchLocalBrowser} from '../lib/local-browser.mjs';
const source=readFileSync(new URL('../../resources/views/pos/restaurant/table-management.blade.php',import.meta.url),'utf8');
const css=source.match(/<style>([\s\S]*?)<\/style>/)[1];
const labels=[...source.matchAll(/<label class="tn-table-setting-control">([\s\S]*?)<\/label>/g)].map(x=>x[0].replace(/{{[\s\S]*?}}/g,'').replace(/@csrf/g,'').replace(/onchange="[^"]*"/g,''));
test('actual table-setting component remains visible and operable, 1366/390 light/dark',async()=>{
 const {browser:b}=await launchLocalBrowser();
 try{for(const width of [1366,390])for(const dark of [false,true]){
  const p=await b.newPage({viewport:{width,height:844}});await p.setContent(`<html class="${dark?'dark':''}"><style>${css}</style>${labels.join('')}</html>`);
  for(const c of await p.locator('input[type=checkbox]').all()){
   assert.equal(await c.isVisible(),true);const r=await c.boundingBox();assert.ok(r.width>=20&&r.height>=20);
   await c.check();assert.equal(await c.isChecked(),true);await c.uncheck();assert.equal(await c.isChecked(),false);
   await c.focus();assert.equal(await c.evaluate(e=>document.activeElement===e),true);
  }await p.close();
 }}finally{await b.close();}
});
