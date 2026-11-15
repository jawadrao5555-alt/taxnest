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
  assert.equal(geometry.inlineBottom,'auto');assert.equal(geometry.inlineLeft,'auto');assert.ok(geometry.inlineTop>=geometry.summaryBottom);
  assert.equal(geometry.floatingPosition,'fixed');assert.equal(geometry.floatingBottom,viewport.width===390?68:20);
  await p.close();
 }}finally{await browser.close();}
});
