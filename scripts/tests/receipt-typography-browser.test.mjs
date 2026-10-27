import test from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';import {execFileSync} from 'node:child_process';import {launchLocalBrowser} from '../lib/local-browser.mjs';
const php=process.env.PHP_BINARY||'php';
const css=(style,paper)=>execFileSync(php,['-n','-r',`require 'app/Support/PosReceiptTypography.php';echo App\\Support\\PosReceiptTypography::css(json_decode($argv[1],true),$argv[2]);`,JSON.stringify(style),paper],{encoding:'utf8'});
test('production typography CSS preserves legacy defaults and full names/money at both widths',async()=>{
 assert.equal(css({},'80mm'),'');assert.equal(css({font_family:'url(evil)',item_text:'0px'},'58mm'),'');
 const {browser:b}=await launchLocalBrowser();
 try{for(const paper of ['80mm','58mm']){
  const source=readFileSync(`resources/views/pos/receipts/receipt_${paper}.blade.php`,'utf8');const base=source.match(/<style>([\s\S]*?)<\/style>/)[1];
  const p=await b.newPage();await p.setContent(`<style>${base}${css({font_family:'sans',item_text:'compact',wide_names:true},paper)}</style><table class="items-table"><tr><td class="col-item">Square Shape Pizza S — complete long item name</td><td class="col-qty">1</td><td class="col-rate">850</td><td class="col-total">850</td></tr></table>`);
  const name=p.locator('.col-item');assert.ok((await name.innerText()).includes('complete long item name'));assert.equal(await name.evaluate(e=>getComputedStyle(e).fontSize),paper==='58mm'?'9px':'10px');assert.equal(await p.locator('.col-total').innerText(),'850');assert.equal(await name.evaluate(e=>e.scrollWidth<=e.clientWidth+1),true);await p.close();
 }}finally{await b.close();}
});
