/** Capture a deterministic detail viewport, not a full-page or unmasked export. */
export function assertDetailBounds(rects,viewport){
 if(!rects.length||rects.some(r=>!r||r.width<=0||r.height<=0||r.x<0||r.y<0||r.x+r.width>viewport.width+1||r.y+r.height>viewport.height-28))throw Error('Relevant receipt/closing detail is outside the fixed screenshot viewport');
}
export async function scrolledPixelDetail(page,selectors,name,saveEvidenceScreenshot){
 const original=await page.evaluate(()=>({x:window.scrollX,y:window.scrollY}));
 try{
  for(const selector of selectors)await page.locator(selector).first().waitFor({state:'visible'});
  await page.evaluate(selectors=>{
   const boxes=selectors.map(s=>document.querySelector(s).getBoundingClientRect());
   const first=Math.min(...boxes.map(b=>b.top));
   window.scrollTo({left:0,top:window.scrollY+first-20,behavior:'instant'});
  },selectors);
  const rects=[];for(const selector of selectors)rects.push(await page.locator(selector).first().boundingBox());
  assertDetailBounds(rects,page.viewportSize());
  await saveEvidenceScreenshot(page,name);
 }finally{await page.evaluate(({x,y})=>window.scrollTo({left:x,top:y,behavior:'instant'}),original);}
}
