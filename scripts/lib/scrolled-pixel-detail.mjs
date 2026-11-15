/** Capture a deterministic detail viewport, not a full-page or unmasked export. */
export function assertDetailBounds(rects,viewport){
 if(!rects.length||rects.some(r=>!r||r.width<=0||r.height<=0||r.x<0||r.y<0||r.x+r.width>viewport.width+1||r.y+r.height>viewport.height-28))throw Error('Relevant receipt/closing detail is outside the fixed screenshot viewport: '+JSON.stringify({rects,viewport}));
}
export async function scrolledPixelDetail(page,selectors,name,saveEvidenceScreenshot){
 const original=await page.evaluate(()=>({x:window.scrollX,y:window.scrollY}));
 const scrollParents=await page.locator(selectors[0]).first().evaluateHandle(el=>{
  const parents=[];for(let p=el.parentElement;p;p=p.parentElement){if(p.scrollHeight>p.clientHeight&&/(auto|scroll)/.test(getComputedStyle(p).overflowY))parents.push({element:p,x:p.scrollLeft,y:p.scrollTop});}return parents;
 });
 try{
  for(const selector of selectors)await page.locator(selector).first().waitFor({state:'visible'});
  await page.evaluate(({selectors,parents})=>{
   const first=selectors.map(s=>document.querySelector(s)).sort((a,b)=>a.getBoundingClientRect().top-b.getBoundingClientRect().top)[0];
   first.scrollIntoView({block:'start',inline:'nearest',behavior:'instant'});
   // Account for nested admin/POS scroll containers, not only window scrolling.
   if(parents.length)parents[0].element.scrollTop=Math.max(0,parents[0].element.scrollTop-20);
   else window.scrollBy({left:0,top:-20,behavior:'instant'});
  },{selectors,parents:scrollParents});
  const rects=[];for(const selector of selectors)rects.push(await page.locator(selector).first().boundingBox());
  assertDetailBounds(rects,page.viewportSize());
  await saveEvidenceScreenshot(page,name);
 }finally{
  await page.evaluate(parents=>{for(const p of parents)p.element.scrollTo({left:p.x,top:p.y,behavior:'instant'});},scrollParents);
  await scrollParents.dispose();
  await page.evaluate(({x,y})=>window.scrollTo({left:x,top:y,behavior:'instant'}),original);
 }
}
