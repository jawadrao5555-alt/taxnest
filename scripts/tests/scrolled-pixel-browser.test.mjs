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
