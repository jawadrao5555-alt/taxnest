import test from 'node:test';
import assert from 'node:assert/strict';
import {launchLocalBrowser} from '../lib/local-browser.mjs';
import {scrolledPixelDetail} from '../lib/scrolled-pixel-detail.mjs';
test('fixed detail screenshots scroll nested main and window, then restore both',async()=>{
 const {browser}=await launchLocalBrowser();
 try{for(const viewport of [{width:1366,height:900},{width:390,height:844}])for(const nested of [true,false]){
  const p=await browser.newPage({viewport});
  await p.setContent(`<style>body{margin:0}${nested?'main{height:700px;overflow-y:auto;margin-top:60px}':''}#before{height:1400px}#detail{height:220px;background:white}#after{height:1400px}</style><main><div id="before"></div><section id="detail"><h2 id="heading">Synthetic detail</h2><button id="save">Save</button></section><div id="after"></div></main>`);
  const before=await p.evaluate(()=>({window:scrollY,main:document.querySelector('main').scrollTop}));let captures=0;
  await scrolledPixelDetail(p,['#heading','#save'],'not-exported',async()=>{captures++;const b=await p.locator('#save').boundingBox();assert.ok(b.y>=0&&b.y+b.height<viewport.height-28);});
  assert.equal(captures,1);assert.deepEqual(await p.evaluate(()=>({window:scrollY,main:document.querySelector('main').scrollTop})),before);await p.close();
 }}finally{await browser.close();}
});

test('mobile support offsets apply only to floating widgets, never the inline usage dock',async()=>{
 const {readFileSync}=await import('node:fs');
 const mobileCss=readFileSync(new URL('../../public/css/mobile.css',import.meta.url),'utf8');
 const {browser}=await launchLocalBrowser();
 try{for(const viewport of [{width:1366,height:900},{width:390,height:844}]){
  const p=await browser.newPage({viewport});
  await p.setContent(`<style>body{margin:0}.summary{height:450px}.inline{position:relative;height:56px}.floating{position:fixed;bottom:20px;left:20px;width:56px;height:56px}${mobileCss}</style><body class="tn-premium-shell"><section class="summary">Synthetic summary</section><div class="inline" x-data="tnMadadgar()" data-madadgar-placement="inline">Support</div><div class="floating" x-data="tnMadadgar()" data-madadgar-placement="floating">Floating</div></body>`);
  const geometry=await p.evaluate(()=>{const i=document.querySelector('.inline'),f=document.querySelector('.floating'),s=document.querySelector('.summary');return {inlineBottom:getComputedStyle(i).bottom,inlineLeft:getComputedStyle(i).left,inlineTop:i.getBoundingClientRect().top,summaryBottom:s.getBoundingClientRect().bottom,floatingBottom:parseFloat(getComputedStyle(f).bottom),floatingPosition:getComputedStyle(f).position};});
  // Chromium resolves relative auto offsets to zero pixels. Both mean no displacement.
  assert.ok(['auto','0px'].includes(geometry.inlineBottom));assert.ok(['auto','0px'].includes(geometry.inlineLeft));assert.ok(geometry.inlineTop>=geometry.summaryBottom);
  assert.equal(geometry.floatingPosition,'fixed');assert.equal(geometry.floatingBottom,viewport.width===390?68:20);
  await p.close();
 }}finally{await browser.close();}
});

test('expense history final rows and full right-hand audit columns fit fixed captures',async()=>{
 const {readFileSync}=await import('node:fs');
 const css=readFileSync(new URL('../../resources/views/saas-admin/platform-expenses.blade.php',import.meta.url),'utf8').match(/<style>([\s\S]*?)<\/style>/)[1];
 const mobileCss=readFileSync(new URL('../../public/css/mobile.css',import.meta.url),'utf8');
 const {browser}=await launchLocalBrowser();
 try{for(const viewport of [{width:1366,height:900},{width:390,height:844}]){
  const p=await browser.newPage({viewport});
  const row=kind=>`<tr><td>2026-11-15 ${kind}</td><td>Synthetic reference</td><td>PKR 123.45</td><td>Synthetic Expense Owner 390 (#99)<br>2026-11-15 19:00:00<br>Request 12345678-1234-1234-1234-123456789abc</td><td>${kind==='reversal'?'Reverses #1':'Reversed (original retained)'}</td></tr>`;
  await p.setContent(`<style>body{margin:0;font:16px sans-serif}main{height:100vh;overflow:auto}#platform-expenses{padding:24px;box-sizing:border-box}#expense-history{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:850px}td{height:90px}${mobileCss}${css}</style><main><section id="platform-expenses"><div style="height:1200px">Synthetic forms</div><div id="expense-history"><table class="table-static"><thead><tr><th>Date</th><th>Reference</th><th>Recorded amount</th><th>Actor / audit</th><th>Correction</th></tr></thead><tbody>${row('reversal')}${row('expense')}</tbody></table></div></section></main>`);
  const aligned=await p.evaluate(()=>[3,4,5].every(c=>{const h=document.querySelector(`#expense-history th:nth-child(${c})`).getBoundingClientRect();const d=document.querySelector(`#expense-history tbody td:nth-child(${c})`).getBoundingClientRect();return Math.abs(h.x-d.x)<1&&Math.abs(h.width-d.width)<1;}));
  assert.ok(aligned,'Expense header/body must share a native column grid');
  for(const col of [3,4,5])await scrolledPixelDetail(p,[`#expense-history th:nth-child(${col})`,`#expense-history tr:nth-child(1) td:nth-child(${col})`,`#expense-history tr:nth-child(2) td:nth-child(${col})`],'not-exported',async()=>{});
  await p.close();
 }}finally{await browser.close();}
});
